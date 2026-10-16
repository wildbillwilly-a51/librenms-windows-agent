using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;

namespace LibreNMS.WindowsAgent.Core
{
    /// <summary>
    /// Identity of a Windows service that belongs to a SQL Server installation.
    /// </summary>
    public sealed class SqlServiceIdentity
    {
        public string Component { get; set; } = SqlServerPolicy.ComponentUnknown;

        /// <summary>Instance the service belongs to, or empty for host-wide services.</summary>
        public string Instance { get; set; } = string.Empty;

        public bool InstanceScoped { get; set; }
    }

    /// <summary>
    /// Health verdict for one SQL Server component, using the same
    /// state/reason/impact/expected vocabulary as the Horizon collector.
    /// </summary>
    public sealed class SqlClassification
    {
        public string State { get; set; } = "ok";
        public string ReasonCode { get; set; } = "none";
        public string Impact { get; set; } = "none";
        public bool Expected { get; set; }

        public bool IsHealthIssue
        {
            get
            {
                return Expected &&
                    (string.Equals(State, "warning", StringComparison.OrdinalIgnoreCase) ||
                     string.Equals(State, "critical", StringComparison.OrdinalIgnoreCase));
            }
        }
    }

    /// <summary>
    /// TCP endpoint configuration for one instance, resolved from the registry
    /// rather than guessed from a port allowlist.
    /// </summary>
    public sealed class SqlPortResolution
    {
        /// <summary>Comma separated port list, empty when nothing is configured.</summary>
        public string Ports { get; set; } = string.Empty;

        /// <summary>static, dynamic, dynamic_unassigned, disabled or unknown.</summary>
        public string Source { get; set; } = "unknown";

        public IList<int> PortNumbers { get; } = new List<int>();
    }

    public static class SqlServerPolicy
    {
        public const string ComponentEngine = "engine";
        public const string ComponentAgent = "agent";
        public const string ComponentBrowser = "browser";
        public const string ComponentFullText = "fulltext";
        public const string ComponentAnalysisServices = "analysis_services";
        public const string ComponentReportingServices = "reporting_services";
        public const string ComponentIntegrationServices = "integration_services";
        public const string ComponentMachineLearning = "machine_learning";
        public const string ComponentPolybase = "polybase";
        public const string ComponentVssWriter = "vss_writer";
        public const string ComponentTelemetry = "telemetry";
        public const string ComponentUnknown = "unknown";

        public const string DefaultInstanceName = "MSSQLSERVER";

        /// <summary>
        /// Maps a Windows service name to its SQL Server component and instance.
        /// Returns null when the service is not part of a SQL Server installation.
        /// </summary>
        public static SqlServiceIdentity Identify(string serviceName)
        {
            var name = (serviceName ?? string.Empty).Trim();
            if (name.Length == 0)
            {
                return null;
            }

            // Host-wide services carry no instance suffix.
            if (Equals(name, "SQLBrowser"))
            {
                return HostWide(ComponentBrowser);
            }

            if (Equals(name, "SQLWriter") || Equals(name, "SqlServerWriter"))
            {
                return HostWide(ComponentVssWriter);
            }

            // Integration Services is versioned (MsDtsServer130, MsDtsServer140), not instanced.
            if (StartsWith(name, "MsDtsServer"))
            {
                return HostWide(ComponentIntegrationServices);
            }

            // SSRS 2017 and later installs as a single standalone service.
            if (Equals(name, "SQLServerReportingServices") || Equals(name, "PowerBIReportServer"))
            {
                return HostWide(ComponentReportingServices);
            }

            var engine = Suffix(name, "MSSQLSERVER", "MSSQL$");
            if (engine != null)
            {
                return Instanced(ComponentEngine, engine);
            }

            var agent = Suffix(name, "SQLSERVERAGENT", "SQLAgent$");
            if (agent != null)
            {
                return Instanced(ComponentAgent, agent);
            }

            // MSSQLFDLauncher must be tested before the MSSQL$ engine prefix would
            // otherwise be a concern; it uses its own MSSQLFDLauncher$ form.
            var fullText = Suffix(name, "MSSQLFDLauncher", "MSSQLFDLauncher$");
            if (fullText != null)
            {
                return Instanced(ComponentFullText, fullText);
            }

            var launchpad = Suffix(name, "MSSQLLaunchpad", "MSSQLLaunchpad$");
            if (launchpad != null)
            {
                return Instanced(ComponentMachineLearning, launchpad);
            }

            var olap = Suffix(name, "MSSQLServerOLAPService", "MSOLAP$");
            if (olap != null)
            {
                return Instanced(ComponentAnalysisServices, olap);
            }

            var reporting = Suffix(name, "ReportServer", "ReportServer$");
            if (reporting != null)
            {
                return Instanced(ComponentReportingServices, reporting);
            }

            var telemetry = Suffix(name, "SQLTELEMETRY", "SQLTELEMETRY$");
            if (telemetry != null)
            {
                return Instanced(ComponentTelemetry, telemetry);
            }

            if (StartsWith(name, "SQLPBENGINE") || StartsWith(name, "SQLPBDMS"))
            {
                return HostWide(ComponentPolybase);
            }

            return null;
        }

