using System;
using System.Collections.Generic;
using System.Linq;
using System.Management;
using System.Threading;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class FactoryTalkProcessIdentity
    {
        public string Name { get; set; } = string.Empty;
        public long ProcessId { get; set; }
        public string Role { get; set; } = string.Empty;
    }

    internal sealed class FactoryTalkRuntimeProcessMetric
    {
        public string Name { get; set; } = string.Empty;
        public long ProcessId { get; set; }
        public string Role { get; set; } = string.Empty;
        public double CpuPercent { get; set; }
        public long WorkingSetBytes { get; set; }
        public long PrivateBytes { get; set; }
        public long HandleCount { get; set; }
        public long ThreadCount { get; set; }
        public double IoReadBytesPerSec { get; set; }
        public double IoWriteBytesPerSec { get; set; }
        public long UptimeSeconds { get; set; }
    }

    internal sealed class FactoryTalkRuntimeMetrics
    {
        public string State { get; set; } = "unavailable";
        public string Reason { get; set; } = "none";
        public IList<FactoryTalkRuntimeProcessMetric> Processes { get; } = new List<FactoryTalkRuntimeProcessMetric>();
        public double CpuPercent => Math.Min(100, Processes.Sum(row => row.CpuPercent));
        public long WorkingSetBytes => Processes.Sum(row => row.WorkingSetBytes);
        public long PrivateBytes => Processes.Sum(row => row.PrivateBytes);
        public long HandleCount => Processes.Sum(row => row.HandleCount);
        public long ThreadCount => Processes.Sum(row => row.ThreadCount);
        public double IoReadBytesPerSec => Processes.Sum(row => row.IoReadBytesPerSec);
        public double IoWriteBytesPerSec => Processes.Sum(row => row.IoWriteBytesPerSec);
        public long OldestUptimeSeconds => Processes.Count == 0 ? 0 : Processes.Max(row => row.UptimeSeconds);
    }

    internal static class FactoryTalkProcessMetricsReader
    {
        public static FactoryTalkRuntimeMetrics Read(IEnumerable<FactoryTalkProcessIdentity> processIdentities, CancellationToken cancellationToken)
        {
            var identities = (processIdentities ?? Enumerable.Empty<FactoryTalkProcessIdentity>())
                .Where(process => process.ProcessId > 0)
                .GroupBy(process => process.ProcessId)
                .ToDictionary(group => group.Key, group => group.First());
            var result = new FactoryTalkRuntimeMetrics();
            if (identities.Count == 0)
            {
                result.State = "not_detected";
                return result;
            }

            try
            {
                foreach (var item in Wmi.Query("SELECT Name,IDProcess,PercentProcessorTime,WorkingSet,PrivateBytes,HandleCount,ThreadCount,IOReadBytesPersec,IOWriteBytesPersec,ElapsedTime FROM Win32_PerfFormattedData_PerfProc_Process"))
                {
                    cancellationToken.ThrowIfCancellationRequested();
                    using (item)
                    {
                        var processId = (long)Wmi.UInt64Value(item, "IDProcess");
                        if (!identities.TryGetValue(processId, out var identity))
                        {
                            continue;
                        }

                        var rawCpu = Wmi.DoubleValue(item, "PercentProcessorTime");
                        result.Processes.Add(new FactoryTalkRuntimeProcessMetric
                        {
                            Name = identity.Name,
                            ProcessId = processId,
                            Role = identity.Role,
                            CpuPercent = Math.Min(100, Math.Max(0, rawCpu / Math.Max(1, Environment.ProcessorCount))),
                            WorkingSetBytes = NonNegative(Wmi.UInt64Value(item, "WorkingSet")),
                            PrivateBytes = NonNegative(Wmi.UInt64Value(item, "PrivateBytes")),
                            HandleCount = NonNegative(Wmi.UInt64Value(item, "HandleCount")),
                            ThreadCount = NonNegative(Wmi.UInt64Value(item, "ThreadCount")),
                            IoReadBytesPerSec = Math.Max(0, Wmi.DoubleValue(item, "IOReadBytesPersec")),
                            IoWriteBytesPerSec = Math.Max(0, Wmi.DoubleValue(item, "IOWriteBytesPersec")),
                            UptimeSeconds = NonNegative(Wmi.UInt64Value(item, "ElapsedTime")),
                        });
                    }
                }

                result.State = result.Processes.Count == 0 ? "unavailable" : "ok";
                result.Reason = result.Processes.Count == 0 ? "performance_rows_missing" : "none";
            }
            catch (Exception ex) when (ex is ManagementException || ex is UnauthorizedAccessException || ex is InvalidOperationException || ex is NotSupportedException)
            {
                result.State = "unavailable";
                result.Reason = ex.GetType().Name;
            }

            return result;
        }

        private static long NonNegative(ulong value)
        {
            return value > long.MaxValue ? long.MaxValue : (long)value;
        }
    }
}
