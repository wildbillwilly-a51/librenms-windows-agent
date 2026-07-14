using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class CpuCollector : CollectorBase
    {
        public override string Name => "cpu";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var lines = new List<string>();

            foreach (var cpu in Wmi.Query("SELECT Name,NumberOfCores,NumberOfLogicalProcessors,LoadPercentage,MaxClockSpeed FROM Win32_Processor"))
            {
                using (cpu)
                {
                    lines.Add(string.Join(" ",
                        Kv("name", Wmi.StringValue(cpu, "Name")),
                        Kv("cores", Wmi.UInt64Value(cpu, "NumberOfCores")),
                        Kv("logical_processors", Wmi.UInt64Value(cpu, "NumberOfLogicalProcessors")),
                        Kv("load_percent", Wmi.UInt64Value(cpu, "LoadPercentage")),
                        Kv("max_clock_mhz", Wmi.UInt64Value(cpu, "MaxClockSpeed"))));
                }
            }

            return Complete(new AgentSection("windows_agent_cpu", lines));
        }
    }
}
