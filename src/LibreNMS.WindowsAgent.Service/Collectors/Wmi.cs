using System;
using System.Collections.Generic;
using System.Globalization;
using System.Management;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal static class Wmi
    {
        public static IEnumerable<ManagementObject> Query(string wql)
        {
            return Query(null, wql);
        }

        public static IEnumerable<ManagementObject> Query(string scopePath, string wql)
        {
            using (var searcher = string.IsNullOrWhiteSpace(scopePath)
                ? new ManagementObjectSearcher(wql)
                : new ManagementObjectSearcher(new ManagementScope(scopePath), new ObjectQuery(wql)))
            using (var results = searcher.Get())
            {
                foreach (ManagementObject item in results)
                {
                    yield return item;
                }
            }
        }

        public static string StringValue(ManagementBaseObject item, string property)
        {
            return item.Properties[property]?.Value?.ToString() ?? string.Empty;
        }

        public static ulong UInt64Value(ManagementBaseObject item, string property)
        {
            var value = item.Properties[property]?.Value;
            if (value == null)
            {
                return 0;
            }

            if (value is ulong typed)
            {
                return typed;
            }

            return ulong.TryParse(value.ToString(), NumberStyles.Integer, CultureInfo.InvariantCulture, out var parsed)
                ? parsed
                : 0;
        }

        public static double DoubleValue(ManagementBaseObject item, string property)
        {
            var value = item.Properties[property]?.Value;
            if (value == null)
            {
                return 0;
            }

            if (value is double typed)
            {
                return typed;
            }

            if (value is float floatValue)
            {
                return floatValue;
            }

            return double.TryParse(value.ToString(), NumberStyles.Float, CultureInfo.InvariantCulture, out var parsed)
                ? parsed
                : 0;
        }

        public static DateTimeOffset? DateTimeValue(ManagementBaseObject item, string property)
        {
            var value = StringValue(item, property);
            if (string.IsNullOrWhiteSpace(value))
            {
                return null;
            }

            try
            {
                return ManagementDateTimeConverter.ToDateTime(value);
            }
            catch
            {
                return null;
            }
        }
    }
}
