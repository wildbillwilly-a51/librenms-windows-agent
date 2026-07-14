<?php

use App\Models\Application;
use LibreNMS\RRD\RrdDefinition;

$name = 'windows-agent';

if (empty($agent_data['windows_agent'])) {
    return;
}

$parse_windows_agent_kv = function ($line): array {
    $values = [];
    preg_match_all('/([A-Za-z0-9_]+)=("([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|\\S*)/', (string) $line, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $value = $match[2] ?? '';
        if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
            $value = stripcslashes(substr($value, 1, -1));
        }

        $values[$match[1]] = $value;
    }

    return $values;
};

$agent = $parse_windows_agent_kv($agent_data['windows_agent'] ?? '');
$os = $parse_windows_agent_kv($agent_data['windows_agent_windows_os'] ?? '');
$roles_raw = $agent_data['windows_agent_roles'] ?? '';
$ad_summary_raw = $agent_data['windows_agent_ad_summary'] ?? '';
$ad_replication_raw = $agent_data['windows_agent_ad_replication'] ?? '';
$ad_dfsr_raw = $agent_data['windows_agent_ad_dfsr'] ?? '';
$ad_fsmo_raw = $agent_data['windows_agent_ad_fsmo'] ?? '';
$ad_dc_health_summary_raw = $agent_data['windows_agent_ad_dc_health_summary'] ?? '';
$ad_dc_services_raw = $agent_data['windows_agent_ad_dc_services'] ?? '';
$ad_dc_dns_raw = $agent_data['windows_agent_ad_dc_dns'] ?? '';
$ad_dc_time_raw = $agent_data['windows_agent_ad_dc_time'] ?? '';
$ad_dc_shares_raw = $agent_data['windows_agent_ad_dc_shares'] ?? '';
$ad_dc_security_events_raw = $agent_data['windows_agent_ad_dc_security_events'] ?? '';
$logged_on_users_raw = $agent_data['windows_agent_logged_on_users'] ?? '';
$pending = $parse_windows_agent_kv($agent_data['windows_agent_pending_reboot'] ?? '');
$windows_update = $parse_windows_agent_kv($agent_data['windows_agent_windows_update'] ?? '');
$services_summary = $agent_data['windows_agent_services_summary'] ?? '';
$services = $agent_data['windows_agent_services'] ?? '';
$services_excluded = $agent_data['windows_agent_services_excluded'] ?? '';
$event_logs = $agent_data['windows_agent_event_logs'] ?? '';
$event_log_high_value_summary_raw = $agent_data['windows_agent_event_log_high_value_summary'] ?? '';
$event_log_high_value_raw = $agent_data['windows_agent_event_log_high_value'] ?? '';
$processes = $agent_data['windows_agent_processes'] ?? '';
$tcp_ports = $agent_data['windows_agent_tcp_ports'] ?? '';
$agent_performance_raw = $agent_data['windows_agent_performance'] ?? '';
$cpu_raw = $agent_data['windows_agent_cpu'] ?? '';
$memory_raw = $agent_data['windows_agent_memory'] ?? '';
$disks_raw = $agent_data['windows_agent_disks'] ?? '';
$performance_summary_raw = $agent_data['windows_agent_performance_summary'] ?? '';
$performance_disks_raw = $agent_data['windows_agent_performance_disks'] ?? '';
$performance_network_raw = $agent_data['windows_agent_performance_network'] ?? '';
$performance_processes_raw = $agent_data['windows_agent_performance_processes'] ?? '';
$sql_summary_raw = $agent_data['windows_agent_sql_server_summary'] ?? '';
$sql_instances_raw = $agent_data['windows_agent_sql_server_instances'] ?? '';
$iis_summary_raw = $agent_data['windows_agent_iis_summary'] ?? '';
$iis_sites_raw = $agent_data['windows_agent_iis_sites'] ?? '';
$iis_app_pools_raw = $agent_data['windows_agent_iis_app_pools'] ?? '';
$iis_bindings_raw = $agent_data['windows_agent_iis_bindings'] ?? '';
$horizon_summary_raw = $agent_data['windows_agent_horizon_summary'] ?? '';
$horizon_services_raw = $agent_data['windows_agent_horizon_services'] ?? '';
$horizon_processes_raw = $agent_data['windows_agent_horizon_processes'] ?? '';
$horizon_ports_raw = $agent_data['windows_agent_horizon_ports'] ?? '';
$horizon_certificates_raw = $agent_data['windows_agent_horizon_certificates'] ?? '';
$factorytalk_summary_raw = $agent_data['windows_agent_factorytalk_summary'] ?? '';
$factorytalk_products_raw = $agent_data['windows_agent_factorytalk_products'] ?? '';
$factorytalk_services_raw = $agent_data['windows_agent_factorytalk_services'] ?? '';
$factorytalk_processes_raw = $agent_data['windows_agent_factorytalk_processes'] ?? '';
$factorytalk_ports_raw = $agent_data['windows_agent_factorytalk_ports'] ?? '';
$tls_summary_raw = $agent_data['windows_agent_tls_certificates_summary'] ?? '';
$tls_certificates_raw = $agent_data['windows_agent_tls_certificates'] ?? '';
$backup_summary_raw = $agent_data['windows_agent_backup_storage_summary'] ?? '';
$vss_writers_raw = $agent_data['windows_agent_vss_writers'] ?? '';
$backup_services_raw = $agent_data['windows_agent_backup_services'] ?? '';
$datto_backup_summary_raw = $agent_data['windows_agent_datto_backup_summary'] ?? '';
$datto_backup_services_raw = $agent_data['windows_agent_datto_backup_services'] ?? '';
$datto_backup_processes_raw = $agent_data['windows_agent_datto_backup_processes'] ?? '';
$datto_backup_evidence_raw = $agent_data['windows_agent_datto_backup_evidence'] ?? '';

$roles = [];
$roles_detected_total = 0;
foreach (preg_split('/\r?\n/', trim((string) $roles_raw)) as $line) {
    if ($line === '') {
        continue;
    }

    $role = $parse_windows_agent_kv($line);
    if (! empty($role['role'])) {
        $roles[] = $role;
        if ((int) ($role['detected'] ?? 0) === 1) {
            $roles_detected_total++;
        }
    }
}

$ad_summary = $parse_windows_agent_kv($ad_summary_raw);
$ad_detected = (int) (($ad_summary['ad_detected'] ?? '0') === '1');

