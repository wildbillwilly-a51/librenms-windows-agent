using System;
using System.Collections.Generic;
using System.Diagnostics.Eventing.Reader;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class ActiveDirectoryCollector : CollectorBase, ICollectorTimeoutOverride
    {
        public override string Name => "active_directory";

        public TimeSpan GetTimeout(AgentContext context, TimeSpan defaultTimeout)
        {
            var commandTimeout = Math.Max(1, context.Config.Collectors.ActiveDirectory?.CommandTimeoutSeconds ?? 20);
            return TimeSpan.FromSeconds(Math.Max(defaultTimeout.TotalSeconds, (commandTimeout * 3) + 10));
        }

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var config = context.Config.Collectors.ActiveDirectory ?? new ActiveDirectoryConfig();
            if (IsDisabled(config.Mode))
            {
                return Complete(DisabledSections());
            }

            var computer = ReadComputerSystem();
            var services = ServiceInventoryReader.Read(cancellationToken);
            var roles = RoleDetector.Detect(services, ServerFeatureReader.ReadInstalled(cancellationToken));
            var isDomainController = RoleDetector.IsDetected(roles, "domain_controller") || computer.DomainRole >= 4;

            if (!isDomainController)
            {
                return Complete(NotApplicable("not_applicable", "not_domain_controller", computer), DcHealthSummaryNotApplicable("not_applicable", "not_domain_controller"), SecurityEventsNotApplicable("not_applicable", "not_domain_controller"));
            }

            var timeout = TimeSpan.FromSeconds(Math.Max(1, config.CommandTimeoutSeconds));
            var replication = config.IncludeReplicationTargets
                ? ReadReplication(timeout, cancellationToken)
                : SectionResult.Disabled();
            var dfsr = config.IncludeDfsr
                ? ReadDfsr(cancellationToken)
                : SectionResult.Disabled();
            var fsmo = ReadFsmo(timeout, cancellationToken);
            var dcHealth = config.IncludeDcHealth
                ? ReadDcHealth(config, services, timeout, cancellationToken)
                : DcHealthResult.Disabled();
            var securityEvents = config.IncludeSecurityEvents
                ? ReadSecurityEvents(config, cancellationToken)
                : SectionResult.Disabled();

            var replicationFailures = replication.Rows.Sum(row => IntValue(row, "failure_count"));
            var dfsrUnhealthy = dfsr.Rows.Count(row => !IsDfsrHealthy(row));
            var securityTotal = securityEvents.Rows.Sum(row => IntValue(row, "count"));
            var lockouts = securityEvents.Rows.Where(row => Get(row, "category") == "account_lockout").Sum(row => IntValue(row, "count"));
            var authFailures = securityEvents.Rows.Where(row => Get(row, "category") == "auth_failure").Sum(row => IntValue(row, "count"));
            var privilegedChanges = securityEvents.Rows.Where(row => Get(row, "category") == "privileged_group_change").Sum(row => IntValue(row, "count"));
            var summary = new[]
            {
                string.Join(" ",
                    Kv("state", "ok"),
                    Kv("ad_detected", 1),
                    Kv("domain", computer.Domain),
                    Kv("domain_role", computer.DomainRole),
                    Kv("domain_role_name", DomainRoleName(computer.DomainRole)),
                    Kv("replication_state", replication.State),
                    Kv("replication_failures", replicationFailures),
                    Kv("dfsr_state", dfsr.State),
                    Kv("dfsr_unhealthy", dfsrUnhealthy),
                    Kv("fsmo_state", fsmo.State),
                    Kv("security_event_state", securityEvents.State),
                    Kv("security_events_total", securityTotal),
                    Kv("security_lockouts", lockouts),
                    Kv("security_auth_failures", authFailures),
                    Kv("security_privileged_changes", privilegedChanges),
                    RoleEvidenceFields(
                        string.Format("replication_failures={0};dfsr_unhealthy={1};security_events={2}", replicationFailures, dfsrUnhealthy, securityTotal),
                        "scored",
                        replicationFailures > 0 || dfsrUnhealthy > 0 ? "Review AD replication and DFSR details before troubleshooting dependent services." : "Review AD/DC Local Health for role-specific evidence."))
            };

            return Complete(
                new AgentSection("windows_agent_ad_summary", summary),
                new AgentSection("windows_agent_ad_replication", RenderRows(replication, "repadmin")),
                new AgentSection("windows_agent_ad_dfsr", RenderRows(dfsr, "dfsr")),
                new AgentSection("windows_agent_ad_fsmo", RenderRows(fsmo, "netdom")),
                new AgentSection("windows_agent_ad_dc_health_summary", new[] { dcHealth.SummaryLine }),
                new AgentSection("windows_agent_ad_dc_services", RenderRows(dcHealth.Services, "services")),
                new AgentSection("windows_agent_ad_dc_dns", RenderRows(dcHealth.Dns, "service_inventory")),
                new AgentSection("windows_agent_ad_dc_time", RenderRows(dcHealth.Time, "w32tm")),
                new AgentSection("windows_agent_ad_dc_shares", RenderRows(dcHealth.Shares, "wmi")),
                new AgentSection("windows_agent_ad_dc_security_events", RenderRows(securityEvents, "security_log")));
        }

        private static AgentSection[] DisabledSections()
        {
            return new[]
            {
                NotApplicable("disabled", "disabled"),
                DcHealthSummaryNotApplicable("disabled", "disabled"),
                SecurityEventsNotApplicable("disabled", "disabled"),
            };
        }

        private static AgentSection NotApplicable(string state, string reason, ComputerSystemInfo computer = null)
        {
            computer = computer ?? new ComputerSystemInfo();
            return new AgentSection(
                "windows_agent_ad_summary",
                new[]
                {
                    string.Join(" ",
                        Kv("state", state),
                        Kv("reason", reason),
                        Kv("ad_detected", 0),
                        Kv("domain", computer.Domain),
                        Kv("domain_role", computer.DomainRole),
                        Kv("domain_role_name", DomainRoleName(computer.DomainRole)),
                        Kv("replication_failures", 0),
                        Kv("dfsr_unhealthy", 0),
                        Kv("security_event_state", state),
                        Kv("security_events_total", 0),
                        RoleEvidenceFields("domain_role=" + computer.DomainRole, "inventory", "No action; Active Directory role evidence was not detected."))
                });
        }

        private static AgentSection SecurityEventsNotApplicable(string state, string reason)
        {
            return new AgentSection(
                "windows_agent_ad_dc_security_events",
                new[] { RenderRow(Row("state", state, "category", "security_events", "count", "0", "reason", reason, "source", "security_log")) });
        }

        private static AgentSection DcHealthSummaryNotApplicable(string state, string reason)
        {
            return new AgentSection(
                "windows_agent_ad_dc_health_summary",
                new[]
                {
                    string.Join(" ",
                        Kv("state", state),
                        Kv("reason", reason),
                        Kv("dc_detected", 0),
                        Kv("core_services_total", 0),
                        Kv("core_services_not_running", 0),
                        Kv("dns_service_present", 0),
                        Kv("dns_service_running", 0),
                        Kv("dns_service_issue", 0),
                        Kv("sysvol_share_present", 0),
                        Kv("netlogon_share_present", 0),
                        Kv("shares_missing", 0),
                        Kv("time_state", state),
                        Kv("time_service_running", 0),
                        Kv("time_issues", 0),
                        Kv("health_issues", 0),
                        RoleEvidenceFields("dc_detected=0", "inventory", "No action; this host is not a detected domain controller."))
                });
        }

        private static DcHealthResult ReadDcHealth(ActiveDirectoryConfig config, IReadOnlyList<ServiceInventoryRecord> services, TimeSpan timeout, CancellationToken cancellationToken)
        {
            var serviceRows = ReadDcServiceRows(services);
            var dns = config.IncludeDns ? ReadDnsRows(services) : SectionResult.Disabled();
            var time = config.IncludeTime ? ReadTimeRows(services, timeout, cancellationToken) : SectionResult.Disabled();
            var shares = config.IncludeSysvolNetlogon ? ReadShareRows(cancellationToken) : SectionResult.Disabled();

            var coreServices = serviceRows.Rows.Where(row => string.Equals(Get(row, "core"), "1", StringComparison.OrdinalIgnoreCase)).ToList();
            var coreServicesNotRunning = coreServices.Count(row => !IsRunning(Get(row, "state")));
            var dnsRow = dns.Rows.FirstOrDefault() ?? new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            var timeRow = time.Rows.FirstOrDefault() ?? new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            var shareRows = shares.Rows;
            var sysvol = shareRows.FirstOrDefault(row => string.Equals(Get(row, "name"), "SYSVOL", StringComparison.OrdinalIgnoreCase));
            var netlogon = shareRows.FirstOrDefault(row => string.Equals(Get(row, "name"), "NETLOGON", StringComparison.OrdinalIgnoreCase));

            var health = ActiveDirectoryDcHealth.Evaluate(new ActiveDirectoryDcHealthInput
            {
                IsDomainController = true,
                CoreServicesTotal = coreServices.Count,
                CoreServicesNotRunning = coreServicesNotRunning,
                IncludeDns = config.IncludeDns,
                DnsServicePresent = Get(dnsRow, "service_present") == "1",
                DnsServiceRunning = Get(dnsRow, "service_running") == "1",
                IncludeTime = config.IncludeTime,
                TimeServicePresent = Get(timeRow, "service_present") == "1",
                TimeServiceRunning = Get(timeRow, "service_running") == "1",
                TimeState = Get(timeRow, "state"),
                IncludeSysvolNetlogon = config.IncludeSysvolNetlogon,
                SysvolSharePresent = Get(sysvol, "present") == "1",
                NetlogonSharePresent = Get(netlogon, "present") == "1",
            });

            return new DcHealthResult
            {
                SummaryLine = string.Join(" ",
                    Kv("state", health.State),
                    Kv("dc_detected", 1),
                    Kv("core_services_total", coreServices.Count),
                    Kv("core_services_not_running", coreServicesNotRunning),
                    Kv("dns_service_present", Get(dnsRow, "service_present") == "1" ? 1 : 0),
                    Kv("dns_service_running", Get(dnsRow, "service_running") == "1" ? 1 : 0),
                    Kv("dns_service_issue", health.DnsServiceIssue),
                    Kv("sysvol_share_present", Get(sysvol, "present") == "1" ? 1 : 0),
                    Kv("netlogon_share_present", Get(netlogon, "present") == "1" ? 1 : 0),
                    Kv("shares_missing", health.SharesMissing),
                    Kv("time_state", Get(timeRow, "state")),
                    Kv("time_service_running", Get(timeRow, "service_running") == "1" ? 1 : 0),
                    Kv("time_issues", health.TimeIssues),
                    Kv("health_issues", health.HealthIssues),
                    RoleEvidenceFields(
                        string.Format("core_down={0};shares_missing={1};time_issues={2}", coreServicesNotRunning, health.SharesMissing, health.TimeIssues),
                        "scored",
                        health.HealthIssues > 0 ? "Check core AD services, DNS, SYSVOL/NETLOGON shares, and Windows Time evidence." : "No action; local domain-controller health evidence is healthy.")),
                Services = serviceRows,
                Dns = dns,
                Time = time,
                Shares = shares,
            };
        }

        private static SectionResult ReadSecurityEvents(ActiveDirectoryConfig config, CancellationToken cancellationToken)
        {
            var sinceMs = Math.Max(1, config.SecurityEventSinceHours) * 60L * 60L * 1000L;
            var eventIds = new[] { 4625, 4720, 4722, 4725, 4726, 4728, 4729, 4732, 4733, 4738, 4740, 4756, 4757, 4771, 4776 };
            var rows = eventIds
                .GroupBy(SecurityCategory)
                .ToDictionary(group => group.Key, group => Row(
                    "state", "ok",
                    "category", group.Key,
                    "count", "0",
                    "event_ids", string.Join(",", group),
                    "since_hours", Math.Max(1, config.SecurityEventSinceHours).ToString(),
                    "source", "security_log"), StringComparer.OrdinalIgnoreCase);

            try
            {
                var conditions = string.Join(" or ", eventIds.Select(id => "EventID=" + id));
                var query = new EventLogQuery("Security", PathType.LogName, "*[System[(" + conditions + ") and TimeCreated[timediff(@SystemTime) <= " + sinceMs + "]]]")
                {
                    ReverseDirection = true
                };

                using (var reader = new EventLogReader(query))
                {
                    var read = 0;
                    for (EventRecord record = reader.ReadEvent(); record != null && read < Math.Max(1, config.SecurityEventMaxEvents); record = reader.ReadEvent())
                    {
                        using (record)
                        {
                            cancellationToken.ThrowIfCancellationRequested();
                            var id = record.Id;
                            var category = SecurityCategory(id);
                            if (!rows.ContainsKey(category))
                            {
                                continue;
                            }

                            rows[category]["count"] = (IntValue(rows[category], "count") + 1).ToString();
                            read++;
                        }
                    }
                }

                return new SectionResult { State = "ok", Rows = rows.Values.OrderBy(row => Get(row, "category"), StringComparer.OrdinalIgnoreCase).ToList() };
            }
            catch (Exception ex) when (ex is EventLogException || ex is UnauthorizedAccessException || ex is InvalidOperationException)
            {
                return SectionResult.Single("unavailable", Row("state", "unavailable", "category", "security_events", "count", "0", "reason", ex.GetType().Name, "source", "security_log"));
            }
        }

        private static string SecurityCategory(int eventId)
        {
            switch (eventId)
            {
                case 4740:
                    return "account_lockout";
                case 4625:
                case 4771:
                case 4776:
                    return "auth_failure";
                case 4728:
                case 4729:
                case 4732:
                case 4733:
                case 4756:
                case 4757:
                    return "privileged_group_change";
                default:
                    return "account_change";
            }
        }

        private static SectionResult ReadDcServiceRows(IReadOnlyList<ServiceInventoryRecord> services)
        {
            var wanted = new[]
            {
                new DcServiceSpec("NTDS", "directory", true),
                new DcServiceSpec("Netlogon", "directory", true),
                new DcServiceSpec("Kdc", "kerberos", true),
                new DcServiceSpec("ADWS", "directory", true),
                new DcServiceSpec("DFSR", "sysvol_replication", true),
                new DcServiceSpec("DNS", "dns", false),
                new DcServiceSpec("W32Time", "time", false),
            };

            var rows = new List<Dictionary<string, string>>();
            foreach (var spec in wanted)
            {
                var service = services.FirstOrDefault(item => string.Equals(item.Name, spec.Name, StringComparison.OrdinalIgnoreCase));
                rows.Add(Row(
                    "name", spec.Name,
                    "display", service?.DisplayName ?? string.Empty,
                    "role", spec.Role,
                    "core", spec.Core ? "1" : "0",
                    "present", service == null ? "0" : "1",
                    "state", service?.State ?? "missing",
                    "start_mode", service?.StartMode ?? string.Empty));
            }

            return new SectionResult { State = "ok", Rows = rows };
        }

        private static SectionResult ReadDnsRows(IReadOnlyList<ServiceInventoryRecord> services)
        {
            var dns = services.FirstOrDefault(item => string.Equals(item.Name, "DNS", StringComparison.OrdinalIgnoreCase));
            if (dns == null)
            {
                return SectionResult.Single("not_detected", Row("state", "not_detected", "service_present", "0", "service_running", "0", "reason", "dns_service_absent"));
            }

            var running = IsRunning(dns.State);
            return SectionResult.Single(running ? "ok" : "critical", Row(
                "state", running ? "ok" : "critical",
                "service_present", "1",
                "service_running", running ? "1" : "0",
                "name", dns.Name,
                "display", dns.DisplayName,
                "start_mode", dns.StartMode));
        }

        private static SectionResult ReadTimeRows(IReadOnlyList<ServiceInventoryRecord> services, TimeSpan timeout, CancellationToken cancellationToken)
        {
            var service = services.FirstOrDefault(item => string.Equals(item.Name, "W32Time", StringComparison.OrdinalIgnoreCase));
            var servicePresent = service == null ? "0" : "1";
            var serviceRunning = service != null && IsRunning(service.State) ? "1" : "0";
            var result = CommandRunner.Run("w32tm.exe", "/query /status", timeout, cancellationToken);
            if (result.State != "ok")
            {
                var state = servicePresent == "1" && serviceRunning == "0" ? "warning" : result.State;
                return SectionResult.Single(state, Row(
                    "state", state,
                    "service_present", servicePresent,
                    "service_running", serviceRunning,
                    "tool", "w32tm",
                    "reason", result.Error));
            }

            var values = ParseColonLines(result.Output);
            var leap = FindValue(values, "leap indicator");
            var warning = leap.StartsWith("3(", StringComparison.OrdinalIgnoreCase) ||
                leap.IndexOf("not synchronized", StringComparison.OrdinalIgnoreCase) >= 0;
            return SectionResult.Single(warning ? "warning" : "ok", Row(
                "state", warning ? "warning" : "ok",
                "service_present", servicePresent,
                "service_running", serviceRunning,
                "source", FindValue(values, "source"),
                "stratum", FindValue(values, "stratum"),
                "leap_indicator", leap,
                "last_successful_sync_time", FindValue(values, "last successful sync time"),
                "poll_interval", FindValue(values, "poll interval")));
        }

        private static SectionResult ReadShareRows(CancellationToken cancellationToken)
        {
            var found = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            try
            {
                foreach (var item in Wmi.Query("SELECT Name,Path FROM Win32_Share"))
                {
                    cancellationToken.ThrowIfCancellationRequested();
                    using (item)
                    {
                        var name = Wmi.StringValue(item, "Name");
                        if (string.Equals(name, "SYSVOL", StringComparison.OrdinalIgnoreCase) ||
                            string.Equals(name, "NETLOGON", StringComparison.OrdinalIgnoreCase))
                        {
                            found[name] = Wmi.StringValue(item, "Path");
                        }
                    }
                }
            }
            catch
            {
                return SectionResult.Single("unsupported", Row("state", "unsupported", "tool", "wmi", "reason", "win32_share_unavailable"));
            }

            var rows = new List<Dictionary<string, string>>();
            foreach (var name in new[] { "SYSVOL", "NETLOGON" })
            {
                rows.Add(Row(
                    "state", found.ContainsKey(name) ? "ok" : "missing",
                    "name", name,
                    "present", found.ContainsKey(name) ? "1" : "0",
                    "path", found.TryGetValue(name, out var path) ? path : string.Empty));
            }

            return new SectionResult { State = "ok", Rows = rows };
        }

        private static ComputerSystemInfo ReadComputerSystem()
        {
            try
            {
                foreach (var item in Wmi.Query("SELECT Domain,DomainRole FROM Win32_ComputerSystem"))
                {
                    using (item)
                    {
                        return new ComputerSystemInfo
                        {
                            Domain = Wmi.StringValue(item, "Domain"),
                            DomainRole = (int)Wmi.UInt64Value(item, "DomainRole"),
                        };
                    }
                }
            }
            catch
            {
            }

            return new ComputerSystemInfo();
        }

        private static SectionResult ReadReplication(TimeSpan timeout, CancellationToken cancellationToken)
        {
            var result = CommandRunner.Run("repadmin.exe", "/showrepl /csv", timeout, cancellationToken);
            if (result.State != "ok")
            {
                return SectionResult.Single(result.State, Row("state", result.State, "tool", "repadmin", "reason", result.Error));
            }

            var records = ParseCsv(result.Output);
            if (records.Count == 0)
            {
                return SectionResult.Single("unavailable", Row("state", "unavailable", "tool", "repadmin", "reason", "no_rows"));
            }

            var rows = records.Select(record => Row(
                "state", "ok",
                "source", FindValue(record, "source dsa", "source"),
                "target", FindValue(record, "destination dsa", "destination", "target"),
                "naming_context", FindValue(record, "naming context", "naming_context"),
                "failure_count", IntText(FindValue(record, "number of failures", "failure count", "failures")),
                "last_success", FindValue(record, "last success time", "last success"),
                "last_failure", FindValue(record, "last failure time", "last failure"),
                "last_failure_status", FindValue(record, "last failure status", "last failure status code"))).ToList();

            return new SectionResult { State = "ok", Rows = rows };
        }

        private static SectionResult ReadDfsr(CancellationToken cancellationToken)
        {
            try
            {
                var rows = new List<Dictionary<string, string>>();
                foreach (var item in Wmi.Query(@"\\.\root\MicrosoftDFS", "SELECT ReplicationGroupName,ReplicatedFolderName,MemberName,State FROM DfsrReplicatedFolderInfo"))
                {
                    cancellationToken.ThrowIfCancellationRequested();
                    using (item)
                    {
                        rows.Add(Row(
                            "state", DfsrStateText(Wmi.StringValue(item, "State")),
                            "replication_group", Wmi.StringValue(item, "ReplicationGroupName"),
                            "replicated_folder", Wmi.StringValue(item, "ReplicatedFolderName"),
                            "member", Wmi.StringValue(item, "MemberName"),
                            "state_code", Wmi.StringValue(item, "State"),
                            "source", "wmi"));
                    }
                }

                if (rows.Count > 0)
                {
                    return new SectionResult { State = "ok", Rows = rows };
                }
            }
            catch
            {
            }

            return SectionResult.Single("unsupported", Row("state", "unsupported", "tool", "dfsr_wmi", "reason", "no_dfsr_wmi_rows"));
        }

        private static SectionResult ReadFsmo(TimeSpan timeout, CancellationToken cancellationToken)
        {
            var result = CommandRunner.Run("netdom.exe", "query fsmo", timeout, cancellationToken);
            if (result.State != "ok")
            {
                return SectionResult.Single(result.State, Row("state", result.State, "tool", "netdom", "reason", result.Error));
            }

            var rows = new List<Dictionary<string, string>>();
            foreach (var raw in SplitLines(result.Output))
            {
                var line = raw.Trim();
                if (line.Length == 0 || line.StartsWith("The command", StringComparison.OrdinalIgnoreCase))
                {
                    continue;
                }

                var parts = line.Split(new[] { ' ', '\t' }, StringSplitOptions.RemoveEmptyEntries);
                if (parts.Length < 2)
                {
                    continue;
                }

                var owner = parts[parts.Length - 1];
                var role = string.Join("_", parts.Take(parts.Length - 1)).ToLowerInvariant();
                rows.Add(Row("state", "ok", "role", role, "owner", owner));
            }

            return rows.Count > 0
                ? new SectionResult { State = "ok", Rows = rows }
                : SectionResult.Single("unavailable", Row("state", "unavailable", "tool", "netdom", "reason", "no_fsmo_rows"));
        }

        private static List<string> RenderRows(SectionResult result, string tool)
        {
            if (result.Rows.Count == 0)
            {
                return new List<string> { string.Join(" ", Kv("state", result.State), Kv("tool", tool)) };
            }

            return result.Rows.Select(RenderRow).ToList();
        }

        private static string RenderRow(Dictionary<string, string> row)
        {
            return string.Join(" ", row.Select(pair => Kv(pair.Key, pair.Value)));
        }

        private static Dictionary<string, string> Row(params string[] values)
        {
            var row = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            for (var index = 0; index + 1 < values.Length; index += 2)
            {
                row[values[index]] = values[index + 1] ?? string.Empty;
            }

            return row;
        }

        private static List<Dictionary<string, string>> ParseCsv(string csv)
        {
            var lines = SplitLines(csv).Where(line => !string.IsNullOrWhiteSpace(line)).ToList();
            if (lines.Count < 2)
            {
                return new List<Dictionary<string, string>>();
            }

            var headers = ParseCsvLine(lines[0]).Select(NormalizeKey).ToList();
            var records = new List<Dictionary<string, string>>();
            foreach (var line in lines.Skip(1))
            {
                var cells = ParseCsvLine(line);
                var record = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
                for (var index = 0; index < headers.Count && index < cells.Count; index++)
                {
                    record[headers[index]] = cells[index];
                }

                records.Add(record);
            }

            return records;
        }

        private static List<string> ParseCsvLine(string line)
        {
            var cells = new List<string>();
            var current = new System.Text.StringBuilder();
            var quoted = false;
            for (var index = 0; index < (line ?? string.Empty).Length; index++)
            {
                var ch = line[index];
                if (ch == '"' && quoted && index + 1 < line.Length && line[index + 1] == '"')
                {
                    current.Append('"');
                    index++;
                }
                else if (ch == '"')
                {
                    quoted = !quoted;
                }
                else if (ch == ',' && !quoted)
                {
                    cells.Add(current.ToString());
                    current.Clear();
                }
                else
                {
                    current.Append(ch);
                }
            }

            cells.Add(current.ToString());
            return cells;
        }

        private static string FindValue(Dictionary<string, string> record, params string[] keys)
        {
            foreach (var key in keys.Select(NormalizeKey))
            {
                if (record.TryGetValue(key, out var value))
                {
                    return value;
                }
            }

            return string.Empty;
        }

        private static string Get(Dictionary<string, string> row, string key)
        {
            return row != null && row.TryGetValue(key, out var value) ? value : string.Empty;
        }

        private static bool IsRunning(string state)
        {
            return string.Equals(state, "Running", StringComparison.OrdinalIgnoreCase);
        }

        private static Dictionary<string, string> ParseColonLines(string text)
        {
            var values = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            foreach (var raw in SplitLines(text))
            {
                var index = raw.IndexOf(':');
                if (index <= 0)
                {
                    continue;
                }

                values[NormalizeKey(raw.Substring(0, index))] = raw.Substring(index + 1).Trim();
            }

            return values;
        }

        private static IEnumerable<string> SplitLines(string text)
        {
            return (text ?? string.Empty).Split(new[] { "\r\n", "\n" }, StringSplitOptions.None);
        }

        private static string NormalizeKey(string value)
        {
            return (value ?? string.Empty).Trim().ToLowerInvariant().Replace("_", " ");
        }

        private static int IntValue(Dictionary<string, string> row, string key)
        {
            return int.TryParse(row.TryGetValue(key, out var value) ? value : string.Empty, out var parsed) ? parsed : 0;
        }

        private static string IntText(string value)
        {
            return int.TryParse(value, out var parsed) ? parsed.ToString() : "0";
        }

        private static bool IsDfsrHealthy(Dictionary<string, string> row)
        {
            var state = row.TryGetValue("state", out var value) ? value : string.Empty;
            return string.Equals(state, "normal", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(state, "ok", StringComparison.OrdinalIgnoreCase);
        }

        private static string DfsrStateText(string state)
        {
            switch (state)
            {
                case "4":
                    return "normal";
                case "3":
                    return "auto_recovery";
                case "2":
                    return "initial_sync";
                case "1":
                    return "initialized";
                case "0":
                    return "uninitialized";
                default:
                    return string.IsNullOrWhiteSpace(state) ? "unknown" : state;
            }
        }

        private static string DomainRoleName(int role)
        {
            switch (role)
            {
                case 0:
                    return "standalone_workstation";
                case 1:
                    return "member_workstation";
                case 2:
                    return "standalone_server";
                case 3:
                    return "member_server";
                case 4:
                    return "backup_domain_controller";
                case 5:
                    return "primary_domain_controller";
                default:
                    return "unknown";
            }
        }

        private static bool IsDisabled(string mode)
        {
            return string.Equals(mode, "disabled", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(mode, "off", StringComparison.OrdinalIgnoreCase);
        }

        private sealed class ComputerSystemInfo
        {
            public string Domain { get; set; } = string.Empty;
            public int DomainRole { get; set; } = -1;
        }

        private sealed class SectionResult
        {
            public string State { get; set; } = string.Empty;
            public List<Dictionary<string, string>> Rows { get; set; } = new List<Dictionary<string, string>>();

            public static SectionResult Single(string state, Dictionary<string, string> row)
            {
                return new SectionResult { State = state, Rows = new List<Dictionary<string, string>> { row } };
            }

            public static SectionResult Disabled()
            {
                return Single("disabled", Row("state", "disabled"));
            }
        }

        private sealed class DcServiceSpec
        {
            public DcServiceSpec(string name, string role, bool core)
            {
                Name = name;
                Role = role;
                Core = core;
            }

            public string Name { get; }
            public string Role { get; }
            public bool Core { get; }
        }

        private sealed class DcHealthResult
        {
            public string SummaryLine { get; set; } = string.Empty;
            public SectionResult Services { get; set; } = new SectionResult();
            public SectionResult Dns { get; set; } = new SectionResult();
            public SectionResult Time { get; set; } = new SectionResult();
            public SectionResult Shares { get; set; } = new SectionResult();

            public static DcHealthResult Disabled()
            {
                return new DcHealthResult
                {
                    SummaryLine = DcHealthSummaryNotApplicable("disabled", "disabled").Lines[0],
                    Services = SectionResult.Disabled(),
                    Dns = SectionResult.Disabled(),
                    Time = SectionResult.Disabled(),
                    Shares = SectionResult.Disabled(),
                };
            }
        }
    }
}
