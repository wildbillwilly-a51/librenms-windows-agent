using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;

namespace LibreNMS.WindowsAgent.Core
{
    public sealed class CollectorRunner
    {
        private readonly IReadOnlyList<IAgentCollector> _collectors;
        private readonly IAgentLogger _logger;

        public CollectorRunner(IEnumerable<IAgentCollector> collectors, IAgentLogger logger)
        {
            _collectors = (collectors ?? Enumerable.Empty<IAgentCollector>()).ToArray();
            _logger = logger ?? NullAgentLogger.Instance;
        }

        public async Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var sections = new List<AgentSection>();
            var localLines = new List<string>();
            var collectorTimings = new List<CollectorTiming>();
            var started = Stopwatch.StartNew();
            var resourceStart = ProcessResourceSnapshot.Capture();
            var timeout = TimeSpan.FromSeconds(Math.Max(1, context.Config.Collectors.TimeoutSeconds));
            var enabled = new HashSet<string>(
                context.Config.Collectors.Enabled ?? Enumerable.Empty<string>(),
                StringComparer.OrdinalIgnoreCase);

            var collectTasks = _collectors
                .Where(collector => enabled.Count == 0 || enabled.Contains(collector.Name))
                .Select(collector =>
                {
                    var collectorTimeout = collector is ICollectorTimeoutOverride timeoutOverride
                        ? timeoutOverride.GetTimeout(context, timeout)
                        : timeout;

                    return CollectOneAsync(collector, context, collectorTimeout, cancellationToken);
                })
                .ToArray();

            var results = await Task.WhenAll(collectTasks).ConfigureAwait(false);

            foreach (var result in results)
            {
                collectorTimings.Add(result.Timing);
                foreach (var section in result.Sections)
                {
                    if (string.Equals(section.Name, "local", StringComparison.OrdinalIgnoreCase))
                    {
                        localLines.AddRange(section.Lines);
                    }
                    else
                    {
                        sections.Add(section);
                    }
                }
            }

            started.Stop();
            sections.Add(BuildPerformanceSection(started.Elapsed, collectorTimings, sections.Count, localLines.Count, resourceStart));

            if (localLines.Count > 0)
            {
                sections.Add(new AgentSection("local", localLines));
            }

            return sections;
        }