$ad_replication = [];
foreach (preg_split('/\r?\n/', trim((string) $ad_replication_raw)) as $line) {
    if ($line === '') {
        continue;
    }

    $row = $parse_windows_agent_kv($line);
    if (! empty($row)) {
        $ad_replication[] = $row;
    }
}
$ad_replication_targets_total = count(array_filter($ad_replication, static fn ($row): bool => ($row['state'] ?? '') === 'ok'));
$ad_replication_failures_total = (int) ($ad_summary['replication_failures'] ?? 0);

$ad_dfsr = [];
foreach (preg_split('/\r?\n/', trim((string) $ad_dfsr_raw)) as $line) {
    if ($line === '') {
        continue;
    }

    $row = $parse_windows_agent_kv($line);
    if (! empty($row)) {
        $ad_dfsr[] = $row;
    }
}
$ad_dfsr_targets_total = count(array_filter($ad_dfsr, static fn ($row): bool => ($row['state'] ?? '') !== 'unsupported' && ($row['state'] ?? '') !== 'disabled'));
$ad_dfsr_unhealthy_total = (int) ($ad_summary['dfsr_unhealthy'] ?? 0);

$ad_fsmo = [];
foreach (preg_split('/\r?\n/', trim((string) $ad_fsmo_raw)) as $line) {
    if ($line === '') {
        continue;
    }

    $row = $parse_windows_agent_kv($line);
    if (! empty($row)) {
        $ad_fsmo[] = $row;
    }
}
$ad_fsmo_roles_total = count(array_filter($ad_fsmo, static fn ($row): bool => ($row['state'] ?? '') === 'ok'));

$logged_on_users = [];
foreach (preg_split('/\r?\n/', trim((string) $logged_on_users_raw)) as $line) {
    if ($line === '') {
        continue;
    }

    $row = $parse_windows_agent_kv($line);
    if (! empty($row)) {
        $row['session_name'] = $row['session_name'] ?? ($row['session'] ?? '');
        $row['session_id'] = $row['session_id'] ?? ($row['id'] ?? '');
        $row['idle_time'] = $row['idle_time'] ?? ($row['idle'] ?? '');
        $row['logon_time'] = $row['logon_time'] ?? ($row['logon'] ?? '');
        $logged_on_users[] = $row;
    }
}
$logged_on_users_sessions = array_filter($logged_on_users, static fn ($row): bool => ! empty($row['user']));
$logged_on_users_total = count($logged_on_users_sessions);
$logged_on_users_active = count(array_filter($logged_on_users_sessions, static fn ($row): bool => strtolower($row['state'] ?? '') === 'active'));
$logged_on_users_disconnected = count(array_filter($logged_on_users_sessions, static fn ($row): bool => in_array(strtolower($row['state'] ?? ''), ['disc', 'disconnected'], true)));

$watched_total = 0;
$watched_not_running = 0;
$failed_services = [];
$watched_services = [];
$classified_services = [];
$classified_services_not_running = 0;
$service_groups = [];
foreach (preg_split('/\r?\n/', trim((string) $services)) as $line) {
    if ($line === '' || str_starts_with($line, 'installed_count=')) {
        continue;
    }

    $service = $parse_windows_agent_kv($line);
    if (! empty($service['name'])) {
        $service['group'] = $service['group'] ?? 'core_windows';
        $service['source'] = $service['source'] ?? 'legacy';
        $classified_services[] = $service;
        $service_groups[$service['group']][] = $service;

        if (($service['state'] ?? '') !== 'Running') {
            $classified_services_not_running++;
        }

        if (($service['source'] ?? '') === 'legacy_watchedServices' || ($service['source'] ?? '') === 'legacy') {
            $watched_services[] = $service;
            $watched_total++;
            if (($service['state'] ?? '') !== 'Running') {
                $watched_not_running++;
                $failed_services[] = $service['name'] . '=' . ($service['state'] ?? 'unknown');
            }
        }
    }
}

$service_group_summaries = [];
foreach (preg_split('/\r?\n/', trim((string) $services_summary)) as $line) {
    if ($line === '') {
        continue;
    }

    $summary = $parse_windows_agent_kv($line);
    if (! empty($summary['group'])) {
        $service_group_summaries[$summary['group']] = $summary;
    }
}

$excluded_services = [];
foreach (preg_split('/\r?\n/', trim((string) $services_excluded)) as $line) {
    if ($line === '') {
        continue;
    }

    $excluded = $parse_windows_agent_kv($line);
    if (! empty($excluded['name'])) {
        $excluded_services[] = $excluded;
    }
}

$pending_reboot = (int) (($pending['pending'] ?? '0') === '1');
$update_reboot = (int) (($windows_update['reboot_required'] ?? '0') === '1');
$service_groups_total = count($service_groups);
$classified_services_total = count($classified_services);
$excluded_services_total = count($excluded_services);

$event_log_critical_count = 0;
$event_log_error_count = 0;
$event_log_warning_count = 0;
$event_log_details = [];
foreach (preg_split('/\r?\n/', trim((string) $event_logs)) as $line) {
    if ($line === '' || str_starts_with($line, 'configured_count=')) {
        continue;
    }

    $event_log = $parse_windows_agent_kv($line);
    if (! empty($event_log['log'])) {
        $event_log_details[] = $event_log;
    }

    $event_log_critical_count += (int) ($event_log['critical_count'] ?? 0);
    $event_log_error_count += (int) ($event_log['error_count'] ?? 0);
    $event_log_warning_count += (int) ($event_log['warning_count'] ?? 0);
}
$event_log_high_value_summary = $parse_windows_agent_kv($event_log_high_value_summary_raw);
$event_log_high_value = [];
foreach (preg_split('/\r?\n/', trim((string) $event_log_high_value_raw)) as $line) {
    if ($line === '') {
        continue;
    }

    $event = $parse_windows_agent_kv($line);
    if (! empty($event['log']) && isset($event['event_id'])) {
        $event_log_high_value[] = $event;
    }
}

$watched_processes_total = 0;
$watched_processes_not_running = 0;
$watched_processes = [];
foreach (preg_split('/\r?\n/', trim((string) $processes)) as $line) {
    if ($line === '' || str_starts_with($line, 'watched_count=')) {
        continue;
    }

    $process = $parse_windows_agent_kv($line);
    if (! empty($process['name'])) {
        $watched_processes[] = $process;
        $watched_processes_total++;
        if ((int) ($process['matched_count'] ?? 0) <= 0) {
            $watched_processes_not_running++;
        }
    }
}

