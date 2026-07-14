using System;

namespace LibreNMS.WindowsAgent.Core
{
    public sealed class ActiveDirectoryDcHealthInput
    {
        public bool IsDomainController { get; set; }
        public int CoreServicesTotal { get; set; }
        public int CoreServicesNotRunning { get; set; }
        public bool IncludeDns { get; set; } = true;
        public bool DnsServicePresent { get; set; }
        public bool DnsServiceRunning { get; set; }
        public bool IncludeTime { get; set; } = true;
        public bool TimeServicePresent { get; set; }
        public bool TimeServiceRunning { get; set; }
        public string TimeState { get; set; } = string.Empty;
        public bool IncludeSysvolNetlogon { get; set; } = true;
        public bool SysvolSharePresent { get; set; }
        public bool NetlogonSharePresent { get; set; }
    }

    public sealed class ActiveDirectoryDcHealthResult
    {
        public string State { get; set; } = "not_applicable";
        public int DnsServiceIssue { get; set; }
        public int SharesMissing { get; set; }
        public int TimeIssues { get; set; }
        public int HealthIssues { get; set; }
    }

    public static class ActiveDirectoryDcHealth
    {
        public static ActiveDirectoryDcHealthResult Evaluate(ActiveDirectoryDcHealthInput input)
        {
            input = input ?? new ActiveDirectoryDcHealthInput();
            var result = new ActiveDirectoryDcHealthResult();
            if (!input.IsDomainController)
            {
                return result;
            }

            result.State = "ok";
            if (input.IncludeDns && input.DnsServicePresent && !input.DnsServiceRunning)
            {
                result.DnsServiceIssue = 1;
            }

            if (input.IncludeSysvolNetlogon)
            {
                result.SharesMissing += input.SysvolSharePresent ? 0 : 1;
                result.SharesMissing += input.NetlogonSharePresent ? 0 : 1;
            }

            if (input.IncludeTime)
            {
                var timeState = (input.TimeState ?? string.Empty).Trim();
                if ((input.TimeServicePresent && !input.TimeServiceRunning) ||
                    string.Equals(timeState, "warning", StringComparison.OrdinalIgnoreCase) ||
                    string.Equals(timeState, "critical", StringComparison.OrdinalIgnoreCase))
                {
                    result.TimeIssues = 1;
                }
            }

            result.HealthIssues = Math.Max(0, input.CoreServicesNotRunning) +
                result.DnsServiceIssue +
                result.SharesMissing +
                result.TimeIssues;

            if (input.CoreServicesNotRunning > 0 || result.DnsServiceIssue > 0 || result.SharesMissing > 0)
            {
                result.State = "critical";
            }
            else if (result.TimeIssues > 0)
            {
                result.State = "warning";
            }

            return result;
        }
    }
}
