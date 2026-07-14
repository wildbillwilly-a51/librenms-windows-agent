using System;
using System.Collections.Generic;
using System.Linq;

namespace LibreNMS.WindowsAgent.Core
{
    public static class RoleDetector
    {
        public static readonly IReadOnlyList<string> KnownRoles = new[]
        {
            "domain_controller",
            "ad_dns",
            "dhcp",
            "sql_server",
            "iis_web",
            "factorytalk",
            "dfsr",
            "backup_storage",
            "security_management",
        };

        public static IReadOnlyList<DetectedRole> Detect(IEnumerable<ServiceInventoryRecord> services)
        {
            return Detect(services, Enumerable.Empty<string>());
        }

        public static IReadOnlyList<DetectedRole> Detect(IEnumerable<ServiceInventoryRecord> services, IEnumerable<string> installedFeatures)
        {
            var names = new HashSet<string>(
                (services ?? Enumerable.Empty<ServiceInventoryRecord>()).Select(service => service.Name).Where(name => !string.IsNullOrWhiteSpace(name)),
                StringComparer.OrdinalIgnoreCase);
            var features = new HashSet<string>(
                (installedFeatures ?? Enumerable.Empty<string>()).Where(name => !string.IsNullOrWhiteSpace(name)),
                StringComparer.OrdinalIgnoreCase);

            return new[]
            {
                DomainControllerRole(names, features),
                Role("ad_dns", names, features, new[] { "DNS" }, new[] { "DNS" }, 1),
                Role("dhcp", names, features, new[] { "DHCPServer" }, new[] { "DHCP" }, 1),
                PatternRole("sql_server", names, features, new[] { "MSSQL", "MSSQL$", "SQLAgent", "SQLAgent$", "SQLBrowser", "MSSQLFDLauncher", "ReportServer" }, Array.Empty<string>(), "MSDTC"),
                Role("iis_web", names, features, new[] { "W3SVC", "WAS", "IISADMIN", "AppHostSvc" }, new[] { "Web-Server" }, 1),
                PatternRole("factorytalk", names, features, new[] { "FactoryTalk", "FTLinx", "FTView", "RSLinx", "FlexSvr" }, Array.Empty<string>(), null),
                Role("dfsr", names, features, new[] { "DFSR" }, new[] { "FS-DFS-Replication" }, 1),
                PatternRole("backup_storage", names, features, new[] { "Datto", "Veeam", "VSSProvider", "SDRSVC" }, new[] { "Windows-Server-Backup" }, null),
                PatternRole("security_management", names, features, new[] { "WinDefend", "Sense", "SNMP", "WinRM", "VMTools", "WdNisSvc", "MDCoreSvc" }, new[] { "Windows-Defender", "Windows-Defender-Features" }, null),
            };
        }

        public static bool IsDetected(IEnumerable<DetectedRole> roles, string role)
        {
            return (roles ?? Enumerable.Empty<DetectedRole>()).Any(item => item.Detected && string.Equals(item.Role, role, StringComparison.OrdinalIgnoreCase));
        }

        private static DetectedRole Role(string role, HashSet<string> names, HashSet<string> features, IReadOnlyList<string> exactNames, IReadOnlyList<string> featureNames, int highConfidenceThreshold)
        {
            var matches = exactNames.Where(names.Contains).ToList();
            var featureMatches = featureNames.Where(features.Contains).ToList();
            var detected = matches.Count > 0 || featureMatches.Count > 0;
            return new DetectedRole
            {
                Role = role,
                Detected = detected,
                Confidence = !detected ? "none" : matches.Count >= highConfidenceThreshold || featureMatches.Count > 0 ? "high" : "medium",
                Source = Source(matches, featureMatches),
            };
        }

        private static DetectedRole DomainControllerRole(HashSet<string> names, HashSet<string> features)
        {
            var exactNames = new[] { "NTDS", "Netlogon", "Kdc", "ADWS" };
            var matches = exactNames.Where(names.Contains).ToList();
            var featureMatches = new[] { "AD-Domain-Services" }.Where(features.Contains).ToList();
            var detected = matches.Contains("NTDS", StringComparer.OrdinalIgnoreCase) ||
                matches.Contains("Kdc", StringComparer.OrdinalIgnoreCase) ||
                (featureMatches.Count > 0 && (matches.Contains("Netlogon", StringComparer.OrdinalIgnoreCase) || matches.Contains("ADWS", StringComparer.OrdinalIgnoreCase)));
            return new DetectedRole
            {
                Role = "domain_controller",
                Detected = detected,
                Confidence = !detected ? "none" : matches.Count >= 3 || matches.Contains("NTDS", StringComparer.OrdinalIgnoreCase) || featureMatches.Count > 0 ? "high" : "medium",
                Source = Source(matches, featureMatches),
            };
        }

        private static DetectedRole PatternRole(string role, HashSet<string> names, HashSet<string> features, IReadOnlyList<string> prefixesOrExact, IReadOnlyList<string> featureNames, string ignoredExact)
        {
            var matches = names
                .Where(name => !string.Equals(name, ignoredExact, StringComparison.OrdinalIgnoreCase))
                .Where(name => prefixesOrExact.Any(pattern =>
                    string.Equals(name, pattern, StringComparison.OrdinalIgnoreCase) ||
                    name.StartsWith(pattern, StringComparison.OrdinalIgnoreCase)))
                .OrderBy(name => name, StringComparer.OrdinalIgnoreCase)
                .ToList();
            var featureMatches = featureNames.Where(features.Contains).ToList();
            var detected = matches.Count > 0 || featureMatches.Count > 0;

            return new DetectedRole
            {
                Role = role,
                Detected = detected,
                Confidence = !detected ? "none" : featureMatches.Count > 0 ? "high" : "medium",
                Source = Source(matches, featureMatches),
            };
        }

        private static string Source(IReadOnlyCollection<string> serviceMatches, IReadOnlyCollection<string> featureMatches)
        {
            var parts = new List<string>();
            if (serviceMatches.Count > 0)
            {
                parts.Add("service:" + string.Join(",", serviceMatches));
            }

            if (featureMatches.Count > 0)
            {
                parts.Add("feature:" + string.Join(",", featureMatches));
            }

            return string.Join(";", parts);
        }
    }

    public sealed class DetectedRole
    {
        public string Role { get; set; } = string.Empty;
        public bool Detected { get; set; }
        public string Confidence { get; set; } = string.Empty;
        public string Source { get; set; } = string.Empty;
    }
}