        public static SqlClassification ClassifyEngine(string state, string startMode)
        {
            if (IsRunning(state))
            {
                return Result("ok", "engine_running", "none", true);
            }

            if (IsDisabled(startMode))
            {
                return Result("info", "engine_disabled", "observation", false);
            }

            if (IsAutomatic(startMode))
            {
                return Result("critical", "engine_not_running", "database_service", true);
            }

            return Result("info", "engine_not_expected_running", "observation", false);
        }

        public static SqlClassification ClassifyAgent(string state, string startMode, bool engineRunning)
        {
            if (IsRunning(state))
            {
                return Result("ok", "agent_running", "none", true);
            }

            if (!engineRunning)
            {
                return Result("info", "engine_not_running", "observation", false);
            }

            if (IsDisabled(startMode))
            {
                return Result("info", "agent_disabled", "observation", false);
            }

            if (IsAutomatic(startMode))
            {
                // No SQL Agent means no scheduled backups, log backups or maintenance.
                return Result("critical", "agent_not_running", "scheduled_work", true);
            }

            return Result("info", "agent_not_expected_running", "observation", false);
        }

        public static SqlClassification ClassifyBrowser(string state, string startMode, bool dynamicPortInstancesPresent)
        {
            if (IsRunning(state))
            {
                return Result("ok", "browser_running", "none", dynamicPortInstancesPresent);
            }

            if (!dynamicPortInstancesPresent)
            {
                return Result("info", "browser_not_required", "observation", false);
            }

            if (IsDisabled(startMode))
            {
                return Result("warning", "browser_disabled_with_dynamic_port_instances", "client_resolution", true);
            }

            // Clients cannot resolve a named instance on a dynamic port without the Browser.
            return Result("warning", "browser_not_running_with_dynamic_port_instances", "client_resolution", true);
        }

        public static SqlClassification ClassifyComponent(string component, string state, string startMode, bool engineRunning)
        {
            if (IsRunning(state))
            {
                return Result("ok", "component_running", "none", IsAutomatic(startMode));
            }

            // Telemetry is never required for database health.
            if (component == ComponentTelemetry)
            {
                return Result("info", "component_not_required", "observation", false);
            }

            if (IsDisabled(startMode))
            {
                return Result("info", "component_disabled", "observation", false);
            }

            if (!engineRunning && component != ComponentVssWriter && component != ComponentIntegrationServices)
            {
                return Result("info", "engine_not_running", "observation", false);
            }

            if (IsAutomatic(startMode))
            {
                return Result("warning", "component_not_running", component, true);
            }

            return Result("info", "component_not_expected_running", "observation", false);
        }

        /// <summary>
        /// Resolves the TCP endpoints an instance is configured to listen on.
        /// SQL Server writes the live dynamic port back to TcpDynamicPorts under
        /// IPAll, so a running named instance reports its real port here. A value
        /// of "0" means dynamic ports are configured but none is assigned yet.
        /// </summary>
        public static SqlPortResolution ResolvePorts(string tcpPort, string tcpDynamicPorts, bool tcpEnabled)
        {
            var result = new SqlPortResolution();
            if (!tcpEnabled)
            {
                result.Source = "disabled";
                return result;
            }

            var statics = ParsePorts(tcpPort);
            if (statics.Count > 0)
            {
                result.Source = "static";
                Fill(result, statics);
                return result;
            }

            var trimmedDynamic = (tcpDynamicPorts ?? string.Empty).Trim();
            if (trimmedDynamic == "0")
            {
                result.Source = "dynamic_unassigned";
                return result;
            }

            var dynamics = ParsePorts(trimmedDynamic);
            if (dynamics.Count > 0)
            {
                result.Source = "dynamic";
                Fill(result, dynamics);
                return result;
            }

            result.Source = "unknown";
            return result;
        }

