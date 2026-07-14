using System;
using System.Collections.Generic;
using System.Linq;
using System.Security.Cryptography.X509Certificates;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class TlsCertificateCollector : CollectorBase
    {
        private static readonly HashSet<string> WeakSignatureOids = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
        {
            "1.2.840.113549.1.1.4", // md5RSA
            "1.2.840.113549.1.1.5", // sha1RSA
            "1.3.14.3.2.29" // sha1RSA legacy
        };

        public override string Name => "tls_certificates";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var config = context.Config.Collectors.TlsCertificates ?? new TlsCertificateConfig();
            if (IsDisabled(config.Mode))
            {
                return Complete(
                    new AgentSection("windows_agent_tls_certificates_summary", new[] { SummaryLine("disabled", 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0) }),
                    new AgentSection("windows_agent_tls_certificates", Array.Empty<string>()));
            }

            var stores = (config.Stores ?? new List<string> { "My", "WebHosting" })
                .Where(store => !string.IsNullOrWhiteSpace(store))
                .Select(store => store.Trim())
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .ToList();
            var lines = new List<string>();
            var storeCount = 0;
            var now = context.NowUtc;
            var bindingThumbprints = config.CheckHttpSysBindings
                ? ReadHttpSysCertificateThumbprints(TimeSpan.FromSeconds(5), cancellationToken)
                : new Dictionary<string, List<string>>(StringComparer.OrdinalIgnoreCase);
            var seenThumbprints = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

            foreach (var storeName in stores)
            {
                cancellationToken.ThrowIfCancellationRequested();
                try
                {
                    using (var store = new X509Store(storeName, StoreLocation.LocalMachine))
                    {
                        store.Open(OpenFlags.ReadOnly | OpenFlags.OpenExistingOnly);
                        storeCount++;
                        foreach (var cert in store.Certificates.Cast<X509Certificate2>().OrderBy(cert => cert.NotAfter).ThenBy(cert => cert.Thumbprint, StringComparer.OrdinalIgnoreCase))
                        {
                            cancellationToken.ThrowIfCancellationRequested();

                            var notAfter = new DateTimeOffset(cert.NotAfter.ToUniversalTime(), TimeSpan.Zero);
                            var daysRemaining = (int)Math.Floor((notAfter - now).TotalDays);
                            var expired = daysRemaining < 0;
                            if (expired && !config.IncludeExpired)
                            {
                                continue;
                            }

                            var notBefore = new DateTimeOffset(cert.NotBefore.ToUniversalTime(), TimeSpan.Zero);
                            var notYetValid = notBefore > now;
                            var normalizedThumbprint = NormalizeThumbprint(cert.Thumbprint);
                            if (!string.IsNullOrWhiteSpace(normalizedThumbprint))
                            {
                                seenThumbprints.Add(normalizedThumbprint);
                            }

                            var keyBits = KeySize(cert);
                            var weakKey = keyBits > 0 && keyBits < Math.Max(1, config.MinimumRsaKeySize);
                            var weakSignature = WeakSignatureOids.Contains(cert.SignatureAlgorithm?.Value ?? string.Empty);
                            var chainStatus = config.ValidateChain ? ChainStatus(cert, now) : CertificateChainStatus.NotChecked();
                            var hasPrivateKey = cert.HasPrivateKey;
                            bindingThumbprints.TryGetValue(normalizedThumbprint, out var bindingSources);
                            var bound = bindingSources != null && bindingSources.Count > 0;
                            var health = TlsCertificateHealth.Evaluate(new TlsCertificateHealthInput
                            {
                                Bound = bound,
                                Expired = expired,
                                NotYetValid = notYetValid,
                                InvalidChain = chainStatus.Valid == false,
                                WeakKey = weakKey,
                                WeakSignature = weakSignature,
                                MissingPrivateKeyForBinding = !hasPrivateKey && bound
                            });

                            lines.Add(string.Join(" ",
                                Kv("store", storeName),
                                Kv("subject", cert.Subject),
                                Kv("dns_names", string.Join(",", DnsNames(cert))),
                                Kv("issuer", cert.Issuer),
                                Kv("thumbprint", cert.Thumbprint ?? string.Empty),
                                Kv("not_before_utc", ToUtc(cert.NotBefore)),
                                Kv("not_after_utc", ToUtc(cert.NotAfter)),
                                Kv("days_remaining", daysRemaining),
                                Kv("expired", expired ? 1 : 0),
                                Kv("not_yet_valid", notYetValid ? 1 : 0),
                                Kv("expiring_warning", daysRemaining >= 0 && daysRemaining <= config.WarningDays ? 1 : 0),
                                Kv("expiring_critical", daysRemaining >= 0 && daysRemaining <= config.CriticalDays ? 1 : 0),
                                Kv("has_private_key", hasPrivateKey ? 1 : 0),
                                Kv("key_bits", keyBits),
                                Kv("weak_key", weakKey ? 1 : 0),
                                Kv("signature_algorithm", cert.SignatureAlgorithm?.FriendlyName ?? cert.SignatureAlgorithm?.Value ?? string.Empty),
                                Kv("weak_signature", weakSignature ? 1 : 0),
                                Kv("chain_valid", chainStatus.Valid ? 1 : 0),
                                Kv("chain_status", chainStatus.Status),
                                Kv("bound", bound ? 1 : 0),
                                Kv("binding_sources", bindingSources == null ? string.Empty : string.Join(",", bindingSources)),
                                Kv("health", health.Health),
                                Kv("health_scope", health.HealthScope),
                                Kv("source", "local_machine_store")));
                        }
                    }
                }
                catch
                {
                    // Missing stores such as WebHosting on non-IIS hosts are expected in auto mode.
                }
            }

            var scoredLines = lines.Where(IsScoredHealthLine).ToList();
            var expiredCount = CountFlag(scoredLines, "expired=1");
            var notYetValidCount = CountFlag(scoredLines, "not_yet_valid=1");
            var warningCount = CountFlag(scoredLines, "expiring_warning=1");
            var criticalCount = CountFlag(scoredLines, "expiring_critical=1");
            var invalidChainCount = CountFlag(scoredLines, "chain_valid=0");
            var weakKeyCount = CountFlag(scoredLines, "weak_key=1");
            var weakSignatureCount = CountFlag(scoredLines, "weak_signature=1");
            var missingPrivateKeyCount = CountFlag(scoredLines, "has_private_key=0");
            var boundCount = CountFlag(lines, "bound=1");
            var bindingMissingCount = bindingThumbprints.Keys.Count(thumbprint => !seenThumbprints.Contains(thumbprint));
            var unhealthyCount = CountUnhealthy(scoredLines);
            var state = storeCount == 0 && IsAuto(config.Mode) ? "not_detected" : "ok";

            return Complete(
                new AgentSection("windows_agent_tls_certificates_summary", new[] { SummaryLine(state, storeCount, lines.Count, expiredCount, warningCount, criticalCount, notYetValidCount, invalidChainCount, weakKeyCount, weakSignatureCount, missingPrivateKeyCount, boundCount, bindingMissingCount, unhealthyCount) }),
                new AgentSection("windows_agent_tls_certificates", lines));
        }

        private static string SummaryLine(string state, int storeCount, int certCount, int expiredCount, int warningCount, int criticalCount, int notYetValidCount, int invalidChainCount, int weakKeyCount, int weakSignatureCount, int missingPrivateKeyCount, int boundCount, int bindingMissingCount, int unhealthyCount)
        {
            return string.Join(" ",
                Kv("state", state),
                Kv("detected", certCount > 0 ? 1 : 0),
                Kv("store_count", storeCount),
                Kv("certificate_count", certCount),
                Kv("expired_count", expiredCount),
                Kv("expiring_warning_count", warningCount),
                Kv("expiring_critical_count", criticalCount),
                Kv("not_yet_valid_count", notYetValidCount),
                Kv("invalid_chain_count", invalidChainCount),
                Kv("weak_key_count", weakKeyCount),
                Kv("weak_signature_count", weakSignatureCount),
                Kv("missing_private_key_count", missingPrivateKeyCount),
                Kv("bound_count", boundCount),
                Kv("binding_missing_count", bindingMissingCount),
                Kv("unhealthy_count", unhealthyCount),
                Kv("health_issues", unhealthyCount),
                RoleEvidenceFields(
                    string.Format("certificates={0};bound={1};unhealthy={2}", certCount, boundCount, unhealthyCount),
                    certCount > 0 ? "scored" : "inventory",
                    NextAction(state, unhealthyCount, expiredCount, warningCount + criticalCount, bindingMissingCount)));
        }

        private static string NextAction(string state, int unhealthyCount, int expiredCount, int expiringCount, int bindingMissingCount)
        {
            if (IsDisabled(state))
            {
                return "Collector disabled by config.";
            }

            if (expiredCount > 0)
            {
                return "Replace expired bound or service certificates and verify HTTP.SYS bindings.";
            }

            if (bindingMissingCount > 0)
            {
                return "Check HTTP.SYS SSL bindings against LocalMachine certificate stores.";
            }

            if (expiringCount > 0)
            {
                return "Plan certificate renewal before warning or critical thresholds are reached.";
            }

            return unhealthyCount > 0
                ? "Review certificate health scope, chain, key, and private-key evidence."
                : "No action; scored certificate evidence is healthy.";
        }

        private static int CountFlag(IEnumerable<string> lines, string flag)
        {
            return lines.Count(line => line.IndexOf(flag, StringComparison.OrdinalIgnoreCase) >= 0);
        }

        private static int CountUnhealthy(IEnumerable<string> lines)
        {
            return lines.Count(line => line.IndexOf("health=ok", StringComparison.OrdinalIgnoreCase) < 0);
        }

        private static bool IsScoredHealthLine(string line)
        {
            return line.IndexOf("health_scope=scored", StringComparison.OrdinalIgnoreCase) >= 0;
        }

        private static string ToUtc(DateTime value)
        {
            return value.ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ");
        }

        private static IEnumerable<string> DnsNames(X509Certificate2 cert)
        {
            foreach (var extension in cert.Extensions.Cast<X509Extension>())
            {
                if (extension.Oid?.Value != "2.5.29.17")
                {
                    continue;
                }

                var formatted = extension.Format(false) ?? string.Empty;
                foreach (var part in formatted.Split(new[] { ", " }, StringSplitOptions.RemoveEmptyEntries))
                {
                    const string prefix = "DNS Name=";
                    if (part.StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
                    {
                        yield return part.Substring(prefix.Length);
                    }
                }
            }
        }

        private static int KeySize(X509Certificate2 cert)
        {
            try
            {
                return cert.PublicKey?.Key?.KeySize ?? 0;
            }
            catch
            {
                return 0;
            }
        }

        private static CertificateChainStatus ChainStatus(X509Certificate2 cert, DateTimeOffset now)
        {
            try
            {
                using (var chain = new X509Chain())
                {
                    chain.ChainPolicy.RevocationMode = X509RevocationMode.NoCheck;
                    chain.ChainPolicy.VerificationTime = now.UtcDateTime;
                    var valid = chain.Build(cert);
                    if (valid)
                    {
                        return CertificateChainStatus.ValidStatus();
                    }

                    var status = chain.ChainStatus == null || chain.ChainStatus.Length == 0
                        ? "unknown"
                        : string.Join(",", chain.ChainStatus.Select(item => item.Status.ToString()).Distinct(StringComparer.OrdinalIgnoreCase));
                    return CertificateChainStatus.Invalid(status);
                }
            }
            catch
            {
                return CertificateChainStatus.Invalid("check_failed");
            }
        }

        private static Dictionary<string, List<string>> ReadHttpSysCertificateThumbprints(TimeSpan timeout, CancellationToken cancellationToken)
        {
            var bindings = new Dictionary<string, List<string>>(StringComparer.OrdinalIgnoreCase);
            var result = CommandRunner.Run("netsh.exe", "http show sslcert", timeout, cancellationToken);
            if (result.State != "ok")
            {
                return bindings;
            }

            var source = string.Empty;
            foreach (var rawLine in (result.Output ?? string.Empty).Split(new[] { "\r\n", "\n" }, StringSplitOptions.None))
            {
                var line = rawLine.Trim();
                if (line.StartsWith("IP:port", StringComparison.OrdinalIgnoreCase) ||
                    line.StartsWith("Hostname:port", StringComparison.OrdinalIgnoreCase) ||
                    line.StartsWith("CCS Hostname", StringComparison.OrdinalIgnoreCase))
                {
                    source = ValueAfterColon(line);
                }
                else if (line.StartsWith("Certificate Hash", StringComparison.OrdinalIgnoreCase))
                {
                    var thumbprint = NormalizeThumbprint(ValueAfterColon(line));
                    if (string.IsNullOrWhiteSpace(thumbprint))
                    {
                        continue;
                    }

                    if (!bindings.TryGetValue(thumbprint, out var sources))
                    {
                        sources = new List<string>();
                        bindings[thumbprint] = sources;
                    }

                    if (!string.IsNullOrWhiteSpace(source) && !sources.Contains(source, StringComparer.OrdinalIgnoreCase))
                    {
                        sources.Add(source);
                    }
                }
            }

            return bindings;
        }

        private static string ValueAfterColon(string line)
        {
            var separator = line.IndexOf(" : ", StringComparison.Ordinal);
            if (separator >= 0 && separator < line.Length - 3)
            {
                return line.Substring(separator + 3).Trim();
            }

            var index = line.LastIndexOf(':');
            return index >= 0 && index < line.Length - 1 ? line.Substring(index + 1).Trim() : string.Empty;
        }

        private static string NormalizeThumbprint(string thumbprint)
        {
            return new string((thumbprint ?? string.Empty).Where(char.IsLetterOrDigit).ToArray()).ToUpperInvariant();
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

        private sealed class CertificateChainStatus
        {
            public bool Valid { get; private set; }
            public string Status { get; private set; }

            public static CertificateChainStatus ValidStatus()
            {
                return new CertificateChainStatus { Valid = true, Status = "ok" };
            }

            public static CertificateChainStatus Invalid(string status)
            {
                return new CertificateChainStatus { Valid = false, Status = string.IsNullOrWhiteSpace(status) ? "unknown" : status };
            }

            public static CertificateChainStatus NotChecked()
            {
                return new CertificateChainStatus { Valid = true, Status = "not_checked" };
            }
        }
    }
}
