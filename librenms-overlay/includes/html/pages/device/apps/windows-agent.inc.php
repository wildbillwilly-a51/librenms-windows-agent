<?php

$data = is_array($app->data ?? null) ? $app->data : [];
$agent = $data['agent'] ?? [];
$windows_os = $data['windows_os'] ?? [];
$roles = $data['roles'] ?? [];
$ad_summary = $data['ad_summary'] ?? [];
$ad_replication = $data['ad_replication'] ?? [];
$ad_dfsr = $data['ad_dfsr'] ?? [];
$ad_fsmo = $data['ad_fsmo'] ?? [];
$ad_dc_health_summary = $data['ad_dc_health_summary'] ?? [];
$ad_dc_services = $data['ad_dc_services'] ?? [];
$ad_dc_dns = $data['ad_dc_dns'] ?? [];
$ad_dc_time = $data['ad_dc_time'] ?? [];
$ad_dc_shares = $data['ad_dc_shares'] ?? [];
$ad_dc_security_events = $data['ad_dc_security_events'] ?? [];
$logged_on_users = $data['logged_on_users'] ?? [];
$pending_reboot = $data['pending_reboot'] ?? [];
$windows_update = $data['windows_update'] ?? [];
$watched_services = $data['watched_services'] ?? [];
$classified_service_groups = $data['classified_service_groups'] ?? [];
$service_group_summaries = $data['service_group_summaries'] ?? [];
$excluded_services = $data['excluded_services'] ?? [];
$event_logs = $data['event_logs'] ?? [];
$event_log_high_value_summary = $data['event_log_high_value_summary'] ?? [];
$event_log_high_value = $data['event_log_high_value'] ?? [];
$watched_processes = $data['watched_processes'] ?? [];
$watched_tcp_ports = $data['watched_tcp_ports'] ?? [];
$agent_performance = $data['agent_performance'] ?? [];
$collector_timings = $data['collector_timings'] ?? [];
$cpu_details = $data['cpu'] ?? [];
$memory = $data['memory'] ?? [];
$disks = $data['disks'] ?? [];
$vm_resource_summary = $data['vm_resource_summary'] ?? [];
$performance_summary = $data['performance_summary'] ?? [];
$performance_disks = $data['performance_disks'] ?? [];
$performance_network = $data['performance_network'] ?? [];
$performance_processes = $data['performance_processes'] ?? [];
$sql_server_summary = $data['sql_server_summary'] ?? [];
$sql_server_instances = $data['sql_server_instances'] ?? [];
$iis_summary = $data['iis_summary'] ?? [];
$iis_sites = $data['iis_sites'] ?? [];
$iis_app_pools = $data['iis_app_pools'] ?? [];
$iis_bindings = $data['iis_bindings'] ?? [];
$horizon_summary = $data['horizon_summary'] ?? [];
$horizon_services = $data['horizon_services'] ?? [];
$horizon_processes = $data['horizon_processes'] ?? [];
$horizon_ports = $data['horizon_ports'] ?? [];
$horizon_certificates = $data['horizon_certificates'] ?? [];
$factorytalk_summary = $data['factorytalk_summary'] ?? [];
$factorytalk_products = $data['factorytalk_products'] ?? [];
$factorytalk_services = $data['factorytalk_services'] ?? [];
$factorytalk_processes = $data['factorytalk_processes'] ?? [];
$factorytalk_ports = $data['factorytalk_ports'] ?? [];
$factorytalk_runtime_summary = $data['factorytalk_runtime_summary'] ?? [];
$factorytalk_runtime_processes = $data['factorytalk_runtime_processes'] ?? [];
$factorytalk_native_summary = $data['factorytalk_native_summary'] ?? [];
$factorytalk_linx_connections = $data['factorytalk_linx_connections'] ?? [];
$factorytalk_linx_backplane = $data['factorytalk_linx_backplane'] ?? [];
$factorytalk_linx_transactions = $data['factorytalk_linx_transactions'] ?? [];
$factorytalk_livedata = $data['factorytalk_livedata'] ?? [];
$tls_certificates_summary = $data['tls_certificates_summary'] ?? [];
$tls_certificates = $data['tls_certificates'] ?? [];
$backup_storage_summary = $data['backup_storage_summary'] ?? [];
$vss_writers = $data['vss_writers'] ?? [];
$backup_services = $data['backup_services'] ?? [];
$datto_backup_summary = $data['datto_backup_summary'] ?? [];
$datto_backup_services = $data['datto_backup_services'] ?? [];
$datto_backup_processes = $data['datto_backup_processes'] ?? [];
$datto_backup_evidence = $data['datto_backup_evidence'] ?? [];
$app_id = is_array($app) ? ($app['app_id'] ?? 0) : ($app->app_id ?? 0);

$esc = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$format_bytes = static function ($value): string {
    $bytes = (float) $value;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }

    return number_format($bytes, $index === 0 ? 0 : 2) . ' ' . $units[$index];
};
$format_percent = static function ($value): string {
    return number_format((float) $value, 2) . '%';
};

$sum_field = static function (array $rows, string $field): int {
    $total = 0;
    foreach ($rows as $row) {
        $total += (int) ($row[$field] ?? 0);
    }

    return $total;
};

$section_state = static function ($raw, int $issues = 0) use ($esc): array {
    $state = strtolower(trim((string) $raw));
    if ($state === '') {
        $state = $issues > 0 ? 'warning' : 'ok';
    }

    if ($issues > 0 && in_array($state, ['ok', 'running', 'stable', 'healthy'], true)) {
        $state = 'warning';
    }

    $labels = [
        'ok' => ['OK', 'success'],
        'running' => ['Running', 'success'],
        'stable' => ['Stable', 'success'],
        'healthy' => ['Healthy', 'success'],
        'warning' => ['Warning', 'warning'],
        'critical' => ['Critical', 'danger'],
        'error' => ['Error', 'danger'],
        'failed' => ['Failed', 'danger'],
        'not_detected' => ['Not detected', 'default'],
        'not_applicable' => ['Not applicable', 'default'],
        'disabled' => ['Disabled', 'default'],
        'unsupported' => ['Unsupported', 'default'],
        'unknown' => ['Unknown', 'default'],
        '1' => ['OK', 'success'],
        '0' => ['Issue', 'danger'],
    ];
    $label = $labels[$state] ?? [ucwords(str_replace('_', ' ', $state)), $issues > 0 ? 'warning' : 'default'];

    return [
        'key' => $state,
        'text' => $label[0],
        'class' => $label[1],
        'html' => '<span class="label label-' . $label[1] . '">' . $esc($label[0]) . '</span>',
    ];
};

$state_label = static function ($value, array $healthy = ['running', 'ok', '1', 'true']) use ($esc): string {
    $text = (string) $value;
    $class = in_array(strtolower($text), $healthy, true) ? 'label-success' : 'label-danger';

    return '<span class="label ' . $class . '">' . $esc($text === '' ? 'unknown' : $text) . '</span>';
};

$has_role_details = static function (array $summary, array $rows): bool {
    $state = strtolower((string) ($summary['state'] ?? ''));
    if (in_array($state, ['not_detected', 'disabled', 'unsupported', 'not_applicable'], true)) {
        return false;
    }

    return ! empty($rows);
};

$table = static function (array $headers, array $rows, callable $row_renderer) use ($esc): string {
    if (empty($rows)) {
        return '';
    }

    $html = '<div class="table-responsive"><table class="table table-condensed table-striped windows-agent-data-table">';
    $html .= '<tr>';
    foreach ($headers as $header) {
        $html .= '<th>' . $esc($header) . '</th>';
    }
    $html .= '</tr>';
    foreach ($rows as $row) {
        $html .= '<tr>' . $row_renderer($row) . '</tr>';
    }
    $html .= '</table></div>';

    return $html;
};

$issue_first = static function (array $rows, callable $score, string $tie_field = 'name'): array {
    usort($rows, static function (array $left, array $right) use ($score, $tie_field): int {
        $left_score = (int) $score($left);
        $right_score = (int) $score($right);
        if ($left_score !== $right_score) {
            return $right_score <=> $left_score;
        }

        return strcasecmp((string) ($left[$tie_field] ?? ''), (string) ($right[$tie_field] ?? ''));
    });

    return $rows;
};

$kv_table = static function (array $rows) use ($esc): string {
    $html = '<div class="table-responsive"><table class="table table-condensed table-striped">';
    foreach ($rows as $label => $value) {
        $html .= '<tr><th>' . $esc($label) . '</th><td>' . $value . '</td></tr>';
    }
    $html .= '</table></div>';

    return $html;
};

$render_graph_html = static function (string $key) use ($app_id): string {
    $graph_type = $key;
    $graph_array = [];
    $graph_array['height'] = '100';
    $graph_array['width'] = '215';
    $graph_array['to'] = \App\Facades\LibrenmsConfig::get('time.now');
    $graph_array['id'] = $app_id;
    $graph_array['type'] = 'application_' . $key;

    ob_start();
    include 'includes/html/print-graphrow.inc.php';
    return (string) ob_get_clean();
};

$state_has_issue = static function (array $state): bool {
    return in_array($state['class'] ?? '', ['warning', 'danger'], true);
};