        /// <summary>
        /// True when the instance relies on the SQL Browser for client resolution.
        /// </summary>
        public static bool RequiresBrowser(string instanceName, SqlPortResolution ports)
        {
            if (IsDefaultInstance(instanceName))
            {
                return false;
            }

            if (ports == null)
            {
                return false;
            }

            return ports.Source == "dynamic" || ports.Source == "dynamic_unassigned";
        }

        public static bool IsDefaultInstance(string instanceName)
        {
            return Equals((instanceName ?? string.Empty).Trim(), DefaultInstanceName);
        }

        public static string NextAction(string state, int enginesNotRunning, int agentsNotRunning, int componentIssues, int unmappedEngineProcesses, int instancesWithoutListener)
        {
            if (string.Equals(state, "disabled", StringComparison.OrdinalIgnoreCase))
            {
                return "Collector disabled by config.";
            }

            if (string.Equals(state, "not_detected", StringComparison.OrdinalIgnoreCase))
            {
                return "No action; SQL service evidence was not detected.";
            }

            if (enginesNotRunning > 0)
            {
                return "Start the stopped SQL Server database engine service and review its SQL Server error log.";
            }

            if (agentsNotRunning > 0)
            {
                return "Start the stopped SQL Server Agent service; scheduled backups and maintenance jobs are not running.";
            }

            if (instancesWithoutListener > 0)
            {
                return "Confirm the TCP listener configuration for instances with no active listening endpoint.";
            }

            if (componentIssues > 0)
            {
                return "Review the stopped SQL Server component services listed below.";
            }

            if (unmappedEngineProcesses > 0)
            {
                return "Investigate sqlservr.exe processes that no detected SQL Server service owns.";
            }

            return "No action; surface SQL evidence is healthy.";
        }

        private static void Fill(SqlPortResolution result, IList<int> ports)
        {
            foreach (var port in ports)
            {
                result.PortNumbers.Add(port);
            }

            result.Ports = string.Join(",", ports.Select(port => port.ToString(CultureInfo.InvariantCulture)).ToArray());
        }

        private static IList<int> ParsePorts(string value)
        {
            var ports = new List<int>();
            foreach (var part in (value ?? string.Empty).Split(','))
            {
                var trimmed = part.Trim();
                if (trimmed.Length == 0)
                {
                    continue;
                }

                int parsed;
                if (int.TryParse(trimmed, NumberStyles.Integer, CultureInfo.InvariantCulture, out parsed) &&
                    parsed > 0 && parsed <= 65535 && !ports.Contains(parsed))
                {
                    ports.Add(parsed);
                }
            }

            ports.Sort();
            return ports;
        }

        private static SqlServiceIdentity HostWide(string component)
        {
            return new SqlServiceIdentity { Component = component, Instance = string.Empty, InstanceScoped = false };
        }

        private static SqlServiceIdentity Instanced(string component, string instance)
        {
            return new SqlServiceIdentity { Component = component, Instance = instance, InstanceScoped = true };
        }

        /// <summary>
        /// Returns the instance name when <paramref name="name"/> is either the
        /// default-instance service name or the named-instance prefixed form.
        /// </summary>
        private static string Suffix(string name, string defaultInstanceService, string namedPrefix)
        {
            if (Equals(name, defaultInstanceService))
            {
                return DefaultInstanceName;
            }

            if (StartsWith(name, namedPrefix) && name.Length > namedPrefix.Length)
            {
                return name.Substring(namedPrefix.Length);
            }

            return null;
        }

        private static SqlClassification Result(string state, string reason, string impact, bool expected)
        {
            return new SqlClassification
            {
                State = state,
                ReasonCode = reason,
                Impact = impact,
                Expected = expected
            };
        }

        private static bool IsRunning(string state)
        {
            return string.Equals(state, "Running", StringComparison.OrdinalIgnoreCase);
        }

        private static bool IsAutomatic(string startMode)
        {
            return string.Equals(startMode, "Auto", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(startMode, "Automatic", StringComparison.OrdinalIgnoreCase);
        }

        private static bool IsDisabled(string startMode)
        {
            return string.Equals(startMode, "Disabled", StringComparison.OrdinalIgnoreCase);
        }

        private static bool Equals(string left, string right)
        {
            return string.Equals(left, right, StringComparison.OrdinalIgnoreCase);
        }

        private static bool StartsWith(string value, string prefix)
        {
            return (value ?? string.Empty).StartsWith(prefix, StringComparison.OrdinalIgnoreCase);
        }
    }
}
