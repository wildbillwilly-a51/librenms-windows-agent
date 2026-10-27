using System;
using System.Collections.Generic;
using System.Collections.ObjectModel;
using System.Linq;

namespace LibreNMS.WindowsAgent.Core
{
    /// <summary>
    /// Which performance object family a counter belongs to. The two families use
    /// different object-name prefixes for the same instance.
    /// </summary>
    public enum SqlCounterScope
    {
        Engine = 0,
        Agent = 1
    }

    /// <summary>
    /// One SQL Server performance counter the collector reads, described without
    /// any reference to PDH so the naming rules stay unit-testable.
    /// </summary>
    public sealed class SqlCounterSpec
    {
        public SqlCounterSpec(
            string key,
            SqlCounterScope scope,
            string objectSuffix,
            string counterName,
            string counterInstance = "",
            bool isRate = false,
            bool cumulative = false,
            bool optional = false)
        {
            Key = key;
            Scope = scope;
            ObjectSuffix = objectSuffix;
            CounterName = counterName;
            CounterInstance = counterInstance ?? string.Empty;
            IsRate = isRate;
            Cumulative = cumulative;
            Optional = optional;
        }

        /// <summary>Key emitted on the agent line, e.g. <c>page_life_expectancy</c>.</summary>
        public string Key { get; }

        public SqlCounterScope Scope { get; }

        /// <summary>Object name without the instance prefix, e.g. <c>Buffer Manager</c>.</summary>
        public string ObjectSuffix { get; }

        public string CounterName { get; }

        /// <summary>Counter instance within the object, e.g. <c>_Total</c>; empty for single-instance objects.</summary>
        public string CounterInstance { get; }

        /// <summary>True when the counter is a rate and needs two samples separated in time.</summary>
        public bool IsRate { get; }

        /// <summary>True when the counter counts up from process start and is reported as a delta.</summary>
        public bool Cumulative { get; }

        /// <summary>True when absence is normal rather than evidence of a problem.</summary>
        public bool Optional { get; }
    }

    /// <summary>
    /// Result of turning two readings of a cumulative counter into a delta.
    /// </summary>
    public sealed class SqlCounterDelta
    {
        /// <summary>False on the first reading, when there is nothing to subtract from.</summary>
        public bool Available { get; set; }

        public double Value { get; set; }

        /// <summary>True when the counter went backwards, which means the owning process restarted.</summary>
        public bool Reset { get; set; }
    }

    public sealed class SqlServerCounterHealthInput
    {
        /// <summary>False when no counter at all could be read for the instance.</summary>
        public bool CountersAvailable { get; set; }

        public double MemoryGrantsPending { get; set; } = -1;
        public double TotalServerMemoryKb { get; set; } = -1;
        public double TargetServerMemoryKb { get; set; } = -1;
        public double PageLifeExpectancy { get; set; } = -1;
        public double ProcessesBlocked { get; set; } = -1;
        public double BatchRequestsPerSec { get; set; } = -1;
        public double CompilationsPerSec { get; set; } = -1;
        public double DeadlocksPerSec { get; set; } = -1;

        public int MemoryGrantsPendingWarning { get; set; } = 1;
        public int PageLifeExpectancyWarning { get; set; } = 300;
        public int ProcessesBlockedWarning { get; set; } = 1;
        public int CompilationPercentWarning { get; set; } = 10;

        /// <summary>
        /// Batch rate below which the compile ratio is ignored. An idle instance can
        /// show a high compile percentage from a handful of ad-hoc batches.
        /// </summary>
        public int CompilationMinimumBatchesPerSec { get; set; } = 10;
    }

    public sealed class SqlServerCounterHealthResult
    {
        public string State { get; set; } = "unknown";
        public int MemoryPressure { get; set; }
        public int BufferPressure { get; set; }
        public int BlockingPressure { get; set; }
        public int CompilePressure { get; set; }
        public int DeadlockPressure { get; set; }
        public int PressureIssues { get; set; }

        /// <summary>Compilations as a percentage of batch requests, or -1 when not computable.</summary>
        public double CompilationPercent { get; set; } = -1;

        /// <summary>Total server memory as a percentage of target, or -1 when not computable.</summary>
        public double MemoryUsedPercent { get; set; } = -1;
    }

    /// <summary>
    /// Pure naming, delta and threshold logic for the SQL Server performance
    /// counter collector. Nothing here touches PDH; the Service project owns the
    /// I/O so this stays testable on any platform.
    /// </summary>
    public static class SqlServerCounterPolicy
    {
        /// <summary>Sentinel emitted for a counter that could not be read.</summary>
        public const double Unavailable = -1;

