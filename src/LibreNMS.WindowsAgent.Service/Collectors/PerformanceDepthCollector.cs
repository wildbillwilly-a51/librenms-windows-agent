using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Diagnostics;
using System.Management;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class PerformanceDepthCollector : CollectorBase
    {
        public override string Name => "performance_depth";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var config = context.Config.Collectors.PerformanceDepth ?? new PerformanceDepthConfig();
            if (IsDisabled(config.Mode))
            {
                return Complete(
                    new AgentSection("windows_agent_performance_summary", new[] { SummaryLine("disabled", new WindowsPerformanceHealthResult(), 0, 0, 0, 0, 0, 0, 0, 0, 0) }),
                    new AgentSection("windows_agent_performance_disks", Array.Empty<string>()),
                    new AgentSection("windows_agent_performance_network", Array.Empty<string>()),
                    new AgentSection("windows_agent_performance_processes", Array.Empty<string>()));
            }

            try
            {
                var cpuQueueLength = ReadCpuQueueLength(cancellationToken);
                var memory = ReadMemory(cancellationToken);
                var disks = config.IncludeDisks ? ReadDisks(cancellationToken) : new List<DiskPressureRow>();
                var networks = config.IncludeNetwork ? ReadNetwork(cancellationToken) : new List<NetworkPressureRow>();
                var processes = config.IncludeTopProcesses ? ReadTopProcesses(config.TopProcesses, cancellationToken) : new List<ProcessPressureRow>();

                var diskReadMax = disks.Count == 0 ? 0 : disks.Max(row => row.AvgReadMs);
                var diskWriteMax = disks.Count == 0 ? 0 : disks.Max(row => row.AvgWriteMs);
                var diskQueueMax = disks.Count == 0 ? 0 : disks.Max(row => row.CurrentQueueLength);
                var networkBytesTotal = networks.Sum(row => row.BytesPerSec);
                var networkErrorsTotal = networks.Sum(row => row.ErrorsPerSec + row.DiscardsPerSec);

                var health = WindowsPerformanceHealth.Evaluate(new WindowsPerformanceHealthInput
                {
                    CpuQueueLength = cpuQueueLength,
                    MemoryAvailableMb = memory.AvailableMb,
                    MemoryCommittedPercent = memory.CommittedPercent,
                    PagesPerSec = memory.PagesPerSec,
                    DiskReadLatencyMsMax = diskReadMax,
                    DiskWriteLatencyMsMax = diskWriteMax,
                    DiskQueueLengthMax = diskQueueMax,
                    NetworkErrorsTotal = networkErrorsTotal,
                    CpuQueueWarning = config.CpuQueueWarning,
                    MemoryAvailableWarningMb = config.MemoryAvailableWarningMb,
                    MemoryCommittedWarningPercent = config.MemoryCommittedWarningPercent,
                    PagingWarningPagesPerSec = config.PagingWarningPagesPerSec,
                    DiskLatencyWarningMs = config.DiskLatencyWarningMs,
                    DiskQueueWarning = config.DiskQueueWarning,
                });

                return Complete(
                    new AgentSection("windows_agent_performance_summary", new[] { SummaryLine(
                        health.State,
                        health,
                        cpuQueueLength,
                        memory.AvailableMb,
                        memory.CommittedPercent,
                        memory.PagesPerSec,
                        diskReadMax,
                        diskWriteMax,
                        diskQueueMax,
                        networkBytesTotal,
                        networkErrorsTotal) }),
                    new AgentSection("windows_agent_performance_disks", disks.Select(row => string.Join(" ",
                        Kv("name", row.Name),
                        Kv("avg_read_ms", Math.Round(row.AvgReadMs, 3)),
                        Kv("avg_write_ms", Math.Round(row.AvgWriteMs, 3)),
                        Kv("current_queue_length", Math.Round(row.CurrentQueueLength, 3)),
                        Kv("disk_bytes_per_sec", Math.Round(row.BytesPerSec, 3))))),
                    new AgentSection("windows_agent_performance_network", networks.Select(row => string.Join(" ",
                        Kv("name", row.Name),
                        Kv("bytes_per_sec", Math.Round(row.BytesPerSec, 3)),
                        Kv("packets_per_sec", Math.Round(row.PacketsPerSec, 3)),
                        Kv("errors_per_sec", Math.Round(row.ErrorsPerSec, 3)),
                        Kv("discards_per_sec", Math.Round(row.DiscardsPerSec, 3))))),
                    new AgentSection("windows_agent_performance_processes", processes.Select(row => string.Join(" ",
                        Kv("name", row.Name),
                        Kv("pid", row.ProcessId),
                        Kv("rank_source", row.RankSource),
                        Kv("cpu_percent", Math.Round(row.CpuPercent, 3)),
                        Kv("working_set_bytes", row.WorkingSetBytes),
                        Kv("private_bytes", row.PrivateBytes),
                        Kv("handle_count", row.HandleCount),
                        Kv("thread_count", row.ThreadCount)))));
            }
            catch (Exception ex) when (ex is ManagementException || ex is UnauthorizedAccessException || ex is InvalidOperationException || ex is NotSupportedException || ex is Win32Exception)
            {
                return Complete(
                    new AgentSection("windows_agent_performance_summary", new[] { string.Join(" ", Kv("state", "unsupported"), Kv("reason", ex.GetType().Name)) }),
                    new AgentSection("windows_agent_performance_disks", Array.Empty<string>()),
                    new AgentSection("windows_agent_performance_network", Array.Empty<string>()),
                    new AgentSection("windows_agent_performance_processes", Array.Empty<string>()));
            }
        }

        private static string SummaryLine(string state, WindowsPerformanceHealthResult health, double cpuQueueLength, double memoryAvailableMb, double memoryCommittedPercent, double pagesPerSec, double diskReadMsMax, double diskWriteMsMax, double diskQueueLengthMax, double networkBytesPerSecTotal, double networkErrorsTotal)
        {
            return string.Join(" ",
                Kv("state", state),
                Kv("cpu_queue_length", Math.Round(cpuQueueLength, 3)),
                Kv("cpu_pressure", health.CpuPressure),
                Kv("memory_available_mb", Math.Round(memoryAvailableMb, 3)),
                Kv("memory_committed_percent", Math.Round(memoryCommittedPercent, 3)),
                Kv("memory_pressure", health.MemoryPressure),
                Kv("pages_per_sec", Math.Round(pagesPerSec, 3)),
                Kv("paging_pressure", health.PagingPressure),
                Kv("disk_read_ms_max", Math.Round(diskReadMsMax, 3)),
                Kv("disk_write_ms_max", Math.Round(diskWriteMsMax, 3)),
                Kv("disk_queue_length_max", Math.Round(diskQueueLengthMax, 3)),
                Kv("disk_pressure", health.DiskPressure),
                Kv("network_bytes_per_sec_total", Math.Round(networkBytesPerSecTotal, 3)),
                Kv("network_errors_total", Math.Round(networkErrorsTotal, 3)),
                Kv("network_issue", health.NetworkIssue),
                Kv("pressure_issues", health.PressureIssues));
        }

        private static double ReadCpuQueueLength(CancellationToken cancellationToken)
        {
            foreach (var item in Wmi.Query("SELECT ProcessorQueueLength FROM Win32_PerfFormattedData_PerfOS_System"))
            {
                cancellationToken.ThrowIfCancellationRequested();
                using (item)
                {
                    return Wmi.DoubleValue(item, "ProcessorQueueLength");
                }
            }

            return 0;
        }

        private static MemoryPressureRow ReadMemory(CancellationToken cancellationToken)
        {
            foreach (var item in Wmi.Query("SELECT AvailableMBytes,PercentCommittedBytesInUse,PagesPersec FROM Win32_PerfFormattedData_PerfOS_Memory"))
            {
                cancellationToken.ThrowIfCancellationRequested();
                using (item)
                {
                    return new MemoryPressureRow
                    {
                        AvailableMb = Wmi.DoubleValue(item, "AvailableMBytes"),
                        CommittedPercent = Wmi.DoubleValue(item, "PercentCommittedBytesInUse"),
                        PagesPerSec = Wmi.DoubleValue(item, "PagesPersec"),
                    };
                }
            }

            return new MemoryPressureRow();
        }

        private static List<DiskPressureRow> ReadDisks(CancellationToken cancellationToken)
        {
            var rows = new List<DiskPressureRow>();
            foreach (var item in Wmi.Query("SELECT Name,AvgDisksecPerRead,AvgDisksecPerWrite,CurrentDiskQueueLength,DiskBytesPersec FROM Win32_PerfFormattedData_PerfDisk_LogicalDisk"))
            {
                cancellationToken.ThrowIfCancellationRequested();
                using (item)
                {
                    var name = Wmi.StringValue(item, "Name");
                    if (string.Equals(name, "_Total", StringComparison.OrdinalIgnoreCase))
                    {
                        continue;
                    }

                    rows.Add(new DiskPressureRow
                    {
                        Name = name,
                        AvgReadMs = Wmi.DoubleValue(item, "AvgDisksecPerRead") * 1000,
                        AvgWriteMs = Wmi.DoubleValue(item, "AvgDisksecPerWrite") * 1000,
                        CurrentQueueLength = Wmi.DoubleValue(item, "CurrentDiskQueueLength"),
                        BytesPerSec = Wmi.DoubleValue(item, "DiskBytesPersec"),
                    });
                }
            }

            return rows.OrderByDescending(row => Math.Max(row.AvgReadMs, row.AvgWriteMs)).ThenBy(row => row.Name, StringComparer.OrdinalIgnoreCase).ToList();
        }

        private static List<NetworkPressureRow> ReadNetwork(CancellationToken cancellationToken)
        {
            var rows = new List<NetworkPressureRow>();
            foreach (var item in Wmi.Query("SELECT Name,BytesTotalPersec,PacketsPersec,PacketsReceivedErrors,PacketsOutboundErrors,PacketsReceivedDiscarded,PacketsOutboundDiscarded FROM Win32_PerfFormattedData_Tcpip_NetworkInterface"))
            {
                cancellationToken.ThrowIfCancellationRequested();
                using (item)
                {
                    rows.Add(new NetworkPressureRow
                    {
                        Name = Wmi.StringValue(item, "Name"),
                        BytesPerSec = Wmi.DoubleValue(item, "BytesTotalPersec"),
                        PacketsPerSec = Wmi.DoubleValue(item, "PacketsPersec"),
                        ErrorsPerSec = Wmi.DoubleValue(item, "PacketsReceivedErrors") + Wmi.DoubleValue(item, "PacketsOutboundErrors"),
                        DiscardsPerSec = Wmi.DoubleValue(item, "PacketsReceivedDiscarded") + Wmi.DoubleValue(item, "PacketsOutboundDiscarded"),
                    });
                }
            }

            return rows.OrderByDescending(row => row.BytesPerSec).ThenBy(row => row.Name, StringComparer.OrdinalIgnoreCase).ToList();
        }

        private static List<ProcessPressureRow> ReadTopProcesses(int topProcesses, CancellationToken cancellationToken)
        {
            var all = new List<ProcessPressureRow>();
            foreach (var item in Wmi.Query("SELECT Name,IDProcess,PercentProcessorTime,WorkingSet,PrivateBytes,HandleCount,ThreadCount FROM Win32_PerfFormattedData_PerfProc_Process"))
            {
                cancellationToken.ThrowIfCancellationRequested();
                using (item)
                {
                    var name = Wmi.StringValue(item, "Name");
                    if (string.Equals(name, "_Total", StringComparison.OrdinalIgnoreCase) || string.Equals(name, "Idle", StringComparison.OrdinalIgnoreCase))
                    {
                        continue;
                    }

                    all.Add(new ProcessPressureRow
                    {
                        Name = name,
                        ProcessId = (long)Wmi.UInt64Value(item, "IDProcess"),
                        CpuPercent = Wmi.DoubleValue(item, "PercentProcessorTime"),
                        WorkingSetBytes = (long)Wmi.UInt64Value(item, "WorkingSet"),
                        PrivateBytes = (long)Wmi.UInt64Value(item, "PrivateBytes"),
                        HandleCount = (long)Wmi.UInt64Value(item, "HandleCount"),
                        ThreadCount = (long)Wmi.UInt64Value(item, "ThreadCount"),
                    });
                }
            }

            var limit = Math.Max(1, topProcesses);
            var selected = new Dictionary<string, ProcessPressureRow>(StringComparer.OrdinalIgnoreCase);
            foreach (var row in all.OrderByDescending(row => row.CpuPercent).ThenBy(row => row.Name, StringComparer.OrdinalIgnoreCase).Take(limit))
            {
                row.RankSource = "cpu";
                selected[ProcessKey(row)] = row;
            }

            foreach (var row in all.OrderByDescending(row => row.WorkingSetBytes).ThenBy(row => row.Name, StringComparer.OrdinalIgnoreCase).Take(limit))
            {
                var key = ProcessKey(row);
                if (selected.TryGetValue(key, out var existing))
                {
                    existing.RankSource = "cpu,memory";
                    continue;
                }

                row.RankSource = "memory";
                selected[key] = row;
            }

            return selected.Values.OrderBy(row => row.RankSource, StringComparer.OrdinalIgnoreCase).ThenByDescending(row => row.CpuPercent).ThenByDescending(row => row.WorkingSetBytes).ToList();
        }

        private static string ProcessKey(ProcessPressureRow row)
        {
            return row.Name + ":" + row.ProcessId;
        }

        private static bool IsDisabled(string mode)
        {
            return string.Equals(mode, "disabled", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(mode, "off", StringComparison.OrdinalIgnoreCase);
        }

        private sealed class MemoryPressureRow
        {
            public double AvailableMb { get; set; }
            public double CommittedPercent { get; set; }
            public double PagesPerSec { get; set; }
        }

        private sealed class DiskPressureRow
        {
            public string Name { get; set; } = string.Empty;
            public double AvgReadMs { get; set; }
            public double AvgWriteMs { get; set; }
            public double CurrentQueueLength { get; set; }
            public double BytesPerSec { get; set; }
        }

        private sealed class NetworkPressureRow
        {
            public string Name { get; set; } = string.Empty;
            public double BytesPerSec { get; set; }
            public double PacketsPerSec { get; set; }
            public double ErrorsPerSec { get; set; }
            public double DiscardsPerSec { get; set; }
        }

        private sealed class ProcessPressureRow
        {
            public string Name { get; set; } = string.Empty;
            public long ProcessId { get; set; }
            public string RankSource { get; set; } = string.Empty;
            public double CpuPercent { get; set; }
            public long WorkingSetBytes { get; set; }
            public long PrivateBytes { get; set; }
            public long HandleCount { get; set; }
            public long ThreadCount { get; set; }
        }
    }
}
