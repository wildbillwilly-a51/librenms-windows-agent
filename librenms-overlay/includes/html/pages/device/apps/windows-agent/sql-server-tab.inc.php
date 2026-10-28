<?php

$sql_request = is_array($vars ?? null) ? $vars : [];
foreach (['sql_instance', 'sql_show', 'sql_open', 'sql_section'] as $key) {
    if (! array_key_exists($key, $sql_request) && isset($_GET[$key])) $sql_request[$key] = $_GET[$key];
}
$sql_ids = array_column($sql_view['cards'], 'id');
$sql_scalar = static fn (string $key, string $default): string => is_string($sql_request[$key] ?? null) ? $sql_request[$key] : $default;
$sql_selected = $sql_scalar('sql_instance', 'all');
$sql_invalid_selection = $sql_selected !== 'all' && ! in_array($sql_selected, $sql_ids, true);
if ($sql_invalid_selection) $sql_selected = 'all';
$sql_show = $sql_scalar('sql_show', 'all') === 'issues' ? 'issues' : 'all';
$sql_open = $sql_scalar('sql_open', '');
if (! in_array($sql_open, $sql_ids, true)) $sql_open = '';
$sql_section = $sql_scalar('sql_section', '');
if (! in_array($sql_section, ['performance', 'services', 'collection'], true)) $sql_section = '';
$sql_has_scope = count(array_intersect(array_keys($sql_request), ['sql_instance', 'sql_show', 'sql_open', 'sql_section'])) > 0;
$sql_base = ['page' => 'device', 'device' => (int) ($device['device_id'] ?? 0), 'tab' => 'apps', 'app' => 'windows-agent'];
if (isset($vars['instance']) && is_numeric($vars['instance'])) $sql_base['instance'] = (int) $app->app_id;
$sql_url = static function (array $scope = [], string $anchor = 'windows-agent-sql-server') use ($sql_base): string {
    return \LibreNMS\Util\Url::generate($sql_base) . ($scope === [] ? '' : '?' . http_build_query($scope, '', '&', PHP_QUERY_RFC3986)) . '#' . $anchor;
};
$sql_facts = static function (array $facts) use ($esc): string {
    $html = '<dl class="windows-agent-sql-evidence">';
    foreach ($facts as $label => $value) $html .= '<div><dt>' . $esc($label) . '</dt><dd>' . $esc($value) . '</dd></div>';
    return $html . '</dl>';
};
$sql_evidence = static function (string $id, string $section, string $title, string $context, string $body) use ($esc, $sql_open, $sql_section): string {
    return '<details class="windows-agent-sql-section" data-sql-section="' . $section . '" id="windows-agent-sql-' . $id . '-' . $section . '"' . ($sql_open === $id && $sql_section === $section ? ' open' : '') . '><summary><strong>' . $esc($title) . '</strong><span class="text-muted">' . $esc($context) . '</span></summary><div class="windows-agent-sql-section-body">' . $body . '</div></details>';
};

$sql_value = static function (array $row, string $key, int $decimals = 0, string $unit = '') use ($sql_view): string {
    $value = ($sql_view['number'])($row, $key);
    return $value === null ? 'Not measured' : number_format($value, $decimals) . $unit;
};
$sql_max_tile = static function (string $key) use ($sql_view): array {
    $values = $sql_view['measures'][$key];
    if ($values === []) {
        return ['Not measured', $sql_view['runningCount'] > 0 ? 'No readable sample' : 'No running engine sample'];
    }
    $max = max($values);
    $names = array_keys(array_filter($values, static fn ($value): bool => $value === $max));
    $context = $max > 0 ? implode(', ', array_slice($names, 0, 2)) . (count($names) > 2 ? ' +' . (count($names) - 2) : '') : 'Maximum at last sample';
    return [(string) (int) $max, $context . ' · ' . count($values) . '/' . $sql_view['runningCount'] . ' running instances measured'];
};
$sql_coverage = 'Counters read on ' . count($sql_view['counterNames']) . '/' . $sql_view['runningCount'] . ' running instances · Database state, backups and job history are not collected';
$sql_engine_value = count($sql_view['cards']) === $sql_view['total'] && $sql_view['total'] > 0
    ? $sql_view['runningCount'] . '/' . $sql_view['total'] . ' running'
    : 'Not reported';