        private const string DefaultEnginePrefix = "SQLServer:";
        private const string NamedEnginePrefix = "MSSQL$";
        private const string DefaultAgentPrefix = "SQLAgent:";
        private const string NamedAgentPrefix = "SQLAgent$";

        public const string TotalCounterInstance = "_Total";

        public const int MinimumSampleMs = 250;
        public const int MaximumSampleMs = 3000;

        private static readonly SqlCounterSpec[] SpecList =
        {
            // Memory Manager
            new SqlCounterSpec("total_server_memory_kb", SqlCounterScope.Engine, "Memory Manager", "Total Server Memory (KB)"),
            new SqlCounterSpec("target_server_memory_kb", SqlCounterScope.Engine, "Memory Manager", "Target Server Memory (KB)"),
            new SqlCounterSpec("memory_grants_pending", SqlCounterScope.Engine, "Memory Manager", "Memory Grants Pending"),

            // Buffer Manager
            new SqlCounterSpec("page_life_expectancy", SqlCounterScope.Engine, "Buffer Manager", "Page life expectancy"),
            new SqlCounterSpec("buffer_cache_hit_ratio", SqlCounterScope.Engine, "Buffer Manager", "Buffer cache hit ratio"),
            new SqlCounterSpec("free_list_stalls_per_sec", SqlCounterScope.Engine, "Buffer Manager", "Free list stalls/sec", isRate: true),
            new SqlCounterSpec("lazy_writes_per_sec", SqlCounterScope.Engine, "Buffer Manager", "Lazy writes/sec", isRate: true),
            new SqlCounterSpec("checkpoint_pages_per_sec", SqlCounterScope.Engine, "Buffer Manager", "Checkpoint pages/sec", isRate: true),

            // General Statistics
            new SqlCounterSpec("user_connections", SqlCounterScope.Engine, "General Statistics", "User Connections"),
            new SqlCounterSpec("processes_blocked", SqlCounterScope.Engine, "General Statistics", "Processes blocked"),
            new SqlCounterSpec("logins_per_sec", SqlCounterScope.Engine, "General Statistics", "Logins/sec", isRate: true),
            new SqlCounterSpec("logouts_per_sec", SqlCounterScope.Engine, "General Statistics", "Logouts/sec", isRate: true),

            // SQL Statistics
            new SqlCounterSpec("batch_requests_per_sec", SqlCounterScope.Engine, "SQL Statistics", "Batch Requests/sec", isRate: true),
            new SqlCounterSpec("compilations_per_sec", SqlCounterScope.Engine, "SQL Statistics", "SQL Compilations/sec", isRate: true),
            new SqlCounterSpec("recompilations_per_sec", SqlCounterScope.Engine, "SQL Statistics", "SQL Re-Compilations/sec", isRate: true),

            // Locks, aggregated across lock resource types
            new SqlCounterSpec("lock_waits_per_sec", SqlCounterScope.Engine, "Locks", "Lock Waits/sec", TotalCounterInstance, isRate: true),
            new SqlCounterSpec("lock_wait_time_ms", SqlCounterScope.Engine, "Locks", "Lock Wait Time (ms)", TotalCounterInstance),
            new SqlCounterSpec("lock_average_wait_time_ms", SqlCounterScope.Engine, "Locks", "Average Wait Time (ms)", TotalCounterInstance),
            new SqlCounterSpec("deadlocks_per_sec", SqlCounterScope.Engine, "Locks", "Number of Deadlocks/sec", TotalCounterInstance, isRate: true),
            new SqlCounterSpec("lock_timeouts_per_sec", SqlCounterScope.Engine, "Locks", "Lock Timeouts/sec", TotalCounterInstance, isRate: true),

            // Access Methods
            new SqlCounterSpec("full_scans_per_sec", SqlCounterScope.Engine, "Access Methods", "Full Scans/sec", isRate: true),
            new SqlCounterSpec("page_splits_per_sec", SqlCounterScope.Engine, "Access Methods", "Page Splits/sec", isRate: true),
            new SqlCounterSpec("forwarded_records_per_sec", SqlCounterScope.Engine, "Access Methods", "Forwarded Records/sec", isRate: true),

            // Latches
            new SqlCounterSpec("latch_average_wait_time_ms", SqlCounterScope.Engine, "Latches", "Average Latch Wait Time (ms)"),

            // Transactions
            new SqlCounterSpec("longest_transaction_seconds", SqlCounterScope.Engine, "Transactions", "Longest Transaction Running Time"),
            new SqlCounterSpec("tempdb_free_space_kb", SqlCounterScope.Engine, "Transactions", "Free Space in tempdb (KB)"),
            new SqlCounterSpec("version_store_size_kb", SqlCounterScope.Engine, "Transactions", "Version Store Size (KB)"),

            // Write path, aggregated across databases
            new SqlCounterSpec("transactions_per_sec", SqlCounterScope.Engine, "Databases", "Transactions/sec", TotalCounterInstance, isRate: true),
            new SqlCounterSpec("log_growths", SqlCounterScope.Engine, "Databases", "Log Growths", TotalCounterInstance),
            new SqlCounterSpec("log_bytes_flushed_per_sec", SqlCounterScope.Engine, "Databases", "Log Bytes Flushed/sec", TotalCounterInstance, isRate: true),
            new SqlCounterSpec("log_flush_wait_time_ms", SqlCounterScope.Engine, "Databases", "Log Flush Wait Time", TotalCounterInstance),

            // Availability Groups: present only when the instance hosts an AG replica.
            new SqlCounterSpec("ag_log_send_queue_kb", SqlCounterScope.Engine, "Database Replica", "Log Send Queue", TotalCounterInstance, optional: true),
            new SqlCounterSpec("ag_redo_queue_kb", SqlCounterScope.Engine, "Database Replica", "Recovery Queue", TotalCounterInstance, optional: true),

            // SQL Agent job counts are cumulative since Agent start, so they are
            // reported as deltas. The SQLAgent object stays registered while the Agent
            // service is stopped, so ShouldRead gates these on the service state
            // instead of on whether the category exists.
            new SqlCounterSpec("agent_failed_jobs", SqlCounterScope.Agent, "Jobs", "Failed jobs", TotalCounterInstance, cumulative: true, optional: true),
            new SqlCounterSpec("agent_successful_jobs", SqlCounterScope.Agent, "Jobs", "Successful jobs", TotalCounterInstance, cumulative: true, optional: true),
            new SqlCounterSpec("agent_active_jobs", SqlCounterScope.Agent, "Jobs", "Active jobs", TotalCounterInstance, optional: true),
            new SqlCounterSpec("agent_queued_jobs", SqlCounterScope.Agent, "Jobs", "Queued jobs", TotalCounterInstance, optional: true),
            new SqlCounterSpec("agent_job_success_rate", SqlCounterScope.Agent, "Jobs", "Job success rate", TotalCounterInstance, optional: true),
        };

