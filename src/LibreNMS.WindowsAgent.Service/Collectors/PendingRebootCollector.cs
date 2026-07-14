using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;
using Microsoft.Win32;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class PendingRebootCollector : CollectorBase
    {
        public override string Name => "pending_reboot";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var sources = FindPendingRebootSources();
            var pending = sources.Count > 0;

            return Complete(
                new AgentSection(
                    "windows_agent_pending_reboot",
                    new[]
                    {
                        string.Join(" ",
                            Kv("pending", pending ? 1 : 0),
                            Kv("sources", string.Join(",", sources)))
                    }),
                new AgentSection(
                    "local",
                    new[]
                    {
                        LocalCheck.Format(
                            pending ? LocalCheckStatus.Warning : LocalCheckStatus.Ok,
                            "Windows Agent Pending Reboot",
                            $"pending={(pending ? 1 : 0)}",
                            pending
                                ? "Pending reboot detected: " + string.Join(", ", sources)
                                : "No pending reboot detected.")
                    }));
        }

        internal static IReadOnlyList<string> FindPendingRebootSources()
        {
            var sources = new List<string>();

            if (KeyExists(Registry.LocalMachine, @"SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending"))
            {
                sources.Add("component_based_servicing");
            }

            if (KeyExists(Registry.LocalMachine, @"SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired"))
            {
                sources.Add("windows_update");
            }

            using (var sessionManager = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Control\Session Manager"))
            {
                if (sessionManager?.GetValue("PendingFileRenameOperations") != null)
                {
                    sources.Add("pending_file_rename");
                }
            }

            using (var computerName = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Control\ComputerName\ActiveComputerName"))
            using (var pendingName = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Control\ComputerName\ComputerName"))
            {
                var active = computerName?.GetValue("ComputerName")?.ToString();
                var pending = pendingName?.GetValue("ComputerName")?.ToString();
                if (!string.IsNullOrWhiteSpace(active) && !string.IsNullOrWhiteSpace(pending) && active != pending)
                {
                    sources.Add("computer_rename");
                }
            }

            using (var updates = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\Updates"))
            {
                var value = updates?.GetValue("UpdateExeVolatile")?.ToString();
                if (!string.IsNullOrWhiteSpace(value) && value != "0")
                {
                    sources.Add("update_exe_volatile");
                }
            }

            return sources.Distinct().ToArray();
        }

        private static bool KeyExists(RegistryKey root, string path)
        {
            using (var key = root.OpenSubKey(path))
            {
                return key != null;
            }
        }
    }
}
