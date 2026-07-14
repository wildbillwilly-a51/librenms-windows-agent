using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class UptimeCollector : CollectorBase
    {
        public override string Name => "uptime";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var os = Wmi.Query("SELECT LastBootUpTime FROM Win32_OperatingSystem").FirstOrDefault();
            using (os)
            {
                var boot = os == null ? (DateTimeOffset?)null : Wmi.DateTimeValue(os, "LastBootUpTime");
                var uptimeSeconds = boot.HasValue ? Math.Max(0, (long)(context.NowUtc - boot.Value.ToUniversalTime()).TotalSeconds) : 0;

                return Complete(new AgentSection(
                    "windows_agent_uptime",
                    new[]
                    {
                        string.Join(" ",
                            Kv("uptime_seconds", uptimeSeconds),
                            Kv("boot_utc", boot?.ToUniversalTime().ToString("O") ?? string.Empty))
                    }));
            }
        }
    }
}