$watched_tcp_ports_total = 0;
$watched_tcp_ports_not_listening = 0;
$watched_tcp_ports = [];
foreach (preg_split('/\r?\n/', trim((string) $tcp_ports)) as $line) {
    if ($line === '' || str_starts_with($line, 'watched_count=')) {
        continue;
    }

    $tcp_port = $parse_windows_agent_kv($line);
    if (! empty($tcp_port['port'])) {
        $watched_tcp_ports[] = $tcp_port;
        $watched_tcp_ports_total++;
        if ((int) ($tcp_port['listening'] ?? 0) !== 1) {
            $watched_tcp_ports_not_listening++;
        }
    }
}

$agent_performance = [];
$collector_timings = [];
foreach (preg_split('/\r?\n/', trim((string) $agent_performance_raw)) as $line) {
    if ($line === '') {
        continue;
    }

    $row = $parse_windows_agent_kv($line);
    if (($row['type'] ?? '') === 'summary') {
        $agent_performance = $row;
    } elseif (($row['type'] ?? '') === 'collector') {
        $collector_timings[] = $row;
    }
}

$cpu_details = [];
$vm_cpu_load_percent = 0;
$cpu_count = 0;
foreach (preg_split('/\r?\n/', trim((string) $cpu_raw)) as $line) {
    if ($line === '') {
        continue;
    }

    $cpu = $parse_windows_agent_kv($line);
    if (! empty($cpu)) {
        $cpu_details[] = $cpu;
        if (isset($cpu['load_percent']) && is_numeric($cpu['load_percent'])) {
            $vm_cpu_load_percent += (float) $cpu['load_percent'];
            $cpu_count++;
        }
    }
}
$vm_cpu_load_percent = $cpu_count > 0 ? round($vm_cpu_load_percent / $cpu_count, 2) : 0;

$memory = $parse_windows_agent_kv($memory_raw);
$memory_total_bytes = (float) ($memory['physical_total_bytes'] ?? 0);
$memory_free_bytes = (float) ($memory['physical_free_bytes'] ?? 0);
$memory_used_bytes = max(0, $memory_total_bytes - $memory_free_bytes);
$vm_memory_used_percent = $memory_total_bytes > 0 ? round(($memory_used_bytes / $memory_total_bytes) * 100, 2) : 0;

$disk_details = [];
$vm_disk_used_percent_max = 0;
$vm_disk_free_bytes_min = 0;
foreach (preg_split('/\r?\n/', trim((string) $disks_raw)) as $line) {
    if ($line === '') {
        continue;
    }

    $disk = $parse_windows_agent_kv($line);
    if (empty($disk['device'])) {
        continue;
    }

    $size_bytes = (float) ($disk['size_bytes'] ?? 0);
    $free_bytes = (float) ($disk['free_bytes'] ?? 0);
    $used_bytes = max(0, $size_bytes - $free_bytes);
    $used_percent = $size_bytes > 0 ? round(($used_bytes / $size_bytes) * 100, 2) : 0;
    $free_percent = $size_bytes > 0 ? round(($free_bytes / $size_bytes) * 100, 2) : 0;
    $disk['used_bytes'] = (string) (int) $used_bytes;
    $disk['used_percent'] = (string) $used_percent;
    $disk['free_percent'] = (string) $free_percent;
    $disk_details[] = $disk;
    $vm_disk_used_percent_max = max($vm_disk_used_percent_max, $used_percent);
    $vm_disk_free_bytes_min = $vm_disk_free_bytes_min === 0 ? (int) $free_bytes : min($vm_disk_free_bytes_min, (int) $free_bytes);
}

$parse_rows = static function ($raw, $key) use ($parse_windows_agent_kv): array {
    $rows = [];
    foreach (preg_split('/\r?\n/', trim((string) $raw)) as $line) {
        if ($line === '') {
            continue;
        }

        $row = $parse_windows_agent_kv($line);
        if ($key === '' || ! empty($row[$key])) {
            $rows[] = $row;
        }
    }

    return $rows;
};

$sql_summary = $parse_windows_agent_kv($sql_summary_raw);
$performance_summary = $parse_windows_agent_kv($performance_summary_raw);
$performance_disks = $parse_rows($performance_disks_raw, 'name');
$performance_network = $parse_rows($performance_network_raw, 'name');
$performance_processes = $parse_rows($performance_processes_raw, 'name');
$sql_instances = $parse_rows($sql_instances_raw, 'instance');
$iis_summary = $parse_windows_agent_kv($iis_summary_raw);
$iis_sites = $parse_rows($iis_sites_raw, 'name');
$iis_app_pools = $parse_rows($iis_app_pools_raw, 'name');
$iis_bindings = $parse_rows($iis_bindings_raw, 'site');
$horizon_summary = $parse_windows_agent_kv($horizon_summary_raw);
$horizon_services = $parse_rows($horizon_services_raw, 'name');
$horizon_processes = $parse_rows($horizon_processes_raw, 'name');
$horizon_ports = $parse_rows($horizon_ports_raw, 'port');
$horizon_certificates = $parse_rows($horizon_certificates_raw, 'thumbprint');
$factorytalk_summary = $parse_windows_agent_kv($factorytalk_summary_raw);
$factorytalk_products = $parse_rows($factorytalk_products_raw, 'name');
$factorytalk_services = $parse_rows($factorytalk_services_raw, 'name');
$factorytalk_processes = $parse_rows($factorytalk_processes_raw, 'name');
$factorytalk_ports = $parse_rows($factorytalk_ports_raw, 'port');
$tls_summary = $parse_windows_agent_kv($tls_summary_raw);
$tls_certificates = $parse_rows($tls_certificates_raw, 'thumbprint');
$backup_summary = $parse_windows_agent_kv($backup_summary_raw);
$vss_writers = $parse_rows($vss_writers_raw, 'name');
$backup_services = $parse_rows($backup_services_raw, 'name');
$datto_backup_summary = $parse_windows_agent_kv($datto_backup_summary_raw);
$datto_backup_services = $parse_rows($datto_backup_services_raw, 'name');
$datto_backup_processes = $parse_rows($datto_backup_processes_raw, 'name');
$datto_backup_evidence = $parse_rows($datto_backup_evidence_raw, 'type');
$ad_dc_health_summary = $parse_windows_agent_kv($ad_dc_health_summary_raw);
$ad_dc_services = $parse_rows($ad_dc_services_raw, 'name');
$ad_dc_dns = $parse_rows($ad_dc_dns_raw, 'state');
$ad_dc_time = $parse_rows($ad_dc_time_raw, 'state');
$ad_dc_shares = $parse_rows($ad_dc_shares_raw, 'name');
$ad_dc_security_events = $parse_rows($ad_dc_security_events_raw, 'category');

