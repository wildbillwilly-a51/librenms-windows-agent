using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.NetworkInformation;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class SqlServerCollector : CollectorBase
    {
        // SQL Agent job counts run from Agent process start, so the previous reading
        // is kept to report them as deltas. The collector instance outlives a refresh.
        private readonly object _cumulativeLock = new object();
        private readonly Dictionary<string, double> _previousCumulative = new Dictionary<string, double>(StringComparer.OrdinalIgnoreCase);

        public override string Name => "sql_server";

        public override async Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var config = context.Config.Collectors.SqlServer ?? new SqlServerConfig();
            if (IsDisabled(config.Mode))
            {
                return Empty("disabled");
            }

            var services = ServiceInventoryReader.Read(cancellationToken);
            var tagged = services
                .Select(service => new TaggedService { Service = service, Identity = SqlServerPolicy.Identify(service.Name) })
                .Where(item => item.Identity != null)
                .OrderBy(item => item.Service.Name, StringComparer.OrdinalIgnoreCase)
                .ToList();

            if (tagged.Count == 0 && IsAuto(config.Mode))
            {
                return Empty("not_detected");
            }

            var registry = SqlServerRegistryReader.Read(cancellationToken);
            var listeners = ActiveListenerPorts();
            var engines = tagged.Where(item => item.Identity.Component == SqlServerPolicy.ComponentEngine).ToList();
            var agents = tagged.Where(item => item.Identity.Component == SqlServerPolicy.ComponentAgent).ToList();
            var browser = tagged.FirstOrDefault(item => item.Identity.Component == SqlServerPolicy.ComponentBrowser);

            var instances = BuildInstances(engines, agents, registry, listeners, cancellationToken);

            // The Browser is only load-bearing when a named instance is reachable
            // solely through dynamic port resolution.
            var browserRequired = instances.Any(row => row.RequiresBrowser);
            var browserState = browser == null ? "missing" : browser.Service.State;
            var browserClass = SqlServerPolicy.ClassifyBrowser(
                browserState,
                browser == null ? "Unknown" : browser.Service.StartMode,
                browserRequired);

            var components = config.IncludeComponents
                ? BuildComponents(tagged, instances, cancellationToken)
                : new List<ComponentRow>();
            var unmapped = config.IncludeProcessMapping
                ? UnmappedEngineProcesses(engines, cancellationToken)
                : 0;

            foreach (var row in instances)
            {
                row.BrowserState = browserState;
            }

            var summary = Summarize(instances, components, browserClass, browserRequired, browserState, registry.Count, unmapped);

            var performance = config.IncludeCounters && instances.Count > 0
                ? await PerformanceLinesAsync(config, context, instances, cancellationToken).ConfigureAwait(false)
                : (IList<string>)Array.Empty<string>();

            return Sections(
                new AgentSection("windows_agent_sql_server_summary", new[] { summary }),
                new AgentSection("windows_agent_sql_server_instances", instances.Select(InstanceLine).ToList()),
                new AgentSection("windows_agent_sql_server_components", components.Select(ComponentLine).ToList()),
                new AgentSection("windows_agent_sql_server_performance", performance));
        }

        private static IReadOnlyList<AgentSection> Empty(string state)
        {
            return Sections(
                new AgentSection("windows_agent_sql_server_summary", new[] { EmptySummaryLine(state) }),
                new AgentSection("windows_agent_sql_server_instances", Array.Empty<string>()),
                new AgentSection("windows_agent_sql_server_components", Array.Empty<string>()),
                new AgentSection("windows_agent_sql_server_performance", Array.Empty<string>()));
        }

        private static IReadOnlyList<AgentSection> Sections(params AgentSection[] sections)
        {
            return sections;
        }

        /// <summary>
        /// Reads server-level counters for every detected engine instance. Counter
        /// availability has not been observed on a real SQL host, so anything that
        /// goes wrong here yields a shorter row or an empty section rather than a
        /// failed collector.
        /// </summary>
        private async Task<IList<string>> PerformanceLinesAsync(
            SqlServerConfig config,
            AgentContext context,
            IList<InstanceRow> instances,
            CancellationToken cancellationToken)
        {
            // The window is paid once for the whole host, not once per instance.
            var sampleMs = SqlServerCounterPolicy.ClampSampleMs(
                config.CounterSampleMs,
                context.Config.Collectors.TimeoutSeconds);

            IList<SqlCounterReading> readings;
            try
            {
                readings = await SqlServerCounterReader
                    .ReadAsync(instances.Select(row => row.Instance), sampleMs, cancellationToken)
                    .ConfigureAwait(false);
            }
            catch (OperationCanceledException)
            {
                throw;
            }
            catch (Exception)
            {
                return Array.Empty<string>();
            }

            var lines = new List<string>();
            foreach (var reading in readings)
            {
                cancellationToken.ThrowIfCancellationRequested();
                lines.Add(PerformanceLine(config, reading));
            }

            return lines;
        }

        private string PerformanceLine(SqlServerConfig config, SqlCounterReading reading)
        {
            var health = SqlServerCounterPolicy.Evaluate(new SqlServerCounterHealthInput
            {
                CountersAvailable = reading.EngineCountersAvailable,
                MemoryGrantsPending = reading.Value("memory_grants_pending"),
                TotalServerMemoryKb = reading.Value("total_server_memory_kb"),
                TargetServerMemoryKb = reading.Value("target_server_memory_kb"),
                PageLifeExpectancy = reading.Value("page_life_expectancy"),
                ProcessesBlocked = reading.Value("processes_blocked"),
                BatchRequestsPerSec = reading.Value("batch_requests_per_sec"),
                CompilationsPerSec = reading.Value("compilations_per_sec"),
                DeadlocksPerSec = reading.Value("deadlocks_per_sec"),
                MemoryGrantsPendingWarning = config.MemoryGrantsPendingWarning,
                PageLifeExpectancyWarning = config.PageLifeExpectancyWarning,
                ProcessesBlockedWarning = config.ProcessesBlockedWarning,
                CompilationPercentWarning = config.CompilationPercentWarning,
            });

            var failedJobs = CumulativeDelta(reading.Instance, "agent_failed_jobs", reading.Value("agent_failed_jobs"));
            var successfulJobs = CumulativeDelta(reading.Instance, "agent_successful_jobs", reading.Value("agent_successful_jobs"));

            var fields = new List<string>
            {
                Kv("instance", reading.Instance),
                Kv("state", reading.EngineCountersAvailable ? health.State : "unavailable"),
                Kv("counters_read", reading.ReadCount),
                Kv("counters_missing", reading.MissingCount),
                Kv("agent_counters", reading.AgentCountersAvailable ? 1 : 0),
            };

            foreach (var spec in SqlServerCounterPolicy.Specs)
            {
                if (spec.Cumulative)
                {
                    continue;
                }

                fields.Add(Kv(spec.Key, Round(reading.Value(spec.Key))));
            }

            fields.Add(Kv("agent_failed_jobs", Round(failedJobs.Value)));
            fields.Add(Kv("agent_successful_jobs", Round(successfulJobs.Value)));
            fields.Add(Kv("agent_jobs_delta_available", failedJobs.Available ? 1 : 0));
            fields.Add(Kv("agent_jobs_reset", failedJobs.Reset || successfulJobs.Reset ? 1 : 0));

            fields.Add(Kv("memory_used_percent", Round(health.MemoryUsedPercent)));
            fields.Add(Kv("compilation_percent", Round(health.CompilationPercent)));
            fields.Add(Kv("memory_pressure", health.MemoryPressure));
            fields.Add(Kv("buffer_pressure", health.BufferPressure));
            fields.Add(Kv("blocking_pressure", health.BlockingPressure));
            fields.Add(Kv("compile_pressure", health.CompilePressure));
            fields.Add(Kv("deadlock_pressure", health.DeadlockPressure));
            fields.Add(Kv("pressure_issues", health.PressureIssues));

            return string.Join(" ", fields);
        }

        private SqlCounterDelta CumulativeDelta(string instance, string key, double current)
        {
            var cacheKey = instance + "|" + key;
            lock (_cumulativeLock)
            {
                double previous;
                var hasPrevious = _previousCumulative.TryGetValue(cacheKey, out previous);
                var delta = SqlServerCounterPolicy.ResolveDelta(hasPrevious, previous, current);

                if (current >= 0)
                {
                    _previousCumulative[cacheKey] = current;
                }
                else
                {
                    _previousCumulative.Remove(cacheKey);
                }

                return delta;
            }
        }

        private static double Round(double value)
        {
            return value < 0 ? SqlServerCounterPolicy.Unavailable : Math.Round(value, 3);
        }

        private static List<InstanceRow> BuildInstances(
            IEnumerable<TaggedService> engines,
            IList<TaggedService> agents,
            IDictionary<string, SqlInstanceRegistryInfo> registry,
            ICollection<int> listeners,
            CancellationToken cancellationToken)
        {
            var rows = new List<InstanceRow>();
            foreach (var engine in engines)
            {
                cancellationToken.ThrowIfCancellationRequested();

                var instanceName = engine.Identity.Instance;
                var agent = agents.FirstOrDefault(item =>
                    string.Equals(item.Identity.Instance, instanceName, StringComparison.OrdinalIgnoreCase));

                SqlInstanceRegistryInfo info;
                if (!registry.TryGetValue(instanceName, out info))
                {
                    info = new SqlInstanceRegistryInfo { Instance = instanceName };
                }

                // Treat a missing Tcp key as "not configured" rather than enabled,
                // so an instance with no TCP endpoint is reported as such.
                var ports = SqlServerPolicy.ResolvePorts(info.TcpPort, info.TcpDynamicPorts, info.TcpKeyPresent && info.TcpEnabled);
                var engineRunning = IsRunning(engine.Service.State);
                var executable = ServiceCommandLine.ExtractExecutablePath(engine.Service.PathName);

                rows.Add(new InstanceRow
                {
                    Instance = instanceName,
                    Service = engine.Service.Name,
                    Display = engine.Service.DisplayName,
                    State = engine.Service.State,
                    StartMode = engine.Service.StartMode,
                    ProcessId = engine.Service.ProcessId,
                    PathExists = File.Exists(executable),
                    FileVersion = FileVersion(executable),
                    Edition = info.Edition,
                    PatchLevel = info.PatchLevel,
                    ProductVersion = info.ProductVersion,
                    LoginMode = info.LoginMode,
                    TcpEnabled = info.TcpKeyPresent && info.TcpEnabled,
                    Ports = ports,
                    ListenerActive = ports.PortNumbers.Count > 0 && ports.PortNumbers.Any(listeners.Contains),
                    RequiresBrowser = SqlServerPolicy.RequiresBrowser(instanceName, ports),
                    AgentService = agent == null ? string.Empty : agent.Service.Name,
                    AgentState = agent == null ? "missing" : agent.Service.State,
                    AgentStartMode = agent == null ? string.Empty : agent.Service.StartMode,
                    AgentClassification = SqlServerPolicy.ClassifyAgent(
                        agent == null ? "Stopped" : agent.Service.State,
                        agent == null ? "Unknown" : agent.Service.StartMode,
                        engineRunning),
                    Classification = SqlServerPolicy.ClassifyEngine(engine.Service.State, engine.Service.StartMode)
                });
            }

            return rows;
        }

        private static List<ComponentRow> BuildComponents(
            IEnumerable<TaggedService> tagged,
            IList<InstanceRow> instances,
            CancellationToken cancellationToken)
        {
            var rows = new List<ComponentRow>();
            foreach (var item in tagged)
            {
                cancellationToken.ThrowIfCancellationRequested();

                var component = item.Identity.Component;
                if (component == SqlServerPolicy.ComponentEngine ||
                    component == SqlServerPolicy.ComponentAgent ||
                    component == SqlServerPolicy.ComponentBrowser)
                {
                    // Engines and agents are reported per instance; the Browser is
                    // reported in the summary because it is host-wide.
                    continue;
                }

                var instanceName = item.Identity.InstanceScoped ? item.Identity.Instance : string.Empty;
                var engineRunning = instanceName.Length == 0
                    ? instances.Any(row => IsRunning(row.State))
                    : instances.Any(row =>
                        string.Equals(row.Instance, instanceName, StringComparison.OrdinalIgnoreCase) && IsRunning(row.State));

                rows.Add(new ComponentRow
                {
                    Name = item.Service.Name,
                    Display = item.Service.DisplayName,
                    Component = component,
                    Instance = instanceName,
                    State = item.Service.State,
                    StartMode = item.Service.StartMode,
                    ProcessId = item.Service.ProcessId,
                    Classification = SqlServerPolicy.ClassifyComponent(
                        component,
                        item.Service.State,
                        item.Service.StartMode,
                        engineRunning)
                });
            }

            return rows;
        }

        /// <summary>
        /// Counts sqlservr.exe processes that no detected engine service owns.
        /// A non-zero value means instance discovery is incomplete.
        /// </summary>
        private static int UnmappedEngineProcesses(IEnumerable<TaggedService> engines, CancellationToken cancellationToken)
        {
            var owned = new HashSet<long>(engines.Select(item => item.Service.ProcessId).Where(pid => pid > 0));
            var unmapped = 0;

            try
            {
                foreach (var item in Wmi.Query("SELECT ProcessId FROM Win32_Process WHERE Name='sqlservr.exe'"))
                {
                    cancellationToken.ThrowIfCancellationRequested();
                    using (item)
                    {
                        var pid = (long)Wmi.UInt64Value(item, "ProcessId");
                        if (pid > 0 && !owned.Contains(pid))
                        {
                            unmapped++;
                        }
                    }
                }
            }
            catch (System.Management.ManagementException)
            {
                return 0;
            }
            catch (UnauthorizedAccessException)
            {
                return 0;
            }

            return unmapped;
        }

        private static string Summarize(
            IList<InstanceRow> instances,
            IList<ComponentRow> components,
            SqlClassification browserClass,
            bool browserRequired,
            string browserState,
            int registryInstances,
            int unmappedEngineProcesses)
        {
            var total = instances.Count;
            var running = instances.Count(row => IsRunning(row.State));
            var notRunning = Math.Max(0, total - running);
            var enginesExpectedDown = instances.Count(row => row.Classification.IsHealthIssue);

            var agentsTotal = instances.Count(row => row.AgentService.Length > 0);
            var agentsRunning = instances.Count(row => IsRunning(row.AgentState));
            var agentsExpectedDown = instances.Count(row => row.AgentClassification.IsHealthIssue);
            var agentsNotExpectedDown = instances.Count(row =>
                !IsRunning(row.AgentState) && !row.AgentClassification.IsHealthIssue && row.AgentService.Length > 0);

            var componentsExpectedDown = components.Count(row => row.Classification.IsHealthIssue);
            var instancesWithoutListener = instances.Count(row => IsRunning(row.State) && row.TcpEnabled && !row.ListenerActive);

            var listenerPortsTotal = instances
                .SelectMany(row => row.Ports.PortNumbers)
                .Distinct()
                .Count();

            var healthIssues = enginesExpectedDown +
                agentsExpectedDown +
                componentsExpectedDown +
                (browserClass.IsHealthIssue ? 1 : 0);

            var detected = total > 0 ? 1 : 0;
            var state = total > 0 ? "ok" : "not_detected";

            return string.Join(" ",
                Kv("state", state),
                Kv("detected", detected),
                Kv("instances_total", total),
                Kv("instances_running", running),
                Kv("instances_not_running", notRunning),
                Kv("listener_ports_total", listenerPortsTotal),
                Kv("health_issues", healthIssues),
                Kv("engines_expected_not_running", enginesExpectedDown),
                Kv("agents_total", agentsTotal),
                Kv("agents_running", agentsRunning),
                Kv("agents_expected_not_running", agentsExpectedDown),
                Kv("agents_not_expected_running", agentsNotExpectedDown),
                Kv("components_total", components.Count),
                Kv("components_expected_not_running", componentsExpectedDown),
                Kv("instances_without_listener", instancesWithoutListener),
                Kv("registry_instances", registryInstances),
                Kv("unmapped_engine_processes", unmappedEngineProcesses),
                Kv("browser_state", browserState),
                Kv("browser_required", browserRequired ? 1 : 0),
                Kv("browser_severity", browserClass.State),
                Kv("browser_reason", browserClass.ReasonCode),
                RoleEvidenceFields(
                    string.Format(
                        "instances={0};listeners={1};agents_down={2};components_down={3}",
                        total,
                        listenerPortsTotal,
                        agentsExpectedDown,
                        componentsExpectedDown),
                    detected == 1 ? "scored" : "inventory",
                    SqlServerPolicy.NextAction(
                        state,
                        enginesExpectedDown,
                        agentsExpectedDown,
                        componentsExpectedDown,
                        unmappedEngineProcesses,
                        instancesWithoutListener)));
        }

        private static string EmptySummaryLine(string state)
        {
            return string.Join(" ",
                Kv("state", state),
                Kv("detected", 0),
                Kv("instances_total", 0),
                Kv("instances_running", 0),
                Kv("instances_not_running", 0),
                Kv("listener_ports_total", 0),
                Kv("health_issues", 0),
                Kv("engines_expected_not_running", 0),
                Kv("agents_total", 0),
                Kv("agents_running", 0),
                Kv("agents_expected_not_running", 0),
                Kv("agents_not_expected_running", 0),
                Kv("components_total", 0),
                Kv("components_expected_not_running", 0),
                Kv("instances_without_listener", 0),
                Kv("registry_instances", 0),
                Kv("unmapped_engine_processes", 0),
                Kv("browser_state", "missing"),
                Kv("browser_required", 0),
                Kv("browser_severity", "info"),
                Kv("browser_reason", "browser_not_required"),
                RoleEvidenceFields(
                    "instances=0;listeners=0;agents_down=0;components_down=0",
                    "inventory",
                    SqlServerPolicy.NextAction(state, 0, 0, 0, 0, 0)));
        }

        private static string InstanceLine(InstanceRow row)
        {
            return string.Join(" ",
                Kv("instance", row.Instance),
                Kv("service", row.Service),
                Kv("display", row.Display),
                Kv("state", row.State),
                Kv("start_mode", row.StartMode),
                Kv("path_exists", row.PathExists ? 1 : 0),
                Kv("version", row.FileVersion),
                Kv("agent_service", row.AgentService),
                Kv("agent_state", row.AgentState),
                Kv("browser_state", row.BrowserState),
                Kv("listener_ports", row.Ports.Ports),
                Kv("pid", row.ProcessId),
                Kv("edition", row.Edition),
                Kv("patch_level", row.PatchLevel),
                Kv("product_version", row.ProductVersion),
                Kv("login_mode", LoginModeText(row.LoginMode)),
                Kv("tcp_enabled", row.TcpEnabled ? 1 : 0),
                Kv("port_source", row.Ports.Source),
                Kv("listener_active", row.ListenerActive ? 1 : 0),
                Kv("requires_browser", row.RequiresBrowser ? 1 : 0),
                Kv("agent_start_mode", row.AgentStartMode),
                Kv("agent_expected", row.AgentClassification.Expected ? 1 : 0),
                Kv("agent_severity", row.AgentClassification.State),
                Kv("agent_reason", row.AgentClassification.ReasonCode),
                Kv("expected", row.Classification.Expected ? 1 : 0),
                Kv("severity", row.Classification.State),
                Kv("reason", row.Classification.ReasonCode),
                Kv("impact", row.Classification.Impact));
        }

        private static string ComponentLine(ComponentRow row)
        {
            return string.Join(" ",
                Kv("name", row.Name),
                Kv("display", row.Display),
                Kv("component", row.Component),
                Kv("instance", row.Instance),
                Kv("state", row.State),
                Kv("start_mode", row.StartMode),
                Kv("pid", row.ProcessId),
                Kv("expected", row.Classification.Expected ? 1 : 0),
                Kv("severity", row.Classification.State),
                Kv("reason", row.Classification.ReasonCode),
                Kv("impact", row.Classification.Impact));
        }

        private static string LoginModeText(int loginMode)
        {
            switch (loginMode)
            {
                case 1:
                    return "windows";
                case 2:
                    return "mixed";
                default:
                    return "unknown";
            }
        }

        private static HashSet<int> ActiveListenerPorts()
        {
            var ports = new HashSet<int>();
            try
            {
                foreach (var listener in IPGlobalProperties.GetIPGlobalProperties().GetActiveTcpListeners())
                {
                    ports.Add(listener.Port);
                }
            }
            catch (NetworkInformationException)
            {
            }

            return ports;
        }

        private static bool IsRunning(string state)
        {
            return string.Equals(state, "Running", StringComparison.OrdinalIgnoreCase);
        }

        private static string FileVersion(string path)
        {
            try
            {
                return File.Exists(path) ? FileVersionInfo.GetVersionInfo(path).FileVersion ?? string.Empty : string.Empty;
            }
            catch (IOException)
            {
                return string.Empty;
            }
            catch (UnauthorizedAccessException)
            {
                return string.Empty;
            }
        }

        private static bool IsDisabled(string mode)
        {
            return string.Equals(mode, "disabled", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(mode, "off", StringComparison.OrdinalIgnoreCase);
        }

        private static bool IsAuto(string mode)
        {
            return string.IsNullOrWhiteSpace(mode) || string.Equals(mode, "auto", StringComparison.OrdinalIgnoreCase);
        }

        private sealed class TaggedService
        {
            public ServiceInventoryRecord Service { get; set; }
            public SqlServiceIdentity Identity { get; set; }
        }

        private sealed class InstanceRow
        {
            public string Instance { get; set; } = string.Empty;
            public string Service { get; set; } = string.Empty;
            public string Display { get; set; } = string.Empty;
            public string State { get; set; } = string.Empty;
            public string StartMode { get; set; } = string.Empty;
            public long ProcessId { get; set; }
            public bool PathExists { get; set; }
            public string FileVersion { get; set; } = string.Empty;
            public string Edition { get; set; } = string.Empty;
            public string PatchLevel { get; set; } = string.Empty;
            public string ProductVersion { get; set; } = string.Empty;
            public int LoginMode { get; set; }
            public bool TcpEnabled { get; set; }
            public SqlPortResolution Ports { get; set; } = new SqlPortResolution();
            public bool ListenerActive { get; set; }
            public bool RequiresBrowser { get; set; }
            public string AgentService { get; set; } = string.Empty;
            public string AgentState { get; set; } = string.Empty;
            public string AgentStartMode { get; set; } = string.Empty;
            public string BrowserState { get; set; } = "missing";
            public SqlClassification AgentClassification { get; set; } = new SqlClassification();
            public SqlClassification Classification { get; set; } = new SqlClassification();
        }

        private sealed class ComponentRow
        {
            public string Name { get; set; } = string.Empty;
            public string Display { get; set; } = string.Empty;
            public string Component { get; set; } = string.Empty;
            public string Instance { get; set; } = string.Empty;
            public string State { get; set; } = string.Empty;
            public string StartMode { get; set; } = string.Empty;
            public long ProcessId { get; set; }
            public SqlClassification Classification { get; set; } = new SqlClassification();
        }
    }
}