$render_section_summary = static function (string $id, string $title, array $state, string $summary, string $details = '', array $graphs = [], string $details_label = 'Details', string $graphs_label = 'Graphs') use ($esc, $render_graph_html): string {
    if (in_array($state['key'] ?? '', ['not_detected', 'disabled', 'unsupported', 'not_applicable'], true)) {
        $graphs = [];
    }

    $arrow = '<span class="windows-agent-collapse-arrow windows-agent-collapse-arrow-down glyphicon glyphicon-chevron-down" aria-hidden="true"></span><span class="windows-agent-collapse-arrow windows-agent-collapse-arrow-up glyphicon glyphicon-chevron-up" aria-hidden="true"></span>';
    $html = '<div class="panel panel-default windows-agent-section" id="windows-agent-section-' . $esc($id) . '">';
    $html .= '<div class="panel-heading">';
    $html .= '<div class="row">';
    $html .= '<div class="col-md-3"><strong>' . $esc($title) . '</strong> ' . $state['html'] . '</div>';
    $html .= '<div class="col-md-6">' . $summary . '</div>';
    $html .= '<div class="col-md-3 text-right">';
    if ($details !== '') {
        $html .= '<a class="btn btn-xs btn-default collapsed windows-agent-collapse-toggle" data-toggle="collapse" href="#windows-agent-details-' . $esc($id) . '" aria-expanded="false">' . $esc($details_label) . ' ' . $arrow . '</a> ';
    }
    if (! empty($graphs)) {
        $html .= '<a class="btn btn-xs btn-default collapsed windows-agent-collapse-toggle" data-toggle="collapse" href="#windows-agent-graphs-' . $esc($id) . '" aria-expanded="false">' . $esc($graphs_label) . ' ' . $arrow . '</a>';
    }
    $html .= '</div></div></div>';
    if ($details !== '' || ! empty($graphs)) {
        $html .= '<div class="panel-body">';
        if ($details !== '') {
            $html .= '<div id="windows-agent-details-' . $esc($id) . '" class="collapse windows-agent-details-collapse">' . $details . '</div>';
        }
        if (! empty($graphs)) {
            $html .= '<div id="windows-agent-graphs-' . $esc($id) . '" class="collapse windows-agent-graph-collapse">';
            $secondary_graph_html = '';
            foreach ($graphs as $graph) {
                $graph_key = $graph['key'] ?? '';
                if ($graph_key === '') {
                    continue;
                }

                $graph_html = '<div class="windows-agent-graph-view">';
                $graph_html .= '<h4>' . $esc($graph['label'] ?? 'Graph') . '</h4>';
                $graph_html .= $render_graph_html($graph_key);
                $graph_html .= '</div>';
                if ((bool) ($graph['secondary'] ?? false)) {
                    $secondary_graph_html .= $graph_html;
                } else {
                    $html .= $graph_html;
                }
            }
            if ($secondary_graph_html !== '') {
                $secondary_id = 'windows-agent-secondary-graphs-' . $esc($id);
                $html .= '<div class="windows-agent-subsection">';
                $html .= '<a class="btn btn-sm btn-default collapsed windows-agent-collapse-toggle" data-toggle="collapse" href="#' . $secondary_id . '" aria-expanded="false">Additional graphs ' . $arrow . '</a>';
                $html .= '<div id="' . $secondary_id . '" class="collapse windows-agent-subsection-body">' . $secondary_graph_html . '</div></div>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
};

$render_tab = static function (string $id, bool $active, string $body) use ($esc): void {
    echo '<div role="tabpanel" class="tab-pane' . ($active ? ' active' : '') . '" id="' . $esc($id) . '">';
    echo $body;
    echo '</div>';
};

$metric = static function (string $label, $value) use ($esc): string {
    return '<span class="text-muted">' . $esc($label) . ':</span> <strong>' . $esc($value) . '</strong>';
};

$format_duration = static function ($value): string {
    $seconds = max(0, (int) $value);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($days > 0) {
        return $days . 'd ' . $hours . 'h';
    }
    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }

    return $minutes . 'm';
};

$render_disclosure = static function (string $id, string $label, string $body, string $summary = '') use ($esc): string {
    if ($body === '') {
        return '';
    }

    $arrow = '<span class="windows-agent-collapse-arrow windows-agent-collapse-arrow-down glyphicon glyphicon-chevron-down" aria-hidden="true"></span><span class="windows-agent-collapse-arrow windows-agent-collapse-arrow-up glyphicon glyphicon-chevron-up" aria-hidden="true"></span>';
    $html = '<div class="windows-agent-subsection">';
    $html .= '<a class="btn btn-sm btn-default collapsed windows-agent-collapse-toggle" data-toggle="collapse" href="#' . $esc($id) . '" aria-expanded="false">' . $esc($label) . ' ' . $arrow . '</a>';
    if ($summary !== '') {
        $html .= ' <span class="text-muted windows-agent-disclosure-summary">' . $esc($summary) . '</span>';
    }
    $html .= '<div id="' . $esc($id) . '" class="collapse windows-agent-subsection-body">' . $body . '</div></div>';

    return $html;
};

$agent_issues = (int) ($agent_performance['collectors_failed'] ?? 0) + (int) ($agent_performance['collectors_timed_out'] ?? 0);
$agent_resource_cpu_percent = (float) ($agent_performance['process_cpu_percent'] ?? 0);
$agent_resource_io_bytes = (float) ($agent_performance['process_io_bytes'] ?? 0);
$agent_resource_memory_bytes = (float) ($agent_performance['process_working_set_bytes'] ?? 0);
$agent_resource_duration_ms = (int) ($agent_performance['collect_duration_ms'] ?? 0);
$agent_resource_known = array_key_exists('process_cpu_percent', $agent_performance) || array_key_exists('process_io_bytes', $agent_performance);
$agent_resource_impact_key = 'unknown';
if ($agent_resource_known) {
    $agent_resource_impact_key = 'low';
    if (
        $agent_resource_cpu_percent > 15
        || $agent_resource_memory_bytes > 262144000
        || $agent_resource_io_bytes > 104857600
        || $agent_resource_duration_ms > 30000
    ) {
        $agent_resource_impact_key = 'high';
    } elseif (
        $agent_resource_cpu_percent > 5
        || $agent_resource_memory_bytes > 104857600
        || $agent_resource_io_bytes > 10485760
        || $agent_resource_duration_ms > 10000
    ) {
        $agent_resource_impact_key = 'moderate';
    }
}
$agent_resource_states = [
    'low' => ['key' => 'low', 'text' => 'Low', 'class' => 'success', 'html' => '<span class="label label-success">Low</span>'],
    'moderate' => ['key' => 'moderate', 'text' => 'Moderate', 'class' => 'default', 'html' => '<span class="label label-default">Moderate</span>'],
    'high' => ['key' => 'high', 'text' => 'High', 'class' => 'warning', 'html' => '<span class="label label-warning">High</span>'],
    'unknown' => ['key' => 'unknown', 'text' => 'Unknown', 'class' => 'default', 'html' => '<span class="label label-default">Unknown</span>'],
];
$agent_resource_state = $agent_resource_states[$agent_resource_impact_key] ?? $agent_resource_states['unknown'];
$agent_resource_assessment = [
    'low' => 'No meaningful host impact detected.',
    'moderate' => 'Collector load is noticeable but still within expected bounds.',
    'high' => 'Collector load may be slowing the host; review details and collector timings.',
    'unknown' => 'Upgrade the Windows agent to report collector resource impact.',
][$agent_resource_impact_key] ?? 'Collector resource impact is unknown.';
$vm_state = empty($vm_resource_summary) ? $section_state('unknown') : $section_state('ok');
$classified_services_stopped = $sum_field($service_group_summaries, 'not_running');
$watched_service_issues = 0;
foreach ($watched_services as $service) {
    if (($service['state'] ?? '') !== 'Running') {
        $watched_service_issues++;
    }
}
$event_evidence_count = $sum_field($event_logs, 'critical_count') + $sum_field($event_logs, 'error_count');
$backup_health_issues = (int) ($backup_storage_summary['vss_writers_failed'] ?? 0);
$backup_summary_state = strtolower((string) ($backup_storage_summary['state'] ?? 'not_detected'));
$backup_state_key = in_array($backup_summary_state, ['not_detected', 'disabled', 'unsupported', 'not_applicable'], true)
    ? $backup_summary_state
    : ($backup_health_issues > 0 ? 'warning' : 'ok');
$tls_certificate_count = (int) ($tls_certificates_summary['certificate_count'] ?? 0);
$tls_unhealthy_count = (int) ($tls_certificates_summary['unhealthy_count'] ?? 0);
$tls_summary_state = strtolower((string) ($tls_certificates_summary['state'] ?? 'not_detected'));
$tls_graphs = ($tls_certificate_count > 0 || $tls_unhealthy_count > 0) ? [
    ['label' => 'TLS Health Issues', 'key' => 'windows-agent_tls_health'],
] : [];
$process_issues = 0;
foreach ($watched_processes as $process) {
    if ((int) ($process['matched_count'] ?? 0) === 0) {
        $process_issues++;
    }
}
$tcp_issues = 0;
foreach ($watched_tcp_ports as $tcp_port) {
    if ((int) ($tcp_port['listening'] ?? 0) === 0) {
        $tcp_issues++;
    }
}
$logged_on_user_sessions = [];
foreach ($logged_on_users as $row) {
    if (empty($row['user'])) {
        continue;
    }

    $row['session_name'] = $row['session_name'] ?? ($row['session'] ?? '');
    $row['session_id'] = $row['session_id'] ?? ($row['id'] ?? '');
    $row['idle_time'] = $row['idle_time'] ?? ($row['idle'] ?? '');
    $row['logon_time'] = $row['logon_time'] ?? ($row['logon'] ?? '');
    $logged_on_user_sessions[] = $row;
}

$factorytalk_detected = (int) ($factorytalk_summary['detected'] ?? 0) === 1;
$factorytalk_active_connections = $sum_field($factorytalk_linx_connections, 'active');
$factorytalk_transactions_in_use = $sum_field($factorytalk_linx_transactions, 'in_use');
$factorytalk_transaction_pool_size = $sum_field($factorytalk_linx_transactions, 'pool_size');
$factorytalk_transaction_utilization = $factorytalk_transaction_pool_size > 0
    ? round(($factorytalk_transactions_in_use / $factorytalk_transaction_pool_size) * 100, 1)
    : null;
$factorytalk_attention = [];
$factorytalk_reported_health_issues = (int) ($factorytalk_summary['health_issues'] ?? 0);
$factorytalk_core_services_not_running = (int) ($factorytalk_summary['core_services_not_running'] ?? 0);
$factorytalk_reported_next_action = trim((string) ($factorytalk_summary['next_action'] ?? ''));

foreach ($factorytalk_services as $service) {
    if ((int) ($service['core'] ?? 0) !== 1 || strtolower((string) ($service['state'] ?? '')) === 'running') {
        continue;
    }

    $factorytalk_attention[] = [
        'title' => 'Core service is not running: ' . (string) ($service['display'] ?? $service['name'] ?? 'unknown'),
        'detail' => 'Current state: ' . (string) ($service['state'] ?? 'unknown') . '; startup: ' . (string) ($service['start_mode'] ?? 'unknown'),
        'action' => $factorytalk_reported_next_action !== '' ? $factorytalk_reported_next_action : 'Check the service and its FactoryTalk dependencies.',
    ];
}

$factorytalk_health_issue_count = max($factorytalk_reported_health_issues, $factorytalk_core_services_not_running, count($factorytalk_attention));
if ($factorytalk_health_issue_count > 0 && empty($factorytalk_attention)) {
    $factorytalk_attention[] = [
        'title' => 'FactoryTalk health issues were reported',
        'detail' => $factorytalk_health_issue_count . ' issue(s) were reported by the FactoryTalk health collector.',
        'action' => $factorytalk_reported_next_action !== '' ? $factorytalk_reported_next_action : 'Review the service inventory for the reported condition.',
    ];
}

$factorytalk_section_state = $section_state($factorytalk_summary['state'] ?? ($factorytalk_detected ? 'unknown' : 'not_detected'), $factorytalk_health_issue_count);
$factorytalk_section_summary = $factorytalk_detected
    ? $metric('Health issues', $factorytalk_health_issue_count) . ' ' . $metric('Core down', $factorytalk_core_services_not_running) . ' ' . $metric('Runtime CPU', empty($factorytalk_runtime_summary) ? 'N/A' : $format_percent($factorytalk_runtime_summary['cpu_percent'] ?? 0)) . ' ' . $metric('Active connections', $factorytalk_active_connections)
    : $metric('Detected', '0') . ' ' . $metric('Products', $factorytalk_summary['products_total'] ?? '0');

$sections = [
    'agent' => [
        'title' => 'Agent',
        'state' => $section_state($agent_issues > 0 ? 'warning' : 'ok', $agent_issues),
        'summary' => $metric('Version', $agent['version'] ?? 'unknown') . ' ' . $metric('Host', $agent['host'] ?? 'unknown') . ' ' . $metric('Collector issues', $agent_issues),
    ],
    'collector_impact' => [
        'title' => 'Collector Impact',
        'state' => $agent_resource_state,
        'summary' => $metric('CPU', $format_percent($agent_resource_cpu_percent)) . ' ' . $metric('Memory', $format_bytes($agent_resource_memory_bytes)) . ' ' . $metric('Disk I/O', $format_bytes($agent_resource_io_bytes)) . ' ' . $metric('Duration', $agent_resource_duration_ms . ' ms'),
    ],
    'vm' => [
        'title' => 'VM Resources',
        'state' => $vm_state,
        'summary' => $metric('CPU', ($vm_resource_summary['cpu_load_percent'] ?? '0') . '%') . ' ' . $metric('Memory', ($vm_resource_summary['memory_used_percent'] ?? '0') . '%') . ' ' . $metric('Max disk', ($vm_resource_summary['disk_used_percent_max'] ?? '0') . '%'),
    ],
    'performance' => [
        'title' => 'Performance',
        'state' => $section_state($performance_summary['state'] ?? 'not_detected', (int) ($performance_summary['pressure_issues'] ?? 0)),
        'summary' => $metric('Pressure issues', $performance_summary['pressure_issues'] ?? '0') . ' ' . $metric('CPU queue', $performance_summary['cpu_queue_length'] ?? '0') . ' ' . $metric('Committed', ($performance_summary['memory_committed_percent'] ?? '0') . '%'),
    ],
    'ad_dc' => [
        'title' => 'AD/DC',
        'state' => $section_state($ad_dc_health_summary['state'] ?? 'not_applicable', (int) ($ad_dc_health_summary['health_issues'] ?? 0)),
        'summary' => $metric('Issues', $ad_dc_health_summary['health_issues'] ?? '0') . ' ' . $metric('Core down', $ad_dc_health_summary['core_services_not_running'] ?? '0') . ' ' . $metric('Shares missing', $ad_dc_health_summary['shares_missing'] ?? '0'),
    ],
    'sql' => [
        'title' => 'SQL',
        'state' => $section_state($sql_server_summary['state'] ?? 'not_detected', (int) ($sql_server_summary['instances_not_running'] ?? 0)),
        'summary' => $metric('Instances', $sql_server_summary['instances_total'] ?? '0') . ' ' . $metric('Down', $sql_server_summary['instances_not_running'] ?? '0'),
    ],
    'iis' => [
        'title' => 'IIS',
        'state' => $section_state($iis_summary['state'] ?? 'not_detected', (int) ($iis_summary['sites_stopped'] ?? 0) + (int) ($iis_summary['app_pools_stopped'] ?? 0)),
        'summary' => $metric('Sites', $iis_summary['sites_total'] ?? '0') . ' ' . $metric('Pools', $iis_summary['app_pools_total'] ?? '0') . ' ' . $metric('Stopped', ((int) ($iis_summary['sites_stopped'] ?? 0) + (int) ($iis_summary['app_pools_stopped'] ?? 0))),
    ],
    'horizon' => [
        'title' => 'Horizon',
        'state' => $section_state($horizon_summary['state'] ?? 'not_detected', (int) ($horizon_summary['health_issues'] ?? 0)),
        'summary' => $metric('Detected', $horizon_summary['detected'] ?? '0') . ' ' . $metric('Services down', $horizon_summary['services_not_running'] ?? '0') . ' ' . $metric('Issues', $horizon_summary['health_issues'] ?? '0'),
    ],
    'factorytalk' => [
        'title' => 'FactoryTalk',
        'state' => $factorytalk_section_state,
        'summary' => $factorytalk_section_summary,
    ],
    'tls' => [
        'title' => 'TLS',
        'state' => $section_state($tls_certificates_summary['state'] ?? 'not_detected', $tls_unhealthy_count),
        'summary' => $metric('Stores', $tls_certificates_summary['store_count'] ?? '0') . ' ' . $metric('Certs', $tls_certificates_summary['certificate_count'] ?? '0') . ' ' . $metric('Expired', $tls_certificates_summary['expired_count'] ?? '0') . ' ' . $metric('Unhealthy', $tls_certificates_summary['unhealthy_count'] ?? '0'),
    ],
    'backup' => [
        'title' => 'Backup',
        'state' => $section_state($backup_state_key, $backup_health_issues),
        'summary' => $metric('VSS failed', $backup_storage_summary['vss_writers_failed'] ?? '0') . ' ' . $metric('Services down', $backup_storage_summary['backup_services_not_running'] ?? '0'),
    ],
    'datto' => [
        'title' => 'Datto',
        'state' => $section_state($datto_backup_summary['state'] ?? 'not_detected', (int) ($datto_backup_summary['health_issues'] ?? 0)),
        'summary' => $metric('Detected', $datto_backup_summary['detected'] ?? '0') . ' ' . $metric('Service running', $datto_backup_summary['service_running'] ?? '0') . ' ' . $metric('Issues', $datto_backup_summary['health_issues'] ?? '0'),
    ],
    'services' => [
        'title' => 'Services',
        'state' => $section_state($watched_service_issues > 0 ? 'warning' : 'ok', $watched_service_issues),
        'summary' => $metric('Watched down', $watched_service_issues) . ' ' . $metric('Classified stopped', $classified_services_stopped) . ' ' . $metric('Groups', count($classified_service_groups)),
    ],
    'events' => [
        'title' => 'Events',
        'state' => $section_state('ok'),
        'summary' => $metric('Logs', count($event_logs)) . ' ' . $metric('Critical/errors', $event_evidence_count) . ' ' . $metric('High-value groups', $event_log_high_value_summary['signatures_total'] ?? '0'),
    ],
    'processes' => [
        'title' => 'Processes',
        'state' => $section_state($process_issues > 0 ? 'warning' : 'ok', $process_issues),
        'summary' => $metric('Watched', count($watched_processes)) . ' ' . $metric('Missing', $process_issues),
    ],
    'tcp' => [
        'title' => 'TCP Ports',
        'state' => $section_state($tcp_issues > 0 ? 'warning' : 'ok', $tcp_issues),
        'summary' => $metric('Watched', count($watched_tcp_ports)) . ' ' . $metric('Not listening', $tcp_issues),
    ],
];

$overview = '<div class="panel panel-default windows-agent-overview-panel"><div class="panel-body"><h3 class="windows-agent-overview-title">Health Overview</h3>';
$overview .= '<div class="table-responsive"><table class="table table-condensed table-striped windows-agent-data-table">';
$overview .= '<tr><th>Section</th><th>State</th><th>Summary</th></tr>';
foreach ($sections as $section) {
    $overview .= '<tr><td><strong>' . $esc($section['title']) . '</strong></td><td>' . $section['state']['html'] . '</td><td>' . $section['summary'] . '</td></tr>';
}
$overview .= '</table></div></div></div>';

$agent_details = $kv_table([
    'Agent version' => $esc($agent['version'] ?? 'unknown'),
    'Agent host' => $esc($agent['host'] ?? 'unknown'),
    'Last agent UTC' => $esc($data['last_agent_utc'] ?? ''),
    'Config path' => $esc($agent['config'] ?? ''),
    'Windows OS' => $esc($windows_os['caption'] ?? 'unknown'),
    'Version' => $esc($windows_os['version'] ?? ''),
    'Build' => $esc($windows_os['build'] ?? ''),
    'Architecture' => $esc($windows_os['architecture'] ?? ''),
]);

$collector_details = $table(['Collector', 'State', 'Duration', 'Sections', 'Lines'], $issue_first($collector_timings, static fn (array $row): int => (strtolower((string) ($row['state'] ?? '')) === 'ok' ? 0 : 100000) + (int) ($row['duration_ms'] ?? 0), 'collector'), static function ($row) use ($esc, $state_label): string {
    return '<td>' . $esc($row['collector'] ?? '') . '</td><td>' . $state_label($row['state'] ?? 'unknown', ['ok']) . '</td><td>' . $esc($row['duration_ms'] ?? '0') . ' ms</td><td>' . $esc($row['section_count'] ?? '0') . '</td><td>' . $esc($row['line_count'] ?? '0') . '</td>';
});

$agent_resource_details = $kv_table([
    'Impact' => $agent_resource_state['html'],
    'Assessment' => $esc($agent_resource_assessment),
    'CPU used during collection' => $esc($format_percent($agent_resource_cpu_percent)) . ' / ' . $esc($agent_performance['process_cpu_ms'] ?? '0') . ' ms',
    'Memory footprint' => $esc($format_bytes($agent_resource_memory_bytes)),
    'Disk I/O during collection' => $esc($format_bytes($agent_resource_io_bytes)),
    'Disk I/O read/write' => $esc($format_bytes($agent_performance['process_io_read_bytes'] ?? 0)) . ' / ' . $esc($format_bytes($agent_performance['process_io_write_bytes'] ?? 0)),
    'Collection duration' => $esc($agent_resource_duration_ms) . ' ms',
]);

$agent_perf_details = $kv_table([
    'Collection duration' => $esc($agent_performance['collect_duration_ms'] ?? '0') . ' ms',
    'Collectors run' => $esc($agent_performance['collectors_run'] ?? '0'),
    'Failed / timed out' => $esc($agent_performance['collectors_failed'] ?? '0') . ' / ' . $esc($agent_performance['collectors_timed_out'] ?? '0'),
    'Payload size' => $esc($format_bytes($agent_performance['payload_bytes'] ?? 0)),
    'Process working set' => $esc($format_bytes($agent_performance['process_working_set_bytes'] ?? 0)),
    'Process private bytes' => $esc($format_bytes($agent_performance['process_private_bytes'] ?? 0)),
]) . $collector_details;

$vm_details = $kv_table([
    'CPU load' => $esc($vm_resource_summary['cpu_load_percent'] ?? '0') . '%',
    'Memory used' => $esc($vm_resource_summary['memory_used_percent'] ?? '0') . '%',
    'Physical memory used/free' => $esc($format_bytes($memory['used_bytes'] ?? 0)) . ' / ' . $esc($format_bytes($memory['physical_free_bytes'] ?? 0)),
    'Max disk used' => $esc($vm_resource_summary['disk_used_percent_max'] ?? '0') . '%',
    'Minimum disk free' => $esc($format_bytes($vm_resource_summary['disk_free_bytes_min'] ?? 0)),
]);
$vm_details .= $table(['CPU', 'Cores', 'Logical', 'Load', 'Max clock'], $cpu_details, static function ($row) use ($esc): string {
    return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['cores'] ?? '') . '</td><td>' . $esc($row['logical_processors'] ?? '') . '</td><td>' . $esc($row['load_percent'] ?? '0') . '%</td><td>' . $esc($row['max_clock_mhz'] ?? '') . ' MHz</td>';
});
$vm_details .= $table(['Disk', 'Volume', 'Filesystem', 'Used', 'Free', 'Used %', 'Free %'], $disks, static function ($row) use ($esc, $format_bytes): string {
    return '<td>' . $esc($row['device'] ?? '') . '</td><td>' . $esc($row['volume'] ?? '') . '</td><td>' . $esc($row['filesystem'] ?? '') . '</td><td>' . $esc($format_bytes($row['used_bytes'] ?? 0)) . '</td><td>' . $esc($format_bytes($row['free_bytes'] ?? 0)) . '</td><td>' . $esc($row['used_percent'] ?? '0') . '%</td><td>' . $esc($row['free_percent'] ?? '0') . '%</td>';
});

$perf_details = $kv_table([
    'Pressure issues' => $esc($performance_summary['pressure_issues'] ?? '0'),
    'CPU queue / pressure' => $esc($performance_summary['cpu_queue_length'] ?? '0') . ' / ' . $esc($performance_summary['cpu_pressure'] ?? '0'),
    'Memory available / committed' => $esc($performance_summary['memory_available_mb'] ?? '0') . ' MB / ' . $esc($performance_summary['memory_committed_percent'] ?? '0') . '%',
    'Paging' => $esc($performance_summary['pages_per_sec'] ?? '0') . ' pages/sec / pressure=' . $esc($performance_summary['paging_pressure'] ?? '0'),
    'Disk read/write max' => $esc($performance_summary['disk_read_ms_max'] ?? '0') . ' / ' . $esc($performance_summary['disk_write_ms_max'] ?? '0') . ' ms',
    'Network throughput / errors' => $esc($format_bytes($performance_summary['network_bytes_per_sec_total'] ?? 0)) . '/s / ' . $esc($performance_summary['network_errors_total'] ?? '0'),
]);
if ($has_role_details($performance_summary, $performance_disks)) {
    $perf_details .= $table(['Disk', 'Read ms', 'Write ms', 'Queue', 'Bytes/sec'], $issue_first($performance_disks, static fn (array $row): int => (int) ($row['avg_read_ms'] ?? 0) + (int) ($row['avg_write_ms'] ?? 0) + ((int) ($row['current_queue_length'] ?? 0) * 10)), static function ($row) use ($esc, $format_bytes): string {
        return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['avg_read_ms'] ?? '0') . '</td><td>' . $esc($row['avg_write_ms'] ?? '0') . '</td><td>' . $esc($row['current_queue_length'] ?? '0') . '</td><td>' . $esc($format_bytes($row['disk_bytes_per_sec'] ?? 0)) . '/s</td>';
    });
}
if ($has_role_details($performance_summary, $performance_network)) {
    $perf_details .= $table(['Interface', 'Bytes/sec', 'Packets/sec', 'Errors/sec', 'Discards/sec'], $issue_first($performance_network, static fn (array $row): int => (int) ($row['errors_per_sec'] ?? 0) + (int) ($row['discards_per_sec'] ?? 0)), static function ($row) use ($esc, $format_bytes): string {
        return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($format_bytes($row['bytes_per_sec'] ?? 0)) . '/s</td><td>' . $esc($row['packets_per_sec'] ?? '0') . '</td><td>' . $esc($row['errors_per_sec'] ?? '0') . '</td><td>' . $esc($row['discards_per_sec'] ?? '0') . '</td>';
    });
}
if ($has_role_details($performance_summary, $performance_processes)) {
    $perf_details .= $table(['Process', 'PID', 'Rank', 'CPU %', 'Working set', 'Private bytes', 'Handles', 'Threads'], $performance_processes, static function ($row) use ($esc, $format_bytes): string {
        return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['pid'] ?? '') . '</td><td>' . $esc($row['rank_source'] ?? '') . '</td><td>' . $esc($row['cpu_percent'] ?? '0') . '</td><td>' . $esc($format_bytes($row['working_set_bytes'] ?? 0)) . '</td><td>' . $esc($format_bytes($row['private_bytes'] ?? 0)) . '</td><td>' . $esc($row['handle_count'] ?? '0') . '</td><td>' . $esc($row['thread_count'] ?? '0') . '</td>';
    });
}