$agent_collect_duration_ms = (int) ($agent_performance['collect_duration_ms'] ?? 0);
$agent_collectors_run = (int) ($agent_performance['collectors_run'] ?? 0);
$agent_collectors_failed = (int) ($agent_performance['collectors_failed'] ?? 0);
$agent_collectors_timed_out = (int) ($agent_performance['collectors_timed_out'] ?? 0);
$agent_payload_bytes = (int) ($agent_performance['payload_bytes'] ?? 0);
$agent_process_working_set_bytes = (int) ($agent_performance['process_working_set_bytes'] ?? 0);
$agent_process_private_bytes = (int) ($agent_performance['process_private_bytes'] ?? 0);
$agent_process_cpu_ms = (int) ($agent_performance['process_cpu_ms'] ?? 0);
$agent_process_cpu_percent = (float) ($agent_performance['process_cpu_percent'] ?? 0);
$agent_process_io_bytes = (int) ($agent_performance['process_io_bytes'] ?? 0);
$agent_resource_impact_level = -1;
if (array_key_exists('process_cpu_percent', $agent_performance) || array_key_exists('process_io_bytes', $agent_performance)) {
    $agent_resource_impact_level = 0;
    if (
        $agent_process_cpu_percent > 15
        || $agent_process_working_set_bytes > 262144000
        || $agent_process_io_bytes > 104857600
        || $agent_collect_duration_ms > 30000
    ) {
        $agent_resource_impact_level = 2;
    } elseif (
        $agent_process_cpu_percent > 5
        || $agent_process_working_set_bytes > 104857600
        || $agent_process_io_bytes > 10485760
        || $agent_collect_duration_ms > 10000
    ) {
        $agent_resource_impact_level = 1;
    }
}