$sql_listener_value = $sql_view['listenerKnown'] > 0 ? $sql_view['listenerActive'] . '/' . $sql_view['listenerKnown'] . ' listening' : ($sql_view['listenerUnknown'] > 0 ? 'Not reported' : 'Not applicable');
$sql_listener_detail = 'Local TCP only';
if ($sql_view['listenerUnknown'] > 0) {
    $sql_listener_detail .= ' · ' . $sql_view['listenerUnknown'] . ' not reported';
}
if ($sql_view['tcpDisabled'] > 0) {
    $sql_listener_detail .= ' · ' . $sql_view['tcpDisabled'] . ' TCP disabled';
}
$sql_schedule_value = $sql_view['agentDown'] > 0 ? $sql_view['agentDown'] . ' expected down' : ($sql_view['jobKnown'] > 0 ? (int) $sql_view['jobFailures'] . ' job failures' : ($sql_view['agentUnknown'] > 0 ? 'Partially reported' : 'No Agent outage'));
$sql_schedule_detail = $sql_view['agentRunning'] . ' Agent service(s) running · ' . $sql_view['agentExpected'] . ' expected · ' . $sql_view['agentDown'] . ' expected down';
if ($sql_view['jobKnown'] > 0) {
    $sql_schedule_detail .= ' · ' . (int) $sql_view['jobFailures'] . ' observed job failures · ' . $sql_view['jobKnown'] . '/' . $sql_view['total'] . ' instances measured';
} else {
    $sql_schedule_detail .= ' · job failures not measured';
}
$sql_blocked_tile = $sql_max_tile('processes_blocked');
$sql_grants_tile = $sql_max_tile('memory_grants_pending');
$sql_attention = array_map(static function (array $item) use ($sql_view, $sql_url): array {
    $name = $item['instance'];
    $issueTitle = $item['title'];
    $item['title'] = $name . ' — ' . $item['title'];
    foreach ($sql_view['cards'] as $card) {
        if ($card['name'] !== $name || $card['ambiguous']) continue;
        $section = str_contains(strtolower($issueTitle), 'service') || str_contains(strtolower($issueTitle), 'listener') ? 'services' : 'performance';
        $item['href'] = $sql_url(['sql_instance' => $card['id'], 'sql_show' => 'all', 'sql_open' => $card['id'], 'sql_section' => $section], 'windows-agent-sql-instance-' . $card['id']);
        break;
    }
    return $item;
}, $sql_view['issues']);

