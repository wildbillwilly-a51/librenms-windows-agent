using System;
using System.Collections.Generic;
using System.Linq;
using System.ServiceProcess;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class WindowsUpdateCollector : CollectorBase
    {
        public override string Name => "windows_update";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var service = ServiceController.GetServices()
                .FirstOrDefault(candidate => string.Equals(candidate.ServiceName, "wuauserv", StringComparison.OrdinalIgnoreCase));

            try
            {
                var startMode = GetWindowsUpdateStartMode();
                var rebootSources = PendingRebootCollector.FindPendingRebootSources();
                var updateRebootPending = rebootSources.Contains("windows_update");

                return Complete(
                    new AgentSection(
                        "windows_agent_windows_update",
                        new[]
                        {
                            string.Join(" ",
                                Kv("service_installed", service == null ? 0 : 1),
                                Kv("service_state", service?.Status.ToString() ?? "missing"),
                                Kv("start_mode", startMode),
                                Kv("reboot_required", updateRebootPending ? 1 : 0))
                        }),
                    new AgentSection(
                        "local",
                        new[]
                        {
                            LocalCheck.Format(
                                updateRebootPending ? LocalCheckStatus.Warning : LocalCheckStatus.Ok,
                                "Windows Agent Windows Update Reboot",
                                $"pending={(updateRebootPending ? 1 : 0)}",
                                updateRebootPending
                                    ? "Windows Update reports a required reboot."
                                    : "Windows Update does not report a required reboot.")
                        }));
            }
            finally
            {
                service?.Dispose();
            }
        }

        private static string GetWindowsUpdateStartMode()
        {
            var query = "SELECT StartMode FROM Win32_Service WHERE Name = 'wuauserv'";
            var item = Wmi.Query(query).FirstOrDefault();
            using (item)
            {
                return item == null ? "missing" : Wmi.StringValue(item, "StartMode");
            }
        }
    }
}
