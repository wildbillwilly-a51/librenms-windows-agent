using System;
using System.Collections.Generic;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class ServicesCollector : CollectorBase
    {
        public override string Name => "services";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var legacyWatched = context.Config.Collectors.WatchedServices ?? new List<string>();
            var inventory = ServiceInventoryReader.Read(cancellationToken);
            var result = ServiceClassifier.Classify(inventory, context.Config.Collectors.Services, legacyWatched);
            var includedByName = result.Included.ToDictionary(service => service.Name, service => service, StringComparer.OrdinalIgnoreCase);
            var summaryLines = BuildSummaryLines(inventory.Count, result);
            var serviceLines = result.Included
                .OrderBy(service => service.Group, StringComparer.OrdinalIgnoreCase)
                .ThenBy(service => service.Name, StringComparer.OrdinalIgnoreCase)
                .Select(ServiceLine)
                .ToList();
            var excludedLines = result.Excluded
                .OrderBy(service => service.Name, StringComparer.OrdinalIgnoreCase)
                .Select(ServiceLine)
                .ToList();
            var localLines = BuildLegacyLocalChecks(legacyWatched, includedByName);

            return Complete(
                new AgentSection("windows_agent_services_summary", summaryLines),
                new AgentSection("windows_agent_services", serviceLines),
                new AgentSection("windows_agent_services_excluded", excludedLines),
                new AgentSection("local", localLines));
        }

        private static List<string> BuildSummaryLines(int installedCount, ServiceClassificationResult result)
        {
            var lines = new List<string>
            {
                string.Join(" ",
                    Kv("installed_count", installedCount),
                    Kv("included_count", result.Included.Count),
                    Kv("excluded_count", result.Excluded.Count),
                    Kv("group_count", result.Included.Select(service => service.Group).Distinct(StringComparer.OrdinalIgnoreCase).Count()))
            };

            foreach (var group in result.Included.GroupBy(service => service.Group, StringComparer.OrdinalIgnoreCase).OrderBy(group => group.Key, StringComparer.OrdinalIgnoreCase))
            {
                lines.Add(string.Join(" ",
                    Kv("group", group.Key),
                    Kv("total", group.Count()),
                    Kv("not_running", group.Count(service => !IsRunning(service.State)))));
            }

            return lines;
        }

        private static List<string> BuildLegacyLocalChecks(IEnumerable<string> legacyWatched, IDictionary<string, ClassifiedService> includedByName)
        {
            var localLines = new List<string>();
            foreach (var name in (legacyWatched ?? Enumerable.Empty<string>()).Where(value => !string.IsNullOrWhiteSpace(value)).Select(value => value.Trim()).Distinct(StringComparer.OrdinalIgnoreCase))
            {
                if (!includedByName.TryGetValue(name, out var service))
                {
                    localLines.Add(LocalCheck.Format(
                        LocalCheckStatus.Unknown,
                        $"Windows Agent Service {name}",
                        "-",
                        "Watched service is not installed or is excluded."));
                    continue;
                }

                localLines.Add(LocalCheck.Format(
                    IsRunning(service.State) ? LocalCheckStatus.Ok : LocalCheckStatus.Critical,
                    $"Windows Agent Service {service.Name}",
                    "-",
                    $"Service {service.DisplayName} is {service.State}."));
            }

            return localLines;
        }

        private static string ServiceLine(ClassifiedService service)
        {
            var redactedPath = ServiceCommandLine.RedactPath(service.PathName);
            return string.Join(" ",
                Kv("group", service.Group),
                Kv("name", service.Name),
                Kv("display", service.DisplayName),
                Kv("state", service.State),
                Kv("start_mode", service.StartMode),
                Kv("account", service.StartName),
                Kv("path", redactedPath),
                Kv("path_redacted", string.Equals(redactedPath, service.PathName ?? string.Empty, StringComparison.Ordinal) ? 0 : 1),
                Kv("source", service.Source));
        }

        private static bool IsRunning(string state)
        {
            return string.Equals(state, "Running", StringComparison.OrdinalIgnoreCase);
        }
    }
}