$fields = [
    'agent_up' => 1,
    'roles_detected_total' => $roles_detected_total,
    'ad_detected' => $ad_detected,
    'ad_replication_targets_total' => $ad_replication_targets_total,
    'ad_replication_failures_total' => $ad_replication_failures_total,
    'ad_dfsr_targets_total' => $ad_dfsr_targets_total,
    'ad_dfsr_unhealthy_total' => $ad_dfsr_unhealthy_total,
    'ad_fsmo_roles_total' => $ad_fsmo_roles_total,
    'ad_dc_security_events_total' => (int) ($ad_summary['security_events_total'] ?? 0),
    'ad_dc_security_lockouts' => (int) ($ad_summary['security_lockouts'] ?? 0),
    'ad_dc_security_auth_failures' => (int) ($ad_summary['security_auth_failures'] ?? 0),
    'ad_dc_security_privileged_changes' => (int) ($ad_summary['security_privileged_changes'] ?? 0),
    'ad_dc_detected' => (int) ($ad_dc_health_summary['dc_detected'] ?? 0),
    'ad_dc_core_services_not_running' => (int) ($ad_dc_health_summary['core_services_not_running'] ?? 0),
    'ad_dc_dns_service_running' => (int) ($ad_dc_health_summary['dns_service_running'] ?? 0),
    'ad_dc_dns_service_issue' => (int) ($ad_dc_health_summary['dns_service_issue'] ?? 0),
    'ad_dc_sysvol_share_present' => (int) ($ad_dc_health_summary['sysvol_share_present'] ?? 0),
    'ad_dc_netlogon_share_present' => (int) ($ad_dc_health_summary['netlogon_share_present'] ?? 0),
    'ad_dc_shares_missing' => (int) ($ad_dc_health_summary['shares_missing'] ?? 0),
    'ad_dc_time_issues' => (int) ($ad_dc_health_summary['time_issues'] ?? 0),
    'ad_dc_health_issues' => (int) ($ad_dc_health_summary['health_issues'] ?? 0),
    'logged_on_users_total' => $logged_on_users_total,
    'logged_on_users_active' => $logged_on_users_active,
    'logged_on_users_disconnected' => $logged_on_users_disconnected,
    'pending_reboot' => $pending_reboot,
    'windows_update_reboot_required' => $update_reboot,
    'watched_services_total' => $watched_total,
    'watched_services_not_running' => $watched_not_running,
    'service_groups_total' => $service_groups_total,
    'classified_services_total' => $classified_services_total,
    'classified_services_not_running' => $classified_services_not_running,
    'excluded_services_total' => $excluded_services_total,
    'event_log_critical_count' => $event_log_critical_count,
    'event_log_error_count' => $event_log_error_count,
    'event_log_warning_count' => $event_log_warning_count,
    'watched_processes_total' => $watched_processes_total,
    'watched_processes_not_running' => $watched_processes_not_running,
    'watched_tcp_ports_total' => $watched_tcp_ports_total,
    'watched_tcp_ports_not_listening' => $watched_tcp_ports_not_listening,
    'agent_collect_duration_ms' => $agent_collect_duration_ms,
    'agent_collectors_run' => $agent_collectors_run,
    'agent_collectors_failed' => $agent_collectors_failed,
    'agent_collectors_timed_out' => $agent_collectors_timed_out,
    'agent_payload_bytes' => $agent_payload_bytes,
    'agent_process_working_set_bytes' => $agent_process_working_set_bytes,
    'agent_process_private_bytes' => $agent_process_private_bytes,
    'agent_resource_cpu_percent' => $agent_process_cpu_percent,
    'agent_resource_io_bytes' => $agent_process_io_bytes,
    'agent_resource_impact_level' => $agent_resource_impact_level,
    'vm_cpu_load_percent' => $vm_cpu_load_percent,
    'vm_memory_used_percent' => $vm_memory_used_percent,
    'vm_disk_used_percent_max' => $vm_disk_used_percent_max,
    'vm_disk_free_bytes_min' => $vm_disk_free_bytes_min,
    'perf_cpu_queue_length' => (float) ($performance_summary['cpu_queue_length'] ?? 0),
    'perf_cpu_pressure' => (int) ($performance_summary['cpu_pressure'] ?? 0),
    'perf_memory_available_mb' => (float) ($performance_summary['memory_available_mb'] ?? 0),
    'perf_memory_committed_percent' => (float) ($performance_summary['memory_committed_percent'] ?? 0),
    'perf_memory_pressure' => (int) ($performance_summary['memory_pressure'] ?? 0),
    'perf_pages_per_sec' => (float) ($performance_summary['pages_per_sec'] ?? 0),
    'perf_paging_pressure' => (int) ($performance_summary['paging_pressure'] ?? 0),
    'perf_disk_read_ms_max' => (float) ($performance_summary['disk_read_ms_max'] ?? 0),
    'perf_disk_write_ms_max' => (float) ($performance_summary['disk_write_ms_max'] ?? 0),
    'perf_disk_queue_length_max' => (float) ($performance_summary['disk_queue_length_max'] ?? 0),
    'perf_disk_pressure' => (int) ($performance_summary['disk_pressure'] ?? 0),
    'perf_network_bytes_per_sec_total' => (float) ($performance_summary['network_bytes_per_sec_total'] ?? 0),
    'perf_network_errors_total' => (float) ($performance_summary['network_errors_total'] ?? 0),
    'perf_network_issue' => (int) ($performance_summary['network_issue'] ?? 0),
    'perf_pressure_issues' => (int) ($performance_summary['pressure_issues'] ?? 0),
    'sql_instances_total' => (int) ($sql_summary['instances_total'] ?? 0),
    'sql_instances_not_running' => (int) ($sql_summary['instances_not_running'] ?? 0),
    'iis_sites_total' => (int) ($iis_summary['sites_total'] ?? 0),
    'iis_sites_stopped' => (int) ($iis_summary['sites_stopped'] ?? 0),
    'iis_app_pools_total' => (int) ($iis_summary['app_pools_total'] ?? 0),
    'iis_app_pools_stopped' => (int) ($iis_summary['app_pools_stopped'] ?? 0),
    'horizon_detected' => (int) ($horizon_summary['detected'] ?? 0),
    'horizon_services_total' => (int) ($horizon_summary['services_total'] ?? 0),
    'horizon_services_not_running' => (int) ($horizon_summary['services_not_running'] ?? 0),
    'horizon_processes_total' => (int) ($horizon_summary['processes_total'] ?? 0),
    'horizon_ports_total' => (int) ($horizon_summary['ports_total'] ?? 0),
    'horizon_ports_listening' => (int) ($horizon_summary['ports_listening'] ?? 0),
    'horizon_certificates_total' => (int) ($horizon_summary['certificates_total'] ?? 0),
    'horizon_certificates_expired' => (int) ($horizon_summary['certificates_expired'] ?? 0),
    'horizon_certificates_expiring' => (int) ($horizon_summary['certificates_expiring'] ?? 0),
    'horizon_health_issues' => (int) ($horizon_summary['health_issues'] ?? 0),
    'factorytalk_detected' => (int) ($factorytalk_summary['detected'] ?? 0),
    'factorytalk_products_total' => (int) ($factorytalk_summary['products_total'] ?? 0),
    'factorytalk_services_total' => (int) ($factorytalk_summary['services_total'] ?? 0),
    'factorytalk_services_not_running' => (int) ($factorytalk_summary['services_not_running'] ?? 0),
    'factorytalk_core_services_not_running' => (int) ($factorytalk_summary['core_services_not_running'] ?? 0),
    'factorytalk_processes_total' => (int) ($factorytalk_summary['processes_total'] ?? 0),
    'factorytalk_ports_total' => (int) ($factorytalk_summary['ports_total'] ?? 0),
    'factorytalk_ports_listening' => (int) ($factorytalk_summary['ports_listening'] ?? 0),
    'factorytalk_health_issues' => (int) ($factorytalk_summary['health_issues'] ?? 0),
    'tls_certificates_total' => (int) ($tls_summary['certificate_count'] ?? 0),
    'tls_certificates_expired' => (int) ($tls_summary['expired_count'] ?? 0),
    'tls_certificates_expiring_warning' => (int) ($tls_summary['expiring_warning_count'] ?? 0),
    'tls_certificates_expiring_critical' => (int) ($tls_summary['expiring_critical_count'] ?? 0),
    'tls_certificates_not_yet_valid' => (int) ($tls_summary['not_yet_valid_count'] ?? 0),
    'tls_certificates_invalid_chain' => (int) ($tls_summary['invalid_chain_count'] ?? 0),
    'tls_certificates_weak_key' => (int) ($tls_summary['weak_key_count'] ?? 0),
    'tls_certificates_weak_signature' => (int) ($tls_summary['weak_signature_count'] ?? 0),
    'tls_certificates_missing_private_key' => (int) ($tls_summary['missing_private_key_count'] ?? 0),
    'tls_certificates_bound' => (int) ($tls_summary['bound_count'] ?? 0),
    'tls_certificates_binding_missing' => (int) ($tls_summary['binding_missing_count'] ?? 0),
    'tls_certificates_unhealthy' => (int) ($tls_summary['unhealthy_count'] ?? 0),
    'vss_writers_total' => (int) ($backup_summary['vss_writers_total'] ?? 0),
    'vss_writers_failed' => (int) ($backup_summary['vss_writers_failed'] ?? 0),
    'backup_services_total' => (int) ($backup_summary['backup_services_total'] ?? 0),
    'backup_services_not_running' => (int) ($backup_summary['backup_services_not_running'] ?? 0),
    'datto_backup_detected' => (int) ($datto_backup_summary['detected'] ?? 0),
    'datto_backup_service_running' => (int) ($datto_backup_summary['service_running'] ?? 0),
    'datto_backup_provider_present' => (int) ($datto_backup_summary['provider_present'] ?? 0),
    'datto_backup_provider_issue' => (int) ($datto_backup_summary['provider_issue'] ?? 0),
    'datto_backup_processes_total' => (int) ($datto_backup_summary['processes_total'] ?? 0),
    'datto_backup_recent_errors' => (int) ($datto_backup_summary['recent_errors'] ?? 0),
    'datto_backup_recent_critical_failures' => (int) ($datto_backup_summary['recent_critical_failures'] ?? 0),
    'datto_backup_last_success_age_hours' => (int) ($datto_backup_summary['last_success_age_hours'] ?? -1),
    'datto_backup_stale_warning' => (int) ($datto_backup_summary['stale_warning'] ?? 0),
    'datto_backup_stale_critical' => (int) ($datto_backup_summary['stale_critical'] ?? 0),
    'datto_backup_health_issues' => (int) ($datto_backup_summary['health_issues'] ?? 0),
];

