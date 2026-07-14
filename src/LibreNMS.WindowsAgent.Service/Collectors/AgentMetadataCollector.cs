using System;
using System.Collections.Generic;
using System.Reflection;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class AgentMetadataCollector : CollectorBase
    {
        public override string Name => "agent";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var version = Assembly.GetExecutingAssembly().GetName().Version?.ToString() ?? "unknown";
            return Complete(new AgentSection(
                "windows_agent",
                new[]
                {
                    string.Join(" ",
                        Kv("name", "windows-agent-librenms-windows-agent"),
                        Kv("version", version),
                        Kv("protocol", "checkmk_tcp"),
                        Kv("host", context.HostName),
                        Kv("utc", context.NowUtc.ToString("O")),
                        Kv("config", context.ConfigPath),
                        Kv("process_64bit", Environment.Is64BitProcess ? 1 : 0),
                        Kv("os_64bit", Environment.Is64BitOperatingSystem ? 1 : 0))
                }));
        }
    }
}
