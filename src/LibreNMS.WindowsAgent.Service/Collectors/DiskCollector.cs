using System.Collections.Generic;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class DiskCollector : CollectorBase
    {
        public override string Name => "disks";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var lines = new List<string>();

            foreach (var disk in Wmi.Query("SELECT DeviceID,VolumeName,FileSystem,Size,FreeSpace FROM Win32_LogicalDisk WHERE DriveType = 3"))
            {
                using (disk)
                {
                    var size = Wmi.UInt64Value(disk, "Size");
                    var free = Wmi.UInt64Value(disk, "FreeSpace");
                    lines.Add(string.Join(" ",
                        Kv("device", Wmi.StringValue(disk, "DeviceID")),
                        Kv("volume", Wmi.StringValue(disk, "VolumeName")),
                        Kv("filesystem", Wmi.StringValue(disk, "FileSystem")),
                        Kv("size_bytes", size),
                        Kv("free_bytes", free),
                        Kv("used_bytes", size > free ? size - free : 0)));
                }
            }

            return Complete(new AgentSection("windows_agent_disks", lines));
        }
    }
}