$status_parts = [
    'version=' . ($agent['version'] ?? 'unknown'),
    'os=' . ($os['caption'] ?? 'unknown'),
    'roles=' . $roles_detected_total,
    'ad_repl_failures=' . $ad_replication_failures_total,
    'dfsr_unhealthy=' . $ad_dfsr_unhealthy_total,
    'ad_sec_lockouts=' . ($fields['ad_dc_security_lockouts'] ?? 0),
    'ad_sec_auth_failures=' . ($fields['ad_dc_security_auth_failures'] ?? 0),
    'ad_dc_state=' . ($ad_dc_health_summary['state'] ?? 'not_applicable'),
    'ad_dc_issues=' . ($fields['ad_dc_health_issues'] ?? 0),
    'users=' . $logged_on_users_total,
    'users_active=' . $logged_on_users_active,
    'users_disc=' . $logged_on_users_disconnected,
    'pending_reboot=' . $pending_reboot,
    'update_reboot=' . $update_reboot,
    'watched_services_not_running=' . $watched_not_running,
    'classified_services_down=' . $classified_services_not_running,
    'excluded_services=' . $excluded_services_total,
    'events_e=' . $event_log_error_count,
    'processes_down=' . $watched_processes_not_running,
    'tcp_down=' . $watched_tcp_ports_not_listening,
    'perf_ms=' . $agent_collect_duration_ms,
    'agent_impact=' . (['unknown', 'low', 'moderate', 'high'][$agent_resource_impact_level + 1] ?? 'unknown'),
    'perf_pressure=' . ($fields['perf_pressure_issues'] ?? 0),
    'sql_down=' . ($fields['sql_instances_not_running'] ?? 0),
    'iis_sites_down=' . ($fields['iis_sites_stopped'] ?? 0),
    'iis_pools_down=' . ($fields['iis_app_pools_stopped'] ?? 0),
    'horizon_state=' . ($horizon_summary['state'] ?? 'not_detected'),
    'horizon_issues=' . ($fields['horizon_health_issues'] ?? 0),
    'factorytalk_state=' . ($factorytalk_summary['state'] ?? 'not_detected'),
    'factorytalk_issues=' . ($fields['factorytalk_health_issues'] ?? 0),
    'tls_expired=' . ($fields['tls_certificates_expired'] ?? 0),
    'tls_unhealthy=' . ($fields['tls_certificates_unhealthy'] ?? 0),
    'vss_failed=' . ($fields['vss_writers_failed'] ?? 0),
    'datto_state=' . ($datto_backup_summary['state'] ?? 'not_detected'),
    'datto_issues=' . ($fields['datto_backup_health_issues'] ?? 0),
];
if (! empty($failed_services)) {
    $status_parts[] = 'failed_services=' . implode(',', $failed_services);
}

$response = ($pending_reboot || $update_reboot || $watched_not_running > 0)
    ? 'WARNING: ' . implode(' ', $status_parts)
    : 'OK: ' . implode(' ', $status_parts);

$app_id = dbFetchCell(
    'SELECT `app_id` FROM `applications` WHERE `device_id` = ? AND `app_type` = ? AND `app_instance` = ? AND `deleted_at` IS NULL LIMIT 1',
    [$device['device_id'], $name, '']
);

if (empty($app_id)) {
    echo "Found new application '$name'\n";
    $app_id = dbInsert([
        'device_id' => $device['device_id'],
        'app_type' => $name,
        'app_status' => '',
        'app_instance' => '',
        'discovered' => 1,
    ], 'applications');
}

$windows_agent_app = Application::find($app_id);
if (! $windows_agent_app) {
    return;
}

$windows_agent_app->data = [
    'agent' => $agent,
    'windows_os' => $os,
    'roles' => $roles,
    'ad_summary' => $ad_summary,
    'ad_replication' => $ad_replication,
    'ad_dfsr' => $ad_dfsr,
    'ad_fsmo' => $ad_fsmo,
    'ad_dc_health_summary' => $ad_dc_health_summary,
    'ad_dc_services' => $ad_dc_services,
    'ad_dc_dns' => $ad_dc_dns,
    'ad_dc_time' => $ad_dc_time,
    'ad_dc_shares' => $ad_dc_shares,
    'ad_dc_security_events' => $ad_dc_security_events,
    'logged_on_users' => $logged_on_users,
    'pending_reboot' => $pending,
    'windows_update' => $windows_update,
    'watched_services' => $watched_services,
    'classified_services' => $classified_services,
    'classified_service_groups' => $service_groups,
    'service_group_summaries' => $service_group_summaries,
    'failed_services' => $failed_services,
    'excluded_services' => $excluded_services,
    'event_logs' => $event_log_details,
    'event_log_summary' => [
        'critical_count' => $event_log_critical_count,
        'error_count' => $event_log_error_count,
        'warning_count' => $event_log_warning_count,
    ],
    'event_log_high_value_summary' => $event_log_high_value_summary,
    'event_log_high_value' => $event_log_high_value,
    'watched_processes' => $watched_processes,
    'process_summary' => [
        'watched_total' => $watched_processes_total,
        'not_running' => $watched_processes_not_running,
    ],
    'watched_tcp_ports' => $watched_tcp_ports,
    'tcp_port_summary' => [
        'watched_total' => $watched_tcp_ports_total,
        'not_listening' => $watched_tcp_ports_not_listening,
    ],
    'agent_performance' => $agent_performance,
    'collector_timings' => $collector_timings,
    'cpu' => $cpu_details,
    'memory' => array_merge($memory, [
        'used_bytes' => (string) (int) $memory_used_bytes,
        'used_percent' => (string) $vm_memory_used_percent,
    ]),
    'disks' => $disk_details,
    'vm_resource_summary' => [
        'cpu_load_percent' => $vm_cpu_load_percent,
        'memory_used_percent' => $vm_memory_used_percent,
        'disk_used_percent_max' => $vm_disk_used_percent_max,
        'disk_free_bytes_min' => $vm_disk_free_bytes_min,
    ],
    'performance_summary' => $performance_summary,
    'performance_disks' => $performance_disks,
    'performance_network' => $performance_network,
    'performance_processes' => $performance_processes,
    'sql_server_summary' => $sql_summary,
    'sql_server_instances' => $sql_instances,
    'iis_summary' => $iis_summary,
    'iis_sites' => $iis_sites,
    'iis_app_pools' => $iis_app_pools,
    'iis_bindings' => $iis_bindings,
    'horizon_summary' => $horizon_summary,
    'horizon_services' => $horizon_services,
    'horizon_processes' => $horizon_processes,
    'horizon_ports' => $horizon_ports,
    'horizon_certificates' => $horizon_certificates,
    'factorytalk_summary' => $factorytalk_summary,
    'factorytalk_products' => $factorytalk_products,
    'factorytalk_services' => $factorytalk_services,
    'factorytalk_processes' => $factorytalk_processes,
    'factorytalk_ports' => $factorytalk_ports,
    'tls_certificates_summary' => $tls_summary,
    'tls_certificates' => $tls_certificates,
    'backup_storage_summary' => $backup_summary,
    'vss_writers' => $vss_writers,
    'backup_services' => $backup_services,
    'datto_backup_summary' => $datto_backup_summary,
    'datto_backup_services' => $datto_backup_services,
    'datto_backup_processes' => $datto_backup_processes,
    'datto_backup_evidence' => $datto_backup_evidence,
    'last_agent_utc' => $agent['utc'] ?? '',
];

