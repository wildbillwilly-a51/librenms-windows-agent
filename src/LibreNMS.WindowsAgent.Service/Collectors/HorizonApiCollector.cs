using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Threading;
using System.Threading.Tasks;
using LibreNMS.WindowsAgent.Core;

namespace LibreNMS.WindowsAgent.Service.Collectors
{
    internal sealed class HorizonApiCollector : IAgentCollector, ICollectorTimeoutOverride
    {
        public string Name => "horizon_api";

        public TimeSpan GetTimeout(AgentContext context, TimeSpan defaultTimeout)
        {
            var config = context.Config.Collectors.Horizon?.Api ?? new HorizonApiConfig();
            return TimeSpan.FromSeconds(Math.Max(defaultTimeout.TotalSeconds, Math.Min(65, config.TimeoutSeconds + 2)));
        }

        public async Task<IReadOnlyList<AgentSection>> CollectAsync(AgentContext context, CancellationToken cancellationToken)
        {
            var config = context.Config.Collectors.Horizon?.Api ?? new HorizonApiConfig();
            var metrics = await HorizonApiClient.CollectAsync(config, cancellationToken).ConfigureAwait(false);
            return BuildSections(metrics, config);
        }

        internal static IReadOnlyList<AgentSection> BuildSections(HorizonApiMetrics metrics, HorizonApiConfig config)
        {
            return new[]
            {
                SummarySection(metrics),
                PodSummarySection(metrics),
                new AgentSection("windows_agent_horizon_pod_members", metrics.ConnectionServers.Select(ConnectionServerLine)),
                new AgentSection("windows_agent_horizon_configuration_replications", metrics.Replications.Select(ReplicationLine)),
                DirectorySummarySection(metrics),
                new AgentSection("windows_agent_horizon_directory_domains", metrics.Domains.Select(DomainLine)),
                new AgentSection("windows_agent_horizon_directory_member_status", metrics.DomainMembers.Select(DomainMemberLine)),
                new AgentSection("windows_agent_horizon_gateways", metrics.Gateways.Select(GatewayLine)),
                PoolsSummarySection(metrics, config),
                new AgentSection("windows_agent_horizon_pools", metrics.Pools.Select(PoolLine)),
                new AgentSection("windows_agent_horizon_pool_machine_states", metrics.Pools.SelectMany(PoolStateLines)),
                new AgentSection("windows_agent_horizon_api_session_protocols", metrics.SessionProtocols.Select(row =>
                    "protocol=" + Kv(row.Key) + " sessions=" + row.Value.ToString(CultureInfo.InvariantCulture)))
            };
        }

        private static AgentSection SummarySection(HorizonApiMetrics metrics)
        {
            var healthState = OverallHealth(metrics);
            return Single("windows_agent_horizon_api_summary", string.Format(
                CultureInfo.InvariantCulture,
                "state={0} health_state={1} reason={2} duration_ms={3} endpoint_failures={4} connection_servers_total={5} connection_servers_unhealthy={6} services_unhealthy={7} replications_total={8} replications_unhealthy={9} certificates_invalid={10} horizon_domains_total={11} horizon_domain_links_total={12} horizon_domain_links_unhealthy={13} gateways_total={14} gateways_unhealthy={15} sessions_total={16} sessions_connected={17} sessions_disconnected={18} sessions_other={19} session_pages={20} sessions_truncated={21} machine_pages={22} machines_truncated={23} clone_pools_total={24} clone_pools_warning={25} clone_pools_critical={26}",
                Kv(metrics.State), Kv(healthState), Kv(metrics.Reason), metrics.DurationMs, metrics.EndpointFailures.Count,
                metrics.ConnectionServersTotal, metrics.ConnectionServersUnhealthy, metrics.ServicesUnhealthy,
                metrics.ReplicationsTotal, metrics.ReplicationsUnhealthy, metrics.CertificatesInvalid,
                metrics.HorizonDomainsTotal, metrics.HorizonDomainLinksTotal, metrics.HorizonDomainLinksUnhealthy,
                metrics.GatewaysTotal, metrics.GatewaysUnhealthy, metrics.SessionsTotal, metrics.SessionsConnected,
                metrics.SessionsDisconnected, metrics.SessionsOther, metrics.SessionPages, Bool(metrics.SessionsTruncated),
                metrics.MachinePages, Bool(metrics.MachinesTruncated), metrics.ClonePoolsTotal,
                metrics.ClonePoolsWarning, metrics.ClonePoolsCritical));
        }

