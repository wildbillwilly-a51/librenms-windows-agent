using System;
using System.Collections.Generic;
using System.Linq;
using System.Text.RegularExpressions;

namespace LibreNMS.WindowsAgent.Core
{
    public static class ServiceClassifier
    {
        public const string CoreWindows = "core_windows";
        public const string DomainIdentity = "domain_identity";
        public const string Database = "database";
        public const string WebApp = "web_app";
        public const string BackupStorage = "backup_storage";
        public const string SecurityManagement = "security_management";
        public const string ExternalVendorApps = "external_vendor_apps";
        public const string ExcludedLowValue = "excluded_low_value";

        private static readonly IReadOnlyList<string> GroupOrder = new[]
        {
            CoreWindows,
            DomainIdentity,
            Database,
            WebApp,
            BackupStorage,
            SecurityManagement,
        };

        private static readonly IReadOnlyDictionary<string, ServiceGroupConfig> DefaultGroups =
            new Dictionary<string, ServiceGroupConfig>(StringComparer.OrdinalIgnoreCase)
            {
                [CoreWindows] = new ServiceGroupConfig
                {
                    Include = new List<string> { "EventLog", "Winmgmt", "RpcSs", "Schedule", "LanmanServer", "LanmanWorkstation", "W32Time", "PlugPlay" }
                },
                [DomainIdentity] = new ServiceGroupConfig
                {
                    Include = new List<string> { "NTDS", "Netlogon", "Kdc", "ADWS", "DFSR", "DNS", "DHCPServer" }
                },
                [Database] = new ServiceGroupConfig
                {
                    Include = new List<string> { "SQLBrowser", "MSDTC" },
                    Patterns = new List<string> { "MSSQL*", "SQLAgent*", "MSSQLFDLauncher*", "ReportServer*", "postgresql*", "mysql*", "mariadb*" }
                },
                [WebApp] = new ServiceGroupConfig
                {
                    Include = new List<string> { "W3SVC", "WAS", "IISADMIN", "AppHostSvc" },
                    Patterns = new List<string> { "Tomcat*", "*AppPool*", "*Worker*" }
                },
                [BackupStorage] = new ServiceGroupConfig
                {
                    Include = new List<string> { "VSS", "SDRSVC", "DFSR" },
                    Patterns = new List<string> { "Datto*", "Veeam*", "VSSProvider*" }
                },
                [SecurityManagement] = new ServiceGroupConfig
                {
                    Include = new List<string> { "WinDefend", "Sense", "SNMP", "WinRM", "VMTools" },
                    Patterns = new List<string> { "*Defender*", "*CrowdStrike*", "*Sentinel*", "*Sophos*", "*CarbonBlack*", "*Tanium*", "*N-able*", "*RMM*" }
                },
            };

        public static ServiceClassificationResult Classify(
            IEnumerable<ServiceInventoryRecord> services,
            ServiceClassificationConfig config,
            IEnumerable<string> legacyWatchedServices)
        {
            config = config ?? new ServiceClassificationConfig();
            var legacy = new HashSet<string>(
                (legacyWatchedServices ?? Enumerable.Empty<string>()).Where(value => !string.IsNullOrWhiteSpace(value)).Select(value => value.Trim()),
                StringComparer.OrdinalIgnoreCase);
            var exclude = new HashSet<string>(
                (config.Exclude ?? new List<string>()).Where(value => !string.IsNullOrWhiteSpace(value)).Select(value => value.Trim()),
                StringComparer.OrdinalIgnoreCase);
            var groups = MergeGroups(config.Groups);
            var result = new ServiceClassificationResult();

            foreach (var service in services.OrderBy(item => item.Name, StringComparer.OrdinalIgnoreCase))
            {
                if (exclude.Contains(service.Name))
                {
                    result.Excluded.Add(ClassifiedService.From(service, ExcludedLowValue, "explicit_exclude"));
                    continue;
                }

                if (legacy.Contains(service.Name))
                {
                    result.Included.Add(ClassifiedService.From(service, CoreWindows, "legacy_watchedServices"));
                    continue;
                }

                var explicitGroup = FindExplicitGroup(service, groups);
                if (explicitGroup != null)
                {
                    result.Included.Add(ClassifiedService.From(service, explicitGroup, "explicit"));
                    continue;
                }

                var patternGroup = FindPatternGroup(service, groups);
                if (patternGroup != null)
                {
                    result.Included.Add(ClassifiedService.From(service, patternGroup, "pattern"));
                    continue;
                }

                if (config.IncludeUnknownVendorServices && IsLikelyVendorService(service))
                {
                    result.Included.Add(ClassifiedService.From(service, ExternalVendorApps, "vendor"));
                }
            }

            return result;
        }

