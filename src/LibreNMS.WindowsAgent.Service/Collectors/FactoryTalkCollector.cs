using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Management;
using System.Net;
using System.Net.NetworkInformation;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;
using Microsoft.Win32;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class FactoryTalkCollector : CollectorBase
    {
        public override string Name => "factorytalk";

        public override Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var config = context.Config.Collectors.FactoryTalk ?? new FactoryTalkConfig();
            if (IsDisabled(config.Mode))
            {
                return Task.FromResult(DisabledSections());
            }

            var products = config.IncludeProducts ? ReadProducts() : new List<ProductRow>();
            var services = config.IncludeServices
                ? ServiceInventoryReader.Read(cancellationToken).Where(IsFactoryTalkService).OrderBy(service => service.Name, StringComparer.OrdinalIgnoreCase).ToList()
                : new List<ServiceInventoryRecord>();
            var processes = config.IncludeProcesses ? ReadProcesses(cancellationToken) : new List<ProcessRow>();
            var detected = products.Count > 0 || services.Count > 0 || processes.Count > 0;

            if (!detected && IsAuto(config.Mode))
            {
                return Task.FromResult(NotDetectedSections());
            }

            var ports = config.IncludePorts ? ReadPorts(config.Ports) : new List<PortRow>();
            var servicesNotRunning = services.Count(IsServiceNotRunning);
            var coreServicesNotRunning = services.Count(IsCoreServiceIssue);
            var portsListening = ports.Count(port => port.Listening);
            var health = FactoryTalkHealth.Evaluate(new FactoryTalkHealthInput
            {
                Detected = detected,
                CoreServicesNotRunning = coreServicesNotRunning,
                PortsMissing = 0
            });

            return Complete(
                SummarySection(
                    health.State,
                    detected ? 1 : 0,
                    products.Count,
                    services.Count,
                    servicesNotRunning,
                    coreServicesNotRunning,
                    processes.Count,
                    ports.Count,
                    portsListening,
                    health.HealthIssues),
                new AgentSection("windows_agent_factorytalk_products", products.Select(ProductLine)),
                new AgentSection("windows_agent_factorytalk_services", services.Select(ServiceLine)),
                new AgentSection("windows_agent_factorytalk_processes", processes.Select(ProcessLine)),
                new AgentSection("windows_agent_factorytalk_ports", ports.Select(PortLine)));
        }

        private static IReadOnlyList<AgentSection> DisabledSections()
        {
            return new[]
            {
                SummarySection("disabled", 0, 0, 0, 0, 0, 0, 0, 0, 0),
                Empty("windows_agent_factorytalk_products"),
                Empty("windows_agent_factorytalk_services"),
                Empty("windows_agent_factorytalk_processes"),
                Empty("windows_agent_factorytalk_ports")
            };
        }

        private static IReadOnlyList<AgentSection> NotDetectedSections()
        {
            return new[]
            {
                SummarySection("not_detected", 0, 0, 0, 0, 0, 0, 0, 0, 0),
                Empty("windows_agent_factorytalk_products"),
                Empty("windows_agent_factorytalk_services"),
                Empty("windows_agent_factorytalk_processes"),
                Empty("windows_agent_factorytalk_ports")
            };
        }

        private static AgentSection Empty(string name)
        {
            return new AgentSection(name, Array.Empty<string>());
        }

        private static AgentSection SummarySection(
            string state,
            int detected,
            int productsTotal,
            int servicesTotal,
            int servicesNotRunning,
            int coreServicesNotRunning,
            int processesTotal,
            int portsTotal,
            int portsListening,
            int healthIssues)
        {
            return new AgentSection("windows_agent_factorytalk_summary", new[]
            {
                string.Format(
                    CultureInfo.InvariantCulture,
                    "state={0} detected={1} products_total={2} services_total={3} services_not_running={4} core_services_not_running={5} processes_total={6} ports_total={7} ports_listening={8} health_issues={9} evidence={10} health_scope={11} next_action={12}",
                    Kv(state),
                    detected,
                    productsTotal,
                    servicesTotal,
                    servicesNotRunning,
                    coreServicesNotRunning,
                    processesTotal,
                    portsTotal,
                    portsListening,
                    healthIssues,
                    Kv(string.Format(CultureInfo.InvariantCulture, "products={0};services={1};processes={2};ports={3}", productsTotal, servicesTotal, processesTotal, portsTotal)),
                    Kv(detected == 1 ? "scored" : "inventory"),
                    Kv(NextAction(state, detected, healthIssues, coreServicesNotRunning)))
            });
        }

        private static string NextAction(string state, int detected, int healthIssues, int coreServicesNotRunning)
        {
            if (IsDisabled(state))
            {
                return "Collector disabled by config.";
            }

            if (detected == 0)
            {
                return "No action; FactoryTalk or Rockwell evidence was not detected.";
            }

            if (coreServicesNotRunning > 0)
            {
                return "Check FactoryTalk/Rockwell core services, activation/licensing, and recent application events.";
            }

            return healthIssues > 0
                ? "Review FactoryTalk service, process, and port evidence."
                : "No action; FactoryTalk evidence is healthy.";
        }

        private static List<ProductRow> ReadProducts()
        {
            var rows = new List<ProductRow>();
            ReadProductsFromView(rows, RegistryView.Registry64);
            ReadProductsFromView(rows, RegistryView.Registry32);

            return rows
                .GroupBy(row => row.Name + "|" + row.Version, StringComparer.OrdinalIgnoreCase)
                .Select(group => group.First())
                .OrderBy(row => row.Name, StringComparer.OrdinalIgnoreCase)
                .ToList();
        }

        private static void ReadProductsFromView(List<ProductRow> rows, RegistryView view)
        {
            try
            {
                using (var baseKey = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, view))
                using (var uninstall = baseKey.OpenSubKey(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall"))
                {
                    if (uninstall == null)
                    {
                        return;
                    }

                    foreach (var subKeyName in uninstall.GetSubKeyNames())
                    {
                        using (var subKey = uninstall.OpenSubKey(subKeyName))
                        {
                            var name = (subKey?.GetValue("DisplayName") as string ?? string.Empty).Trim();
                            var publisher = (subKey?.GetValue("Publisher") as string ?? string.Empty).Trim();
                            var location = (subKey?.GetValue("InstallLocation") as string ?? string.Empty).Trim();
                            if (!IsFactoryTalkText(JoinText(name, publisher, location)))
                            {
                                continue;
                            }

                            rows.Add(new ProductRow
                            {
                                Name = name,
                                Version = (subKey?.GetValue("DisplayVersion") as string ?? string.Empty).Trim(),
                                Publisher = publisher,
                                InstallLocation = location,
                                Role = ProductRole(name)
                            });
                        }
                    }
                }
            }
            catch (Exception ex) when (ex is UnauthorizedAccessException || ex is System.Security.SecurityException || ex is IOException)
            {
            }
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
                    if (!IsFactoryTalkProcessText(JoinText(name, path)))
                    {
                        continue;
                    }

                    rows.Add(new ProcessRow
                    {
                        Name = name,
                        ProcessId = Convert.ToInt64(Wmi.UInt64Value(item, "ProcessId")),
                        Role = ComponentRole(JoinText(name, path)),
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

            return rows.OrderBy(row => row.Name, StringComparer.OrdinalIgnoreCase).ToList();
        }

        private static List<PortRow> ReadPorts(IEnumerable<int> ports)
        {
            var targets = (ports ?? Array.Empty<int>()).Distinct().OrderBy(port => port).ToList();
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
                    Name = PortName(port),
                    Port = port,
                    Listening = addresses.Count > 0,
                    Addresses = string.Join(",", addresses)
                };
            }).ToList();
        }

        private static bool IsFactoryTalkService(ServiceInventoryRecord service)
        {
            return service != null && IsFactoryTalkServiceText(JoinText(service.Name, service.DisplayName, service.PathName));
        }

        private static bool IsFactoryTalkServiceText(string text)
        {
            return IsFactoryTalkText(text) ||
                ContainsAny(text, "rslinx", "ftlinx", "ftview", "flexsvr");
        }

        private static bool IsFactoryTalkProcessText(string text)
        {
            return IsFactoryTalkText(text) ||
                ContainsAny(text, "rslinx", "ftlinx", "ftview", "flexsvr", "codemeter");
        }

        private static bool IsFactoryTalkText(string text)
        {
            return ContainsAny(text, "factorytalk", "factory talk", "rockwell automation", "rockwell software", @"rockwell software\", @"rockwell automation\");
        }

        private static bool IsCoreServiceIssue(ServiceInventoryRecord service)
        {
            return IsCoreService(service) && IsServiceNotRunning(service);
        }

        private static bool IsCoreService(ServiceInventoryRecord service)
        {
            if (service == null)
            {
                return false;
            }

            if (string.Equals(service.StartMode, "Disabled", StringComparison.OrdinalIgnoreCase) ||
                string.Equals(service.StartMode, "Manual", StringComparison.OrdinalIgnoreCase))
            {
                return false;
            }

            var role = ComponentRole(JoinText(service.Name, service.DisplayName, service.PathName));
            return role == "directory" || role == "communications" || role == "activation" || role == "alarms_events" || role == "hmi";
        }

        private static bool IsServiceNotRunning(ServiceInventoryRecord service)
        {
            return service != null && !string.Equals(service.State, "Running", StringComparison.OrdinalIgnoreCase);
        }

        private static string ProductRole(string name)
        {
            return ComponentRole(name);
        }

        private static string ComponentRole(string text)
        {
            if (ContainsAny(text, "directory")) return "directory";
            if (ContainsAny(text, "alarms", "events")) return "alarms_events";
            if (ContainsAny(text, "activation", "flexsvr", "codemeter")) return "activation";
            if (ContainsAny(text, "linx gateway", "opc")) return "gateway";
            if (ContainsAny(text, "factorytalk linx", "ftlinx", "rslinx", "rs linx")) return "communications";
            if (ContainsAny(text, "view se", "viewsite", "hmi", "ftview")) return "hmi";
            if (ContainsAny(text, "viewpoint", "web")) return "web";
            if (ContainsAny(text, "assetcentre", "asset centre")) return "asset";
            if (ContainsAny(text, "historian")) return "historian";
            return "factorytalk";
        }

        private static string PortName(int port)
        {
            if (port >= 27000 && port <= 27009) return "activation";
            if (port == 22350) return "codemeter";
            if (port == 4244) return "local_opc_server";
            if (port == 4245) return "factorytalk_linx";
            if (port == 9111) return "alarms_events";
            if (port == 44818) return "cip";
            return "factorytalk";
        }

        private static string ProductLine(ProductRow product)
        {
            return string.Format(
                CultureInfo.InvariantCulture,
                "name={0} version={1} publisher={2} role={3} install_location={4}",
                Kv(product.Name),
                Kv(product.Version),
                Kv(product.Publisher),
                Kv(product.Role),
                Kv(product.InstallLocation));
        }

        private static string ServiceLine(ServiceInventoryRecord service)
        {
            var role = ComponentRole(JoinText(service.Name, service.DisplayName, service.PathName));
            return string.Format(
                CultureInfo.InvariantCulture,
                "name={0} display={1} role={2} core={3} state={4} start_mode={5} path={6}",
                Kv(service.Name),
                Kv(service.DisplayName),
                Kv(role),
                IsCoreService(service) ? 1 : 0,
                Kv(service.State),
                Kv(service.StartMode),
                Kv(ServiceCommandLine.RedactPath(service.PathName)));
        }

        private static string ProcessLine(ProcessRow process)
        {
            return string.Format(
                CultureInfo.InvariantCulture,
                "name={0} pid={1} role={2} path={3}",
                Kv(process.Name),
                process.ProcessId,
                Kv(process.Role),
                Kv(process.Path));
        }

        private static string PortLine(PortRow port)
        {
            return string.Format(
                CultureInfo.InvariantCulture,
                "name={0} port={1} listening={2} addresses={3}",
                Kv(port.Name),
                port.Port,
                port.Listening ? 1 : 0,
                Kv(port.Addresses));
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

        private static bool ContainsAny(string text, params string[] values)
        {
            return values.Any(value => (text ?? string.Empty).IndexOf(value, StringComparison.OrdinalIgnoreCase) >= 0);
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

        private sealed class ProductRow
        {
            public string Name { get; set; } = string.Empty;
            public string Version { get; set; } = string.Empty;
            public string Publisher { get; set; } = string.Empty;
            public string Role { get; set; } = string.Empty;
            public string InstallLocation { get; set; } = string.Empty;
        }

        private sealed class ProcessRow
        {
            public string Name { get; set; } = string.Empty;
            public long ProcessId { get; set; }
            public string Role { get; set; } = string.Empty;
            public string Path { get; set; } = string.Empty;
        }

        private sealed class PortRow
        {
            public string Name { get; set; } = string.Empty;
            public int Port { get; set; }
            public bool Listening { get; set; }
            public string Addresses { get; set; } = string.Empty;
        }
    }
}