        private static readonly ReadOnlyCollection<SqlCounterSpec> ReadOnlySpecs =
            new ReadOnlyCollection<SqlCounterSpec>(SpecList);

        public static IList<SqlCounterSpec> Specs
        {
            get { return ReadOnlySpecs; }
        }

        /// <summary>
        /// Builds the literal performance object name for an instance. The default
        /// instance uses the unsuffixed prefix; a named instance embeds its own name,
        /// which is why PDH is used instead of the WMI classes that mangle it.
        /// </summary>
        public static string ObjectName(string instanceName, SqlCounterScope scope, string objectSuffix)
        {
            var suffix = (objectSuffix ?? string.Empty).Trim();
            if (suffix.Length == 0)
            {
                return string.Empty;
            }

            return ObjectPrefix(instanceName, scope) + suffix;
        }

        public static string ObjectName(string instanceName, SqlCounterSpec spec)
        {
            return spec == null ? string.Empty : ObjectName(instanceName, spec.Scope, spec.ObjectSuffix);
        }

        /// <summary>
        /// Object-name prefix, including the trailing colon.
        /// </summary>
        public static string ObjectPrefix(string instanceName, SqlCounterScope scope)
        {
            var instance = (instanceName ?? string.Empty).Trim();
            var isDefault = instance.Length == 0 || SqlServerPolicy.IsDefaultInstance(instance);

            if (scope == SqlCounterScope.Agent)
            {
                return isDefault ? DefaultAgentPrefix : NamedAgentPrefix + instance + ":";
            }

            return isDefault ? DefaultEnginePrefix : NamedEnginePrefix + instance + ":";
        }

        /// <summary>
        /// Distinct object names the collector needs for one instance, in the order
        /// the specs declare them. Used to probe once per object instead of once per
        /// counter.
        /// </summary>
        public static IList<string> ObjectNames(string instanceName)
        {
            var names = new List<string>();
            foreach (var spec in SpecList)
            {
                var name = ObjectName(instanceName, spec);
                if (name.Length > 0 && !names.Contains(name, StringComparer.OrdinalIgnoreCase))
                {
                    names.Add(name);
                }
            }

            return names;
        }

