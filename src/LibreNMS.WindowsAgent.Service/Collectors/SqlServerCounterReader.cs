using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Diagnostics;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    /// <summary>
    /// Raw counter values for one SQL Server instance. Every key in
    /// <see cref="SqlServerCounterPolicy.Specs"/> is present; a counter that could
    /// not be read carries <see cref="SqlServerCounterPolicy.Unavailable"/> so an
    /// absent counter is never confused with a measured zero.
    /// </summary>
    internal sealed class SqlCounterReading
    {
        public string Instance { get; set; } = string.Empty;
        public Dictionary<string, double> Values { get; } = new Dictionary<string, double>(StringComparer.OrdinalIgnoreCase);

        /// <summary>Required counters that were read successfully.</summary>
        public int ReadCount { get; set; }

        /// <summary>Required counters that were absent or unreadable.</summary>
        public int MissingCount { get; set; }

        /// <summary>True when at least one engine counter was read.</summary>
        public bool EngineCountersAvailable { get; set; }

        /// <summary>True when the SQLAgent:Jobs object was present; it is absent while the Agent is stopped.</summary>
        public bool AgentCountersAvailable { get; set; }

        public double Value(string key)
        {
            double value;
            return Values.TryGetValue(key, out value) ? value : SqlServerCounterPolicy.Unavailable;
        }
    }

    /// <summary>
    /// Reads SQL Server performance counters through PDH.
    ///
    /// PDH rather than WMI on purpose: the <c>Win32_PerfRawData_*</c> classes mangle
    /// a named instance into the class name, while
    /// <see cref="PerformanceCounterCategory"/> takes the literal object name
    /// (<c>MSSQL$NAME:Buffer Manager</c>) unchanged.
    ///
    /// Every failure mode degrades to a missing value rather than an exception:
    /// counter availability has not been observed on a real SQL host, so the list in
    /// docs/sql-collector-expansion.md is treated as a hypothesis, not a contract.
    /// </summary>
    internal static class SqlServerCounterReader
    {
        /// <summary>
        /// Samples every instance twice, with a single shared delay in between, so
        /// the sampling window costs the same no matter how many instances exist.
        /// </summary>
        public static async Task<IList<SqlCounterReading>> ReadAsync(
            IEnumerable<string> instanceNames,
            int sampleMs,
            CancellationToken cancellationToken)
        {
            var results = new List<SqlCounterReading>();
            var handles = new List<CounterHandle>();
            var objectCache = new Dictionary<string, bool>(StringComparer.OrdinalIgnoreCase);

            try
            {
                foreach (var instanceName in instanceNames ?? Array.Empty<string>())
                {
                    cancellationToken.ThrowIfCancellationRequested();

                    var reading = new SqlCounterReading { Instance = instanceName };
                    results.Add(reading);

                    foreach (var spec in SqlServerCounterPolicy.Specs)
                    {
                        reading.Values[spec.Key] = SqlServerCounterPolicy.Unavailable;

                        var objectName = SqlServerCounterPolicy.ObjectName(instanceName, spec);
                        if (!ObjectExists(objectName, objectCache))
                        {
                            if (!spec.Optional)
                            {
                                reading.MissingCount++;
                            }

                            continue;
                        }

                        var counter = Open(objectName, spec);
                        if (counter == null)
                        {
                            if (!spec.Optional)
                            {
                                reading.MissingCount++;
                            }

                            continue;
                        }

                        handles.Add(new CounterHandle { Reading = reading, Spec = spec, Counter = counter });
                    }
                }

                if (handles.Count == 0)
                {
                    return results;
                }

                // First pass primes the rate counters, which have no value until a
                // second sample gives them an interval to divide by.
                var needsSecondPass = false;
                foreach (var handle in handles)
                {
                    cancellationToken.ThrowIfCancellationRequested();
                    handle.First = Sample(handle);
                    if (handle.First.HasValue && handle.Spec.IsRate)
                    {
                        needsSecondPass = true;
                    }
                }

                if (needsSecondPass)
                {
                    await Task.Delay(sampleMs, cancellationToken).ConfigureAwait(false);
                }

                foreach (var handle in handles)
                {
                    cancellationToken.ThrowIfCancellationRequested();

                    double? value;
                    if (handle.Spec.IsRate)
                    {
                        value = needsSecondPass ? Sample(handle) : null;
                    }
                    else
                    {
                        // A second read of a level counter is simply the fresher one;
                        // fall back to the first if the counter disappeared in between.
                        value = Sample(handle) ?? handle.First;
                    }

                    if (value.HasValue)
                    {
                        handle.Reading.Values[handle.Spec.Key] = value.Value;
                        handle.Reading.ReadCount++;
                        if (handle.Spec.Scope == SqlCounterScope.Engine)
                        {
                            handle.Reading.EngineCountersAvailable = true;
                        }
                        else
                        {
                            handle.Reading.AgentCountersAvailable = true;
                        }
                    }
                    else if (!handle.Spec.Optional)
                    {
                        handle.Reading.MissingCount++;
                    }
                }

                return results;
            }
            finally
            {
                foreach (var handle in handles)
                {
                    try
                    {
                        handle.Counter.Dispose();
                    }
                    catch (Exception ex) when (IsCounterFailure(ex))
                    {
                    }
                }
            }
        }

        private static bool ObjectExists(string objectName, IDictionary<string, bool> cache)
        {
            if (string.IsNullOrEmpty(objectName))
            {
                return false;
            }

            bool exists;
            if (cache.TryGetValue(objectName, out exists))
            {
                return exists;
            }

            try
            {
                exists = PerformanceCounterCategory.Exists(objectName);
            }
            catch (Exception ex) when (IsCounterFailure(ex))
            {
                exists = false;
            }

            cache[objectName] = exists;
            return exists;
        }

        private static PerformanceCounter Open(string objectName, SqlCounterSpec spec)
        {
            try
            {
                return new PerformanceCounter(objectName, spec.CounterName, spec.CounterInstance, true);
            }
            catch (Exception ex) when (IsCounterFailure(ex))
            {
                return null;
            }
        }

        private static double? Sample(CounterHandle handle)
        {
            try
            {
                return handle.Counter.NextValue();
            }
            catch (Exception ex) when (IsCounterFailure(ex))
            {
                return null;
            }
        }

        /// <summary>
        /// Every way a performance counter read goes wrong short of cancellation:
        /// absent category or counter, a corrupted counter registry, a disabled
        /// provider, or no rights to the performance data.
        /// </summary>
        private static bool IsCounterFailure(Exception ex)
        {
            return ex is InvalidOperationException
                || ex is UnauthorizedAccessException
                || ex is Win32Exception
                || ex is PlatformNotSupportedException
                || ex is NotSupportedException
                || ex is System.IO.IOException;
        }

        private sealed class CounterHandle
        {
            public SqlCounterReading Reading { get; set; }
            public SqlCounterSpec Spec { get; set; }
            public PerformanceCounter Counter { get; set; }
            public double? First { get; set; }
        }
    }
}