        private static AgentSection PodSummarySection(HorizonApiMetrics metrics)
        {
            var state = metrics.ConnectionServersUnhealthy > 0 || metrics.ReplicationsUnhealthy > 0 || metrics.GatewaysUnhealthy > 0
                ? "critical"
                : metrics.ConnectionServersTotal > 0 && !metrics.EndpointFailures.Contains("connection_server_monitor") ? "ok" : "incomplete";
            return Single("windows_agent_horizon_pod_summary", string.Format(
                CultureInfo.InvariantCulture,
                "state={0} pod_name={1} cluster_name={2} members_total={3} members_unhealthy={4} configuration_replications_total={5} configuration_replications_unhealthy={6} gateways_total={7} gateways_unhealthy={8}",
                Kv(state), Kv(metrics.PodName), Kv(metrics.ClusterName), metrics.ConnectionServersTotal,
                metrics.ConnectionServersUnhealthy, metrics.ReplicationsTotal, metrics.ReplicationsUnhealthy,
                metrics.GatewaysTotal, metrics.GatewaysUnhealthy));
        }

        private static AgentSection DirectorySummarySection(HorizonApiMetrics metrics)
        {
            var state = metrics.HorizonDomainLinksUnhealthy > 0 ? "critical" : metrics.HorizonDomainsTotal > 0 ? "ok" : "incomplete";
            return Single("windows_agent_horizon_directory_summary", string.Format(
                CultureInfo.InvariantCulture,
                "state={0} scope=horizon_domain_access domains_total={1} member_links_total={2} member_links_unhealthy={3}",
                Kv(state), metrics.HorizonDomainsTotal, metrics.HorizonDomainLinksTotal, metrics.HorizonDomainLinksUnhealthy));
        }

        private static AgentSection PoolsSummarySection(HorizonApiMetrics metrics, HorizonApiConfig config)
        {
            var state = metrics.ClonePoolsCritical > 0 ? "critical"
                : metrics.ClonePoolsWarning > 0 ? "warning"
                : metrics.ClonePoolsIncomplete > 0 ? "incomplete"
                : metrics.ClonePoolsTotal > 0 && metrics.ClonePoolsDisabled == metrics.ClonePoolsTotal ? "disabled"
                : metrics.ClonePoolsTotal > 0 ? "ok" : "not_detected";
            return Single("windows_agent_horizon_pools_summary", string.Format(
                CultureInfo.InvariantCulture,
                "state={0} pools_total={1} pools_healthy={2} pools_warning={3} pools_critical={4} pools_incomplete={5} pools_disabled={6} spare_total={7} spare_ready={8} spare_unready={9} warning_percent={10} critical_percent={11} minimum_sample={12}",
                Kv(state), metrics.ClonePoolsTotal, metrics.ClonePoolsHealthy, metrics.ClonePoolsWarning,
                metrics.ClonePoolsCritical, metrics.ClonePoolsIncomplete, metrics.ClonePoolsDisabled, metrics.SpareMachinesTotal,
                metrics.SpareMachinesReady, metrics.SpareMachinesUnready, config.PoolWarningUnreadyPercent,
                config.PoolCriticalUnreadyPercent, config.PoolMinimumSpareSample));
        }

        private static string ConnectionServerLine(HorizonConnectionServerMetric member)
        {
            return string.Format(CultureInfo.InvariantCulture,
                "name={0} status={1} server_type={2} local_api_target={3} enabled={4} gateway_mode={5} version={6} connections={7} services_unhealthy={8} configuration_replications_total={9} configuration_replications_unhealthy={10} certificate_valid={11}",
                Kv(member.Name), Kv(member.Status), Kv(member.ServerType), Bool(member.LocalApiTarget), Bool(member.Enabled),
                Kv(member.GatewayMode), Kv(member.Version), member.Connections, member.ServicesUnhealthy,
                member.ReplicationsTotal, member.ReplicationsUnhealthy, Bool(member.CertificateValid));
        }