$rrd_def = RrdDefinition::make()
    ->addDataset('agent_up', 'GAUGE', 0, 1)
    ->addDataset('pending_reboot', 'GAUGE', 0, 1)
    ->addDataset('windows_update_reboot_required', 'GAUGE', 0, 1)
    ->addDataset('watched_services_total', 'GAUGE', 0)
    ->addDataset('watched_services_not_running', 'GAUGE', 0);

$tags = [
    'name' => $name,
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', $name, $windows_agent_app->app_id],
    'rrd_def' => $rrd_def,
];

$rrd_fields = array_intersect_key($fields, array_flip([
    'agent_up',
    'pending_reboot',
    'windows_update_reboot_required',
    'watched_services_total',
    'watched_services_not_running',
]));

app('Datastore')->put($device, 'app', $tags, $rrd_fields);

$classified_services_rrd_def = RrdDefinition::make()
    ->addDataset('total', 'GAUGE', 0)
    ->addDataset('not_running', 'GAUGE', 0)
    ->addDataset('excluded', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-classified-services',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-classified-services', $windows_agent_app->app_id],
    'rrd_def' => $classified_services_rrd_def,
], [
    'total' => $classified_services_total,
    'not_running' => $classified_services_not_running,
    'excluded' => $excluded_services_total,
]);

$event_rrd_def = RrdDefinition::make()
    ->addDataset('critical', 'GAUGE', 0)
    ->addDataset('error', 'GAUGE', 0)
    ->addDataset('warning', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-event-logs',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-event-logs', $windows_agent_app->app_id],
    'rrd_def' => $event_rrd_def,
], [
    'critical' => $event_log_critical_count,
    'error' => $event_log_error_count,
    'warning' => $event_log_warning_count,
]);

$process_rrd_def = RrdDefinition::make()
    ->addDataset('total', 'GAUGE', 0)
    ->addDataset('not_running', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-processes',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-processes', $windows_agent_app->app_id],
    'rrd_def' => $process_rrd_def,
], [
    'total' => $watched_processes_total,
    'not_running' => $watched_processes_not_running,
]);

$tcp_rrd_def = RrdDefinition::make()
    ->addDataset('total', 'GAUGE', 0)
    ->addDataset('not_listening', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-tcp-ports',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-tcp-ports', $windows_agent_app->app_id],
    'rrd_def' => $tcp_rrd_def,
], [
    'total' => $watched_tcp_ports_total,
    'not_listening' => $watched_tcp_ports_not_listening,
]);

$performance_rrd_def = RrdDefinition::make()
    ->addDataset('duration_ms', 'GAUGE', 0)
    ->addDataset('payload_bytes', 'GAUGE', 0)
    ->addDataset('failed', 'GAUGE', 0)
    ->addDataset('timed_out', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-performance',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-performance', $windows_agent_app->app_id],
    'rrd_def' => $performance_rrd_def,
], [
    'duration_ms' => $agent_collect_duration_ms,
    'payload_bytes' => $agent_payload_bytes,
    'failed' => $agent_collectors_failed,
    'timed_out' => $agent_collectors_timed_out,
]);

$resource_impact_rrd_def = RrdDefinition::make()
    ->addDataset('cpu_percent', 'GAUGE', 0, 100)
    ->addDataset('memory_bytes', 'GAUGE', 0)
    ->addDataset('io_bytes', 'GAUGE', 0)
    ->addDataset('impact_level', 'GAUGE', -1, 2);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-resource-impact',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-resource-impact', $windows_agent_app->app_id],
    'rrd_def' => $resource_impact_rrd_def,
], [
    'cpu_percent' => $agent_process_cpu_percent,
    'memory_bytes' => $agent_process_working_set_bytes,
    'io_bytes' => $agent_process_io_bytes,
    'impact_level' => $agent_resource_impact_level,
]);

$vm_resources_rrd_def = RrdDefinition::make()
    ->addDataset('cpu_load', 'GAUGE', 0, 100)
    ->addDataset('memory_used', 'GAUGE', 0, 100)
    ->addDataset('disk_used_max', 'GAUGE', 0, 100);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-vm-resources',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-vm-resources', $windows_agent_app->app_id],
    'rrd_def' => $vm_resources_rrd_def,
], [
    'cpu_load' => $vm_cpu_load_percent,
    'memory_used' => $vm_memory_used_percent,
    'disk_used_max' => $vm_disk_used_percent_max,
]);

$performance_depth_rrd_def = RrdDefinition::make()
    ->addDataset('cpu_queue', 'GAUGE', 0)
    ->addDataset('mem_committed', 'GAUGE', 0, 100)
    ->addDataset('pages_sec', 'GAUGE', 0)
    ->addDataset('disk_read_ms', 'GAUGE', 0)
    ->addDataset('disk_write_ms', 'GAUGE', 0)
    ->addDataset('disk_queue', 'GAUGE', 0)
    ->addDataset('issues', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-performance-depth',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-performance-depth', $windows_agent_app->app_id],
    'rrd_def' => $performance_depth_rrd_def,
], [
    'cpu_queue' => $fields['perf_cpu_queue_length'],
    'mem_committed' => $fields['perf_memory_committed_percent'],
    'pages_sec' => $fields['perf_pages_per_sec'],
    'disk_read_ms' => $fields['perf_disk_read_ms_max'],
    'disk_write_ms' => $fields['perf_disk_write_ms_max'],
    'disk_queue' => $fields['perf_disk_queue_length_max'],
    'issues' => $fields['perf_pressure_issues'],
]);

$ad_dc_health_rrd_def = RrdDefinition::make()
    ->addDataset('core_down', 'GAUGE', 0)
    ->addDataset('dns_issue', 'GAUGE', 0, 1)
    ->addDataset('shares_missing', 'GAUGE', 0)
    ->addDataset('time_issues', 'GAUGE', 0)
    ->addDataset('health_issues', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-ad-dc-health',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-ad-dc-health', $windows_agent_app->app_id],
    'rrd_def' => $ad_dc_health_rrd_def,
], [
    'core_down' => $fields['ad_dc_core_services_not_running'],
    'dns_issue' => $fields['ad_dc_dns_service_issue'],
    'shares_missing' => $fields['ad_dc_shares_missing'],
    'time_issues' => $fields['ad_dc_time_issues'],
    'health_issues' => $fields['ad_dc_health_issues'],
]);

