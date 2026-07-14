using System;

namespace LibreNMS.WindowsAgent.Core
{
    public enum LocalCheckStatus
    {
        Ok = 0,
        Warning = 1,
        Critical = 2,
        Unknown = 3
    }

    public static class LocalCheck
    {
        public static string Format(LocalCheckStatus status, string serviceName, string perfData, string message)
        {
            if (string.IsNullOrWhiteSpace(serviceName))
            {
                throw new ArgumentException("Service name is required.", nameof(serviceName));
            }

            var perf = string.IsNullOrWhiteSpace(perfData) ? "-" : perfData.Trim();
            return string.Format(
                "{0} {1} {2} {3}",
                (int)status,
                Quote(serviceName.Trim()),
                perf,
                (message ?? string.Empty).Replace("\r", " ").Replace("\n", " "));
        }

        public static string Quote(string value)
        {
            return "\"" + (value ?? string.Empty).Replace("\\", "\\\\").Replace("\"", "\\\"") + "\"";
        }
    }
}
