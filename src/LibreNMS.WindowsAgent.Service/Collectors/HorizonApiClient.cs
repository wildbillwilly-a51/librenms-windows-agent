using System;
using System.Collections;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using System.Web.Script.Serialization;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class HorizonApiMetrics
    {
        public string State { get; set; } = "disabled";
        public string Reason { get; set; } = "none";
        public long DurationMs { get; set; }
        public string PodName { get; set; } = string.Empty;
        public string ClusterName { get; set; } = string.Empty;
        public int ConnectionServersTotal { get; set; }
        public int ConnectionServersUnhealthy { get; set; }
        public int ServicesUnhealthy { get; set; }
        public int ReplicationsTotal { get; set; }
        public int ReplicationsUnhealthy { get; set; }
        public int CertificatesInvalid { get; set; }
        public int HorizonDomainsTotal { get; set; }
        public int HorizonDomainLinksTotal { get; set; }
        public int HorizonDomainLinksUnhealthy { get; set; }
        public int GatewaysTotal { get; set; }
        public int GatewaysUnhealthy { get; set; }
        public int SessionsTotal { get; set; }
        public int SessionsConnected { get; set; }
        public int SessionsDisconnected { get; set; }
        public int SessionsOther { get; set; }
        public int SessionPages { get; set; }
        public bool SessionsTruncated { get; set; }
        public int MachinePages { get; set; }
        public bool MachinesTruncated { get; set; }
        public int ClonePoolsTotal { get; set; }
        public int ClonePoolsHealthy { get; set; }
        public int ClonePoolsWarning { get; set; }
        public int ClonePoolsCritical { get; set; }
        public int ClonePoolsIncomplete { get; set; }
        public int ClonePoolsDisabled { get; set; }
        public int SpareMachinesTotal { get; set; }
        public int SpareMachinesReady { get; set; }
        public int SpareMachinesUnready { get; set; }
        public IList<string> EndpointFailures { get; } = new List<string>();
        public IList<HorizonConnectionServerMetric> ConnectionServers { get; } = new List<HorizonConnectionServerMetric>();
        public IList<HorizonReplicationMetric> Replications { get; } = new List<HorizonReplicationMetric>();
        public IList<HorizonDomainMetric> Domains { get; } = new List<HorizonDomainMetric>();
        public IList<HorizonDomainMemberMetric> DomainMembers { get; } = new List<HorizonDomainMemberMetric>();
        public IList<HorizonGatewayMetric> Gateways { get; } = new List<HorizonGatewayMetric>();
        public IList<HorizonPoolMetric> Pools { get; } = new List<HorizonPoolMetric>();
        public IDictionary<string, int> SessionProtocols { get; } = new SortedDictionary<string, int>(StringComparer.OrdinalIgnoreCase);
    }

    internal sealed class HorizonConnectionServerMetric
    {
        public string Id { get; set; } = string.Empty;
        public string Name { get; set; } = string.Empty;
        public string Status { get; set; } = "UNKNOWN";
        public string Version { get; set; } = string.Empty;
        public string ServerType { get; set; } = "connection_server";
        public string GatewayMode { get; set; } = "none";
        public bool Enabled { get; set; } = true;
        public bool LocalApiTarget { get; set; }
        public int Connections { get; set; }
        public int ServicesUnhealthy { get; set; }
        public int ReplicationsTotal { get; set; }
        public int ReplicationsUnhealthy { get; set; }
        public bool CertificateValid { get; set; } = true;
    }

    internal sealed class HorizonReplicationMetric
    {
        public string Source { get; set; } = string.Empty;
        public string Target { get; set; } = string.Empty;
        public string Status { get; set; } = "UNKNOWN";
    }

    internal sealed class HorizonDomainMetric
    {
        public string DnsName { get; set; } = string.Empty;
        public string NetbiosName { get; set; } = string.Empty;
        public string DomainType { get; set; } = string.Empty;
        public int MembersTotal { get; set; }
        public int MembersUnhealthy { get; set; }
        public int ServiceAccountsActive { get; set; }
        public int ServiceAccountsUnhealthy { get; set; }
    }

    internal sealed class HorizonGatewayMetric
    {
        public string Name { get; set; } = string.Empty;
        public string Type { get; set; } = string.Empty;
        public string Status { get; set; } = "UNKNOWN";
        public string Version { get; set; } = string.Empty;
        public int ActiveConnections { get; set; }
    }

    internal sealed class HorizonDomainMemberMetric
    {
        public string Domain { get; set; } = string.Empty;
        public string Member { get; set; } = string.Empty;
        public string Status { get; set; } = "UNKNOWN";
        public string TrustRelationship { get; set; } = string.Empty;
    }

    internal sealed class HorizonPoolMetric
    {
        public string Id { get; set; } = string.Empty;
        public string Name { get; set; } = string.Empty;
        public string DisplayName { get; set; } = string.Empty;
        public string Source { get; set; } = string.Empty;
        public bool Enabled { get; set; } = true;
        public string HealthState { get; set; } = "incomplete";
        public string HealthReason { get; set; } = "not_scored";
        public int MachinesTotal { get; set; }
        public int MachinesWithSessions { get; set; }
        public int SpareTotal { get; set; }
        public int SpareReady { get; set; }
        public int SpareUnready { get; set; }
        public int SpareMaintenance { get; set; }
        public decimal SpareUnreadyPercent { get; set; }
        public IDictionary<string, int> MachineStates { get; } = new SortedDictionary<string, int>(StringComparer.OrdinalIgnoreCase);
    }

    internal static class HorizonApiClient
    {
        private const int LoginResponseMaxBytes = 1024 * 1024;
        private const int TelemetryResponseMaxBytes = 16 * 1024 * 1024;

        public static async Task<HorizonApiMetrics> CollectAsync(HorizonApiConfig config, CancellationToken cancellationToken)
        {
            var metrics = new HorizonApiMetrics();
            if (config == null || !string.Equals(config.Mode, "enabled", StringComparison.OrdinalIgnoreCase))
            {
                return metrics;
            }

            metrics.State = "unconfigured";
            if (!Uri.TryCreate(config.BaseUrl, UriKind.Absolute, out var baseUri) ||
                !string.Equals(baseUri.Scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase))
            {
                metrics.Reason = "https_base_url_required";
                return metrics;
            }

            var credentialPath = HorizonApiCredentialStore.ResolvePath(config.CredentialFile);
            if (!File.Exists(credentialPath))
            {
                metrics.Reason = "credential_file_missing";
                return metrics;
            }

            var timer = Stopwatch.StartNew();
            try
            {
                var credential = HorizonApiCredentialStore.Read(credentialPath);
                using (var apiTimeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken))
                using (var handler = new HttpClientHandler { AllowAutoRedirect = false })
                using (var client = new HttpClient(handler) { BaseAddress = EnsureTrailingSlash(baseUri), Timeout = Timeout.InfiniteTimeSpan })
                {
                    apiTimeout.CancelAfter(TimeSpan.FromSeconds(config.TimeoutSeconds));
                    var requestCancellation = apiTimeout.Token;
                    client.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
                    var bearerValue = await LoginAsync(client, credential, requestCancellation).ConfigureAwait(false);
                    client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", bearerValue);

                    await CollectEnvironmentAsync(client, metrics, requestCancellation).ConfigureAwait(false);
                    if (config.IncludeConnectionServers)
                    {
                        await CollectConnectionServersAsync(client, metrics, requestCancellation).ConfigureAwait(false);
                    }
                    if (config.IncludeHorizonDomains)
                    {
                        await CollectDomainsAsync(client, metrics, requestCancellation).ConfigureAwait(false);
                    }
                    if (config.IncludeGateways)
                    {
                        await CollectGatewaysAsync(client, metrics, requestCancellation).ConfigureAwait(false);
                    }

                    var sessionMachineIds = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
                    if (config.IncludeSessions || config.IncludeClonePools)
                    {
                        await AggregateSessionsAsync(client, config, metrics, sessionMachineIds, requestCancellation).ConfigureAwait(false);
                    }
                    if (config.IncludeClonePools)
                    {
                        await CollectClonePoolsAsync(client, config, metrics, sessionMachineIds, requestCancellation).ConfigureAwait(false);
                    }
                }

                metrics.State = metrics.EndpointFailures.Count == 0 ? "ok" : "partial";
                metrics.Reason = metrics.EndpointFailures.Count == 0 ? "none" : string.Join(",", metrics.EndpointFailures);
            }
            catch (OperationCanceledException) when (!cancellationToken.IsCancellationRequested)
            {
                metrics.State = "unavailable";
                metrics.Reason = "timeout";
            }
            catch (Exception ex) when (ex is HttpRequestException || ex is InvalidOperationException || ex is FormatException || ex is IOException || ex is UnauthorizedAccessException || ex is System.Security.Cryptography.CryptographicException)
            {
                metrics.State = "unavailable";
                metrics.Reason = ex.GetType().Name;
            }
            finally
            {
                timer.Stop();
                metrics.DurationMs = timer.ElapsedMilliseconds;
            }

            return metrics;
        }

        private static async Task CollectEnvironmentAsync(HttpClient client, HorizonApiMetrics metrics, CancellationToken token)
        {
            var json = await TryGetAsync(client, "rest/config/v1/environment-properties", "environment", metrics, token).ConfigureAwait(false);
            if (json == null) return;
            var row = DeserializeObject(json) as IDictionary<string, object>;
            metrics.PodName = StringValue(row, "local_pod_name");
            metrics.ClusterName = StringValue(row, "cluster_name");
        }

        private static async Task CollectConnectionServersAsync(HttpClient client, HorizonApiMetrics metrics, CancellationToken token)
        {
            var monitorJson = await TryGetAsync(client, "rest/monitor/v3/connection-servers", "connection_server_monitor", metrics, token).ConfigureAwait(false);
            if (monitorJson != null) AggregateConnectionServers(monitorJson, metrics);

            var configJson = await TryGetAsync(client, "rest/config/v2/connection-servers", "connection_server_config", metrics, token).ConfigureAwait(false);
            if (configJson == null) return;
            foreach (var row in AsObjectList(DeserializeObject(configJson)))
            {
                var member = FindConnectionServer(metrics, StringValue(row, "id"), StringValue(row, "name"));
                if (member == null)
                {
                    member = new HorizonConnectionServerMetric { Id = StringValue(row, "id"), Name = StringValue(row, "name") };
                    metrics.ConnectionServers.Add(member);
                    metrics.ConnectionServersTotal++;
                }
                member.Enabled = BooleanValue(row, "enabled", true);
                member.LocalApiTarget = BooleanValue(row, "local_connection_server", false);
                member.Version = FirstNonEmpty(member.Version, StringValue(row, "version"));
                var gateways = new List<string>();
                if (!BooleanValue(row, "bypass_tunnel", true)) gateways.Add("tunnel");
                if (!BooleanValue(row, "bypass_pcoip_gateway", true)) gateways.Add("pcoip");
                if (!BooleanValue(row, "bypass_app_blast_gateway", true)) gateways.Add("blast");
                member.GatewayMode = gateways.Count == 0 ? "none" : string.Join(",", gateways);
                member.ServerType = gateways.Count == 0 ? "connection_server" : "connection_server_with_embedded_gateway";
                if (string.IsNullOrWhiteSpace(metrics.ClusterName)) metrics.ClusterName = StringValue(row, "cluster_name");
            }
        }

        private static async Task CollectDomainsAsync(HttpClient client, HorizonApiMetrics metrics, CancellationToken token)
        {
            var json = await TryGetAsync(client, "rest/monitor/v3/ad-domains", "horizon_domain_monitor", metrics, token).ConfigureAwait(false);
            if (json == null) return;
            foreach (var row in AsObjectList(DeserializeObject(json)))
            {
                var domain = new HorizonDomainMetric
                {
                    DnsName = StringValue(row, "dns_name"),
                    NetbiosName = StringValue(row, "netbios_name"),
                    DomainType = StringValue(row, "domain_type")
                };
                foreach (var member in AsObjectList(Value(row, "connection_servers")))
                {
                    domain.MembersTotal++;
                    metrics.HorizonDomainLinksTotal++;
                    var memberStatus = NormalizeStatus(StringValue(member, "status"));
                    metrics.DomainMembers.Add(new HorizonDomainMemberMetric
                    {
                        Domain = FirstNonEmpty(domain.DnsName, domain.NetbiosName),
                        Member = StringValue(member, "name"),
                        Status = memberStatus,
                        TrustRelationship = NormalizeStatus(StringValue(member, "trust_relationship"))
                    });
                    if (!IsDomainAccessHealthy(memberStatus))
                    {
                        domain.MembersUnhealthy++;
                        metrics.HorizonDomainLinksUnhealthy++;
                    }
                }
                foreach (var account in AsObjectList(Value(row, "service_accounts")))
                {
                    if (string.Equals(StringValue(account, "status"), "ACTIVE", StringComparison.OrdinalIgnoreCase)) domain.ServiceAccountsActive++;
                    else domain.ServiceAccountsUnhealthy++;
                }
                metrics.Domains.Add(domain);
                metrics.HorizonDomainsTotal++;
            }
        }

        private static async Task CollectGatewaysAsync(HttpClient client, HorizonApiMetrics metrics, CancellationToken token)
        {
            var json = await TryGetAsync(client, "rest/monitor/v3/gateways", "gateway_monitor", metrics, token).ConfigureAwait(false);
            if (json == null) return;
            foreach (var row in AsObjectList(DeserializeObject(json)))
            {
                var details = Value(row, "details") as IDictionary<string, object>;
                var gateway = new HorizonGatewayMetric
                {
                    Name = StringValue(row, "name"),
                    Type = StringValue(details, "type"),
                    Version = StringValue(details, "version"),
                    Status = NormalizeStatus(StringValue(row, "status")),
                    ActiveConnections = IntegerValue(row, "active_connection_count")
                };
                metrics.Gateways.Add(gateway);
                metrics.GatewaysTotal++;
                if (!IsHealthyStatus(gateway.Status)) metrics.GatewaysUnhealthy++;
            }
        }

        private static async Task AggregateSessionsAsync(HttpClient client, HorizonApiConfig config, HorizonApiMetrics metrics, ISet<string> sessionMachineIds, CancellationToken token)
        {
            for (var page = 1; page <= config.MaxPages; page++)
            {
                var uri = string.Format(CultureInfo.InvariantCulture, "rest/inventory/v1/sessions?page={0}&size={1}", page, config.PageSize);
                var json = await TryGetAsync(client, uri, "sessions", metrics, token).ConfigureAwait(false);
                if (json == null) return;
                var rows = AsObjectList(DeserializeObject(json));
                metrics.SessionPages = page;
                foreach (var row in rows)
                {
                    metrics.SessionsTotal++;
                    var state = NormalizeStatus(StringValue(row, "session_state"));
                    if (state == "CONNECTED") metrics.SessionsConnected++;
                    else if (state == "DISCONNECTED") metrics.SessionsDisconnected++;
                    else metrics.SessionsOther++;

                    var machineId = StringValue(row, "machine_id");
                    if (!string.IsNullOrWhiteSpace(machineId)) sessionMachineIds.Add(machineId);
                    var protocol = NormalizeLabel(StringValue(row, "session_protocol"));
                    if (!string.IsNullOrEmpty(protocol)) Increment(metrics.SessionProtocols, protocol);
                }
                if (rows.Count < config.PageSize) return;
            }
            metrics.SessionsTruncated = true;
        }

        private static async Task CollectClonePoolsAsync(HttpClient client, HorizonApiConfig config, HorizonApiMetrics metrics, ISet<string> sessionMachineIds, CancellationToken token)
        {
            var poolJson = await TryGetAsync(client, "rest/inventory/v1/desktop-pools", "desktop_pools", metrics, token).ConfigureAwait(false);
            if (poolJson == null) return;
            foreach (var row in AsObjectList(DeserializeObject(poolJson)))
            {
                var source = NormalizeStatus(StringValue(row, "source"));
                if (!IsCloneSource(source)) continue;
                metrics.Pools.Add(new HorizonPoolMetric
                {
                    Id = StringValue(row, "id"),
                    Name = StringValue(row, "name"),
                    DisplayName = StringValue(row, "display_name"),
                    Source = source,
                    Enabled = BooleanValue(row, "enabled", true)
                });
            }

            var poolById = metrics.Pools.Where(pool => !string.IsNullOrWhiteSpace(pool.Id)).ToDictionary(pool => pool.Id, StringComparer.OrdinalIgnoreCase);
            for (var page = 1; page <= config.MaxPages; page++)
            {
                var uri = string.Format(CultureInfo.InvariantCulture, "rest/inventory/v1/machines?page={0}&size={1}", page, config.PageSize);
                var machineJson = await TryGetAsync(client, uri, "machines", metrics, token).ConfigureAwait(false);
                if (machineJson == null) break;
                var rows = AsObjectList(DeserializeObject(machineJson));
                metrics.MachinePages = page;
                foreach (var row in rows)
                {
                    if (!poolById.TryGetValue(StringValue(row, "desktop_pool_id"), out var pool)) continue;
                    pool.MachinesTotal++;
                    var state = NormalizeStatus(StringValue(row, "state"));
                    if (string.IsNullOrWhiteSpace(state)) state = "UNKNOWN";
                    Increment(pool.MachineStates, state);
                    var machineId = StringValue(row, "id");
                    if (!string.IsNullOrWhiteSpace(machineId) && sessionMachineIds.Contains(machineId))
                    {
                        pool.MachinesWithSessions++;
                        continue;
                    }

                    pool.SpareTotal++;
                    var managed = Value(row, "managed_machine_data") as IDictionary<string, object>;
                    var maintenance = BooleanValue(managed, "in_maintenance_mode", false) || state == "MAINTENANCE";
                    if (maintenance) pool.SpareMaintenance++;
                    if (state == "AVAILABLE" && !maintenance) pool.SpareReady++;
                    else pool.SpareUnready++;
                }
                if (rows.Count < config.PageSize) break;
                if (page == config.MaxPages) metrics.MachinesTruncated = true;
            }

            metrics.ClonePoolsTotal = metrics.Pools.Count;
            foreach (var pool in metrics.Pools)
            {
                ScorePool(pool, config, metrics.SessionsTruncated || metrics.MachinesTruncated || metrics.EndpointFailures.Contains("sessions") || metrics.EndpointFailures.Contains("machines"));
                metrics.SpareMachinesTotal += pool.SpareTotal;
                metrics.SpareMachinesReady += pool.SpareReady;
                metrics.SpareMachinesUnready += pool.SpareUnready;
                if (pool.HealthState == "ok") metrics.ClonePoolsHealthy++;
                else if (pool.HealthState == "warning") metrics.ClonePoolsWarning++;
                else if (pool.HealthState == "critical") metrics.ClonePoolsCritical++;
                else if (pool.HealthState == "disabled") metrics.ClonePoolsDisabled++;
                else metrics.ClonePoolsIncomplete++;
            }
        }

        private static void ScorePool(HorizonPoolMetric pool, HorizonApiConfig config, bool incomplete)
        {
            var result = HorizonPoolHealth.Evaluate(new HorizonPoolHealthInput
            {
                Enabled = pool.Enabled,
                InventoryComplete = !incomplete,
                MachinesTotal = pool.MachinesTotal,
                SpareTotal = pool.SpareTotal,
                SpareReady = pool.SpareReady,
                SpareUnready = pool.SpareUnready,
                WarningUnreadyPercent = config.PoolWarningUnreadyPercent,
                CriticalUnreadyPercent = config.PoolCriticalUnreadyPercent,
                MinimumSpareSample = config.PoolMinimumSpareSample
            });
            pool.HealthState = result.State;
            pool.HealthReason = result.Reason;
            pool.SpareUnreadyPercent = result.UnreadyPercent;
        }

        internal static void AggregateConnectionServers(string json, HorizonApiMetrics metrics)
        {
            foreach (var row in AsObjectList(DeserializeObject(json)))
            {
                var member = new HorizonConnectionServerMetric
                {
                    Id = StringValue(row, "id"),
                    Name = StringValue(row, "name"),
                    Status = NormalizeStatus(StringValue(row, "status")),
                    Connections = IntegerValue(row, "connection_count")
                };
                var details = Value(row, "details") as IDictionary<string, object>;
                member.Version = StringValue(details, "version");
                foreach (var service in AsObjectList(Value(row, "services")))
                {
                    if (!IsHealthyStatus(StringValue(service, "status")))
                    {
                        member.ServicesUnhealthy++;
                        metrics.ServicesUnhealthy++;
                    }
                }
                foreach (var replication in AsObjectList(Value(row, "cs_replications")))
                {
                    var item = new HorizonReplicationMetric
                    {
                        Source = member.Name,
                        Target = StringValue(replication, "server_name"),
                        Status = NormalizeStatus(StringValue(replication, "status"))
                    };
                    member.ReplicationsTotal++;
                    metrics.ReplicationsTotal++;
                    if (!IsHealthyStatus(item.Status))
                    {
                        member.ReplicationsUnhealthy++;
                        metrics.ReplicationsUnhealthy++;
                    }
                    metrics.Replications.Add(item);
                }
                var certificate = Value(row, "certificate") as IDictionary<string, object>;
                member.CertificateValid = certificate == null || BooleanValue(certificate, "valid", true);
                if (!member.CertificateValid) metrics.CertificatesInvalid++;
                if (!IsHealthyStatus(member.Status) || member.ServicesUnhealthy > 0 || member.ReplicationsUnhealthy > 0 || !member.CertificateValid)
                {
                    metrics.ConnectionServersUnhealthy++;
                }
                metrics.ConnectionServers.Add(member);
                metrics.ConnectionServersTotal++;
            }
        }

        private static HorizonConnectionServerMetric FindConnectionServer(HorizonApiMetrics metrics, string id, string name)
        {
            return metrics.ConnectionServers.FirstOrDefault(member =>
                (!string.IsNullOrWhiteSpace(id) && string.Equals(member.Id, id, StringComparison.OrdinalIgnoreCase)) ||
                (!string.IsNullOrWhiteSpace(name) && string.Equals(member.Name, name, StringComparison.OrdinalIgnoreCase)));
        }

        private static async Task<string> LoginAsync(HttpClient client, HorizonApiCredential credential, CancellationToken token)
        {
            var fields = new Dictionary<string, string>
            {
                ["username"] = credential.Username,
                ["domain"] = credential.Domain ?? string.Empty
            };
            fields.Add("password", credential.Password);
            var body = new JavaScriptSerializer().Serialize(fields);
            using (var request = new HttpRequestMessage(HttpMethod.Post, "rest/login"))
            {
                request.Content = new StringContent(body, Encoding.UTF8, "application/json");
                using (var response = await client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, token).ConfigureAwait(false))
                {
                    response.EnsureSuccessStatusCode();
                    var json = await ReadContentAsync(response, LoginResponseMaxBytes, token).ConfigureAwait(false);
                    var root = DeserializeObject(json) as IDictionary<string, object>;
                    var bearerValue = StringValue(root, "access_token");
                    if (string.IsNullOrWhiteSpace(bearerValue)) throw new InvalidOperationException("Horizon login did not return an access token.");
                    return bearerValue;
                }
            }
        }

        private static async Task<string> TryGetAsync(HttpClient client, string uri, string failureName, HorizonApiMetrics metrics, CancellationToken token)
        {
            try
            {
                return await GetAsync(client, uri, token).ConfigureAwait(false);
            }
            catch (HttpRequestException)
            {
                if (!metrics.EndpointFailures.Contains(failureName)) metrics.EndpointFailures.Add(failureName);
                return null;
            }
        }

        private static async Task<string> GetAsync(HttpClient client, string uri, CancellationToken token)
        {
            using (var request = new HttpRequestMessage(HttpMethod.Get, uri))
            using (var response = await client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, token).ConfigureAwait(false))
            {
                response.EnsureSuccessStatusCode();
                return await ReadContentAsync(response, TelemetryResponseMaxBytes, token).ConfigureAwait(false);
            }
        }

        private static async Task<string> ReadContentAsync(HttpResponseMessage response, int maxBytes, CancellationToken token)
        {
            if (response.Content.Headers.ContentLength.HasValue && response.Content.Headers.ContentLength.Value > maxBytes)
                throw new InvalidOperationException("Horizon API response exceeded the safety limit.");
            using (var input = await response.Content.ReadAsStreamAsync().ConfigureAwait(false))
            using (var output = new MemoryStream())
            {
                var buffer = new byte[81920];
                while (true)
                {
                    var read = await input.ReadAsync(buffer, 0, buffer.Length, token).ConfigureAwait(false);
                    if (read <= 0) break;
                    if (output.Length + read > maxBytes) throw new InvalidOperationException("Horizon API response exceeded the safety limit.");
                    output.Write(buffer, 0, read);
                }
                return Encoding.UTF8.GetString(output.ToArray());
            }
        }

        private static object DeserializeObject(string json)
        {
            return new JavaScriptSerializer { MaxJsonLength = TelemetryResponseMaxBytes }.DeserializeObject(json ?? "[]");
        }

        private static List<IDictionary<string, object>> AsObjectList(object value)
        {
            var result = new List<IDictionary<string, object>>();
            var enumerable = value as IEnumerable;
            if (enumerable == null || value is string || value is IDictionary<string, object>) return result;
            foreach (var item in enumerable)
            {
                if (item is IDictionary<string, object> row) result.Add(row);
            }
            return result;
        }

        private static object Value(IDictionary<string, object> row, string key)
        {
            if (row == null || !row.TryGetValue(key, out var value)) return null;
            return value;
        }

        private static string StringValue(IDictionary<string, object> row, string key)
        {
            return Convert.ToString(Value(row, key), CultureInfo.InvariantCulture) ?? string.Empty;
        }

        private static int IntegerValue(IDictionary<string, object> row, string key)
        {
            return int.TryParse(StringValue(row, key), NumberStyles.Integer, CultureInfo.InvariantCulture, out var value) ? value : 0;
        }

        private static bool BooleanValue(IDictionary<string, object> row, string key, bool defaultValue)
        {
            var value = Value(row, key);
            if (value == null) return defaultValue;
            if (value is bool flag) return flag;
            return bool.TryParse(Convert.ToString(value, CultureInfo.InvariantCulture), out flag) ? flag : defaultValue;
        }

        private static bool IsHealthyStatus(string value)
        {
            var status = NormalizeStatus(value);
            return status == "OK" || status == "UP" || status == "RUNNING" || status == "GREEN" || status == "ONLINE" || status == "ACCESSIBLE" || status == "FULLY_ACCESSIBLE";
        }

        private static bool IsDomainAccessHealthy(string value)
        {
            return IsHealthyStatus(value);
        }

        private static bool IsCloneSource(string source)
        {
            return source == "INSTANT_CLONE" || source == "LINKED_CLONE" || source == "VIEW_COMPOSER";
        }

        private static string NormalizeStatus(string value)
        {
            return (value ?? string.Empty).Trim().ToUpperInvariant();
        }

        private static string NormalizeLabel(string value)
        {
            return new string((value ?? string.Empty).ToLowerInvariant().Where(ch => char.IsLetterOrDigit(ch) || ch == '_').Take(32).ToArray());
        }

        private static void Increment(IDictionary<string, int> values, string key)
        {
            values[key] = values.TryGetValue(key, out var current) ? current + 1 : 1;
        }

        private static string FirstNonEmpty(string first, string second)
        {
            return string.IsNullOrWhiteSpace(first) ? second : first;
        }

        private static Uri EnsureTrailingSlash(Uri value)
        {
            return value.AbsoluteUri.EndsWith("/", StringComparison.Ordinal) ? value : new Uri(value.AbsoluteUri + "/");
        }
    }
}
