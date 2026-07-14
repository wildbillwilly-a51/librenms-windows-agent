using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net.NetworkInformation;
using System.ServiceProcess;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class SqlServerCollector : CollectorBase
    {
        public override string Name => "sql_server";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var config = context.Config.Collectors.SqlServer ?? new SqlServerConfig();
            if (IsDisabled(config.Mode))
            {
                return Complete(
                    new AgentSection("windows_agent_sql_server_summary", new[] { SummaryLine("disabled", 0, 0, 0, 0) }),
                    new AgentSection("windows_agent_sql_server_instances", Array.Empty<string>()));
            }

            var services = ServiceInventoryReader.Read(cancellationToken);
            var sqlServices = services
                .Where(IsSqlService)
                .OrderBy(service => service.Name, StringComparer.OrdinalIgnoreCase)
                .ToList();

            if (sqlServices.Count == 0 && IsAuto(config.Mode))
            {
                return Complete(
                    new AgentSection("windows_agent_sql_server_summary", new[] { SummaryLine("not_detected", 0, 0, 0, 0) }),
                    new AgentSection("windows_agent_sql_server_instances", Array.Empty<string>()));
            }

            var listeners = IPGlobalProperties.GetIPGlobalProperties().GetActiveTcpListeners();
            var instanceServices = sqlServices.Where(IsSqlEngineService).ToList();
            var agentServices = sqlServices.Where(IsSqlAgentService).ToList();
            var browser = sqlServices.FirstOrDefault(service => string.Equals(service.Name, "SQLBrowser", StringComparison.OrdinalIgnoreCase));
            var lines = new List<string>();

            foreach (var service in instanceServices)
            {
                cancellationToken.ThrowIfCancellationRequested();
                var instance = InstanceName(service.Name);
                var agent = agentServices.FirstOrDefault(item => string.Equals(InstanceName(item.Name), instance, StringComparison.OrdinalIgnoreCase));
                var executable = ExtractExecutablePath(service.PathName);

                lines.Add(string.Join(" ",
                    Kv("instance", instance),
                    Kv("service", service.Name),
                    Kv("display", service.DisplayName),
                    Kv("state", service.State),
                    Kv("start_mode", service.StartMode),
                    Kv("path_exists", File.Exists(executable) ? 1 : 0),
                    Kv("version", FileVersion(executable)),
                    Kv("agent_service", agent?.Name ?? string.Empty),
                    Kv("agent_state", agent?.State ?? "missing"),
                    Kv("browser_state", browser?.State ?? "missing"),
                    Kv("listener_ports", string.Join(",", listeners.Select(listener => listener.Port).Where(IsLikelySqlPort).Distinct().OrderBy(port => port)))));
            }

            var running = instanceServices.Count(service => IsRunning(service.State));
            var notRunning = Math.Max(0, instanceServices.Count - running);
            var listenerCount = listeners.Count(listener => IsLikelySqlPort(listener.Port));

            return Complete(
                new AgentSection("windows_agent_sql_server_summary", new[] { SummaryLine(instanceServices.Count > 0 ? "ok" : "not_detected", instanceServices.Count, running, notRunning, listenerCount) }),
                new AgentSection("windows_agent_sql_server_instances", lines));
        }

        private static string SummaryLine(string state, int total, int running, int notRunning, int listenerCount)
        {
            var detected = total > 0 ? 1 : 0;
            var healthIssues = notRunning;
            return string.Join(" ",
                Kv("state", state),
                Kv("detected", detected),
                Kv("instances_total", total),
                Kv("instances_running", running),
                Kv("instances_not_running", notRunning),
                Kv("listener_ports_total", listenerCount),
                Kv("health_issues", healthIssues),
                RoleEvidenceFields(
                    string.Format("instances={0};listeners={1}", total, listenerCount),
                    detected == 1 ? "scored" : "inventory",
                    NextAction(state, healthIssues, listenerCount)));
        }

        private static string NextAction(string state, int healthIssues, int listenerCount)
        {
            if (IsDisabled(state))
            {
                return "Collector disabled by config.";
            }

            if (string.Equals(state, "not_detected", StringComparison.OrdinalIgnoreCase))
            {
                return "No action; SQL service evidence was not detected.";
            }

            if (healthIssues > 0)
            {
                return "Check stopped SQL Server or SQL Agent services and recent SQL Server error logs.";
            }

            if (listenerCount == 0)
            {
                return "Confirm whether this SQL instance should expose a TCP listener.";
            }

            return "No action; surface SQL evidence is healthy.";
        }

        private static bool IsSqlService(ServiceInventoryRecord service)
        {
            var name = service.Name ?? string.Empty;
            return IsSqlEngineService(service) ||
                IsSqlAgentService(service) ||
                string.Equals(name, "SQLBrowser", StringComparison.OrdinalIgnoreCase) ||
                name.StartsWith("MSSQLFDLauncher", StringComparison.OrdinalIgnoreCase) ||
                name.StartsWith("ReportServer", StringComparison.OrdinalIgnoreCase);
        }

        private static bool IsSqlEngineService(ServiceInventoryRecord service)
        {
            var name = service.Name ?? string.Empty;
            return string.Equals(name, "MSSQLSERVER", StringComparison.OrdinalIgnoreCase) ||
                name.StartsWith("MSSQL$", StringComparison.OrdinalIgnoreCase);
        }

        private static bool IsSqlAgentService(ServiceInventoryRecord service)
        {
            var name = service.Name ?? string.Empty;
            return string.Equals(name, "SQLSERVERAGENT", StringComparison.OrdinalIgnoreCase) ||
                name.StartsWith("SQLAgent$", StringComparison.OrdinalIgnoreCase);
        }

        private static string InstanceName(string serviceName)
        {
            if (string.Equals(serviceName, "MSSQLSERVER", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(serviceName, "SQLSERVERAGENT", StringComparison.OrdinalIgnoreCase))
            {
                return "MSSQLSERVER";
            }

            var dollar = (serviceName ?? string.Empty).IndexOf('$');
            return dollar >= 0 && dollar < serviceName.Length - 1 ? serviceName.Substring(dollar + 1) : serviceName;
        }

        private static bool IsLikelySqlPort(int port)
        {
            return port == 1433 || port == 1434;
        }

        private static bool IsRunning(string state)
        {
            return string.Equals(state, "Running", StringComparison.OrdinalIgnoreCase);
        }

        private static string ExtractExecutablePath(string pathName)
        {
            var value = (pathName ?? string.Empty).Trim();
            if (value.StartsWith("\"", StringComparison.Ordinal) && value.IndexOf('"', 1) > 1)
            {
                return value.Substring(1, value.IndexOf('"', 1) - 1);
            }

            var exe = value.IndexOf(".exe", StringComparison.OrdinalIgnoreCase);
            return exe >= 0 ? value.Substring(0, exe + 4) : value;
        }

        private static string FileVersion(string path)
        {
            try
            {
                return File.Exists(path) ? FileVersionInfo.GetVersionInfo(path).FileVersion ?? string.Empty : string.Empty;
            }
            catch (IOException)
            {
                return string.Empty;
            }
            catch (UnauthorizedAccessException)
            {
                return string.Empty;
            }
        }

        private static bool IsDisabled(string mode)
        {
            return string.Equals(mode, "disabled", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(mode, "off", StringComparison.OrdinalIgnoreCase);
        }

        private static bool IsAuto(string mode)
        {
            return string.IsNullOrWhiteSpace(mode) || string.Equals(mode, "auto", StringComparison.OrdinalIgnoreCase);
        }
    }
}