$sql_tab = '<div class="windows-agent-sql">';
$sql_tab .= $render_tab_summary([
    'state' => $sections['sql']['state'],
    'verdict' => $sql_view['verdict'],
    'next' => $sql_attention[0]['action'] ?? ($sql_view['gaps'] !== [] ? 'Review collection coverage and instance evidence below.' : ''),
    'collected' => $sql_view['freshness'],
    'coverage' => $sql_coverage,
    'tiles' => [
        ['Engine services', $sql_engine_value, 'Windows service state'],
        ['Local TCP listeners', $sql_listener_value, $sql_listener_detail],
        ['Scheduled work', $sql_schedule_value, $sql_schedule_detail],
        ['Blocking now', $sql_blocked_tile[0], $sql_blocked_tile[1]],
        ['Pending memory grants', $sql_grants_tile[0], $sql_grants_tile[1]],
    ],
    'attention' => $sql_attention,
]);
if ($sql_view['gaps'] !== []) {
    $sql_tab .= '<div class="windows-agent-sql-coverage" role="status"><strong>Collection coverage</strong><ul>';
    foreach ($sql_view['gaps'] as $gap) {
        $sql_tab .= '<li>' . $esc($gap) . '</li>';
    }
    $sql_tab .= '</ul></div>';
}
$sql_tab .= '<h4>Instances <small>' . $sql_view['total'] . ' discovered</small></h4><p class="text-muted">Service and local listener checks do not prove that a database or a remote application can connect. Expand an instance for evidence.</p>';
if ($sql_view['cards'] === []) {
    $sql_tab .= '<p>Instance details were not reported.</p>';
}
$sql_tab .= '<form method="get" action="' . $esc($sql_url()) . '" class="windows-agent-sql-filters" data-sql-filters>';
$sql_tab .= '<div><label for="windows-agent-sql-instance-filter">Instance</label><select id="windows-agent-sql-instance-filter" name="sql_instance" class="form-control"><option value="all">All instances</option>';
foreach ($sql_view['cards'] as $card) $sql_tab .= '<option value="' . $card['id'] . '"' . ($sql_selected === $card['id'] ? ' selected' : '') . '>' . $esc($card['name']) . '</option>';
$sql_tab .= '</select></div><div><label for="windows-agent-sql-show-filter">Show</label><select id="windows-agent-sql-show-filter" name="sql_show" class="form-control"><option value="all"' . ($sql_show === 'all' ? ' selected' : '') . '>All instances</option><option value="issues"' . ($sql_show === 'issues' ? ' selected' : '') . '>Issues and unknown</option></select></div><button type="submit" class="btn btn-primary">Apply</button><a class="btn btn-default" data-sql-clear href="' . $esc($sql_url(['sql_instance' => 'all', 'sql_show' => 'all'])) . '">Clear filters</a></form>';
if ($sql_invalid_selection) $sql_tab .= '<p class="text-muted">Requested instance is no longer available; showing all instances.</p>';
$sql_shown = 0;
$sql_outside = 0;
foreach ($sql_view['cards'] as $card) {
    $visible = ($sql_selected === 'all' || $sql_selected === $card['id']) && ($sql_show === 'all' || $card['cardState'] !== 'ok');
    $sql_shown += $visible ? 1 : 0;
    $sql_outside += $visible ? 0 : count($card['cardIssues']);
}
$sql_tab .= '<p class="text-muted windows-agent-sql-filter-status" data-sql-filter-status role="status">Showing ' . $sql_shown . ' of ' . count($sql_view['cards']) . ' instances; ' . $sql_outside . ' instance condition(s) outside this filter. Summary and attention remain global.</p>';
$sql_tab .= '<p data-sql-empty' . ($sql_shown > 0 ? ' hidden' : '') . '>No instances match these filters. Clear filters to view all evidence.</p>';
foreach ($sql_view['cards'] as $sql_index => $card) {
    $sql_dom_id = $card['id'] . ($card['ambiguous'] ? '-ambiguous-' . $sql_index : '');
    $visible = ($sql_selected === 'all' || $sql_selected === $card['id']) && ($sql_show === 'all' || $card['cardState'] !== 'ok');

    $row = $card['row'];
    $perf = $card['perf'];
    $state = $section_state($card['cardState']);
    $sql_tab .= '<details class="windows-agent-sql-instance" id="windows-agent-sql-instance-' . $sql_dom_id . '" data-sql-instance="' . $card['id'] . '" data-sql-state="' . $esc($card['cardState']) . '" data-sql-issues="' . count($card['cardIssues']) . '"' . (! $visible ? ' hidden' : '') . ($sql_open === $card['id'] || $sql_selected === $card['id'] ? ' open' : '') . '><summary><span class="windows-agent-sql-instance-name"><strong>' . $esc($card['name']) . '</strong> ' . $state['html'] . '</span>';
    $sql_tab .= '<span class="windows-agent-sql-instance-facts"><span>Engine <strong>' . $esc($row['state'] ?? 'Not reported') . '</strong></span><span>Agent <strong>' . $esc($card['agentText']) . '</strong></span><span>TCP <strong>' . $esc($card['listener']) . '</strong></span></span>';
    $sql_reason = $card['cardIssues'][0]['title'] ?? ($card['cardState'] === 'unknown' ? 'Some checks are not measured' : 'No issues observed in measured checks');
    if (count($card['cardIssues']) > 1) {
        $sql_reason .= ' · +' . (count($card['cardIssues']) - 1) . ' more';
    }
    $sql_tab .= '<span class="text-muted windows-agent-sql-instance-reason">' . $esc($sql_reason) . ' <span class="windows-agent-sql-expand">Evidence</span></span></summary><div class="windows-agent-sql-instance-body">';
    $serviceFacts = [
        'Edition / patch' => ($row['edition'] ?? 'Not reported') . ' / ' . ($row['patch_level'] ?? $row['version'] ?? 'Not reported'),
        'Engine startup' => $row['start_mode'] ?? 'Not reported',
        'Agent reason' => $row['agent_reason'] ?? 'Not reported',
        'TCP ports / source' => ($row['listener_ports'] ?? 'Not reported') . ' / ' . ($row['port_source'] ?? 'Not reported'),
        'Browser required' => array_key_exists('requires_browser', $row) ? ((int) $row['requires_browser'] === 1 ? 'Yes' : 'No') : 'Not reported',
    ];
    $performanceFacts = [
        'Blocked processes' => $card['readable'] ? $sql_value($perf, 'processes_blocked') : 'Not measured',
        'Grants pending' => $card['readable'] ? $sql_value($perf, 'memory_grants_pending') : 'Not measured',
        'Page life (seconds)' => $sql_value($perf, 'page_life_expectancy'),
        'Connections' => $sql_value($perf, 'user_connections'),
        'Batches / second' => $sql_value($perf, 'batch_requests_per_sec', 1),
        'Compilation / batch (%)' => $sql_value($perf, 'compilation_percent', 1),
        'Memory / target (%)' => $sql_value($perf, 'memory_used_percent', 1),
        'Deadlocks / second (sample)' => $sql_value($perf, 'deadlocks_per_sec', 2),
        'Job failures' => $card['jobText'],
        'tempdb free (KB)' => $sql_value($perf, 'tempdb_free_space_kb'),
        'Version store (KB)' => $sql_value($perf, 'version_store_size_kb'),
        'Longest RCSI transaction (seconds)' => $sql_value($perf, 'longest_transaction_seconds'),
    ];
    $performanceNote = '<p class="text-muted">Rates are short samples. Job failures span the latest two agent counter collections, not a daily or poll total. Memory / target is cache context. tempdb free space has no fullness denominator here. The longest-transaction counter covers RCSI activity; zero does not rule out other long transactions.</p>';
    $sql_tab .= $sql_evidence($sql_dom_id, 'performance', 'Performance', $card['readable'] ? 'Last observed counters and sampled job evidence' : 'Counters not measured', $sql_facts($performanceFacts) . $performanceNote);
    $sql_tab .= $sql_evidence($sql_dom_id, 'services', 'Services and identity', $card['listener'] . ' · Agent ' . $card['agentText'], $sql_facts($serviceFacts));
    $collectionFacts = [
        'Counter coverage' => $card['readable'] ? ($perf['counters_read'] ?? '0') . ' read, ' . ($perf['counters_missing'] ?? '0') . ' missing' : 'Not measured',
        'Sampled job alert evidence' => $card['jobsQualified'] ? 'Qualified at this observation; expiry must still be checked' : 'Unavailable or awaiting a baseline',
        'Counter alert evidence' => $card['qualified'] ? 'Qualified at this observation; expiry must still be checked' : 'Unavailable for qualified counter alerts',
        'Collected UTC' => $sql_view['collected'] === '' ? 'Not reported' : $sql_view['collected'],
        'Payload valid until (UTC)' => $sql_view['validUntil'] > 0 ? gmdate('Y-m-d H:i:s', $sql_view['validUntil']) : 'Unknown',
        'Job counter reset' => array_key_exists('agent_jobs_reset', $perf) ? ((int) $perf['agent_jobs_reset'] === 1 ? 'Yes — awaiting a baseline' : 'No reset reported') : 'Not reported',
        'Metric instance key' => $card['id'],
        'Database state / log capacity' => 'Not collected by the current agent',
        'Backup / integrity history' => 'Not collected by the current agent',
        'Named job outcomes / runtime' => 'Not collected; sampled job counters are shown in Performance',
    ];
    $sql_tab .= $sql_evidence($sql_dom_id, 'collection', 'Collection', $card['qualified'] ? 'Current counter evidence · query coverage unavailable' : 'Review missing, stale or reset evidence', $sql_facts($collectionFacts) . '<p class="text-muted">Freshness uses the whole payload timestamp and a 15-minute limit. It does not prove a distinct SQL sample or sustained condition. New qualified metrics do not create or enable alerts.</p>');
    $sql_tab .= '</div></details>';

}

