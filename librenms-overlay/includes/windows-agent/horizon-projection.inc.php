<?php

namespace WindowsAgentOverlay;

// One central Horizon projection for both application writers.
final class HorizonProjection
{
    public const DATA_KEYS = [
        'horizon_api_summary', 'horizon_api_session_protocols', 'horizon_pod_summary',
        'horizon_pod_members', 'horizon_configuration_replications', 'horizon_directory_summary',
        'horizon_directory_domains', 'horizon_directory_member_status', 'horizon_gateways',
        'horizon_pools_summary', 'horizon_pools', 'horizon_pool_machine_states',
        'horizon_pool_machines', 'horizon_pool_machine_issues', 'horizon_central_meta',
        'horizon_health_summary', 'horizon_vendor_metrics', 'horizon_conditions',
        'horizon_observations', 'horizon_condition_history',
    ];

    public static function metrics(array $snapshot): array
    {
        $api = $snapshot['horizon_api_summary'] ?? [];
        $pod = $snapshot['horizon_pod_summary'] ?? [];
        $directory = $snapshot['horizon_directory_summary'] ?? [];
        $pools = $snapshot['horizon_pools_summary'] ?? [];
        $meta = $snapshot['horizon_central_meta'] ?? [];
        $health = $snapshot['horizon_health_summary'] ?? $api;
        $vendor = $snapshot['horizon_vendor_metrics'] ?? [];

        return [
            'horizon_api_available' => in_array(strtolower((string) ($api['state'] ?? '')), ['ok', 'partial'], true) ? 1 : 0,
            'horizon_api_connection_servers_total' => (int) ($api['connection_servers_total'] ?? 0),
            'horizon_api_connection_servers_unhealthy' => (int) ($api['connection_servers_unhealthy'] ?? 0),
            'horizon_api_services_unhealthy' => (int) ($api['services_unhealthy'] ?? 0),
            'horizon_api_replications_unhealthy' => (int) ($api['replications_unhealthy'] ?? 0),
            'horizon_api_certificates_invalid' => (int) ($api['certificates_invalid'] ?? 0),
            'horizon_api_sessions_total' => (int) ($api['sessions_total'] ?? 0),
            'horizon_api_sessions_connected' => (int) ($api['sessions_connected'] ?? 0),
            'horizon_api_sessions_disconnected' => (int) ($api['sessions_disconnected'] ?? 0),
            'horizon_api_sessions_other' => (int) ($api['sessions_other'] ?? 0),
            'horizon_api_sessions_truncated' => (int) ($api['sessions_truncated'] ?? 0),
            'horizon_api_service_details_truncated' => (int) ($api['service_details_truncated'] ?? 0),
            'horizon_api_machine_issues_total' => (int) ($api['machine_issues_total'] ?? 0),
            'horizon_api_machine_issues_truncated' => (int) ($api['machine_issues_truncated'] ?? 0),
            'horizon_pod_members_total' => (int) ($pod['members_total'] ?? 0),
            'horizon_pod_members_unhealthy' => (int) ($pod['members_unhealthy'] ?? 0),
            'horizon_pod_replications_total' => (int) ($pod['configuration_replications_total'] ?? 0),
            'horizon_pod_replications_unhealthy' => (int) ($pod['configuration_replications_unhealthy'] ?? 0),
            'horizon_directory_links_total' => (int) ($directory['member_links_total'] ?? 0),
            'horizon_directory_links_unhealthy' => (int) ($directory['member_links_unhealthy'] ?? 0),
            'horizon_gateways_total' => (int) ($pod['gateways_total'] ?? 0),
            'horizon_gateways_unhealthy' => (int) ($pod['gateways_unhealthy'] ?? 0),
            'horizon_pools_total' => (int) ($pools['pools_total'] ?? 0),
            'horizon_pools_informational' => (int) ($pools['pools_informational'] ?? 0),
            'horizon_pools_warning' => (int) ($pools['pools_warning'] ?? 0),
            'horizon_pools_critical' => (int) ($pools['pools_critical'] ?? 0),
            'horizon_pools_incomplete' => (int) ($pools['pools_incomplete'] ?? 0),
            'horizon_spare_total' => (int) ($pools['spare_total'] ?? 0),
            'horizon_spare_ready' => (int) ($pools['spare_ready'] ?? 0),
            'horizon_spare_unready' => (int) ($pools['spare_unready'] ?? 0),
            'horizon_central_stale' => (int) ($meta['stale'] ?? 0),
            'horizon_central_snapshot_age_seconds' => (int) ($meta['snapshot_age_seconds'] ?? -1),
            'horizon_central_collection_duration_ms' => (int) ($meta['collection_duration_ms'] ?? 0),
            'horizon_central_endpoints_attempted' => (int) ($meta['endpoints_attempted'] ?? 0),
            'horizon_central_requests_total' => (int) ($meta['requests_total'] ?? 0),
            'horizon_central_pages_total' => (int) ($meta['pages_total'] ?? 0),
            'horizon_central_inventory_complete' => (int) ($meta['inventory_complete'] ?? 0),
            'horizon_platform_health_state' => self::stateMetric((string) ($health['platform_health_state'] ?? $pod['state'] ?? 'incomplete')),
            'horizon_dependency_health_state' => self::stateMetric((string) ($health['dependency_health_state'] ?? 'incomplete')),
            'horizon_capacity_health_state' => self::stateMetric((string) ($health['capacity_health_state'] ?? $pools['state'] ?? 'incomplete')),
            'horizon_collector_health_state' => self::stateMetric((string) ($health['collector_health_state'] ?? 'incomplete')),
            'horizon_overall_health_state' => self::stateMetric((string) ($health['overall_health_state'] ?? $api['health_state'] ?? 'incomplete')),
            'horizon_vendor_warnings_total' => (int) ($vendor['warnings_total'] ?? 0),
            'horizon_vendor_errors_total' => (int) ($vendor['errors_total'] ?? 0),
            'horizon_vendor_unknown_total' => (int) ($vendor['unknown_total'] ?? 0),
            'horizon_vendor_problem_machines_total' => (int) ($vendor['problem_machines_total'] ?? 0),
            'horizon_vendor_problem_machine_mismatch' => (int) ($vendor['problem_machine_mismatch'] ?? 0),
        ];
    }