$sql_details = $has_role_details($sql_server_summary, $sql_server_instances) ? $table(['Instance', 'Service', 'State', 'Start mode', 'Agent', 'Browser', 'Ports'], $issue_first($sql_server_instances, static fn (array $row): int => strtolower((string) ($row['state'] ?? '')) === 'running' ? 0 : 1, 'instance'), static function ($row) use ($esc, $state_label): string {
    return '<td>' . $esc($row['instance'] ?? '') . '</td><td>' . $esc($row['service'] ?? '') . '</td><td>' . $state_label($row['state'] ?? 'unknown') . '</td><td>' . $esc($row['start_mode'] ?? '') . '</td><td>' . $esc($row['agent_state'] ?? '') . '</td><td>' . $esc($row['browser_state'] ?? '') . '</td><td>' . $esc($row['listener_ports'] ?? '') . '</td>';
}) : '';

$iis_details = '';
$iis_rows = [];
if ($has_role_details($iis_summary, $iis_sites)) {
    foreach ($iis_sites as $row) {
        $iis_rows[] = [
            'type' => 'Site',
            'name' => $row['name'] ?? '',
            'state' => $row['state'] ?? 'unknown',
            'detail' => 'ID ' . ($row['id'] ?? '') . ', bindings ' . ($row['bindings_count'] ?? ''),
            'path' => $row['physical_path'] ?? '',
            'score' => in_array(strtolower((string) ($row['state'] ?? '')), ['started', 'running'], true) ? 10 : 0,
        ];
    }
}
if ($has_role_details($iis_summary, $iis_app_pools)) {
    foreach ($iis_app_pools as $row) {
        $iis_rows[] = [
            'type' => 'App pool',
            'name' => $row['name'] ?? '',
            'state' => $row['state'] ?? 'unknown',
            'detail' => trim(($row['runtime_version'] ?? '') . ' ' . ($row['pipeline_mode'] ?? '')),
            'path' => $row['identity_type'] ?? '',
            'score' => in_array(strtolower((string) ($row['state'] ?? '')), ['started', 'running'], true) ? 10 : 0,
        ];
    }
}
if ($has_role_details($iis_summary, $iis_bindings)) {
    foreach ($iis_bindings as $row) {
        $binding = trim(($row['protocol'] ?? '') . ' ' . ($row['binding_information'] ?? ''));
        $target = trim(($row['hostname'] ?? '') . ':' . ($row['port'] ?? ''), ':');
        $iis_rows[] = [
            'type' => 'Binding',
            'name' => $row['site'] ?? '',
            'state' => 'inventory',
            'detail' => $binding . ($target === '' ? '' : ' (' . $target . ')'),
            'path' => $row['certificate_thumbprint'] ?? '',
            'score' => 20,
        ];
    }
}
if (! empty($iis_rows)) {
    $iis_details .= $table(['Type', 'Name', 'State', 'Detail', 'Path / Certificate'], $issue_first($iis_rows, static fn (array $row): int => (int) ($row['score'] ?? 0), 'name'), static function ($row) use ($esc, $state_label): string {
        $state = strtolower((string) ($row['state'] ?? ''));
        $state_html = $state === 'inventory'
            ? '<span class="label label-default">Inventory</span>'
            : $state_label($row['state'] ?? 'unknown', ['started', 'running']);

        return '<td>' . $esc($row['type'] ?? '') . '</td><td>' . $esc($row['name'] ?? '') . '</td><td>' . $state_html . '</td><td>' . $esc($row['detail'] ?? '') . '</td><td>' . $esc($row['path'] ?? '') . '</td>';
    });
}