$sql_graphs = [
    ['label' => 'Engine inventory and stopped services — host counts', 'key' => 'windows-agent_sql_server', 'secondary' => $sql_view['counterNames'] !== []],
];
if ($sql_view['counterNames'] !== []) {
    $sql_graphs[] = ['label' => 'Blocking and grants — independent instance maxima', 'key' => 'windows-agent_sql_server_queues'];
    $sql_graphs[] = ['label' => 'Batch rate — sum of readable instances', 'key' => 'windows-agent_sql_server_workload'];
    $sql_graphs[] = ['label' => 'Page life expectancy — lowest readable instance, seconds', 'key' => 'windows-agent_sql_server_page_life'];
    $sql_graphs[] = ['label' => 'Compilation rates — sum of readable instances', 'key' => 'windows-agent_sql_server_compilation', 'secondary' => true];
    $sql_graphs[] = ['label' => 'Memory / target — highest readable instance, context only', 'key' => 'windows-agent_sql_server_memory', 'secondary' => true];
}
$sql_trend_note = '<p class="text-muted">Host aggregates only; these plots do not contain per-instance history. Currently readable instances: ' . $esc($sql_view['counterNames'] === [] ? 'none' : implode(', ', $sql_view['counterNames'])) . '. Partial sums may under-report the host. Historical contributing instances are not recorded at each point. Negative unavailable values are hidden in the performance views; gaps do not mean zero.</p>';
$sql_tab .= $render_disclosure('windows-agent-sql-trends', 'Trends', $sql_trend_note . $render_graph_list($sql_graphs, 'windows-agent-sql', true), 'Host aggregates · separate units');