        /// <summary>
        /// Whether a counter should be read at all. SQL Agent counters are skipped
        /// while the instance's Agent service is not running: their category stays
        /// registered and would otherwise read as zeros that look like a healthy,
        /// idle Agent.
        /// </summary>
        public static bool ShouldRead(SqlCounterSpec spec, bool agentRunning)
        {
            if (spec == null)
            {
                return false;
            }

            return spec.Scope != SqlCounterScope.Agent || agentRunning;
        }

        /// <summary>
        /// Keeps the rate-counter sampling window inside the collector budget. The
        /// window is paid once per refresh for the whole host, but a misconfigured
        /// value must still not consume the timeout a collector is allowed.
        /// </summary>
        public static int ClampSampleMs(int requestedMs, int collectorTimeoutSeconds)
        {
            var requested = requestedMs < MinimumSampleMs
                ? MinimumSampleMs
                : (requestedMs > MaximumSampleMs ? MaximumSampleMs : requestedMs);

            var budget = Math.Max(1, collectorTimeoutSeconds) * 1000 / 4;
            var allowed = Math.Min(requested, budget);
            return allowed < MinimumSampleMs ? MinimumSampleMs : allowed;
        }

        /// <summary>
        /// Turns two readings of a counter that counts up from process start into a
        /// delta. A backwards step means the owning process restarted, so the current
        /// value is itself the delta since that restart.
        /// </summary>
        public static SqlCounterDelta ResolveDelta(bool hasPrevious, double previous, double current)
        {
            if (current < 0)
            {
                return new SqlCounterDelta { Available = false, Value = Unavailable };
            }

            if (!hasPrevious || previous < 0)
            {
                return new SqlCounterDelta { Available = false, Value = 0 };
            }

            if (current < previous)
            {
                return new SqlCounterDelta { Available = true, Value = current, Reset = true };
            }

            return new SqlCounterDelta { Available = true, Value = current - previous };
        }

        /// <summary>
        /// Maps a health state to the ordinal the overlay flattens for per-instance
        /// alert rules: higher is worse, and sentinels sit below ok so a
        /// "greater than" rule skips an instance with no counters.
        /// </summary>
        public static int StateOrdinal(string state)
        {
            switch ((state ?? string.Empty).ToLowerInvariant())
            {
                case "ok":
                    return 0;
                case "info":
                    return 1;
                case "warning":
                    return 2;
                case "critical":
                    return 3;
                case "disabled":
                    return -1;
                default:
                    return -2;
            }
        }

        public static SqlServerCounterHealthResult Evaluate(SqlServerCounterHealthInput input)
        {
            input = input ?? new SqlServerCounterHealthInput();
            var result = new SqlServerCounterHealthResult();

            if (!input.CountersAvailable)
            {
                result.State = "unknown";
                return result;
            }

            if (input.TargetServerMemoryKb > 0 && input.TotalServerMemoryKb >= 0)
            {
                result.MemoryUsedPercent = Math.Round((input.TotalServerMemoryKb * 100) / input.TargetServerMemoryKb, 2);
            }

            if (input.BatchRequestsPerSec > 0 && input.CompilationsPerSec >= 0)
            {
                result.CompilationPercent = Math.Round((input.CompilationsPerSec * 100) / input.BatchRequestsPerSec, 2);
            }

            // Queries queued waiting for workspace memory are the memory signal.
            // MemoryUsedPercent (Total vs Target) is reported for context only: an
            // idle or recently started instance sits far below its target without
            // being under pressure, so it must not raise MemoryPressure.
            if (input.MemoryGrantsPending >= Math.Max(1, input.MemoryGrantsPendingWarning))
            {
                result.MemoryPressure = 1;
                result.PressureIssues++;
            }

            if (input.PageLifeExpectancy >= 0 &&
                input.PageLifeExpectancy < Math.Max(1, input.PageLifeExpectancyWarning))
            {
                result.BufferPressure = 1;
                result.PressureIssues++;
            }

            if (input.ProcessesBlocked >= Math.Max(1, input.ProcessesBlockedWarning))
            {
                result.BlockingPressure = 1;
                result.PressureIssues++;
            }

            if (result.CompilationPercent >= 0 &&
                input.BatchRequestsPerSec >= Math.Max(1, input.CompilationMinimumBatchesPerSec) &&
                result.CompilationPercent > Math.Max(1, input.CompilationPercentWarning))
            {
                result.CompilePressure = 1;
                result.PressureIssues++;
            }

            if (input.DeadlocksPerSec > 0)
            {
                result.DeadlockPressure = 1;
                result.PressureIssues++;
            }

            result.State = result.PressureIssues > 0 ? "warning" : "ok";
            return result;
        }
    }
}