$horizon_details = '';
if ($has_role_details($horizon_summary, $horizon_services)) {
    $horizon_details .= $table(['Service', 'Display', 'Role', 'State', 'Start mode', 'Path'], $issue_first($horizon_services, static fn (array $row): int => strtolower((string) ($row['state'] ?? '')) === 'running' ? 0 : 1), static function ($row) use ($esc, $state_label): string {
        return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['display'] ?? '') . '</td><td>' . $esc($row['role'] ?? '') . '</td><td>' . $state_label($row['state'] ?? 'unknown') . '</td><td>' . $esc($row['start_mode'] ?? '') . '</td><td>' . $esc($row['path'] ?? '') . '</td>';
    });
}
if ($has_role_details($horizon_summary, $horizon_processes)) {
    $horizon_details .= $table(['Process', 'PID', 'Path'], $horizon_processes, static function ($row) use ($esc): string {
        return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['pid'] ?? '') . '</td><td>' . $esc($row['path'] ?? '') . '</td>';
    });
}
if ($has_role_details($horizon_summary, $horizon_ports)) {
    $horizon_details .= $table(['Port', 'Listening', 'Addresses'], $issue_first($horizon_ports, static fn (array $row): int => (int) ($row['listening'] ?? 0) === 1 ? 0 : 1, 'port'), static function ($row) use ($esc, $state_label): string {
        return '<td>' . $esc($row['port'] ?? '') . '</td><td>' . $state_label($row['listening'] ?? '0') . '</td><td>' . $esc($row['addresses'] ?? '') . '</td>';
    });
}
if ($has_role_details($horizon_summary, $horizon_certificates)) {
    $horizon_details .= $table(['Store', 'Subject', 'Issuer', 'Expires UTC', 'Days', 'Expired', 'Private key', 'Thumbprint'], $issue_first($horizon_certificates, static fn (array $row): int => ((int) ($row['expired'] ?? 0) * 10000) + max(0, 3650 - (int) ($row['days_remaining'] ?? 3650)), 'subject'), static function ($row) use ($esc, $state_label): string {
        return '<td>' . $esc($row['store'] ?? '') . '</td><td>' . $esc($row['subject'] ?? '') . '</td><td>' . $esc($row['issuer'] ?? '') . '</td><td>' . $esc($row['not_after_utc'] ?? '') . '</td><td>' . $esc($row['days_remaining'] ?? '') . '</td><td>' . $state_label($row['expired'] ?? '0', ['0']) . '</td><td>' . $esc($row['has_private_key'] ?? '0') . '</td><td>' . $esc($row['thumbprint'] ?? '') . '</td>';
    });
}

