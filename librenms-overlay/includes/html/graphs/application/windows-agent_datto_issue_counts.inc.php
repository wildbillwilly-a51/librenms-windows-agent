<?php

$windows_agent_rrd_family = 'windows-agent-datto-backup';
$windows_agent_unit_text = 'Count';
$windows_agent_graph_datasets = [
    'recent_errors' => ['descr' => 'errors', 'colour' => 'F0AD4E'],
    'critical_failures' => ['descr' => 'critical', 'colour' => 'D9534F'],
    'health_issues' => ['descr' => 'issues', 'colour' => 'A94442'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
