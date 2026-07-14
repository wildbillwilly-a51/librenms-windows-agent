using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class MemoryCollector : CollectorBase
    {
        public override string Name => "memory";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var os = Wmi.Query("SELECT TotalVisibleMemorySize,FreePhysicalMemory,TotalVirtualMemorySize,FreeVirtualMemory FROM Win32_OperatingSystem")
                .FirstOrDefault();

            if (os == null)
            {
                return Complete(new AgentSection("windows_agent_memory", new[] { Kv("state", "unavailable") }));
            }

            using (os)
            {
                var totalPhysical = Wmi.UInt64Value(os, "TotalVisibleMemorySize") * 1024;
                var freePhysical = Wmi.UInt64Value(os, "FreePhysicalMemory") * 1024;
                var totalVirtual = Wmi.UInt64Value(os, "TotalVirtualMemorySize") * 1024;
                var freeVirtual = Wmi.UInt64Value(os, "FreeVirtualMemory") * 1024;

                return Complete(new AgentSection(
                    "windows_agent_memory",
                    new[]
                    {
                        string.Join(" ",
                            Kv("physical_total_bytes", totalPhysical),
                            Kv("physical_free_bytes", freePhysical),
                            Kv("physical_used_bytes", totalPhysical > freePhysical ? totalPhysical - freePhysical : 0),
                            Kv("virtual_total_bytes", totalVirtual),
                            Kv("virtual_free_bytes", freeVirtual),
                            Kv("virtual_used_bytes", totalVirtual > freeVirtual ? totalVirtual - freeVirtual : 0))
                    }));
            }
        }
    }
}