$factorytalk_details = '';
if ($factorytalk_detected) {
    $factorytalk_status_class = [
        'success' => 'success',
        'warning' => 'warning',
        'danger' => 'danger',
    ][$factorytalk_section_state['class'] ?? ''] ?? 'info';
    $factorytalk_status_text = $factorytalk_health_issue_count === 0
        ? 'No FactoryTalk health issues were reported.'
        : $factorytalk_health_issue_count . ' FactoryTalk health issue(s) were reported.';
    $factorytalk_next_action = $factorytalk_health_issue_count === 0
        ? ''
        : ($factorytalk_reported_next_action !== '' ? $factorytalk_reported_next_action : (string) ($factorytalk_attention[0]['action'] ?? 'Review the service inventory.'));
    if (empty($factorytalk_native_summary) || (int) ($factorytalk_native_summary['available'] ?? 0) !== 1) {
        $native_display_state = 'Unavailable';
    } elseif ((int) ($factorytalk_native_summary['enabled'] ?? 0) !== 1) {
        $native_display_state = 'Disabled';
    } else {
        $native_display_state = $section_state($factorytalk_native_summary['state'] ?? 'unknown')['text'] ?? 'Unknown';
    }
    $native_snapshot_age = (int) ($factorytalk_native_summary['snapshot_age_seconds'] ?? -1);
    $native_snapshot_detail = empty($factorytalk_native_summary)
        ? 'No snapshot data'
        : ($native_snapshot_age >= 0 ? $native_snapshot_age . 's old' : (string) ($factorytalk_native_summary['last_error'] ?? 'No completed snapshot'));
    $transaction_display = $factorytalk_transaction_utilization === null
        ? 'N/A'
        : number_format($factorytalk_transaction_utilization, 1) . '%';

    $factorytalk_details .= '<div class="windows-agent-factorytalk-dashboard">';
    $factorytalk_details .= '<div class="windows-agent-factorytalk-status windows-agent-factorytalk-status-' . $esc($factorytalk_status_class) . '">';
    $factorytalk_details .= '<span class="label label-' . $esc($factorytalk_status_class) . '">' . $esc($factorytalk_section_state['text'] ?? 'Unknown') . '</span> ';
    $factorytalk_details .= '<strong>' . $esc($factorytalk_status_text) . '</strong>';
    if ($factorytalk_next_action !== '') {
        $factorytalk_details .= ' <span class="windows-agent-factorytalk-action"><strong>Next:</strong> ' . $esc($factorytalk_next_action) . '</span>';
    }
    $factorytalk_details .= '<span class="text-muted windows-agent-factorytalk-collected">Collected ' . $esc($data['last_agent_utc'] ?? 'unknown') . '</span></div>';

    $factorytalk_stats = [
        ['Core services down', $factorytalk_summary['core_services_not_running'] ?? '0', 'Service health'],
        ['Runtime CPU', empty($factorytalk_runtime_summary) ? 'Unavailable' : $format_percent($factorytalk_runtime_summary['cpu_percent'] ?? 0), (string) ($factorytalk_runtime_summary['processes_total'] ?? '0') . ' processes'],
        ['Runtime memory', empty($factorytalk_runtime_summary) ? 'Unavailable' : $format_bytes($factorytalk_runtime_summary['working_set_bytes'] ?? 0), 'Working set'],
        ['Active connections', $factorytalk_active_connections, 'FactoryTalk Linx'],
        ['Transactions', $transaction_display, $factorytalk_transactions_in_use . ' of ' . $factorytalk_transaction_pool_size],
        ['Native snapshot', $native_display_state, $native_snapshot_detail],
    ];
    $factorytalk_details .= '<div class="row windows-agent-factorytalk-stats">';
    foreach ($factorytalk_stats as [$label, $value, $detail]) {
        $factorytalk_details .= '<div class="col-sm-4 col-lg-2 windows-agent-factorytalk-stat"><div class="text-muted windows-agent-factorytalk-stat-label">' . $esc($label) . '</div><div class="windows-agent-factorytalk-stat-value">' . $esc($value) . '</div><div class="text-muted windows-agent-factorytalk-stat-detail">' . $esc($detail) . '</div></div>';
    }
    $factorytalk_details .= '</div>';

    if (! empty($factorytalk_attention)) {
        $factorytalk_details .= '<div class="windows-agent-factorytalk-attention"><h4>Reported Health Issues <small>' . $factorytalk_health_issue_count . '</small></h4><ul>';
        foreach ($factorytalk_attention as $attention) {
            $factorytalk_details .= '<li><strong>' . $esc($attention['title'] ?? 'FactoryTalk health issue') . '</strong> ';
            $factorytalk_details .= '<span class="text-muted">' . $esc($attention['detail'] ?? '') . '</span>';
            $factorytalk_details .= '<div class="text-muted windows-agent-factorytalk-attention-action"><strong>Next:</strong> ' . $esc($attention['action'] ?? 'Review the service inventory.') . '</div></li>';
        }
        $factorytalk_details .= '</ul></div>';
    }

    $factorytalk_top_processes = $factorytalk_runtime_processes;
    usort($factorytalk_top_processes, static function (array $left, array $right): int {
        $cpu_order = (float) ($right['cpu_percent'] ?? 0) <=> (float) ($left['cpu_percent'] ?? 0);
        if ($cpu_order !== 0) {
            return $cpu_order;
        }

        return (int) ($right['working_set_bytes'] ?? 0) <=> (int) ($left['working_set_bytes'] ?? 0);
    });
    $factorytalk_top_processes = array_slice($factorytalk_top_processes, 0, 5);
    if (! empty($factorytalk_top_processes)) {
        $factorytalk_details .= '<h4>Top FactoryTalk Processes <small>by CPU, then memory</small></h4>';
        $factorytalk_details .= $table(['Process', 'Role', 'CPU', 'Working set', 'Uptime'], $factorytalk_top_processes, static function ($row) use ($esc, $format_bytes, $format_percent, $format_duration): string {
            return '<td><strong>' . $esc($row['name'] ?? '') . '</strong></td><td>' . $esc($row['role'] ?? '') . '</td><td>' . $esc($format_percent($row['cpu_percent'] ?? 0)) . '</td><td>' . $esc($format_bytes($row['working_set_bytes'] ?? 0)) . '</td><td>' . $esc($format_duration($row['uptime_seconds'] ?? 0)) . '</td>';
        });
    }

    $factorytalk_all_processes = '';
    if (! empty($factorytalk_runtime_processes)) {
        $factorytalk_all_processes = $table(['Process', 'PID', 'Role', 'CPU', 'Working set', 'Private bytes', 'Handles', 'Threads', 'Read/s', 'Write/s', 'Uptime (s)'], $factorytalk_runtime_processes, static function ($row) use ($esc, $format_bytes, $format_percent): string {
            return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['pid'] ?? '') . '</td><td>' . $esc($row['role'] ?? '') . '</td><td>' . $esc($format_percent($row['cpu_percent'] ?? 0)) . '</td><td>' . $esc($format_bytes($row['working_set_bytes'] ?? 0)) . '</td><td>' . $esc($format_bytes($row['private_bytes'] ?? 0)) . '</td><td>' . $esc($row['handle_count'] ?? '0') . '</td><td>' . $esc($row['thread_count'] ?? '0') . '</td><td>' . $esc($format_bytes($row['io_read_bytes_per_sec'] ?? 0)) . '</td><td>' . $esc($format_bytes($row['io_write_bytes_per_sec'] ?? 0)) . '</td><td>' . $esc($row['uptime_seconds'] ?? '0') . '</td>';
        });
        $factorytalk_details .= $render_disclosure('windows-agent-factorytalk-all-processes', 'Show all process metrics', $factorytalk_all_processes, count($factorytalk_runtime_processes) . ' processes');
    }

    $factorytalk_raw_details = '';
    if (! empty($factorytalk_runtime_summary)) {
        $runtime_state = $section_state($factorytalk_runtime_summary['state'] ?? 'unknown');
        $factorytalk_raw_details .= '<h4>FactoryTalk Runtime Metrics</h4><div class="well well-sm">' .
            $runtime_state['html'] . ' ' .
            $metric('Processes', $factorytalk_runtime_summary['processes_total'] ?? '0') . ' ' .
            $metric('CPU', $format_percent($factorytalk_runtime_summary['cpu_percent'] ?? 0)) . ' ' .
            $metric('Working set', $format_bytes($factorytalk_runtime_summary['working_set_bytes'] ?? 0)) . ' ' .
            $metric('Private bytes', $format_bytes($factorytalk_runtime_summary['private_bytes'] ?? 0)) .
            '</div>';
    }
    if ($has_role_details($factorytalk_summary, $factorytalk_products)) {
        $factorytalk_raw_details .= '<h4>Installed Products</h4>' . $table(['Product', 'Version', 'Publisher', 'Role', 'Install location'], $factorytalk_products, static function ($row) use ($esc): string {
            return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['version'] ?? '') . '</td><td>' . $esc($row['publisher'] ?? '') . '</td><td>' . $esc($row['role'] ?? '') . '</td><td>' . $esc($row['install_location'] ?? '') . '</td>';
        });
    }
    if ($has_role_details($factorytalk_summary, $factorytalk_services)) {
        $factorytalk_raw_details .= '<h4>Service Inventory</h4>' . $table(['Service', 'Display', 'Role', 'Core', 'State', 'Start mode', 'Path'], $issue_first($factorytalk_services, static fn (array $row): int => strtolower((string) ($row['state'] ?? '')) === 'running' ? 0 : (1 + ((int) ($row['core'] ?? 0) * 10))), static function ($row) use ($esc, $state_label): string {
            return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['display'] ?? '') . '</td><td>' . $esc($row['role'] ?? '') . '</td><td>' . $esc($row['core'] ?? '0') . '</td><td>' . $state_label($row['state'] ?? 'unknown') . '</td><td>' . $esc($row['start_mode'] ?? '') . '</td><td>' . $esc($row['path'] ?? '') . '</td>';
        });
    }
    if ($has_role_details($factorytalk_summary, $factorytalk_processes)) {
        $factorytalk_raw_details .= '<h4>Process Inventory</h4>' . $table(['Process', 'PID', 'Role', 'Path'], $factorytalk_processes, static function ($row) use ($esc): string {
            return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['pid'] ?? '') . '</td><td>' . $esc($row['role'] ?? '') . '</td><td>' . $esc($row['path'] ?? '') . '</td>';
        });
    }
    if ($has_role_details($factorytalk_summary, $factorytalk_ports)) {
        $factorytalk_raw_details .= '<h4>Port Inventory</h4>' . $table(['Name', 'Port', 'Listening', 'Addresses'], $factorytalk_ports, static function ($row) use ($esc, $state_label): string {
            return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['port'] ?? '') . '</td><td>' . $state_label($row['listening'] ?? '0') . '</td><td>' . $esc($row['addresses'] ?? '') . '</td>';
        });
    }
    if (! empty($factorytalk_native_summary)) {
        $native_state = $section_state($factorytalk_native_summary['state'] ?? 'unknown');
        $factorytalk_raw_details .= '<h4>Native Counter Snapshot</h4><div class="well well-sm">' .
            $native_state['html'] . ' ' .
            $metric('Mode', $factorytalk_native_summary['mode'] ?? 'disabled') . ' ' .
            $metric('Available', $factorytalk_native_summary['available'] ?? '0') . ' ' .
            $metric('Signed', $factorytalk_native_summary['signature_valid'] ?? '0') . ' ' .
            $metric('Version', $factorytalk_native_summary['version'] ?? '') . ' ' .
            $metric('Age (s)', $factorytalk_native_summary['snapshot_age_seconds'] ?? '-1') . ' ' .
            $metric('Duration (ms)', $factorytalk_native_summary['snapshot_duration_ms'] ?? '0') . ' ' .
            $metric('Last result', $factorytalk_native_summary['last_error'] ?? 'none') .
            '</div>';
    }
    if (! empty($factorytalk_linx_connections)) {
        $factorytalk_raw_details .= '<h4>FactoryTalk Linx Connections</h4>' . $table(['Instance', 'Driver', 'Direction', 'Active', 'Accepted', 'Attempted', 'Closed'], $factorytalk_linx_connections, static function ($row) use ($esc): string {
            return '<td>' . $esc($row['instance'] ?? '') . '</td><td>' . $esc($row['driver'] ?? '') . '</td><td>' . $esc($row['direction'] ?? '') . '</td><td>' . $esc($row['active'] ?? '0') . '</td><td>' . $esc($row['accepted'] ?? '0') . '</td><td>' . $esc($row['attempted'] ?? '0') . '</td><td>' . $esc($row['closed'] ?? '0') . '</td>';
        });
    }
    if (! empty($factorytalk_linx_backplane)) {
        $factorytalk_raw_details .= '<h4>FactoryTalk Linx Backplane</h4>' . $table(['Instance', 'Slot', 'Packets received', 'Packets sent', 'Send failures'], $factorytalk_linx_backplane, static function ($row) use ($esc): string {
            return '<td>' . $esc($row['instance'] ?? '') . '</td><td>' . $esc($row['slot'] ?? '') . '</td><td>' . $esc($row['packets_received'] ?? '0') . '</td><td>' . $esc($row['packets_sent'] ?? '0') . '</td><td>' . $esc($row['send_failures'] ?? '0') . '</td>';
        });
    }
    if (! empty($factorytalk_linx_transactions)) {
        $factorytalk_raw_details .= '<h4>FactoryTalk Linx Transactions</h4>' . $table(['Instance', 'In use', 'Pool size', 'Utilization'], $factorytalk_linx_transactions, static function ($row) use ($esc): string {
            $pool_size = (int) ($row['pool_size'] ?? 0);
            $in_use = (int) ($row['in_use'] ?? 0);
            $utilization = $pool_size > 0 ? number_format(($in_use / $pool_size) * 100, 1) . '%' : 'N/A';
            return '<td>' . $esc($row['instance'] ?? '') . '</td><td>' . $esc($in_use) . '</td><td>' . $esc($pool_size) . '</td><td>' . $esc($utilization) . '</td>';
        });
    }
    if (! empty($factorytalk_livedata)) {
        $factorytalk_raw_details .= '<h4>FactoryTalk Live Data</h4><div class="well well-sm">' . $metric('Clients', $factorytalk_livedata['clients'] ?? '0') . ' ' . $metric('Sources', $factorytalk_livedata['sources'] ?? '0') . '</div>';
    }
    $factorytalk_details .= $render_disclosure('windows-agent-factorytalk-raw', 'Inventory and raw diagnostics', $factorytalk_raw_details, (string) count($factorytalk_products) . ' products, ' . count($factorytalk_services) . ' services');
    $factorytalk_details .= '</div>';
}

