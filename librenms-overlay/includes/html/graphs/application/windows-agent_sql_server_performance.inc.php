<?php

$windows_agent_rrd_family = 'windows-agent-sql-server-performance';
$windows_agent_unit_text = 'Value';
$windows_agent_graph_datasets = [
    'instances' => ['descr' => 'instances', 'colour' => '337AB7'],
    'counters_ok' => ['descr' => 'counters_ok', 'colour' => '5CB85C'],
    'blocked' => ['descr' => 'processes_blocked', 'colour' => 'D9534F'],
    'grants' => ['descr' => 'grants_pending', 'colour' => 'C9302C'],
    'ple_min' => ['descr' => 'page_life_min', 'colour' => '5BC0DE'],
    'mem_percent' => ['descr' => 'memory_vs_target_pct', 'colour' => '8A6D3B'],
    'connections' => ['descr' => 'user_connections', 'colour' => '9B59B6'],
    'batches' => ['descr' => 'batch_requests_sec', 'colour' => '1ABC9C'],
    'compiles' => ['descr' => 'compilations_sec', 'colour' => 'F0AD4E'],
    'recompiles' => ['descr' => 'recompilations_sec', 'colour' => 'EC971F'],
    'deadlocks' => ['descr' => 'deadlocks_sec', 'colour' => '777777'],
    'lock_waits' => ['descr' => 'lock_waits_sec', 'colour' => 'B52B27'],
    'jobs_failed' => ['descr' => 'agent_jobs_failed', 'colour' => 'E67E22'],
    'pressure' => ['descr' => 'pressure_issues', 'colour' => '34495E'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
