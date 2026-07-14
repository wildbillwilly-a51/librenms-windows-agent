using System;

namespace LibreNMS.WindowsAgent.Core
{
    public sealed class DattoBackupHealthInput
    {
        public bool Disabled { get; set; }
        public string ExpectedMode { get; set; } = "auto";
        public bool DattoDetected { get; set; }
        public bool BackupServicePresent { get; set; }
        public bool BackupServiceRunning { get; set; }
        public bool ProviderPresent { get; set; }
        public string ProviderStartMode { get; set; } = string.Empty;
        public int ProcessCount { get; set; }
        public DateTimeOffset? LastSuccessUtc { get; set; }
        public int RecentErrors { get; set; }
        public int RecentCriticalFailures { get; set; }
        public int VssWritersFailed { get; set; }
        public DateTimeOffset NowUtc { get; set; } = DateTimeOffset.UtcNow;
        public int WarningHours { get; set; } = 24;
        public int CriticalHours { get; set; } = 48;
    }

    public sealed class DattoBackupHealthResult
    {
        public string State { get; set; } = "not_detected";
        public string EvidenceState { get; set; } = "unknown";
        public int LastSuccessAgeHours { get; set; } = -1;
        public int StaleWarning { get; set; }
        public int StaleCritical { get; set; }
        public int ProviderIssue { get; set; }
        public int HealthIssues { get; set; }
    }

    public static class DattoBackupHealth
    {
        public static DattoBackupHealthResult Evaluate(DattoBackupHealthInput input)
        {
            input = input ?? new DattoBackupHealthInput();
            var result = new DattoBackupHealthResult();

            if (input.Disabled)
            {
                result.State = "disabled";
                return result;
            }

            if (string.Equals(input.ExpectedMode, "none", StringComparison.OrdinalIgnoreCase))
            {
                result.State = "not_expected";
                return result;
            }

            if (string.Equals(input.ExpectedMode, "agentless_vcenter", StringComparison.OrdinalIgnoreCase))
            {
                result.State = "agentless_vcenter";
                return result;
            }

            if (!input.DattoDetected)
            {
                result.State = string.Equals(input.ExpectedMode, "local_agent", StringComparison.OrdinalIgnoreCase)
                    ? "missing"
                    : "not_detected";
                if (string.Equals(input.ExpectedMode, "local_agent", StringComparison.OrdinalIgnoreCase))
                {
                    result.HealthIssues = 1;
                }
                return result;
            }

            if (input.ProviderPresent && !IsAutoStart(input.ProviderStartMode))
            {
                result.ProviderIssue = 1;
                result.HealthIssues++;
            }

            if (input.BackupServicePresent && !input.BackupServiceRunning)
            {
                result.HealthIssues++;
            }

            if (input.VssWritersFailed > 0)
            {
                result.HealthIssues++;
            }

            if (input.RecentErrors > 0)
            {
                result.HealthIssues++;
            }

            if (input.RecentCriticalFailures > 0)
            {
                result.HealthIssues++;
            }

            if (input.LastSuccessUtc.HasValue)
            {
                var age = Math.Max(0, (int)Math.Floor((input.NowUtc.UtcDateTime - input.LastSuccessUtc.Value.UtcDateTime).TotalHours));
                result.LastSuccessAgeHours = age;
                result.EvidenceState = "ok";

                var warningHours = input.WarningHours <= 0 ? 24 : input.WarningHours;
                var criticalHours = input.CriticalHours <= 0 ? 48 : input.CriticalHours;
                if (age >= criticalHours)
                {
                    result.StaleCritical = 1;
                    result.EvidenceState = "critical";
                    result.HealthIssues++;
                }
                else if (age >= warningHours)
                {
                    result.StaleWarning = 1;
                    result.EvidenceState = "warning";
                    result.HealthIssues++;
                }
            }

            if (input.BackupServicePresent && !input.BackupServiceRunning ||
                input.RecentCriticalFailures > 0 ||
                result.StaleCritical > 0 ||
                input.VssWritersFailed > 0)
            {
                result.State = "critical";
            }
            else if (result.HealthIssues > 0)
            {
                result.State = "warning";
            }
            else
            {
                result.State = "ok";
            }

            return result;
        }

        private static bool IsAutoStart(string startMode)
        {
            return string.Equals(startMode, "Auto", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(startMode, "Automatic", StringComparison.OrdinalIgnoreCase);
        }
    }
}