$tls_details = '';
if ($has_role_details($tls_certificates_summary, $tls_certificates)) {
    $tls_details = $table(['Store', 'Subject', 'Health', 'DNS names', 'Expires UTC', 'Days', 'Chain', 'Key', 'Bound'], $issue_first($tls_certificates, static fn (array $row): int => ((strtolower((string) ($row['health'] ?? '')) === 'ok' ? 0 : 10000) + ((int) ($row['expired'] ?? 0) * 5000) + ((int) ($row['expiring_critical'] ?? 0) * 1000) + ((int) ($row['expiring_warning'] ?? 0) * 500) + max(0, 3650 - (int) ($row['days_remaining'] ?? 3650))), 'subject'), static function ($row) use ($esc, $state_label): string {
        return '<td>' . $esc($row['store'] ?? '') . '</td><td>' . $esc($row['subject'] ?? '') . '</td><td>' . $state_label($row['health'] ?? 'unknown', ['ok']) . '</td><td>' . $esc($row['dns_names'] ?? '') . '</td><td>' . $esc($row['not_after_utc'] ?? '') . '</td><td>' . $esc($row['days_remaining'] ?? '') . '</td><td>' . $esc($row['chain_status'] ?? '') . '</td><td>' . $esc($row['key_bits'] ?? '') . ' bits / private=' . $esc($row['has_private_key'] ?? '0') . '</td><td>' . $esc($row['bound'] ?? '0') . ' ' . $esc($row['binding_sources'] ?? '') . '</td>';
    });
} elseif ($tls_summary_state === 'ok' && $tls_certificate_count === 0) {
    $tls_details = $kv_table([
        'State' => $sections['tls']['state']['html'],
        'Stores scanned' => $esc($tls_certificates_summary['store_count'] ?? '0'),
        'Certificates found' => $esc($tls_certificates_summary['certificate_count'] ?? '0'),
        'Result' => 'No LocalMachine certificates found in configured stores.',
    ]);
}

$backup_details = '';
if ($has_role_details($backup_storage_summary, $vss_writers)) {
    $backup_details .= $table(['Writer', 'State', 'Last error'], $issue_first($vss_writers, static fn (array $row): int => strtolower((string) ($row['state'] ?? '')) === 'stable' ? 0 : 1), static function ($row) use ($esc, $state_label): string {
        return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $state_label($row['state'] ?? 'unknown', ['stable']) . '</td><td>' . $esc($row['last_error'] ?? '') . '</td>';
    });
}
if ($has_role_details($backup_storage_summary, $backup_services)) {
    $backup_details .= $table(['Service', 'Display', 'State', 'Start mode', 'Source'], $issue_first($backup_services, static fn (array $row): int => strtolower((string) ($row['state'] ?? '')) === 'running' ? 0 : 1), static function ($row) use ($esc, $state_label): string {
        return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['display'] ?? '') . '</td><td>' . $state_label($row['state'] ?? 'unknown') . '</td><td>' . $esc($row['start_mode'] ?? '') . '</td><td>' . $esc($row['source'] ?? '') . '</td>';
    });
}

$datto_details = '';
$datto_service_score = static function (array $row): int {
    $role = strtolower((string) ($row['role'] ?? ''));
    $state = strtolower((string) ($row['state'] ?? ''));
    $state_issue = $role === 'provider' ? 0 : ($state === 'running' ? 0 : 1);
    $path_issue = (int) ($row['path_exists'] ?? 0) === 1 ? 0 : 1;

    return $state_issue + $path_issue;
};
if ($has_role_details($datto_backup_summary, $datto_backup_services)) {
    $datto_details .= $table(['Service', 'Role', 'State', 'Start mode', 'Path exists', 'Version'], $issue_first($datto_backup_services, $datto_service_score), static function ($row) use ($esc, $state_label): string {
        $healthy_states = strtolower((string) ($row['role'] ?? '')) === 'provider' ? ['running', 'stopped'] : ['running'];
        return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['role'] ?? '') . '</td><td>' . $state_label($row['state'] ?? 'unknown', $healthy_states) . '</td><td>' . $esc($row['start_mode'] ?? '') . '</td><td>' . $esc($row['path_exists'] ?? '0') . '</td><td>' . $esc($row['version'] ?? '') . '</td>';
    });
}
if ($has_role_details($datto_backup_summary, $datto_backup_processes)) {
    $datto_details .= $table(['Process', 'Matched count'], $datto_backup_processes, static function ($row) use ($esc): string {
        return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['matched_count'] ?? '0') . '</td>';
    });
}
if ($has_role_details($datto_backup_summary, $datto_backup_evidence)) {
    $datto_details .= $table(['Type', 'State', 'Source', 'Timestamp UTC', 'Age', 'Recent errors', 'Critical failures'], $issue_first($datto_backup_evidence, static fn (array $row): int => (strtolower((string) ($row['state'] ?? '')) === 'critical' ? 10000 : (strtolower((string) ($row['state'] ?? '')) === 'warning' ? 5000 : 0)) + ((int) ($row['recent_critical_failures'] ?? 0) * 100) + (int) ($row['recent_errors'] ?? 0), 'type'), static function ($row) use ($esc): string {
        return '<td>' . $esc($row['type'] ?? '') . '</td><td>' . $esc($row['state'] ?? '') . '</td><td>' . $esc($row['source'] ?? '') . '</td><td>' . $esc($row['timestamp_utc'] ?? '') . '</td><td>' . $esc($row['age_hours'] ?? '') . '</td><td>' . $esc($row['recent_errors'] ?? '') . '</td><td>' . $esc($row['recent_critical_failures'] ?? '') . '</td>';
    });
}

$role_details = $table(['Role', 'Detected', 'Confidence', 'Source'], $roles, static function ($row) use ($esc, $state_label): string {
    return '<td>' . $esc($row['role'] ?? '') . '</td><td>' . $state_label($row['detected'] ?? '0') . '</td><td>' . $esc($row['confidence'] ?? '') . '</td><td>' . $esc($row['source'] ?? '') . '</td>';
});
$ad_details = $kv_table([
    'Domain' => $esc($ad_summary['domain'] ?? ''),
    'Domain role' => $esc($ad_summary['domain_role_name'] ?? '') . ' (' . $esc($ad_summary['domain_role'] ?? '') . ')',
    'Replication state' => $esc($ad_summary['replication_state'] ?? ''),
    'Replication failures' => $esc($ad_summary['replication_failures'] ?? '0'),
    'DFSR state' => $esc($ad_summary['dfsr_state'] ?? ''),
    'DFSR unhealthy' => $esc($ad_summary['dfsr_unhealthy'] ?? '0'),
    'FSMO state' => $esc($ad_summary['fsmo_state'] ?? ''),
]);
$ad_dc_details = $kv_table([
    'Core services down' => $esc($ad_dc_health_summary['core_services_not_running'] ?? '0'),
    'DNS service running' => $esc($ad_dc_health_summary['dns_service_running'] ?? '0'),
    'SYSVOL / NETLOGON published' => $esc($ad_dc_health_summary['sysvol_share_present'] ?? '0') . ' / ' . $esc($ad_dc_health_summary['netlogon_share_present'] ?? '0'),
    'Time state' => $esc($ad_dc_health_summary['time_state'] ?? ''),
    'Health issues' => $esc($ad_dc_health_summary['health_issues'] ?? '0'),
]);
if ($has_role_details($ad_dc_health_summary, $ad_dc_dns)) {
    $ad_dc_details .= $table(['DNS state', 'Present', 'Running', 'Reason'], $ad_dc_dns, static function ($row) use ($esc): string {
        return '<td>' . $esc($row['state'] ?? '') . '</td><td>' . $esc($row['service_present'] ?? '0') . '</td><td>' . $esc($row['service_running'] ?? '0') . '</td><td>' . $esc($row['reason'] ?? '') . '</td>';
    });
}
if ($has_role_details($ad_dc_health_summary, $ad_dc_services)) {
    $ad_dc_details .= $table(['Name', 'Role', 'Core', 'Present', 'State', 'Start mode', 'Display'], $issue_first($ad_dc_services, static fn (array $row): int => strtolower((string) ($row['state'] ?? '')) === 'running' ? 0 : (1 + ((int) ($row['core'] ?? 0) * 10))), static function ($row) use ($esc, $state_label): string {
        return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['role'] ?? '') . '</td><td>' . $esc($row['core'] ?? '0') . '</td><td>' . $esc($row['present'] ?? '0') . '</td><td>' . $state_label($row['state'] ?? 'unknown', ['running']) . '</td><td>' . $esc($row['start_mode'] ?? '') . '</td><td>' . $esc($row['display'] ?? '') . '</td>';
    });
}
if ($has_role_details($ad_dc_health_summary, $ad_dc_shares)) {
    $ad_dc_details .= $table(['Share', 'Present', 'Path'], $issue_first($ad_dc_shares, static fn (array $row): int => (int) ($row['present'] ?? 0) === 1 ? 0 : 1), static function ($row) use ($esc): string {
        return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['present'] ?? '0') . '</td><td>' . $esc($row['path'] ?? '') . '</td>';
    });
}
if ($has_role_details($ad_dc_health_summary, $ad_dc_time)) {
    $ad_dc_details .= $table(['State', 'Source', 'Stratum', 'Leap', 'Last sync', 'Reason'], $ad_dc_time, static function ($row) use ($esc): string {
        return '<td>' . $esc($row['state'] ?? '') . '</td><td>' . $esc($row['source'] ?? '') . '</td><td>' . $esc($row['stratum'] ?? '') . '</td><td>' . $esc($row['leap_indicator'] ?? '') . '</td><td>' . $esc($row['last_successful_sync_time'] ?? '') . '</td><td>' . $esc($row['reason'] ?? '') . '</td>';
    });
}
if ($has_role_details($ad_dc_health_summary, $ad_dc_security_events)) {
    $ad_dc_details .= $table(['Security category', 'Count', 'Event IDs', 'Window', 'State', 'Source'], $issue_first($ad_dc_security_events, static fn (array $row): int => (int) ($row['count'] ?? 0), 'category'), static function ($row) use ($esc, $state_label): string {
        return '<td>' . $esc($row['category'] ?? '') . '</td><td>' . $esc($row['count'] ?? '0') . '</td><td>' . $esc($row['event_ids'] ?? '') . '</td><td>' . $esc($row['since_hours'] ?? '') . 'h</td><td>' . $state_label($row['state'] ?? 'inventory', ['inventory', 'ok']) . '</td><td>' . $esc($row['source'] ?? '') . '</td>';
    });
}
$ad_replication_details = $table(['State', 'Source', 'Target', 'Naming context', 'Failures', 'Last success', 'Last failure', 'Status'], $issue_first($ad_replication, static fn (array $row): int => (strtolower((string) ($row['state'] ?? '')) === 'ok' ? 0 : 1000) + (int) ($row['failure_count'] ?? $row['failures'] ?? 0), 'target'), static function ($row) use ($esc): string {
    return '<td>' . $esc($row['state'] ?? '') . '</td><td>' . $esc($row['source'] ?? '') . '</td><td>' . $esc($row['target'] ?? '') . '</td><td>' . $esc($row['naming_context'] ?? '') . '</td><td>' . $esc($row['failure_count'] ?? '0') . '</td><td>' . $esc($row['last_success'] ?? '') . '</td><td>' . $esc($row['last_failure'] ?? '') . '</td><td>' . $esc($row['last_failure_status'] ?? ($row['reason'] ?? '')) . '</td>';
});
$dfsr_details = $table(['State', 'Replication group', 'Folder', 'Member', 'Source', 'Reason'], $issue_first($ad_dfsr, static fn (array $row): int => strtolower((string) ($row['state'] ?? '')) === 'ok' ? 0 : 1, 'replication_group'), static function ($row) use ($esc): string {
    return '<td>' . $esc($row['state'] ?? '') . '</td><td>' . $esc($row['replication_group'] ?? '') . '</td><td>' . $esc($row['replicated_folder'] ?? '') . '</td><td>' . $esc($row['member'] ?? '') . '</td><td>' . $esc($row['source'] ?? ($row['tool'] ?? '')) . '</td><td>' . $esc($row['reason'] ?? '') . '</td>';
});
$fsmo_details = $table(['State', 'Role', 'Owner', 'Reason'], $issue_first($ad_fsmo, static fn (array $row): int => strtolower((string) ($row['state'] ?? '')) === 'ok' ? 0 : 1, 'role'), static function ($row) use ($esc): string {
    return '<td>' . $esc($row['state'] ?? '') . '</td><td>' . $esc($row['role'] ?? '') . '</td><td>' . $esc($row['owner'] ?? '') . '</td><td>' . $esc($row['reason'] ?? '') . '</td>';
});
$users_details = $table(['User', 'Domain', 'Session', 'ID', 'State', 'Idle time', 'Logon time', 'Current', 'Source'], $logged_on_user_sessions, static function ($row) use ($esc, $state_label): string {
    return '<td>' . $esc($row['user'] ?? '') . '</td><td>' . $esc($row['domain'] ?? '') . '</td><td>' . $esc($row['session_name'] ?? '') . '</td><td>' . $esc($row['session_id'] ?? '') . '</td><td>' . $state_label($row['state'] ?? 'unknown', ['active']) . '</td><td>' . $esc($row['idle_time'] ?? '') . '</td><td>' . $esc($row['logon_time'] ?? '') . '</td><td>' . $esc($row['current'] ?? '0') . '</td><td>' . $esc($row['source'] ?? '') . '</td>';
});