        private static Dictionary<string, ServiceGroupConfig> MergeGroups(Dictionary<string, ServiceGroupConfig> configured)
        {
            var merged = DefaultGroups.ToDictionary(
                pair => pair.Key,
                pair => new ServiceGroupConfig
                {
                    Include = new List<string>(pair.Value.Include ?? new List<string>()),
                    Patterns = new List<string>(pair.Value.Patterns ?? new List<string>()),
                },
                StringComparer.OrdinalIgnoreCase);

            foreach (var pair in configured ?? new Dictionary<string, ServiceGroupConfig>())
            {
                if (!merged.TryGetValue(pair.Key, out var group))
                {
                    group = new ServiceGroupConfig();
                    merged[pair.Key] = group;
                }

                group.Include = MergeList(group.Include, pair.Value?.Include);
                group.Patterns = MergeList(group.Patterns, pair.Value?.Patterns);
            }

            return merged;
        }

        private static List<string> MergeList(IEnumerable<string> first, IEnumerable<string> second)
        {
            return (first ?? Enumerable.Empty<string>())
                .Concat(second ?? Enumerable.Empty<string>())
                .Where(value => !string.IsNullOrWhiteSpace(value))
                .Select(value => value.Trim())
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .ToList();
        }

        private static string FindExplicitGroup(ServiceInventoryRecord service, Dictionary<string, ServiceGroupConfig> groups)
        {
            foreach (var groupName in OrderedGroupNames(groups))
            {
                if ((groups[groupName].Include ?? new List<string>()).Contains(service.Name, StringComparer.OrdinalIgnoreCase))
                {
                    return groupName;
                }
            }

            return null;
        }

        private static string FindPatternGroup(ServiceInventoryRecord service, Dictionary<string, ServiceGroupConfig> groups)
        {
            foreach (var groupName in OrderedGroupNames(groups))
            {
                foreach (var pattern in groups[groupName].Patterns ?? new List<string>())
                {
                    if (WildcardMatch(service.Name, pattern) || WildcardMatch(service.DisplayName, pattern))
                    {
                        return groupName;
                    }
                }
            }

            return null;
        }

        private static IEnumerable<string> OrderedGroupNames(Dictionary<string, ServiceGroupConfig> groups)
        {
            foreach (var groupName in GroupOrder)
            {
                if (groups.ContainsKey(groupName))
                {
                    yield return groupName;
                }
            }

            foreach (var groupName in groups.Keys.OrderBy(name => name, StringComparer.OrdinalIgnoreCase))
            {
                if (!GroupOrder.Contains(groupName, StringComparer.OrdinalIgnoreCase))
                {
                    yield return groupName;
                }
            }
        }

        private static bool IsLikelyVendorService(ServiceInventoryRecord service)
        {
            var path = service.PathName ?? string.Empty;
            if (path.IndexOf(@"\Windows\", StringComparison.OrdinalIgnoreCase) >= 0 ||
                path.IndexOf(@"\Windows\System32\", StringComparison.OrdinalIgnoreCase) >= 0)
            {
                return false;
            }

            return path.IndexOf(@"\Program Files\", StringComparison.OrdinalIgnoreCase) >= 0 ||
                path.IndexOf(@"\Program Files (x86)\", StringComparison.OrdinalIgnoreCase) >= 0;
        }

        private static bool WildcardMatch(string value, string pattern)
        {
            if (string.IsNullOrWhiteSpace(value) || string.IsNullOrWhiteSpace(pattern))
            {
                return false;
            }

            var regex = "^" + Regex.Escape(pattern.Trim()).Replace("\\*", ".*").Replace("\\?", ".") + "$";
            return Regex.IsMatch(value, regex, RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
        }
    }

    public sealed class ServiceInventoryRecord
    {
        public string Name { get; set; } = string.Empty;
        public string DisplayName { get; set; } = string.Empty;
        public string State { get; set; } = string.Empty;
        public string StartMode { get; set; } = string.Empty;
        public string StartName { get; set; } = string.Empty;
        public string PathName { get; set; } = string.Empty;
    }

    public sealed class ClassifiedService
    {
        public string Group { get; set; } = string.Empty;
        public string Name { get; set; } = string.Empty;
        public string DisplayName { get; set; } = string.Empty;
        public string State { get; set; } = string.Empty;
        public string StartMode { get; set; } = string.Empty;
        public string StartName { get; set; } = string.Empty;
        public string PathName { get; set; } = string.Empty;
        public string Source { get; set; } = string.Empty;

        public static ClassifiedService From(ServiceInventoryRecord service, string group, string source)
        {
            return new ClassifiedService
            {
                Group = group,
                Name = service.Name,
                DisplayName = service.DisplayName,
                State = service.State,
                StartMode = service.StartMode,
                StartName = service.StartName,
                PathName = service.PathName,
                Source = source,
            };
        }
    }

    public sealed class ServiceClassificationResult
    {
        public List<ClassifiedService> Included { get; } = new List<ClassifiedService>();
        public List<ClassifiedService> Excluded { get; } = new List<ClassifiedService>();
    }
}