$sql_rrd_def = RrdDefinition::make()
    ->addDataset('instances_total', 'GAUGE', 0)
    ->addDataset('not_running', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-sql-server',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-sql-server', $windows_agent_app->app_id],
    'rrd_def' => $sql_rrd_def,
], [
    'instances_total' => $fields['sql_instances_total'],
    'not_running' => $fields['sql_instances_not_running'],
]);

$iis_rrd_def = RrdDefinition::make()
    ->addDataset('sites_total', 'GAUGE', 0)
    ->addDataset('sites_stopped', 'GAUGE', 0)
    ->addDataset('app_pools_total', 'GAUGE', 0)
    ->addDataset('app_pools_stopped', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-iis',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-iis', $windows_agent_app->app_id],
    'rrd_def' => $iis_rrd_def,
], [
    'sites_total' => $fields['iis_sites_total'],
    'sites_stopped' => $fields['iis_sites_stopped'],
    'app_pools_total' => $fields['iis_app_pools_total'],
    'app_pools_stopped' => $fields['iis_app_pools_stopped'],
]);

$horizon_rrd_def = RrdDefinition::make()
    ->addDataset('detected', 'GAUGE', 0, 1)
    ->addDataset('services_down', 'GAUGE', 0)
    ->addDataset('ports_listening', 'GAUGE', 0)
    ->addDataset('cert_expired', 'GAUGE', 0)
    ->addDataset('cert_expiring', 'GAUGE', 0)
    ->addDataset('health_issues', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-horizon',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-horizon', $windows_agent_app->app_id],
    'rrd_def' => $horizon_rrd_def,
], [
    'detected' => $fields['horizon_detected'],
    'services_down' => $fields['horizon_services_not_running'],
    'ports_listening' => $fields['horizon_ports_listening'],
    'cert_expired' => $fields['horizon_certificates_expired'],
    'cert_expiring' => $fields['horizon_certificates_expiring'],
    'health_issues' => $fields['horizon_health_issues'],
]);

$factorytalk_rrd_def = RrdDefinition::make()
    ->addDataset('detected', 'GAUGE', 0, 1)
    ->addDataset('services_down', 'GAUGE', 0)
    ->addDataset('core_down', 'GAUGE', 0)
    ->addDataset('ports_listening', 'GAUGE', 0)
    ->addDataset('health_issues', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-factorytalk',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-factorytalk', $windows_agent_app->app_id],
    'rrd_def' => $factorytalk_rrd_def,
], [
    'detected' => $fields['factorytalk_detected'],
    'services_down' => $fields['factorytalk_services_not_running'],
    'core_down' => $fields['factorytalk_core_services_not_running'],
    'ports_listening' => $fields['factorytalk_ports_listening'],
    'health_issues' => $fields['factorytalk_health_issues'],
]);

$tls_rrd_def = RrdDefinition::make()
    ->addDataset('total', 'GAUGE', 0)
    ->addDataset('expired', 'GAUGE', 0)
    ->addDataset('warning', 'GAUGE', 0)
    ->addDataset('critical', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-tls-certificates',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-tls-certificates', $windows_agent_app->app_id],
    'rrd_def' => $tls_rrd_def,
], [
    'total' => $fields['tls_certificates_total'],
    'expired' => $fields['tls_certificates_expired'],
    'warning' => $fields['tls_certificates_expiring_warning'],
    'critical' => $fields['tls_certificates_expiring_critical'],
]);

$tls_health_rrd_def = RrdDefinition::make()
    ->addDataset('unhealthy', 'GAUGE', 0)
    ->addDataset('invalid_chain', 'GAUGE', 0)
    ->addDataset('weak_key', 'GAUGE', 0)
    ->addDataset('missing_key', 'GAUGE', 0)
    ->addDataset('binding_missing', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-tls-health',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-tls-health', $windows_agent_app->app_id],
    'rrd_def' => $tls_health_rrd_def,
], [
    'unhealthy' => $fields['tls_certificates_unhealthy'],
    'invalid_chain' => $fields['tls_certificates_invalid_chain'],
    'weak_key' => $fields['tls_certificates_weak_key'],
    'missing_key' => $fields['tls_certificates_missing_private_key'],
    'binding_missing' => $fields['tls_certificates_binding_missing'],
]);

$backup_rrd_def = RrdDefinition::make()
    ->addDataset('vss_writers_total', 'GAUGE', 0)
    ->addDataset('vss_writers_failed', 'GAUGE', 0)
    ->addDataset('services_total', 'GAUGE', 0)
    ->addDataset('services_not_running', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-backup-storage',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-backup-storage', $windows_agent_app->app_id],
    'rrd_def' => $backup_rrd_def,
], [
    'vss_writers_total' => $fields['vss_writers_total'],
    'vss_writers_failed' => $fields['vss_writers_failed'],
    'services_total' => $fields['backup_services_total'],
    'services_not_running' => $fields['backup_services_not_running'],
]);

$datto_backup_rrd_def = RrdDefinition::make()
    ->addDataset('detected', 'GAUGE', 0, 1)
    ->addDataset('service_running', 'GAUGE', 0, 1)
    ->addDataset('recent_errors', 'GAUGE', 0)
    ->addDataset('critical_failures', 'GAUGE', 0)
    ->addDataset('stale_warning', 'GAUGE', 0, 1)
    ->addDataset('stale_critical', 'GAUGE', 0, 1)
    ->addDataset('health_issues', 'GAUGE', 0);

app('Datastore')->put($device, 'app', [
    'name' => 'windows-agent-datto-backup',
    'app_id' => $windows_agent_app->app_id,
    'rrd_name' => ['app', 'windows-agent-datto-backup', $windows_agent_app->app_id],
    'rrd_def' => $datto_backup_rrd_def,
], [
    'detected' => $fields['datto_backup_detected'],
    'service_running' => $fields['datto_backup_service_running'],
    'recent_errors' => $fields['datto_backup_recent_errors'],
    'critical_failures' => $fields['datto_backup_recent_critical_failures'],
    'stale_warning' => $fields['datto_backup_stale_warning'],
    'stale_critical' => $fields['datto_backup_stale_critical'],
    'health_issues' => $fields['datto_backup_health_issues'],
]);

update_application($windows_agent_app, $response, $fields, $response);