$reboot_details = $kv_table([
    'Pending reboot' => $state_label($pending_reboot['pending'] ?? '0', ['0']) . ' ' . $esc($pending_reboot['sources'] ?? ''),
    'Windows Update reboot required' => $state_label($windows_update['reboot_required'] ?? '0', ['0']),
    'Windows Update service' => $esc($windows_update['service_state'] ?? 'unknown') . ' / ' . $esc($windows_update['start_mode'] ?? 'unknown'),
]);
$service_details = '';
if (empty($classified_service_groups)) {
    $service_details .= $table(['Name', 'Display name', 'State'], $issue_first($watched_services, static fn (array $row): int => strtolower((string) ($row['state'] ?? '')) === 'running' ? 0 : 1), static function ($row) use ($esc, $state_label): string {
        return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['display'] ?? '') . '</td><td>' . $state_label($row['state'] ?? 'unknown') . '</td>';
    });
} else {
    foreach ($classified_service_groups as $group_key => $services_in_group) {
        if (empty($services_in_group)) {
            continue;
        }

        $summary = $service_group_summaries[$group_key] ?? [];
        $service_details .= '<h4>' . $esc($group_key) . ' <small>Total ' . $esc($summary['total'] ?? count($services_in_group)) . ', not running ' . $esc($summary['not_running'] ?? '0') . '</small></h4>';
        $service_details .= $table(['Name', 'Display name', 'State', 'Start mode', 'Account', 'Path', 'Source'], $services_in_group, static function ($row) use ($esc, $state_label): string {
            return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['display'] ?? '') . '</td><td>' . $state_label($row['state'] ?? 'unknown') . '</td><td>' . $esc($row['start_mode'] ?? '') . '</td><td>' . $esc($row['account'] ?? '') . '</td><td>' . $esc($row['path'] ?? '') . '</td><td>' . $esc($row['source'] ?? '') . '</td>';
        });
    }
}
if (! empty($excluded_services)) {
    $service_details .= '<h4>Excluded / Low Value Services</h4>';
    $service_details .= $table(['Name', 'Display name', 'State', 'Start mode', 'Account', 'Path', 'Source'], $excluded_services, static function ($row) use ($esc, $state_label): string {
        return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['display'] ?? '') . '</td><td>' . $state_label($row['state'] ?? 'unknown') . '</td><td>' . $esc($row['start_mode'] ?? '') . '</td><td>' . $esc($row['account'] ?? '') . '</td><td>' . $esc($row['path'] ?? '') . '</td><td>' . $esc($row['source'] ?? '') . '</td>';
    });
}
$event_details = $table(['Log', 'Scanned', 'Critical', 'Error', 'Warning', 'Latest critical/error UTC'], $issue_first($event_logs, static fn (array $row): int => ((int) ($row['critical_count'] ?? 0) * 1000) + ((int) ($row['error_count'] ?? 0) * 100) + (int) ($row['warning_count'] ?? 0), 'log'), static function ($row) use ($esc): string {
    return '<td>' . $esc($row['log'] ?? '') . '</td><td>' . $esc($row['scanned_events'] ?? '0') . '</td><td>' . $esc($row['critical_count'] ?? '0') . '</td><td>' . $esc($row['error_count'] ?? '0') . '</td><td>' . $esc($row['warning_count'] ?? '0') . '</td><td>' . $esc($row['latest_critical_or_error_utc'] ?? '') . '</td>';
});
if (! empty($event_log_high_value)) {
    $event_details .= '<h4>High-value Event Samples <small>groups ' . $esc($event_log_high_value_summary['signatures_total'] ?? count($event_log_high_value)) . ', events ' . $esc($event_log_high_value_summary['events_total'] ?? '0') . ', samples ' . $esc($event_log_high_value_summary['samples_total'] ?? count($event_log_high_value)) . ', truncated ' . $esc($event_log_high_value_summary['truncated'] ?? '0') . '</small></h4>';
    $event_details .= $table(['Log', 'Provider', 'Event ID', 'Level', 'Count', 'Last seen UTC', 'Sample UTC', 'Message excerpt'], $issue_first($event_log_high_value, static fn (array $row): int => ((int) ($row['level_code'] ?? 9) === 1 ? 10000 : 0) + ((int) ($row['level_code'] ?? 9) === 2 ? 5000 : 0) + (int) ($row['count'] ?? 0), 'provider'), static function ($row) use ($esc): string {
        return '<td>' . $esc($row['log'] ?? '') . '</td><td>' . $esc($row['provider'] ?? '') . '</td><td>' . $esc($row['event_id'] ?? '') . '</td><td>' . $esc($row['level'] ?? '') . '</td><td>' . $esc($row['count'] ?? '0') . '</td><td>' . $esc($row['last_seen_utc'] ?? '') . '</td><td>' . $esc($row['sample_time_utc'] ?? '') . '</td><td>' . $esc($row['message_excerpt'] ?? '') . '</td>';
    });
}
$process_details = $table(['Name', 'Matched', 'Working set', 'Private bytes', 'CPU seconds'], $issue_first($watched_processes, static fn (array $row): int => (int) ($row['matched_count'] ?? 0) === 0 ? 1 : 0), static function ($row) use ($esc, $format_bytes): string {
    return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['matched_count'] ?? '0') . '</td><td>' . $esc($format_bytes($row['working_set_bytes'] ?? 0)) . '</td><td>' . $esc($format_bytes($row['private_bytes'] ?? 0)) . '</td><td>' . $esc($row['processor_seconds'] ?? '0') . '</td>';
});
$tcp_details = $table(['Name', 'Address', 'Port', 'Listening'], $issue_first($watched_tcp_ports, static fn (array $row): int => (int) ($row['listening'] ?? 0) === 1 ? 0 : 1), static function ($row) use ($esc, $state_label): string {
    return '<td>' . $esc($row['name'] ?? '') . '</td><td>' . $esc($row['address'] ?? '*') . '</td><td>' . $esc($row['port'] ?? '') . '</td><td>' . $state_label($row['listening'] ?? '0') . '</td>';
});

$performance_tab = '';
$performance_tab .= $render_section_summary('collector-impact', 'Collector Resource Impact', $sections['collector_impact']['state'], $sections['collector_impact']['summary'], $agent_resource_details, $agent_resource_known ? [
    ['label' => 'Collector CPU Impact', 'key' => 'windows-agent_agent_resource_cpu'],
    ['label' => 'Collector Memory Footprint', 'key' => 'windows-agent_agent_resource_memory'],
    ['label' => 'Collector Disk I/O', 'key' => 'windows-agent_agent_resource_io'],
] : []);
$performance_tab .= $render_section_summary('agent-performance', 'Agent Performance', $sections['agent']['state'], $sections['agent']['summary'], $agent_perf_details, [
    ['label' => 'Collection Duration', 'key' => 'windows-agent_agent_collection_duration'],
    ['label' => 'Payload Size', 'key' => 'windows-agent_agent_payload_size'],
    ['label' => 'Collector Issues', 'key' => 'windows-agent_agent_collector_issues'],
]);
$performance_tab .= $render_section_summary('vm-resources', 'VM Resources', $sections['vm']['state'], $sections['vm']['summary'], $vm_details, [
    ['label' => 'VM Resource Utilization', 'key' => 'windows-agent_vm_resources'],
]);
$performance_tab .= $render_section_summary('performance-depth', 'Windows Performance Depth', $sections['performance']['state'], $sections['performance']['summary'], $perf_details, [
    ['label' => 'CPU Queue', 'key' => 'windows-agent_perf_cpu_queue'],
    ['label' => 'Memory Committed', 'key' => 'windows-agent_perf_memory_committed'],
    ['label' => 'Paging Rate', 'key' => 'windows-agent_perf_paging'],
    ['label' => 'Disk Latency', 'key' => 'windows-agent_perf_disk_latency'],
    ['label' => 'Disk Queue', 'key' => 'windows-agent_perf_disk_queue'],
    ['label' => 'Pressure Issues', 'key' => 'windows-agent_perf_pressure_issues'],
]);

