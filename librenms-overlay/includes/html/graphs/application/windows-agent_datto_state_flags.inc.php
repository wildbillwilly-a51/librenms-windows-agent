<?php

$windows_agent_rrd_family = 'windows-agent-datto-backup';
$windows_agent_unit_text = 'State';
$windows_agent_scale_max = 1;
$windows_agent_graph_datasets = [
    'detected' => ['descr' => 'detected', 'colour' => '337AB7'],
    'service_running' => ['descr' => 'svc_running', 'colour' => '5CB85C'],
    'stale_warning' => ['descr' => 'stale_warn', 'colour' => 'F0AD4E'],
    'stale_critical' => ['descr' => 'stale_crit', 'colour' => 'D9534F'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
