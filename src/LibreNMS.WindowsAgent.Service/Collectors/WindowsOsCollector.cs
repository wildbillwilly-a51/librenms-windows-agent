using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class WindowsOsCollector : CollectorBase
    {
        public override string Name => "os";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var os = Wmi.Query("SELECT Caption,Version,BuildNumber,OSArchitecture,InstallDate,LastBootUpTime FROM Win32_OperatingSystem")
                .FirstOrDefault();

            if (os == null)
            {
                return Complete(new AgentSection("windows_agent_windows_os", new[] { Kv("state", "unavailable") }));
            }

            using (os)
            {
                return Complete(new AgentSection(
                    "windows_agent_windows_os",
                    new[]
                    {
                        string.Join(" ",
                            Kv("caption", Wmi.StringValue(os, "Caption")),
                            Kv("version", Wmi.StringValue(os, "Version")),
                            Kv("build", Wmi.StringValue(os, "BuildNumber")),
                            Kv("architecture", Wmi.StringValue(os, "OSArchitecture")),
                            Kv("install_utc", Wmi.DateTimeValue(os, "InstallDate")?.ToUniversalTime().ToString("O") ?? string.Empty),
                            Kv("last_boot_utc", Wmi.DateTimeValue(os, "LastBootUpTime")?.ToUniversalTime().ToString("O") ?? string.Empty))
                    }));
            }
        }
    }
}