$roles_tab = '';
$roles_tab .= $render_section_summary('sql', 'SQL Server', $sections['sql']['state'], $sections['sql']['summary'], $sql_details);
$roles_tab .= $render_section_summary('iis', 'IIS', $sections['iis']['state'], $sections['iis']['summary'], $iis_details);
$roles_tab .= $render_section_summary('horizon', 'VMware Horizon', $sections['horizon']['state'], $sections['horizon']['summary'], $horizon_details, [
    ['label' => 'Horizon State and Issues', 'key' => 'windows-agent_horizon_state_health'],
    ['label' => 'Horizon Listeners and Certificates', 'key' => 'windows-agent_horizon_edges'],
]);
$factorytalk_graphs = [
    ['label' => 'FactoryTalk State and Issues', 'key' => 'windows-agent_factorytalk_state_health'],
];
if (! empty($factorytalk_runtime_summary) && ! in_array(strtolower((string) ($factorytalk_runtime_summary['state'] ?? '')), ['disabled', 'not_detected', 'unsupported'], true)) {
    $factorytalk_graphs[] = ['label' => 'FactoryTalk Runtime CPU', 'key' => 'windows-agent_factorytalk_runtime_cpu'];
    $factorytalk_graphs[] = ['label' => 'FactoryTalk Runtime Memory', 'key' => 'windows-agent_factorytalk_runtime_memory'];
    $factorytalk_graphs[] = ['label' => 'FactoryTalk Runtime Processes', 'key' => 'windows-agent_factorytalk_runtime_processes', 'secondary' => true];
    $factorytalk_graphs[] = ['label' => 'FactoryTalk Runtime I/O', 'key' => 'windows-agent_factorytalk_runtime_io', 'secondary' => true];
}
if (! empty($factorytalk_linx_connections) || ! empty($factorytalk_linx_backplane) || ! empty($factorytalk_linx_transactions) || ! empty($factorytalk_livedata)) {
    $factorytalk_graphs[] = ['label' => 'FactoryTalk Linx Active Connections', 'key' => 'windows-agent_factorytalk_linx_connections_active'];
    $factorytalk_graphs[] = ['label' => 'FactoryTalk Linx Connection Churn', 'key' => 'windows-agent_factorytalk_linx_connections_churn', 'secondary' => true];
    $factorytalk_graphs[] = ['label' => 'FactoryTalk Linx Backplane Traffic', 'key' => 'windows-agent_factorytalk_linx_traffic'];
    $factorytalk_graphs[] = ['label' => 'FactoryTalk Linx Transactions', 'key' => 'windows-agent_factorytalk_linx_transactions', 'secondary' => true];
    $factorytalk_graphs[] = ['label' => 'FactoryTalk Live Data Clients', 'key' => 'windows-agent_factorytalk_livedata_clients', 'secondary' => true];
}
$roles_tab .= $render_section_summary('factorytalk', 'FactoryTalk', $sections['factorytalk']['state'], $sections['factorytalk']['summary'], $factorytalk_details, $factorytalk_graphs, 'Operational view', 'Trends');
$roles_tab .= $render_section_summary('roles', 'Detected Roles', $section_state(empty($roles) ? 'not_detected' : 'ok'), $metric('Rows', count($roles)), $role_details);
$roles_tab .= $render_section_summary('ad', 'Active Directory Summary', $section_state($ad_summary['state'] ?? 'not_applicable'), $metric('Domain', $ad_summary['domain'] ?? '') . ' ' . $metric('Failures', $ad_summary['replication_failures'] ?? '0'), $ad_details);
$roles_tab .= $render_section_summary('ad-dc', 'AD/DC Local Health', $sections['ad_dc']['state'], $sections['ad_dc']['summary'], $ad_dc_details, [
    ['label' => 'AD/DC Local Health Issues', 'key' => 'windows-agent_ad_dc_health'],
]);
$roles_tab .= $render_section_summary('ad-replication', 'AD Replication Targets', $section_state(empty($ad_replication) ? 'not_applicable' : 'ok'), $metric('Targets', count($ad_replication)), $ad_replication_details);
$roles_tab .= $render_section_summary('dfsr', 'DFSR Replication Health', $section_state(empty($ad_dfsr) ? 'not_applicable' : 'ok'), $metric('Rows', count($ad_dfsr)), $dfsr_details);
$roles_tab .= $render_section_summary('fsmo', 'FSMO Roles', $section_state(empty($ad_fsmo) ? 'not_applicable' : 'ok'), $metric('Roles', count($ad_fsmo)), $fsmo_details);
$roles_tab .= $render_section_summary('users', 'Logged-On Users', $section_state(empty($logged_on_user_sessions) ? 'not_detected' : 'ok'), $metric('Sessions', count($logged_on_user_sessions)), $users_details);

$security_tab = '';
$security_tab .= $render_section_summary('tls', 'TLS Certificate Visibility', $sections['tls']['state'], $sections['tls']['summary'], $tls_details, $tls_graphs);

$backup_tab = '';
$backup_tab .= $render_section_summary('backup-storage', 'Backup / Storage Visibility', $sections['backup']['state'], $sections['backup']['summary'], $backup_details, [
    ['label' => 'VSS Writer Failures', 'key' => 'windows-agent_backup_vss_failures'],
    ['label' => 'Backup Services Down', 'key' => 'windows-agent_backup_services_down'],
]);
$backup_tab .= $render_section_summary('datto', 'Datto Backup Health', $sections['datto']['state'], $sections['datto']['summary'], $datto_details, [
    ['label' => 'Datto State Flags', 'key' => 'windows-agent_datto_state_flags'],
    ['label' => 'Datto Issue Counts', 'key' => 'windows-agent_datto_issue_counts'],
]);

$services_tab = '';
$services_tab .= $render_section_summary('reboot', 'Reboot and Windows Update', $section_state(((int) ($pending_reboot['pending'] ?? 0) || (int) ($windows_update['reboot_required'] ?? 0)) ? 'warning' : 'ok'), $metric('Pending reboot', $pending_reboot['pending'] ?? '0') . ' ' . $metric('Update reboot', $windows_update['reboot_required'] ?? '0'), $reboot_details, [
    ['label' => 'Reboot Required State', 'key' => 'windows-agent_reboot_state'],
]);
$services_tab .= $render_section_summary('services', 'Services', $sections['services']['state'], $sections['services']['summary'], $service_details);
$services_tab .= $render_section_summary('events', 'Event Logs', $sections['events']['state'], $sections['events']['summary'], $event_details, [
    ['label' => 'Event Counts', 'key' => 'windows-agent_event_logs'],
]);
$services_tab .= $render_section_summary('processes', 'Watched Processes', $sections['processes']['state'], $sections['processes']['summary'], $process_details);
$services_tab .= $render_section_summary('tcp', 'Watched TCP Ports', $sections['tcp']['state'], $sections['tcp']['summary'], $tcp_details);

$agent_performance_tab = '';
$agent_performance_tab .= $render_section_summary('agent-os', 'Agent and OS', $sections['agent']['state'], $sections['agent']['summary'], $agent_details);
$agent_performance_tab .= $performance_tab;
$agent_performance_tab .= $render_section_summary('collector-timings', 'Collector Timings', $sections['agent']['state'], $metric('Collectors run', $agent_performance['collectors_run'] ?? '0') . ' ' . $metric('Failed/timed out', $agent_issues), $collector_details);

echo '<style>
.windows-agent-collapse-toggle .windows-agent-collapse-arrow { margin-left: 4px; }
.windows-agent-collapse-toggle .windows-agent-collapse-arrow-down { display: none; }
.windows-agent-collapse-toggle .windows-agent-collapse-arrow-up { display: inline-block; }
.windows-agent-collapse-toggle.collapsed .windows-agent-collapse-arrow-down { display: inline-block; }
.windows-agent-collapse-toggle.collapsed .windows-agent-collapse-arrow-up { display: none; }
.windows-agent-graph-view { margin-bottom: 12px; }
.windows-agent-graph-view h4 { margin-top: 0; margin-bottom: 6px; font-size: 13px; font-weight: 600; }
.windows-agent-overview-title { margin: 0 0 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(127, 127, 127, 0.35); font-size: 16px; font-weight: 600; }
.windows-agent-data-table th,
.windows-agent-data-table td { vertical-align: middle !important; }
.windows-agent-tab-alert { margin-left: 5px; }
.windows-agent-subsection { margin-top: 14px; padding-top: 12px; border-top: 1px solid rgba(127, 127, 127, 0.25); }
.windows-agent-subsection-body { margin-top: 12px; }
.windows-agent-disclosure-summary { margin-left: 6px; }
.windows-agent-factorytalk-status { margin-bottom: 0; padding: 8px 10px; border-left: 3px solid #999; border-bottom: 1px solid rgba(127, 127, 127, 0.2); background: transparent; }
.windows-agent-factorytalk-status-success { border-left-color: #5cb85c; }
.windows-agent-factorytalk-status-warning { border-left-color: #f0ad4e; }
.windows-agent-factorytalk-status-danger { border-left-color: #d9534f; }
.windows-agent-factorytalk-action { margin-left: 10px; font-weight: normal; }
.windows-agent-factorytalk-collected { float: right; margin-left: 10px; font-size: 11px; font-weight: normal; }
.windows-agent-factorytalk-stats { margin: 0 0 18px; border-bottom: 1px solid rgba(127, 127, 127, 0.25); }
.windows-agent-factorytalk-stat { min-height: 78px; padding-top: 12px; padding-bottom: 10px; border-right: 1px solid rgba(127, 127, 127, 0.2); }
.windows-agent-factorytalk-stat:last-child { border-right: 0; }
.windows-agent-factorytalk-stat-label { font-size: 12px; }
.windows-agent-factorytalk-stat-value { margin: 2px 0; font-size: 18px; font-weight: 600; line-height: 1.2; }
.windows-agent-factorytalk-stat-detail { font-size: 11px; }
.windows-agent-factorytalk-attention { margin: 0 0 14px; }
.windows-agent-factorytalk-attention h4 { margin: 0 0 4px; font-size: 14px; font-weight: 600; }
.windows-agent-factorytalk-attention ul { margin: 0; padding: 0; list-style: none; }
.windows-agent-factorytalk-attention li { padding: 6px 0; border-top: 1px solid rgba(127, 127, 127, 0.2); }
.windows-agent-factorytalk-attention-action { margin-top: 2px; }
@media (max-width: 767px) {
    .windows-agent-factorytalk-stat { min-height: 0; border-right: 0; border-bottom: 1px solid rgba(127, 127, 127, 0.15); }
    .windows-agent-factorytalk-action,
    .windows-agent-factorytalk-collected { display: block; float: none; margin: 4px 0 0; }
    .windows-agent-disclosure-summary { display: block; margin: 6px 0 0; }
}
</style>';
echo '<ul class="nav nav-tabs" role="tablist">';
$tabs = [
    'windows-agent-overview' => 'Overview',
    'windows-agent-roles' => 'Roles & Workloads',
    'windows-agent-security' => 'Security & Certificates',
    'windows-agent-backup' => 'Backup',
    'windows-agent-services' => 'Services & Events',
    'windows-agent-agent-performance' => 'Agent Performance',
];
$tab_has_issue = [
    'windows-agent-overview' => false,
    'windows-agent-roles' => $state_has_issue($sections['sql']['state']) || $state_has_issue($sections['iis']['state']) || $state_has_issue($sections['horizon']['state']) || $state_has_issue($sections['factorytalk']['state']) || $state_has_issue($sections['ad_dc']['state']),
    'windows-agent-security' => $state_has_issue($sections['tls']['state']),
    'windows-agent-backup' => $state_has_issue($sections['backup']['state']) || $state_has_issue($sections['datto']['state']),
    'windows-agent-services' => $state_has_issue($sections['services']['state']) || $state_has_issue($sections['events']['state']) || $state_has_issue($sections['processes']['state']) || $state_has_issue($sections['tcp']['state']) || ((int) ($pending_reboot['pending'] ?? 0) || (int) ($windows_update['reboot_required'] ?? 0)),
    'windows-agent-agent-performance' => $state_has_issue($sections['agent']['state']) || $state_has_issue($sections['vm']['state']) || $state_has_issue($sections['performance']['state']),
];
$issue_icon = '<span class="windows-agent-tab-alert glyphicon glyphicon-exclamation-sign text-danger" title="This tab has one or more issues" aria-label="issues present"></span>';
$first = true;
foreach ($tabs as $id => $title) {
    echo '<li role="presentation" class="' . ($first ? 'active' : '') . '"><a href="#' . $esc($id) . '" aria-controls="' . $esc($id) . '" role="tab" data-toggle="tab">' . $esc($title) . (($tab_has_issue[$id] ?? false) ? ' ' . $issue_icon : '') . '</a></li>';
    $first = false;
}
echo '</ul>';
echo '<div class="tab-content" style="padding-top: 15px;">';
$render_tab('windows-agent-overview', true, $overview);
$render_tab('windows-agent-roles', false, $roles_tab);
$render_tab('windows-agent-security', false, $security_tab);
$render_tab('windows-agent-backup', false, $backup_tab);
$render_tab('windows-agent-services', false, $services_tab);
$render_tab('windows-agent-agent-performance', false, $agent_performance_tab);
echo '</div>';
