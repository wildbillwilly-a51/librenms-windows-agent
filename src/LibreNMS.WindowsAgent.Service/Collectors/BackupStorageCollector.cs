using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Diagnostics;
using System.Diagnostics.Eventing.Reader;
using System.IO;
using System.Linq;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class BackupStorageCollector : CollectorBase, ICollectorTimeoutOverride
    {
        private static readonly string[] BackupServicePrefixes = { "Datto", "Veeam", "VSSProvider", "wbengine", "SDRSVC" };
        private static readonly Regex IsoTimestamp = new Regex(@"(?<ts>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)", RegexOptions.Compiled);
        private static readonly Regex UsTimestamp = new Regex(@"(?<ts>\d{1,2}/\d{1,2}/\d{4}\s+\d{1,2}:\d{2}(?::\d{2})?\s*(?:AM|PM)?)", RegexOptions.Compiled | RegexOptions.IgnoreCase);

        public override string Name => "backup_storage";

        public TimeSpan GetTimeout(AgentContext context, TimeSpan defaultTimeout)
        {
            var seconds = context.Config.Collectors.BackupStorage?.CommandTimeoutSeconds ?? 15;
            return TimeSpan.FromSeconds(Math.Max(1, seconds));
        }

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var config = context.Config.Collectors.BackupStorage ?? new BackupStorageConfig();
            if (IsDisabled(config.Mode))
            {
                return Complete(
                    new AgentSection("windows_agent_backup_storage_summary", new[] { SummaryLine("disabled", 0, 0, 0, 0, 0, 0, 0, string.Empty) }),
                    new AgentSection("windows_agent_vss_writers", Array.Empty<string>()),
                    new AgentSection("windows_agent_backup_services", Array.Empty<string>()),
                    new AgentSection("windows_agent_datto_backup_summary", new[] { DattoSummaryLine("disabled", "auto", new DattoBackupHealthResult(), false, false, false, 0, 0, 0) }),
                    new AgentSection("windows_agent_datto_backup_services", Array.Empty<string>()),
                    new AgentSection("windows_agent_datto_backup_processes", Array.Empty<string>()),
                    new AgentSection("windows_agent_datto_backup_evidence", Array.Empty<string>()));
            }

            var needsServices = config.IncludeBackupServices || config.IncludeDattoBackup;
            var serviceInventory = needsServices
                ? ServiceInventoryReader.Read(cancellationToken)
                : new List<ServiceInventoryRecord>();

            var services = config.IncludeBackupServices
                ? serviceInventory.Where(IsBackupService).OrderBy(service => service.Name, StringComparer.OrdinalIgnoreCase).ToList()
                : new List<ServiceInventoryRecord>();
            var serviceLines = services.Select(service => string.Join(" ",
                Kv("name", service.Name),
                Kv("display", service.DisplayName),
                Kv("state", service.State),
                Kv("start_mode", service.StartMode),
                Kv("source", BackupSource(service.Name, service.DisplayName, service.PathName)))).ToList();

            var writerRows = config.IncludeVssWriters
                ? ReadVssWriters(TimeSpan.FromSeconds(Math.Max(1, config.CommandTimeoutSeconds)), cancellationToken)
                : CommandRows.Disabled();
            var writerLines = writerRows.Rows.Select(row => string.Join(" ",
                Kv("name", row.Name),
                Kv("state", row.State),
                Kv("last_error", row.LastError),
                Kv("failure_type", row.FailureType))).ToList();

            var datto = config.IncludeDattoBackup
                ? CollectDatto(context, config, serviceInventory, writerRows.Rows, cancellationToken)
                : DattoResult.Disabled();

            var detected = services.Count > 0 || writerRows.Rows.Count > 0 || datto.Detected;
            var state = !detected && IsAuto(config.Mode)
                ? "not_detected"
                : writerRows.State == "unsupported" ? "unsupported" : "ok";

            return Complete(
                new AgentSection("windows_agent_backup_storage_summary", new[] { SummaryLine(
                    state,
                    writerRows.Rows.Count,
                    writerRows.Rows.Count(row => string.Equals(row.State, "Stable", StringComparison.OrdinalIgnoreCase)),
                    writerRows.Rows.Count(row => !string.Equals(row.State, "Stable", StringComparison.OrdinalIgnoreCase)),
                    writerRows.Rows.Count(row => string.Equals(row.FailureType, "retryable", StringComparison.OrdinalIgnoreCase)),
                    writerRows.Rows.Count(row => string.Equals(row.FailureType, "non_retryable", StringComparison.OrdinalIgnoreCase)),
                    services.Count,
                    services.Count(service => !IsRunning(service.State)),
                    string.Join(",", writerRows.Rows.Where(row => !string.Equals(row.State, "Stable", StringComparison.OrdinalIgnoreCase)).Select(row => row.Name))) }),
                new AgentSection("windows_agent_vss_writers", writerLines),
                new AgentSection("windows_agent_backup_services", serviceLines),
                new AgentSection("windows_agent_datto_backup_summary", new[] { datto.SummaryLine }),
                new AgentSection("windows_agent_datto_backup_services", datto.ServiceLines),
                new AgentSection("windows_agent_datto_backup_processes", datto.ProcessLines),
                new AgentSection("windows_agent_datto_backup_evidence", datto.EvidenceLines));
        }

        private static string SummaryLine(string state, int writersTotal, int writersStable, int writersFailed, int writersRetryableFailed, int writersNonRetryableFailed, int servicesTotal, int servicesNotRunning, string failedNames)
        {
            var detected = writersTotal > 0 || servicesTotal > 0 ? 1 : 0;
            var healthIssues = writersFailed;
            return string.Join(" ",
                Kv("state", state),
                Kv("detected", detected),
                Kv("vss_writers_total", writersTotal),
                Kv("vss_writers_stable", writersStable),
                Kv("vss_writers_failed", writersFailed),
                Kv("vss_writers_retryable_failed", writersRetryableFailed),
                Kv("vss_writers_non_retryable_failed", writersNonRetryableFailed),
                Kv("vss_writers_failed_names", failedNames),
                Kv("backup_services_total", servicesTotal),
                Kv("backup_services_not_running", servicesNotRunning),
                Kv("health_issues", healthIssues),
                RoleEvidenceFields(
                    string.Format("vss_writers={0};backup_services={1};failed_writers={2}", writersTotal, servicesTotal, writersFailed),
                    "scored",
                    NextBackupAction(state, writersFailed, failedNames)));
        }

        private static string NextBackupAction(string state, int writersFailed, string failedNames)
        {
            if (IsDisabled(state))
            {
                return "Collector disabled by config.";
            }

            return writersFailed > 0
                ? "Check failed VSS writers and restart or repair the owning application services: " + failedNames
                : "No action; VSS writer evidence is healthy.";
        }

        private static DattoResult CollectDatto(AgentContext context, BackupStorageConfig config, IReadOnlyList<ServiceInventoryRecord> services, IReadOnlyList<VssWriterRow> writers, CancellationToken cancellationToken)
        {
            var expectedMode = NormalizeExpectedMode(config.ExpectedBackupMode);
            var dattoServices = services.Where(IsDattoService).OrderBy(service => service.Name, StringComparer.OrdinalIgnoreCase).ToList();
            var backupService = dattoServices.FirstOrDefault(IsDattoBackupAgentService);
            var provider = dattoServices.FirstOrDefault(IsDattoProviderService);
            var processCount = CountProcesses("DattoBackupAgent", cancellationToken);
            var evidence = CollectDattoEvidence(context, config, cancellationToken);
            var detected = dattoServices.Count > 0 || processCount > 0 || EvidenceSuggestsDattoInstall(evidence);

            var health = DattoBackupHealth.Evaluate(new DattoBackupHealthInput
            {
                ExpectedMode = expectedMode,
                DattoDetected = detected,
                BackupServicePresent = backupService != null,
                BackupServiceRunning = backupService != null && IsRunning(backupService.State),
                ProviderPresent = provider != null,
                ProviderStartMode = provider?.StartMode ?? string.Empty,
                ProcessCount = processCount,
                LastSuccessUtc = evidence.LastSuccessUtc,
                RecentErrors = evidence.RecentErrors,
                RecentCriticalFailures = evidence.RecentCriticalFailures,
                VssWritersFailed = writers.Count(row => !string.Equals(row.State, "Stable", StringComparison.OrdinalIgnoreCase)),
                NowUtc = context.NowUtc,
                WarningHours = config.DattoBackupWarningHours,
                CriticalHours = config.DattoBackupCriticalHours,
            });

            var serviceLines = dattoServices.Select(service =>
            {
                var executable = ExtractExecutablePath(service.PathName);
                return string.Join(" ",
                    Kv("name", service.Name),
                    Kv("display", service.DisplayName),
                    Kv("role", IsDattoProviderService(service) ? "provider" : IsDattoBackupAgentService(service) ? "backup_agent" : "datto"),
                    Kv("state", service.State),
                    Kv("start_mode", service.StartMode),
                    Kv("path_exists", File.Exists(executable) ? 1 : 0),
                    Kv("version", FileVersion(executable)),
                    Kv("path", executable));
            }).ToList();

            var processLines = new List<string>();
            if (detected || processCount > 0)
            {
                processLines.Add(string.Join(" ",
                    Kv("name", "DattoBackupAgent"),
                    Kv("matched_count", processCount)));
            }

            var evidenceLines = new List<string>();
            if (detected || evidence.ScannedSources > 0)
            {
                evidenceLines.Add(string.Join(" ",
                    Kv("source", evidence.LastSuccessSource),
                    Kv("type", "last_success"),
                    Kv("state", health.EvidenceState),
                    Kv("timestamp_utc", evidence.LastSuccessUtc.HasValue ? evidence.LastSuccessUtc.Value.UtcDateTime.ToString("o") : string.Empty),
                    Kv("age_hours", health.LastSuccessAgeHours)));
                evidenceLines.Add(string.Join(" ",
                    Kv("source", "local_logs_events"),
                    Kv("type", "recent_errors"),
                    Kv("scanned_sources", evidence.ScannedSources),
                    Kv("recent_errors", evidence.RecentErrors),
                    Kv("recent_critical_failures", evidence.RecentCriticalFailures)));
            }

            return new DattoResult
            {
                Detected = detected,
                SummaryLine = DattoSummaryLine(
                    health.State,
                    expectedMode,
                    health,
                    detected,
                    backupService != null && IsRunning(backupService.State),
                    provider != null,
                    processCount,
                    evidence.RecentErrors,
                    evidence.RecentCriticalFailures),
                ServiceLines = serviceLines,
                ProcessLines = processLines,
                EvidenceLines = evidenceLines,
            };
        }

        private static string DattoSummaryLine(string state, DattoBackupHealthResult health, bool detected, bool serviceRunning, bool providerPresent, int processCount, int recentErrors, int recentCriticalFailures)
        {
            return DattoSummaryLine(state, "auto", health, detected, serviceRunning, providerPresent, processCount, recentErrors, recentCriticalFailures);
        }

        private static string DattoSummaryLine(string state, string expectedMode, DattoBackupHealthResult health, bool detected, bool serviceRunning, bool providerPresent, int processCount, int recentErrors, int recentCriticalFailures)
        {
            health = health ?? new DattoBackupHealthResult();
            return string.Join(" ",
                Kv("state", state),
                Kv("expected_mode", expectedMode),
                Kv("detected", detected ? 1 : 0),
                Kv("service_running", serviceRunning ? 1 : 0),
                Kv("provider_present", providerPresent ? 1 : 0),
                Kv("provider_issue", health.ProviderIssue),
                Kv("processes_total", processCount),
                Kv("evidence_state", health.EvidenceState),
                Kv("recent_errors", recentErrors),
                Kv("recent_critical_failures", recentCriticalFailures),
                Kv("last_success_age_hours", health.LastSuccessAgeHours),
                Kv("stale_warning", health.StaleWarning),
                Kv("stale_critical", health.StaleCritical),
                Kv("health_issues", health.HealthIssues),
                RoleEvidenceFields(
                    string.Format("expected={0};detected={1};processes={2};recent_errors={3}", expectedMode, detected ? 1 : 0, processCount, recentErrors),
                    expectedMode == "none" || expectedMode == "agentless_vcenter" ? "inventory" : detected ? "scored" : "inventory",
                    NextDattoAction(expectedMode, detected, health)));
        }

        private static string NextDattoAction(string expectedMode, bool detected, DattoBackupHealthResult health)
        {
            if (expectedMode == "none")
            {
                return "No local Datto backup expectation is configured.";
            }

            if (expectedMode == "agentless_vcenter")
            {
                return "Guest-local evidence is neutral; verify backup success from vCenter or the backup platform.";
            }

            if (!detected)
            {
                return expectedMode == "local_agent"
                    ? "Install or repair the expected local Datto Windows Agent."
                    : "No action; local Datto agent evidence was not detected in auto mode.";
            }

            return health.HealthIssues > 0
                ? "Review Datto service, provider, process, VSS writer, and recent log/event evidence."
                : "No action; local Datto evidence is healthy.";
        }

        private static DattoEvidence CollectDattoEvidence(AgentContext context, BackupStorageConfig config, CancellationToken cancellationToken)
        {
            var evidence = new DattoEvidence();
            var cutoff = context.NowUtc.AddHours(-Math.Max(1, config.DattoBackupEvidenceSinceHours));
            foreach (var path in config.DattoBackupLogPaths ?? new List<string>())
            {
                cancellationToken.ThrowIfCancellationRequested();
                ScanDattoLogPath(path, cutoff, Math.Max(1024, config.DattoBackupMaxLogBytes), evidence);
            }

            ScanDattoEventLogs(cutoff, evidence, cancellationToken);
            return evidence;
        }

        private static void ScanDattoLogPath(string path, DateTimeOffset cutoff, int maxBytes, DattoEvidence evidence)
        {
            if (string.IsNullOrWhiteSpace(path) || !Directory.Exists(path))
            {
                return;
            }

            IEnumerable<string> files;
            try
            {
                files = Directory.EnumerateFiles(path, "*.log", SearchOption.AllDirectories)
                    .OrderByDescending(File.GetLastWriteTimeUtc)
                    .Take(8)
                    .ToList();
            }
            catch (Exception ex) when (ex is IOException || ex is UnauthorizedAccessException || ex is DirectoryNotFoundException)
            {
                return;
            }

            foreach (var file in files)
            {
                try
                {
                    if (new DateTimeOffset(File.GetLastWriteTimeUtc(file), TimeSpan.Zero) < cutoff)
                    {
                        continue;
                    }

                    evidence.ScannedSources++;
                    ScanText(ReadTail(file, maxBytes), "log:" + Path.GetFileName(file), cutoff, evidence);
                }
                catch (Exception ex) when (ex is IOException || ex is UnauthorizedAccessException || ex is NotSupportedException)
                {
                }
            }
        }

        private static void ScanDattoEventLogs(DateTimeOffset cutoff, DattoEvidence evidence, CancellationToken cancellationToken)
        {
            foreach (var logName in new[] { "Application", "System" })
            {
                cancellationToken.ThrowIfCancellationRequested();
                try
                {
                    var cutoffMs = Math.Max(1, (long)(DateTimeOffset.UtcNow - cutoff).TotalMilliseconds);
                    var query = new EventLogQuery(logName, PathType.LogName, "*[System[TimeCreated[timediff(@SystemTime) <= " + cutoffMs + "]]]")
                    {
                        ReverseDirection = true
                    };

                    using (var reader = new EventLogReader(query))
                    {
                        for (var i = 0; i < 500; i++)
                        {
                            cancellationToken.ThrowIfCancellationRequested();
                            using (var record = reader.ReadEvent())
                            {
                                if (record == null)
                                {
                                    break;
                                }

                                if (record.ProviderName == null || record.ProviderName.IndexOf("Datto", StringComparison.OrdinalIgnoreCase) < 0)
                                {
                                    continue;
                                }

                                evidence.ScannedSources++;
                                var level = record.Level ?? 0;
                                if (level == 1)
                                {
                                    evidence.RecentCriticalFailures++;
                                }
                                else if (level == 2)
                                {
                                    evidence.RecentErrors++;
                                }

                                ScanText(record.FormatDescription() ?? string.Empty, "event:" + logName + ":" + record.ProviderName, cutoff, evidence);
                            }
                        }
                    }
                }
                catch (Exception ex) when (ex is EventLogException || ex is UnauthorizedAccessException || ex is InvalidOperationException)
                {
                }
            }
        }

        private static void ScanText(string text, string source, DateTimeOffset cutoff, DattoEvidence evidence)
        {
            foreach (var rawLine in (text ?? string.Empty).Split(new[] { "\r\n", "\n" }, StringSplitOptions.None))
            {
                var line = rawLine.Trim();
                if (line.Length == 0)
                {
                    continue;
                }

                if (IsFailureLine(line))
                {
                    evidence.RecentErrors++;
                    if (IsCriticalFailureLine(line))
                    {
                        evidence.RecentCriticalFailures++;
                    }
                }

                if (!IsSuccessLine(line))
                {
                    continue;
                }

                var timestamp = ExtractTimestamp(line);
                if (!timestamp.HasValue || timestamp.Value < cutoff)
                {
                    continue;
                }

                if (!evidence.LastSuccessUtc.HasValue || timestamp.Value > evidence.LastSuccessUtc.Value)
                {
                    evidence.LastSuccessUtc = timestamp.Value;
                    evidence.LastSuccessSource = source;
                }
            }
        }

        private static string ReadTail(string path, int maxBytes)
        {
            using (var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite))
            {
                var length = stream.Length;
                var bytesToRead = (int)Math.Min(Math.Max(1024, maxBytes), length);
                stream.Seek(-bytesToRead, SeekOrigin.End);
                var buffer = new byte[bytesToRead];
                var read = stream.Read(buffer, 0, buffer.Length);
                return Encoding.UTF8.GetString(buffer, 0, read);
            }
        }

        private static DateTimeOffset? ExtractTimestamp(string line)
        {
            foreach (var regex in new[] { IsoTimestamp, UsTimestamp })
            {
                var match = regex.Match(line ?? string.Empty);
                if (!match.Success)
                {
                    continue;
                }

                if (DateTimeOffset.TryParse(match.Groups["ts"].Value, out var parsed))
                {
                    return parsed.ToUniversalTime();
                }
            }

            return null;
        }

        private static bool IsSuccessLine(string line)
        {
            var text = line ?? string.Empty;
            return text.IndexOf("backup", StringComparison.OrdinalIgnoreCase) >= 0 &&
                (text.IndexOf("success", StringComparison.OrdinalIgnoreCase) >= 0 ||
                 text.IndexOf("completed", StringComparison.OrdinalIgnoreCase) >= 0);
        }

        private static bool IsFailureLine(string line)
        {
            var text = line ?? string.Empty;
            return text.IndexOf("error", StringComparison.OrdinalIgnoreCase) >= 0 ||
                 text.IndexOf("fail", StringComparison.OrdinalIgnoreCase) >= 0 ||
                 text.IndexOf("exception", StringComparison.OrdinalIgnoreCase) >= 0;
        }

        private static bool IsCriticalFailureLine(string line)
        {
            var text = line ?? string.Empty;
            return text.IndexOf("critical backup failure", StringComparison.OrdinalIgnoreCase) >= 0 ||
                text.IndexOf("backup failed", StringComparison.OrdinalIgnoreCase) >= 0 ||
                text.IndexOf("failed backup", StringComparison.OrdinalIgnoreCase) >= 0;
        }

        private static bool EvidenceSuggestsDattoInstall(DattoEvidence evidence)
        {
            return evidence != null && evidence.ScannedSources > 0 && (!string.IsNullOrWhiteSpace(evidence.LastSuccessSource) || evidence.RecentErrors > 0 || evidence.RecentCriticalFailures > 0);
        }

        private static int CountProcesses(string processName, CancellationToken cancellationToken)
        {
            cancellationToken.ThrowIfCancellationRequested();
            try
            {
                var processes = Process.GetProcessesByName(processName);
                try
                {
                    return processes.Length;
                }
                finally
                {
                    foreach (var process in processes)
                    {
                        process.Dispose();
                    }
                }
            }
            catch (Exception ex) when (ex is Win32Exception || ex is InvalidOperationException || ex is NotSupportedException)
            {
                return 0;
            }
        }

        private static CommandRows ReadVssWriters(TimeSpan timeout, CancellationToken cancellationToken)
        {
            var result = CommandRunner.Run("vssadmin.exe", "list writers", timeout, cancellationToken);
            if (result.State != "ok")
            {
                return CommandRows.Unsupported();
            }

            var rows = new List<VssWriterRow>();
            VssWriterRow current = null;
            foreach (var rawLine in (result.Output ?? string.Empty).Split(new[] { "\r\n", "\n" }, StringSplitOptions.None))
            {
                var line = rawLine.Trim();
                if (line.StartsWith("Writer name:", StringComparison.OrdinalIgnoreCase))
                {
                    current = new VssWriterRow { Name = TrimQuoted(ValueAfterColon(line)) };
                    rows.Add(current);
                }
                else if (current != null && line.StartsWith("State:", StringComparison.OrdinalIgnoreCase))
                {
                    current.State = ParseWriterState(ValueAfterColon(line));
                }
                else if (current != null && line.StartsWith("Last error:", StringComparison.OrdinalIgnoreCase))
                {
                    current.LastError = ValueAfterColon(line);
                    current.FailureType = FailureType(current.LastError);
                }
            }

            foreach (var row in rows)
            {
                if (string.IsNullOrWhiteSpace(row.FailureType))
                {
                    row.FailureType = FailureType(row.LastError);
                }
            }

            return CommandRows.Ok(rows);
        }

        private static string ValueAfterColon(string line)
        {
            var index = line.IndexOf(':');
            return index >= 0 && index < line.Length - 1 ? line.Substring(index + 1).Trim() : string.Empty;
        }

        private static string TrimQuoted(string value)
        {
            return (value ?? string.Empty).Trim().Trim('\'', '"');
        }

        private static string ParseWriterState(string value)
        {
            var start = value.IndexOf(']');
            return start >= 0 && start < value.Length - 1 ? value.Substring(start + 1).Trim() : value;
        }

        private static string FailureType(string lastError)
        {
            var text = lastError ?? string.Empty;
            if (text.IndexOf("non-retryable", StringComparison.OrdinalIgnoreCase) >= 0 ||
                text.IndexOf("non retryable", StringComparison.OrdinalIgnoreCase) >= 0)
            {
                return "non_retryable";
            }

            if (text.IndexOf("retryable", StringComparison.OrdinalIgnoreCase) >= 0)
            {
                return "retryable";
            }

            return string.Empty;
        }

        private static bool IsBackupService(ServiceInventoryRecord service)
        {
            var name = service.Name ?? string.Empty;
            return BackupServicePrefixes.Any(prefix =>
                string.Equals(name, prefix, StringComparison.OrdinalIgnoreCase) ||
                name.StartsWith(prefix, StringComparison.OrdinalIgnoreCase)) ||
                IsDattoService(service);
        }

        private static bool IsDattoService(ServiceInventoryRecord service)
        {
            var haystack = string.Join(" ", service.Name ?? string.Empty, service.DisplayName ?? string.Empty, service.PathName ?? string.Empty);
            return haystack.IndexOf("Datto", StringComparison.OrdinalIgnoreCase) >= 0;
        }

        private static bool IsDattoBackupAgentService(ServiceInventoryRecord service)
        {
            var haystack = string.Join(" ", service.Name ?? string.Empty, service.DisplayName ?? string.Empty, service.PathName ?? string.Empty);
            return haystack.IndexOf("Datto", StringComparison.OrdinalIgnoreCase) >= 0 &&
                haystack.IndexOf("Backup", StringComparison.OrdinalIgnoreCase) >= 0 &&
                haystack.IndexOf("Agent", StringComparison.OrdinalIgnoreCase) >= 0;
        }

        private static bool IsDattoProviderService(ServiceInventoryRecord service)
        {
            return string.Equals(service.Name, "DattoProvider", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(service.DisplayName, "Datto Provider", StringComparison.OrdinalIgnoreCase);
        }

        private static string BackupSource(string serviceName, string displayName, string pathName)
        {
            var haystack = string.Join(" ", serviceName ?? string.Empty, displayName ?? string.Empty, pathName ?? string.Empty);
            var name = serviceName ?? string.Empty;
            return haystack.IndexOf("Datto", StringComparison.OrdinalIgnoreCase) >= 0 ? "datto_backup_service" :
                name.StartsWith("Veeam", StringComparison.OrdinalIgnoreCase) ? "veeam_service" :
                string.Equals(name, "wbengine", StringComparison.OrdinalIgnoreCase) ? "windows_backup_service" :
                string.Equals(name, "SDRSVC", StringComparison.OrdinalIgnoreCase) ? "windows_backup_service" :
                "backup_storage_service";
        }

        private static string ExtractExecutablePath(string pathName)
        {
            return ServiceCommandLine.RedactPath(pathName);
        }

        private static string NormalizeExpectedMode(string mode)
        {
            var value = (mode ?? string.Empty).Trim();
            if (string.Equals(value, "local_agent", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(value, "agentless_vcenter", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(value, "none", StringComparison.OrdinalIgnoreCase))
            {
                return value.ToLowerInvariant();
            }

            return "auto";
        }

        private static string FileVersion(string path)
        {
            try
            {
                return File.Exists(path) ? FileVersionInfo.GetVersionInfo(path).FileVersion ?? string.Empty : string.Empty;
            }
            catch (Exception ex) when (ex is IOException || ex is UnauthorizedAccessException || ex is ArgumentException || ex is NotSupportedException)
            {
                return string.Empty;
            }
        }

        private static bool IsRunning(string state)
        {
            return string.Equals(state, "Running", StringComparison.OrdinalIgnoreCase);
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
            public List<VssWriterRow> Rows { get; private set; }

            public static CommandRows Ok(List<VssWriterRow> rows)
            {
                return new CommandRows { State = "ok", Rows = rows ?? new List<VssWriterRow>() };
            }

            public static CommandRows Unsupported()
            {
                return new CommandRows { State = "unsupported", Rows = new List<VssWriterRow>() };
            }

            public static CommandRows Disabled()
            {
                return new CommandRows { State = "disabled", Rows = new List<VssWriterRow>() };
            }
        }

        private sealed class VssWriterRow
        {
            public string Name { get; set; } = string.Empty;
            public string State { get; set; } = string.Empty;
            public string LastError { get; set; } = string.Empty;
            public string FailureType { get; set; } = string.Empty;
        }

        private sealed class DattoEvidence
        {
            public DateTimeOffset? LastSuccessUtc { get; set; }
            public string LastSuccessSource { get; set; } = string.Empty;
            public int RecentErrors { get; set; }
            public int RecentCriticalFailures { get; set; }
            public int ScannedSources { get; set; }
        }

        private sealed class DattoResult
        {
            public bool Detected { get; set; }
            public string SummaryLine { get; set; } = string.Empty;
            public List<string> ServiceLines { get; set; } = new List<string>();
            public List<string> ProcessLines { get; set; } = new List<string>();
            public List<string> EvidenceLines { get; set; } = new List<string>();

            public static DattoResult Disabled()
            {
                return new DattoResult
                {
                    SummaryLine = DattoSummaryLine("disabled", "auto", new DattoBackupHealthResult { State = "disabled" }, false, false, false, 0, 0, 0)
                };
            }
        }
    }
}
