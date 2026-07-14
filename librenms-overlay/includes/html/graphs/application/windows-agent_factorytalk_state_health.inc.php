<?php

$windows_agent_rrd_family = 'windows-agent-factorytalk';
$windows_agent_unit_text = 'Count';
$windows_agent_graph_datasets = [
    'detected' => ['descr' => 'detected', 'colour' => '5CB85C'],
    'services_down' => ['descr' => 'services_down', 'colour' => 'D9534F'],
    'core_down' => ['descr' => 'core_down', 'colour' => 'B94A48'],
    'health_issues' => ['descr' => 'health_issues', 'colour' => '8A6D3B'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
