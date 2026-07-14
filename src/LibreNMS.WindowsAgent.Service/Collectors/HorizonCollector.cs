using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Management;
using System.Net;
using System.Net.NetworkInformation;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class HorizonCollector : IAgentCollector
    {
        public string Name => "horizon";

        public Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var config = context.Config.Collectors.Horizon ?? new HorizonConfig();
            if (IsDisabled(config.Mode))
            {
                return Task.FromResult<IReadOnlyList<AgentSection>>(DisabledSections());
            }

            var services = config.IncludeServices
                ? ServiceInventoryReader.Read(cancellationToken).Where(IsHorizonService).ToList()
                : new List<ServiceInventoryRecord>();
            var processes = config.IncludeProcesses ? ReadProcesses(cancellationToken) : new List<ProcessRow>();
            var serverServices = services.Where(IsHorizonServerService).ToList();
            var serverProcesses = processes.Where(IsHorizonServerProcess).ToList();
            var clientDetected = services.Any(IsHorizonClientService) || processes.Any(IsHorizonClientProcess);
            var detected = serverServices.Count > 0 || serverProcesses.Count > 0;

            if (!detected && clientDetected && IsAuto(config.Mode))
            {
                return Task.FromResult<IReadOnlyList<AgentSection>>(ClientOnlySections(services, processes));
            }

            if (!detected && IsAuto(config.Mode))
            {
                return Task.FromResult<IReadOnlyList<AgentSection>>(NotDetectedSections());
            }

            var ports = config.IncludePorts ? ReadPorts(config.Ports) : new List<PortRow>();
            var certificates = config.IncludeCertificates ? ReadCertificates(context.NowUtc, config) : new List<CertificateRow>();
            var servicesNotRunning = services.Count(IsServiceIssue);
            var portsMissing = ports.Count(p => p.Port == 443 && !p.Listening);
            var expired = certificates.Count(c => c.Expired);
            var expiringCritical = certificates.Count(c => c.ExpiringCritical && !c.Expired);
            var expiring = certificates.Count(c => c.ExpiringWarning || c.ExpiringCritical || c.Expired);
            var health = HorizonHealth.Evaluate(new HorizonHealthInput
            {
                Detected = true,
                ServicesNotRunning = servicesNotRunning,
                PortsMissing = portsMissing,
                CertificatesExpired = expired,
                CertificatesExpiringCritical = expiringCritical
            });

            var sections = new List<AgentSection>
            {
                SummarySection(
                    health.State,
                    detected: 1,
                    clientDetected: clientDetected ? 1 : 0,
                    servicesTotal: services.Count,
                    servicesNotRunning: servicesNotRunning,
                    processesTotal: processes.Count,
                    portsTotal: ports.Count,
                    portsListening: ports.Count(p => p.Listening),
                    portsMissing: portsMissing,
                    certificatesTotal: certificates.Count,
                    certificatesExpired: expired,
                    certificatesExpiring: expiring,
                    healthIssues: health.HealthIssues),
                new AgentSection("windows_agent_horizon_services", services.Select(ServiceLine)),
                new AgentSection("windows_agent_horizon_processes", processes.Select(ProcessLine)),
                new AgentSection("windows_agent_horizon_ports", ports.Select(PortLine)),
                new AgentSection("windows_agent_horizon_certificates", certificates.Select(CertificateLine))
            };

            return Task.FromResult<IReadOnlyList<AgentSection>>(sections);
        }

        private static bool IsAuto(string mode)
        {
            return string.Equals(mode, "auto", StringComparison.OrdinalIgnoreCase);
        }

        private static bool IsDisabled(string mode)
        {
            return string.Equals(mode, "disabled", StringComparison.OrdinalIgnoreCase);
        }

        private static IReadOnlyList<AgentSection> DisabledSections()
        {
            return new[]
            {
                SummarySection("disabled", 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
                Empty("windows_agent_horizon_services"),
                Empty("windows_agent_horizon_processes"),
                Empty("windows_agent_horizon_ports"),
                Empty("windows_agent_horizon_certificates")
            };
        }

        private static IReadOnlyList<AgentSection> NotDetectedSections()
        {
            return new[]
            {
                SummarySection("not_detected", 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
                Empty("windows_agent_horizon_services"),
                Empty("windows_agent_horizon_processes"),
                Empty("windows_agent_horizon_ports"),
                Empty("windows_agent_horizon_certificates")
            };
        }

        private static AgentSection Empty(string name)
        {
            return new AgentSection(name, Array.Empty<string>());
        }

        private static AgentSection SummarySection(
            string state,
            int detected,
            int clientDetected,
            int servicesTotal,
            int servicesNotRunning,
            int processesTotal,
            int portsTotal,
            int portsListening,
            int portsMissing,
            int certificatesTotal,
            int certificatesExpired,
            int certificatesExpiring,
            int healthIssues)
        {
            return new AgentSection("windows_agent_horizon_summary", new[]
            {
                string.Format(
                    CultureInfo.InvariantCulture,
                    "state={0} detected={1} client_detected={2} services_total={3} services_not_running={4} processes_total={5} ports_total={6} ports_listening={7} ports_missing={8} certificates_total={9} certificates_expired={10} certificates_expiring={11} health_issues={12} evidence={13} health_scope={14} next_action={15}",
                    Kv(state),
                    detected,
                    clientDetected,
                    servicesTotal,
                    servicesNotRunning,
                    processesTotal,
                    portsTotal,
                    portsListening,
                    portsMissing,
                    certificatesTotal,
                    certificatesExpired,
                    certificatesExpiring,
                    healthIssues,
                    Kv(string.Format(CultureInfo.InvariantCulture, "services={0};ports={1};certificates={2};client={3}", servicesTotal, portsTotal, certificatesTotal, clientDetected)),
                    Kv(detected == 1 ? "scored" : "inventory"),
                    Kv(NextAction(state, detected, healthIssues, portsMissing, certificatesExpired, certificatesExpiring)))
            });
        }

        private static string NextAction(string state, int detected, int healthIssues, int portsMissing, int certificatesExpired, int certificatesExpiring)
        {
            if (IsDisabled(state))
            {
                return "Collector disabled by config.";
            }

            if (string.Equals(state, "client_only", StringComparison.OrdinalIgnoreCase))
            {
                return "No server action; Horizon Client evidence is inventory only.";
            }

            if (detected == 0)
            {
                return "No action; Horizon server evidence was not detected.";
            }

            if (portsMissing > 0)
            {
                return "Check Horizon Connection Server listeners and Windows firewall for required ports.";
            }

            if (certificatesExpired > 0 || certificatesExpiring > 0)
            {
                return "Review Horizon server certificate bindings and expiration.";
            }

            return healthIssues > 0
                ? "Review Horizon services, ports, and certificate evidence."
                : "No action; Horizon server evidence is healthy.";
        }

        private static IReadOnlyList<AgentSection> ClientOnlySections(List<ServiceInventoryRecord> services, List<ProcessRow> processes)
        {
            return new[]
            {
                SummarySection("client_only", 0, 1, services.Count, 0, processes.Count, 0, 0, 0, 0, 0, 0, 0),
                new AgentSection("windows_agent_horizon_services", services.Select(ServiceLine)),
                new AgentSection("windows_agent_horizon_processes", processes.Select(ProcessLine)),
                Empty("windows_agent_horizon_ports"),
                Empty("windows_agent_horizon_certificates")
            };
        }

        private static bool IsHorizonService(ServiceInventoryRecord service)
        {
            if (service == null)
            {
                return false;
            }

            var text = JoinText(service.Name, service.DisplayName, service.PathName);
            return IsHorizonText(text);
        }

        private static bool IsHorizonClientService(ServiceInventoryRecord service)
        {
            var text = JoinText(service?.Name, service?.DisplayName, service?.PathName);
            return ContainsAny(text, "client_service", "clientservice", "horizon client", "horizon_client", "vmware-view", "vmware view client", "omnissa horizon client");
        }

        private static bool IsHorizonServerService(ServiceInventoryRecord service)
        {
            return IsHorizonService(service) && !IsHorizonClientService(service);
        }

        private static bool IsHorizonClientProcess(ProcessRow process)
        {
            var text = JoinText(process?.Name, process?.Path);
            return ContainsAny(text, "client_service", "clientservice", "horizon client", "horizon_client", "vmware-view", "vmware view client", "omnissa horizon client");
        }

        private static bool IsHorizonServerProcess(ProcessRow process)
        {
            return IsHorizonText(JoinText(process?.Name, process?.Path)) && !IsHorizonClientProcess(process);
        }

        private static bool IsHorizonText(string text)
        {
            if (string.IsNullOrWhiteSpace(text))
            {
                return false;
            }

            return text.IndexOf("horizon", StringComparison.OrdinalIgnoreCase) >= 0
                || text.IndexOf("vmware view", StringComparison.OrdinalIgnoreCase) >= 0
                || text.IndexOf(@"vmware\vmware view", StringComparison.OrdinalIgnoreCase) >= 0
                || text.IndexOf(@"omnissa\horizon", StringComparison.OrdinalIgnoreCase) >= 0
                || text.IndexOf("omnissa horizon", StringComparison.OrdinalIgnoreCase) >= 0;
        }

        private static string Role(ServiceInventoryRecord service)
        {
            var text = JoinText(service.Name, service.DisplayName, service.PathName);
            if (ContainsAny(text, "ldap", "vdm")) return "directory";
            if (ContainsAny(text, "blast", "gateway", "pcoip")) return "gateway";
            if (ContainsAny(text, "event", "log")) return "events";
            if (ContainsAny(text, "connection", "broker")) return "connection_server";
            if (ContainsAny(text, "web", "tomcat")) return "web";
            return "horizon";
        }

        private static bool ContainsAny(string text, params string[] values)
        {
            return values.Any(value => text.IndexOf(value, StringComparison.OrdinalIgnoreCase) >= 0);
        }

        private static bool IsServiceIssue(ServiceInventoryRecord service)
        {
            if (service == null)
            {
                return false;
            }

            if (string.Equals(service.StartMode, "Disabled", StringComparison.OrdinalIgnoreCase)
                || string.Equals(service.StartMode, "Manual", StringComparison.OrdinalIgnoreCase))
            {
                return false;
            }

            return !string.Equals(service.State, "Running", StringComparison.OrdinalIgnoreCase);
        }

        private static List<ProcessRow> ReadProcesses(CancellationToken cancellationToken)
        {
            var rows = new List<ProcessRow>();
            try
            {
                foreach (var item in Wmi.Query("SELECT Name,ExecutablePath,ProcessId FROM Win32_Process"))
                {
                    cancellationToken.ThrowIfCancellationRequested();
                    var name = Wmi.StringValue(item, "Name");
                    var path = Wmi.StringValue(item, "ExecutablePath");
                    if (!IsHorizonText(JoinText(name, path)))
                    {
                        continue;
                    }

                    rows.Add(new ProcessRow
                    {
                        Name = name,
                        ProcessId = Convert.ToInt64(Wmi.UInt64Value(item, "ProcessId")),
                        Path = path
                    });
                }
            }
            catch (ManagementException)
            {
            }
            catch (UnauthorizedAccessException)
            {
            }

            return rows;
        }

        private static List<PortRow> ReadPorts(IEnumerable<int> ports)
        {
            var targets = (ports ?? Array.Empty<int>()).Distinct().OrderBy(p => p).ToList();
            var listeners = new List<IPEndPoint>();
            try
            {
                listeners.AddRange(IPGlobalProperties.GetIPGlobalProperties().GetActiveTcpListeners());
            }
            catch (NetworkInformationException)
            {
            }
            catch (UnauthorizedAccessException)
            {
            }

            return targets.Select(port =>
            {
                var addresses = listeners
                    .Where(listener => listener.Port == port)
                    .Select(listener => listener.Address.ToString())
                    .Distinct(StringComparer.OrdinalIgnoreCase)
                    .OrderBy(address => address, StringComparer.OrdinalIgnoreCase)
                    .ToList();

                return new PortRow
                {
                    Port = port,
                    Listening = addresses.Count > 0,
                    Addresses = string.Join(",", addresses)
                };
            }).ToList();
        }

        private static List<CertificateRow> ReadCertificates(DateTimeOffset nowUtc, HorizonConfig config)
        {
            var rows = new List<CertificateRow>();
            var hostNames = HostNames();
            ReadStore(rows, StoreName.My, hostNames, nowUtc, config);
            ReadStore(rows, "WebHosting", hostNames, nowUtc, config);
            return rows
                .GroupBy(row => row.Thumbprint ?? string.Empty, StringComparer.OrdinalIgnoreCase)
                .Select(group => group.First())
                .OrderBy(row => row.DaysRemaining)
                .ThenBy(row => row.Subject, StringComparer.OrdinalIgnoreCase)
                .ToList();
        }

        private static void ReadStore(List<CertificateRow> rows, StoreName storeName, List<string> hostNames, DateTimeOffset nowUtc, HorizonConfig config)
        {
            ReadStore(rows, storeName.ToString(), hostNames, nowUtc, config);
        }

        private static void ReadStore(List<CertificateRow> rows, string storeName, List<string> hostNames, DateTimeOffset nowUtc, HorizonConfig config)
        {
            try
            {
                using (var store = new X509Store(storeName, StoreLocation.LocalMachine))
                {
                    store.Open(OpenFlags.ReadOnly);
                    foreach (var cert in store.Certificates)
                    {
                        if (IsIgnoredCertificate(cert))
                        {
                            continue;
                        }

                        if (!LooksLikeHostCertificate(cert, hostNames))
                        {
                            continue;
                        }

                        var days = (int)Math.Floor((cert.NotAfter.ToUniversalTime() - nowUtc.UtcDateTime).TotalDays);
                        rows.Add(new CertificateRow
                        {
                            Store = storeName,
                            Subject = cert.Subject,
                            Issuer = cert.Issuer,
                            Thumbprint = cert.Thumbprint,
                            NotAfterUtc = cert.NotAfter.ToUniversalTime(),
                            DaysRemaining = days,
                            Expired = days < 0,
                            ExpiringCritical = days >= 0 && days <= config.CertificateCriticalDays,
                            ExpiringWarning = days >= 0 && days <= config.CertificateWarningDays,
                            HasPrivateKey = cert.HasPrivateKey
                        });
                    }
                }
            }
            catch (Exception ex) when (ex is CryptographicException || ex is UnauthorizedAccessException || ex is ArgumentException)
            {
            }
        }

        private static bool LooksLikeHostCertificate(X509Certificate2 cert, List<string> hostNames)
        {
            var text = JoinText(cert.Subject, cert.GetNameInfo(X509NameType.DnsName, false), cert.GetNameInfo(X509NameType.SimpleName, false));
            return hostNames.Any(hostName => text.IndexOf(hostName, StringComparison.OrdinalIgnoreCase) >= 0);
        }

        private static bool IsIgnoredCertificate(X509Certificate2 cert)
        {
            var text = JoinText(cert.Subject, cert.Issuer, cert.GetNameInfo(X509NameType.SimpleName, false));
            return ContainsAny(
                text,
                "MS-Organization-P2P-Access",
                "MS-Organization-Access",
                "Broker-SSO",
                "Broker-RESTAuth");
        }

        private static List<string> HostNames()
        {
            var names = new List<string>();
            AddName(names, Environment.MachineName);
            try
            {
                AddName(names, Dns.GetHostName());
            }
            catch (System.Net.Sockets.SocketException)
            {
            }

            return names;
        }

        private static void AddName(List<string> names, string value)
        {
            if (string.IsNullOrWhiteSpace(value))
            {
                return;
            }

            names.Add(value);
            var dot = value.IndexOf('.');
            if (dot > 0)
            {
                names.Add(value.Substring(0, dot));
            }
        }

        private static string ServiceLine(ServiceInventoryRecord service)
        {
            return string.Format(
                CultureInfo.InvariantCulture,
                "name={0} display={1} state={2} start_mode={3} role={4} path={5}",
                Kv(service.Name),
                Kv(service.DisplayName),
                Kv(service.State),
                Kv(service.StartMode),
                Kv(Role(service)),
                Kv(ServiceCommandLine.RedactPath(service.PathName)));
        }

        private static string ProcessLine(ProcessRow process)
        {
            return string.Format(
                CultureInfo.InvariantCulture,
                "name={0} pid={1} path={2}",
                Kv(process.Name),
                process.ProcessId,
                Kv(process.Path));
        }

        private static string PortLine(PortRow port)
        {
            return string.Format(
                CultureInfo.InvariantCulture,
                "port={0} listening={1} addresses={2}",
                port.Port,
                port.Listening ? 1 : 0,
                Kv(port.Addresses));
        }

        private static string CertificateLine(CertificateRow cert)
        {
            return string.Format(
                CultureInfo.InvariantCulture,
                "store={0} subject={1} issuer={2} thumbprint={3} not_after_utc={4} days_remaining={5} expired={6} expiring_warning={7} expiring_critical={8} has_private_key={9}",
                Kv(cert.Store),
                Kv(cert.Subject),
                Kv(cert.Issuer),
                Kv(cert.Thumbprint),
                Kv(cert.NotAfterUtc.ToString("o", CultureInfo.InvariantCulture)),
                cert.DaysRemaining,
                cert.Expired ? 1 : 0,
                cert.ExpiringWarning ? 1 : 0,
                cert.ExpiringCritical ? 1 : 0,
                cert.HasPrivateKey ? 1 : 0);
        }

        private static string JoinText(params string[] values)
        {
            return string.Join(" ", values.Where(value => !string.IsNullOrWhiteSpace(value)));
        }

        private static string Kv(string value)
        {
            if (string.IsNullOrEmpty(value))
            {
                return "\"\"";
            }

            if (value.IndexOfAny(new[] { ' ', '\t', '"', '=', '\\' }) < 0)
            {
                return value;
            }

            return "\"" + value.Replace("\\", "\\\\").Replace("\"", "\\\"") + "\"";
        }

        private sealed class ProcessRow
        {
            public string Name { get; set; } = string.Empty;
            public long ProcessId { get; set; }
            public string Path { get; set; } = string.Empty;
        }

        private sealed class PortRow
        {
            public int Port { get; set; }
            public bool Listening { get; set; }
            public string Addresses { get; set; } = string.Empty;
        }

        private sealed class CertificateRow
        {
            public string Store { get; set; } = string.Empty;
            public string Subject { get; set; } = string.Empty;
            public string Issuer { get; set; } = string.Empty;
            public string Thumbprint { get; set; } = string.Empty;
            public DateTime NotAfterUtc { get; set; }
            public int DaysRemaining { get; set; }
            public bool Expired { get; set; }
            public bool ExpiringWarning { get; set; }
            public bool ExpiringCritical { get; set; }
            public bool HasPrivateKey { get; set; }
        }
    }
}