$sql_inventory = '<p class="text-muted windows-agent-sql-scroll-hint">Tables scroll horizontally on smaller screens.</p>';
$sql_inventory .= $table(['Instance', 'Edition', 'Patch level', 'State', 'Start mode', 'Ports', 'PID'], array_column($sql_view['cards'], 'row'), static function (array $row) use ($esc): string {
    $ports = (string) ($row['listener_ports'] ?? '');
    $source = (string) ($row['port_source'] ?? '');
    return '<td>' . $esc($row['instance'] ?? '') . '</td><td>' . $esc($row['edition'] ?? 'Not reported') . '</td><td>' . $esc($row['patch_level'] ?? $row['version'] ?? 'Not reported') . '</td><td>' . $esc($row['state'] ?? 'Not reported') . '</td><td>' . $esc($row['start_mode'] ?? 'Not reported') . '</td><td>' . $esc($ports === '' ? ($source === '' ? 'Not reported' : $source) : $ports . ($source === '' ? '' : ' (' . $source . ')')) . '</td><td>' . $esc($row['pid'] ?? 'Not reported') . '</td>';
});
$sql_inventory .= $sql_component_details;
$sql_inventory .= '<p class="text-muted">LocalDB processes: ' . $esc($sql_view['summary']['localdb_processes'] ?? 'Not reported') . ' · Collected UTC: ' . $esc($sql_view['collected'] === '' ? 'Not reported' : $sql_view['collected']) . '</p>';
$sql_tab .= $render_disclosure('windows-agent-sql-inventory', 'Inventory and components', $sql_inventory, 'All instances · edition, endpoints, process and service evidence');
$sql_tab .= '</div>';

return $sql_tab;