        private async Task<CollectorRunResult> CollectOneAsync(
            IAgentCollector collector,
            AgentContext context,
            TimeSpan timeout,
            CancellationToken cancellationToken)
        {
            var started = Stopwatch.StartNew();
            try
            {
                using (var timeoutCts = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken))
                {
                    var collectTask = collector.CollectAsync(context, timeoutCts.Token);
                    var delayTask = Task.Delay(timeout, cancellationToken);
                    var completed = await Task.WhenAny(collectTask, delayTask).ConfigureAwait(false);

                    if (completed != collectTask)
                    {
                        timeoutCts.Cancel();
                        _logger.Warn($"Collector '{collector.Name}' timed out after {timeout.TotalSeconds:0}s.");
                        return CollectorRunResult.From(
                            CollectorError(collector.Name, "timeout", $"Collector timed out after {timeout.TotalSeconds:0}s."),
                            collector.Name,
                            "timeout",
                            started.Elapsed);
                    }

                    return CollectorRunResult.From(
                        await collectTask.ConfigureAwait(false) ?? Array.Empty<AgentSection>(),
                        collector.Name,
                        "ok",
                        started.Elapsed);
                }
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception ex)
            {
                _logger.Error($"Collector '{collector.Name}' failed.", ex);
                return CollectorRunResult.From(
                    CollectorError(collector.Name, "error", ex.Message),
                    collector.Name,
                    "error",
                    started.Elapsed);
            }
        }

        private static AgentSection BuildPerformanceSection(
            TimeSpan duration,
            IReadOnlyList<CollectorTiming> collectorTimings,
            int sectionCount,
            int localLineCount,
            ProcessResourceSnapshot resourceStart)
        {
            var process = Process.GetCurrentProcess();
            var resourceEnd = ProcessResourceSnapshot.Capture(process);
            var lines = new List<string>();
            var totalLineCount = collectorTimings.Sum(timing => timing.LineCount) + localLineCount;
            var failed = collectorTimings.Count(timing => string.Equals(timing.State, "error", StringComparison.OrdinalIgnoreCase));
            var timedOut = collectorTimings.Count(timing => string.Equals(timing.State, "timeout", StringComparison.OrdinalIgnoreCase));
            var durationMs = Math.Max(0, (long)Math.Round(duration.TotalMilliseconds));
            var cpuMs = Math.Max(0, resourceEnd.TotalProcessorTimeMs - resourceStart.TotalProcessorTimeMs);
            var cpuPercent = CalculateCpuPercent(cpuMs, durationMs);
            var ioReadBytes = Delta(resourceStart.ReadBytes, resourceEnd.ReadBytes);
            var ioWriteBytes = Delta(resourceStart.WriteBytes, resourceEnd.WriteBytes);
            var ioReadOps = Delta(resourceStart.ReadOperations, resourceEnd.ReadOperations);
            var ioWriteOps = Delta(resourceStart.WriteOperations, resourceEnd.WriteOperations);

            try
            {
                lines.Add(string.Join(" ",
                    "type=summary",
                    Kv("collect_duration_ms", durationMs),
                    Kv("collectors_run", collectorTimings.Count),
                    Kv("collectors_failed", failed),
                    Kv("collectors_timed_out", timedOut),
                    Kv("section_count", sectionCount + 1 + (localLineCount > 0 ? 1 : 0)),
                    Kv("line_count", totalLineCount + collectorTimings.Count + 1),
                    Kv("payload_bytes", 0),
                    Kv("process_working_set_bytes", process.WorkingSet64),
                    Kv("process_private_bytes", process.PrivateMemorySize64),
                    Kv("process_cpu_ms", cpuMs),
                    Kv("process_cpu_percent", cpuPercent.ToString("0.##", System.Globalization.CultureInfo.InvariantCulture)),
                    Kv("process_io_read_bytes", ioReadBytes),
                    Kv("process_io_write_bytes", ioWriteBytes),
                    Kv("process_io_bytes", ioReadBytes + ioWriteBytes),
                    Kv("process_io_read_ops", ioReadOps),
                    Kv("process_io_write_ops", ioWriteOps)));
            }
            finally
            {
                process.Dispose();
            }

            foreach (var timing in collectorTimings)
            {
                lines.Add(string.Join(" ",
                    "type=collector",
                    Kv("collector", timing.Name),
                    Kv("duration_ms", timing.DurationMs),
                    Kv("state", timing.State),
                    Kv("section_count", timing.SectionCount),
                    Kv("line_count", timing.LineCount)));
            }

            return new AgentSection("windows_agent_performance", lines);
        }

        private static string Kv(string key, object value)
        {
            return key + "=" + Convert.ToString(value, System.Globalization.CultureInfo.InvariantCulture);
        }

        private static double CalculateCpuPercent(long cpuMs, long durationMs)
        {
            if (cpuMs <= 0 || durationMs <= 0)
            {
                return 0;
            }

            return Math.Round((cpuMs / (double)(durationMs * Math.Max(1, Environment.ProcessorCount))) * 100, 2);
        }

        private static ulong Delta(ulong start, ulong end)
        {
            return end >= start ? end - start : 0;
        }

        private static IReadOnlyList<AgentSection> CollectorError(string collectorName, string state, string message)
        {
            return new[]
            {
                new AgentSection(
                    "windows_agent_errors",
                    new[]
                    {
                        $"collector={collectorName} state={state} message={EscapeValue(message)}"
                    }),
                new AgentSection(
                    "local",
                    new[]
                    {
                        LocalCheck.Format(LocalCheckStatus.Unknown, $"Windows Agent Collector {collectorName}", "-", message)
                    })
            };
        }

        private static string EscapeValue(string value)
        {
            return "\"" + (value ?? string.Empty).Replace("\\", "\\\\").Replace("\"", "\\\"") + "\"";
        }

        private sealed class CollectorTiming
        {
            public string Name { get; set; }
            public string State { get; set; }
            public long DurationMs { get; set; }
            public int SectionCount { get; set; }
            public int LineCount { get; set; }
        }

        private sealed class ProcessResourceSnapshot
        {
            public long TotalProcessorTimeMs { get; private set; }
            public ulong ReadBytes { get; private set; }
            public ulong WriteBytes { get; private set; }
            public ulong ReadOperations { get; private set; }
            public ulong WriteOperations { get; private set; }

            public static ProcessResourceSnapshot Capture()
            {
                using (var process = Process.GetCurrentProcess())
                {
                    return Capture(process);
                }
            }

            public static ProcessResourceSnapshot Capture(Process process)
            {
                var snapshot = new ProcessResourceSnapshot
                {
                    TotalProcessorTimeMs = Math.Max(0, (long)Math.Round(process.TotalProcessorTime.TotalMilliseconds)),
                };

                try
                {
                    IoCounters counters;
                    if (GetProcessIoCounters(process.Handle, out counters))
                    {
                        snapshot.ReadBytes = counters.ReadTransferCount;
                        snapshot.WriteBytes = counters.WriteTransferCount;
                        snapshot.ReadOperations = counters.ReadOperationCount;
                        snapshot.WriteOperations = counters.WriteOperationCount;
                    }
                }
                catch
                {
                    // I/O counters are best-effort; CPU and memory impact still remain useful.
                }

                return snapshot;
            }
        }

        [System.Runtime.InteropServices.StructLayout(System.Runtime.InteropServices.LayoutKind.Sequential)]
        private struct IoCounters
        {
            public ulong ReadOperationCount;
            public ulong WriteOperationCount;
            public ulong OtherOperationCount;
            public ulong ReadTransferCount;
            public ulong WriteTransferCount;
            public ulong OtherTransferCount;
        }

        [System.Runtime.InteropServices.DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool GetProcessIoCounters(IntPtr processHandle, out IoCounters ioCounters);

        private sealed class CollectorRunResult
        {
            public IReadOnlyList<AgentSection> Sections { get; private set; }
            public CollectorTiming Timing { get; private set; }

            public static CollectorRunResult From(
                IReadOnlyList<AgentSection> sections,
                string collectorName,
                string state,
                TimeSpan duration)
            {
                var materialized = sections ?? Array.Empty<AgentSection>();
                return new CollectorRunResult
                {
                    Sections = materialized,
                    Timing = new CollectorTiming
                    {
                        Name = collectorName,
                        State = state,
                        DurationMs = Math.Max(0, (long)Math.Round(duration.TotalMilliseconds)),
                        SectionCount = materialized.Count,
                        LineCount = materialized.Sum(section => section.Lines?.Count ?? 0),
                    },
                };
            }
        }
    }
}
