using System;

namespace LibreNMS.WindowsAgent.Core
{
    public sealed class WindowsPerformanceHealthInput
    {
        public bool Disabled { get; set; }
        public double CpuQueueLength { get; set; }
        public double MemoryAvailableMb { get; set; }
        public double MemoryCommittedPercent { get; set; }
        public double PagesPerSec { get; set; }
        public double DiskReadLatencyMsMax { get; set; }
        public double DiskWriteLatencyMsMax { get; set; }
        public double DiskQueueLengthMax { get; set; }
        public double NetworkErrorsTotal { get; set; }
        public int CpuQueueWarning { get; set; } = 4;
        public int MemoryAvailableWarningMb { get; set; } = 1024;
        public int MemoryCommittedWarningPercent { get; set; } = 90;
        public int PagingWarningPagesPerSec { get; set; } = 50;
        public int DiskLatencyWarningMs { get; set; } = 50;
        public int DiskQueueWarning { get; set; } = 2;
    }

    public sealed class WindowsPerformanceHealthResult
    {
        public string State { get; set; } = "ok";
        public int CpuPressure { get; set; }
        public int MemoryPressure { get; set; }
        public int PagingPressure { get; set; }
        public int DiskPressure { get; set; }
        public int NetworkIssue { get; set; }
        public int PressureIssues { get; set; }
    }

    public static class WindowsPerformanceHealth
    {
        public static WindowsPerformanceHealthResult Evaluate(WindowsPerformanceHealthInput input)
        {
            input = input ?? new WindowsPerformanceHealthInput();
            var result = new WindowsPerformanceHealthResult();
            if (input.Disabled)
            {
                result.State = "disabled";
                return result;
            }

            if (input.CpuQueueLength >= Math.Max(1, input.CpuQueueWarning))
            {
                result.CpuPressure = 1;
                result.PressureIssues++;
            }

            if ((input.MemoryAvailableMb > 0 && input.MemoryAvailableMb <= Math.Max(1, input.MemoryAvailableWarningMb)) ||
                input.MemoryCommittedPercent >= Math.Max(1, input.MemoryCommittedWarningPercent))
            {
                result.MemoryPressure = 1;
                result.PressureIssues++;
            }

            if (input.PagesPerSec >= Math.Max(1, input.PagingWarningPagesPerSec))
            {
                result.PagingPressure = 1;
                result.PressureIssues++;
            }

            if (input.DiskReadLatencyMsMax >= Math.Max(1, input.DiskLatencyWarningMs) ||
                input.DiskWriteLatencyMsMax >= Math.Max(1, input.DiskLatencyWarningMs) ||
                input.DiskQueueLengthMax >= Math.Max(1, input.DiskQueueWarning))
            {
                result.DiskPressure = 1;
                result.PressureIssues++;
            }

            if (input.NetworkErrorsTotal > 0)
            {
                result.NetworkIssue = 1;
                result.PressureIssues++;
            }

            result.State = result.PressureIssues > 0 ? "warning" : "ok";
            return result;
        }
    }
}