    private static function stateMetric(string $state): int
    {
        return ['ok' => 0, 'disabled' => 0, 'info' => 1, 'incomplete' => 2, 'warning' => 3, 'critical' => 4][strtolower($state)] ?? 2;
    }

    public static function poolMetrics(array $snapshot): array
    {
        $fields = [];
        foreach (($snapshot['horizon_pools'] ?? []) as $pool_index => $pool_row) {
            $pool_name = (string) ($pool_row['name'] ?? $pool_index);
            if ($pool_name === '') {
                continue;
            }
            $pool_metric_key = preg_replace('/[^A-Za-z0-9_-]/', '_', $pool_name);
            if ($pool_metric_key === null || $pool_metric_key === '') {
                continue;
            }

            $pool_machines_total = (int) ($pool_row['machines_total'] ?? 0);
            $pool_spare_ready = (int) ($pool_row['spare_ready'] ?? 0);
            $pool_ready_percent = $pool_machines_total > 0
                ? round(($pool_spare_ready * 100) / $pool_machines_total, 1)
                : 0;

            // Severity ordinal only: ok < info < warning < critical, so `>= n` means "this bad or worse"
            // (info = one unavailable spare while capacity remains; count-based per HorizonPoolHealth).
            // disabled and incomplete sit below ok as sentinels so positive-threshold rules skip them.
            $pool_state = [
                'ok' => 0,
                'info' => 1,
                'informational' => 1,
                'warning' => 2,
                'critical' => 3,
                'disabled' => -1,
                'incomplete' => -2,
            ][strtolower((string) ($pool_row['health_state'] ?? 'incomplete'))] ?? -2;

            $fields['horizon_pool_ready:' . $pool_metric_key] = $pool_spare_ready;
            $fields['horizon_pool_ready_percent:' . $pool_metric_key] = $pool_ready_percent;
            $fields['horizon_pool_machines_total:' . $pool_metric_key] = $pool_machines_total;
            $fields['horizon_pool_state:' . $pool_metric_key] = $pool_state;
            $fields['horizon_pool_maintenance:' . $pool_metric_key] = (int) ($pool_row['spare_maintenance'] ?? 0);
            $fields['horizon_pool_spare_total:' . $pool_metric_key] = (int) ($pool_row['spare_total'] ?? 0);
            $fields['horizon_pool_unready:' . $pool_metric_key] = (int) ($pool_row['spare_unready'] ?? 0);
        }

        return $fields;
    }

    public static function ownsMetric(string $name): bool
    {
        static $keys = null;
        $keys ??= array_fill_keys(array_keys(self::metrics([])), true);
        return str_starts_with($name, 'horizon_pool_') || isset($keys[$name]);
    }

    public static function pollerMetrics(array $snapshot, bool $central): array
    {
        $fields = self::metrics($snapshot);
        if (! $central) {
            // Keep agent-only mode's original set; these ten belong to central mode.
            foreach (array_keys($fields) as $name) {
                if (str_starts_with($name, 'horizon_vendor_') || str_ends_with($name, '_health_state')) unset($fields[$name]);
            }
        }

        return array_merge($fields, self::poolMetrics($snapshot));
    }
}
