using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class IisCollector : CollectorBase, ICollectorTimeoutOverride
    {
        public override string Name => "iis";

        public TimeSpan GetTimeout(AgentContext context, TimeSpan defaultTimeout)
        {
            var seconds = context.Config.Collectors.Iis?.CommandTimeoutSeconds ?? 10;
            return TimeSpan.FromSeconds(Math.Max(1, seconds));
        }

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var config = context.Config.Collectors.Iis ?? new IisConfig();
            if (IsDisabled(config.Mode))
            {
                return Complete(
                    new AgentSection("windows_agent_iis_summary", new[] { SummaryLine("disabled", 0, 0, 0, 0, 0, 0, 0) }),
                    new AgentSection("windows_agent_iis_sites", Array.Empty<string>()),
                    new AgentSection("windows_agent_iis_app_pools", Array.Empty<string>()),
                    new AgentSection("windows_agent_iis_bindings", Array.Empty<string>()));
            }

            var services = ServiceInventoryReader.Read(cancellationToken);
            var detected = services.Any(service => IsIisService(service.Name));
            if (!detected && IsAuto(config.Mode))
            {
                return Complete(
                    new AgentSection("windows_agent_iis_summary", new[] { SummaryLine("not_detected", 0, 0, 0, 0, 0, 0, 0) }),
                    new AgentSection("windows_agent_iis_sites", Array.Empty<string>()),
                    new AgentSection("windows_agent_iis_app_pools", Array.Empty<string>()),
                    new AgentSection("windows_agent_iis_bindings", Array.Empty<string>()));
            }

            var timeout = TimeSpan.FromSeconds(Math.Max(1, config.CommandTimeoutSeconds));
            var sites = config.IncludeSites ? RunPowerShell(SitesCommand(), timeout, cancellationToken) : CommandRows.Disabled();
            var appPools = config.IncludeAppPools ? RunPowerShell(AppPoolsCommand(), timeout, cancellationToken) : CommandRows.Disabled();
            var bindings = config.IncludeBindings ? RunPowerShell(BindingsCommand(config.IncludeCertificates), timeout, cancellationToken) : CommandRows.Disabled();

            var unsupported = new[] { sites, appPools, bindings }.Any(rows => rows.State == "unsupported");
            var state = unsupported ? "unsupported" : "ok";
            var siteRows = sites.Rows.Select(row => string.Join(" ",
                Kv("name", row.Value(0)),
                Kv("id", row.Value(1)),
                Kv("state", row.Value(2)),
                Kv("bindings_count", row.Value(3)),
                Kv("physical_path", row.Value(4)))).ToList();
            var appPoolRows = appPools.Rows.Select(row => string.Join(" ",
                Kv("name", row.Value(0)),
                Kv("state", row.Value(1)),
                Kv("runtime_version", row.Value(2)),
                Kv("pipeline_mode", row.Value(3)),
                Kv("identity_type", row.Value(4)))).ToList();
            var bindingRows = bindings.Rows.Select(row => string.Join(" ",
                Kv("site", row.Value(0)),
                Kv("protocol", row.Value(1)),
                Kv("binding_information", row.Value(2)),
                Kv("hostname", row.Value(3)),
                Kv("port", row.Value(4)),
                Kv("certificate_thumbprint", row.Value(5)))).ToList();

            return Complete(
                new AgentSection("windows_agent_iis_summary", new[] { SummaryLine(
                    state,
                    siteRows.Count,
                    sites.Rows.Count(row => IsRunning(row.Value(2))),
                    sites.Rows.Count(row => !IsRunning(row.Value(2))),
                    appPoolRows.Count,
                    appPools.Rows.Count(row => IsRunning(row.Value(1)) || string.Equals(row.Value(1), "Started", StringComparison.OrdinalIgnoreCase)),
                    appPools.Rows.Count(row => !(IsRunning(row.Value(1)) || string.Equals(row.Value(1), "Started", StringComparison.OrdinalIgnoreCase))),
                    bindingRows.Count) }),
                new AgentSection("windows_agent_iis_sites", siteRows),
                new AgentSection("windows_agent_iis_app_pools", appPoolRows),
                new AgentSection("windows_agent_iis_bindings", bindingRows));
        }

        private static string SummaryLine(string state, int sitesTotal, int sitesRunning, int sitesStopped, int appPoolsTotal, int appPoolsRunning, int appPoolsStopped, int bindingsTotal)
        {
            var detected = sitesTotal > 0 || appPoolsTotal > 0 || bindingsTotal > 0 ? 1 : 0;
            var healthIssues = sitesStopped + appPoolsStopped;
            return string.Join(" ",
                Kv("state", state),
                Kv("detected", detected),
                Kv("sites_total", sitesTotal),
                Kv("sites_running", sitesRunning),
                Kv("sites_stopped", sitesStopped),
                Kv("app_pools_total", appPoolsTotal),
                Kv("app_pools_running", appPoolsRunning),
                Kv("app_pools_stopped", appPoolsStopped),
                Kv("bindings_total", bindingsTotal),
                Kv("health_issues", healthIssues),
                RoleEvidenceFields(
                    string.Format("sites={0};app_pools={1};bindings={2}", sitesTotal, appPoolsTotal, bindingsTotal),
                    detected == 1 ? "scored" : "inventory",
                    NextAction(state, healthIssues)));
        }

        private static string NextAction(string state, int healthIssues)
        {
            if (IsDisabled(state))
            {
                return "Collector disabled by config.";
            }

            if (string.Equals(state, "not_detected", StringComparison.OrdinalIgnoreCase))
            {
                return "No action; IIS service evidence was not detected.";
            }

            if (string.Equals(state, "unsupported", StringComparison.OrdinalIgnoreCase))
            {
                return "Confirm IIS WebAdministration PowerShell module is available.";
            }

            return healthIssues > 0
                ? "Check stopped IIS sites or app pools, recent WAS/W3SVC events, and binding configuration."
                : "No action; IIS site and app-pool evidence is healthy.";
        }

        private static CommandRows RunPowerShell(string command, TimeSpan timeout, CancellationToken cancellationToken)
        {
            var result = CommandRunner.Run("powershell.exe", "-NoProfile -ExecutionPolicy Bypass -Command " + ShellQuote(command), timeout, cancellationToken);
            if (result.State != "ok")
            {
                return CommandRows.Unsupported();
            }

            return CommandRows.Parse(result.Output);
        }

        private static string SitesCommand()
        {
            return "Import-Module WebAdministration -ErrorAction Stop; Get-Website | ForEach-Object { $path=$_.PhysicalPath; [string]::Join(\"`t\", @($_.Name,$_.Id,$_.State,$_.Bindings.Collection.Count,$path)) }";
        }

        private static string AppPoolsCommand()
        {
            return "Import-Module WebAdministration -ErrorAction Stop; Get-ChildItem IIS:\\AppPools | ForEach-Object { [string]::Join(\"`t\", @($_.Name,$_.State,$_.managedRuntimeVersion,$_.managedPipelineMode,$_.processModel.identityType)) }";
        }

        private static string BindingsCommand(bool includeCertificates)
        {
            var cert = includeCertificates ? "$thumb=$_.certificateHash;" : "$thumb='';";
            return "Import-Module WebAdministration -ErrorAction Stop; Get-Website | ForEach-Object { $site=$_.Name; $_.Bindings.Collection | ForEach-Object { " + cert + " [string]::Join(\"`t\", @($site,$_.protocol,$_.bindingInformation,(($_.bindingInformation -split ':',3)[2]),(($_.bindingInformation -split ':',3)[1]),$thumb)) } }";
        }

        private static string ShellQuote(string value)
        {
            return "\"" + value.Replace("\\", "\\\\").Replace("\"", "\\\"") + "\"";
        }

        private static bool IsIisService(string name)
        {
            return string.Equals(name, "W3SVC", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(name, "WAS", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(name, "IISADMIN", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(name, "AppHostSvc", StringComparison.OrdinalIgnoreCase);
        }

        private static bool IsRunning(string state)
        {
            return string.Equals(state, "Running", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(state, "Started", StringComparison.OrdinalIgnoreCase);
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

        private sealed class CommandRows
        {
            public string State { get; private set; }
            public List<Row> Rows { get; private set; }

            public static CommandRows Unsupported()
            {
                return new CommandRows { State = "unsupported", Rows = new List<Row>() };
            }

            public static CommandRows Disabled()
            {
                return new CommandRows { State = "disabled", Rows = new List<Row>() };
            }

            public static CommandRows Parse(string output)
            {
                var rows = new List<Row>();
                foreach (var line in (output ?? string.Empty).Split(new[] { "\r\n", "\n" }, StringSplitOptions.RemoveEmptyEntries))
                {
                    rows.Add(new Row(line.Split('\t')));
                }

                return new CommandRows { State = "ok", Rows = rows };
            }
        }

        private sealed class Row
        {
            private readonly string[] _values;

            public Row(string[] values)
            {
                _values = values ?? Array.Empty<string>();
            }

            public string Value(int index)
            {
                return index >= 0 && index < _values.Length ? _values[index] : string.Empty;
            }
        }
    }
}