        private static string ReplicationLine(HorizonReplicationMetric replication)
        {
            return "source=" + Kv(replication.Source) + " target=" + Kv(replication.Target) + " status=" + Kv(replication.Status) + " scope=horizon_configuration_ad_lds";
        }

        private static string DomainLine(HorizonDomainMetric domain)
        {
            return string.Format(CultureInfo.InvariantCulture,
                "dns_name={0} netbios_name={1} domain_type={2} member_links_total={3} member_links_unhealthy={4} service_accounts_active={5} service_accounts_unhealthy={6} scope=horizon_domain_access",
                Kv(domain.DnsName), Kv(domain.NetbiosName), Kv(domain.DomainType), domain.MembersTotal,
                domain.MembersUnhealthy, domain.ServiceAccountsActive, domain.ServiceAccountsUnhealthy);
        }

        private static string GatewayLine(HorizonGatewayMetric gateway)
        {
            return string.Format(CultureInfo.InvariantCulture, "name={0} type={1} status={2} version={3} active_connections={4}",
                Kv(gateway.Name), Kv(gateway.Type), Kv(gateway.Status), Kv(gateway.Version), gateway.ActiveConnections);
        }

        private static string DomainMemberLine(HorizonDomainMemberMetric member)
        {
            return "domain=" + Kv(member.Domain) + " member=" + Kv(member.Member) + " status=" + Kv(member.Status) + " trust_relationship=" + Kv(member.TrustRelationship) + " scope=horizon_domain_access";
        }

        private static string PoolLine(HorizonPoolMetric pool)
        {
            return string.Format(CultureInfo.InvariantCulture,
                "name={0} display_name={1} source={2} enabled={3} health_state={4} health_reason={5} machines_total={6} machines_with_sessions={7} spare_total={8} spare_ready={9} spare_unready={10} spare_maintenance={11} spare_unready_percent={12:0.0}",
                Kv(pool.Name), Kv(pool.DisplayName), Kv(pool.Source), Bool(pool.Enabled), Kv(pool.HealthState),
                Kv(pool.HealthReason), pool.MachinesTotal, pool.MachinesWithSessions, pool.SpareTotal,
                pool.SpareReady, pool.SpareUnready, pool.SpareMaintenance, pool.SpareUnreadyPercent);
        }

        private static IEnumerable<string> PoolStateLines(HorizonPoolMetric pool)
        {
            return pool.MachineStates.Select(row => string.Format(CultureInfo.InvariantCulture,
                "pool={0} source={1} state={2} machines={3}", Kv(pool.Name), Kv(pool.Source), Kv(row.Key), row.Value));
        }

        private static string OverallHealth(HorizonApiMetrics metrics)
        {
            if (metrics.State == "disabled" || metrics.State == "unconfigured" || metrics.State == "unavailable") return metrics.State;
            if (metrics.ConnectionServersUnhealthy > 0 || metrics.ReplicationsUnhealthy > 0 || metrics.HorizonDomainLinksUnhealthy > 0 || metrics.GatewaysUnhealthy > 0 || metrics.ClonePoolsCritical > 0) return "critical";
            if (metrics.ClonePoolsWarning > 0 || metrics.ClonePoolsIncomplete > 0 || metrics.State == "partial") return "warning";
            return "ok";
        }

        private static AgentSection Single(string name, string line)
        {
            return new AgentSection(name, new[] { line });
        }

        private static int Bool(bool value)
        {
            return value ? 1 : 0;
        }

        private static string Kv(string value)
        {
            if (string.IsNullOrEmpty(value)) return "\"\"";
            if (value.IndexOfAny(new[] { ' ', '\t', '"', '=', '\\' }) < 0) return value;
            return "\"" + value.Replace("\\", "\\\\").Replace("\"", "\\\"") + "\"";
        }
    }
}
