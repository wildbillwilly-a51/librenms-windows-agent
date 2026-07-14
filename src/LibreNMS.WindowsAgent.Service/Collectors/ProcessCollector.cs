using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Diagnostics;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class ProcessCollector : CollectorBase
    {
        public override string Name => "processes";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var watched = context.Config.Collectors.WatchedProcesses ?? new List<ProcessWatchConfig>();
            var lines = new List<string>
            {
                string.Join(" ", Kv("watched_count", watched.Count))
            };

            foreach (var watch in watched.Where(item => !string.IsNullOrWhiteSpace(item.Name)))
            {
                cancellationToken.ThrowIfCancellationRequested();

                var name = NormalizeName(watch.Name);
                var processes = Process.GetProcessesByName(name);

                try
                {
                    long workingSetBytes = 0;
                    long privateBytes = 0;
                    double processorSeconds = 0;

                    foreach (var process in processes)
                    {
                        try
                        {
                            workingSetBytes += process.WorkingSet64;
                            privateBytes += process.PrivateMemorySize64;
                            processorSeconds += process.TotalProcessorTime.TotalSeconds;
                        }
                        catch (Exception ex) when (ex is InvalidOperationException || ex is Win32Exception || ex is NotSupportedException)
                        {
                            // The process may exit or deny metric access between enumeration and read.
                        }
                    }

                    lines.Add(string.Join(" ",
                        Kv("name", name),
                        Kv("matched_count", processes.Length),
                        Kv("working_set_bytes", workingSetBytes),
                        Kv("private_bytes", privateBytes),
                        Kv("processor_seconds", Math.Round(processorSeconds, 3))));
                }
                finally
                {
                    foreach (var process in processes)
                    {
                        process.Dispose();
                    }
                }
            }

            return Complete(new AgentSection("windows_agent_processes", lines));
        }

        private static string NormalizeName(string name)
        {
            var value = (name ?? string.Empty).Trim();
            return value.EndsWith(".exe", StringComparison.OrdinalIgnoreCase)
                ? value.Substring(0, value.Length - 4)
                : value;
        }
    }
}
