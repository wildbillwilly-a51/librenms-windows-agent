<?php

$windows_agent_rrd_family = 'windows-agent-horizon-collector';
$windows_agent_unit_text = 'Value';
$windows_agent_graph_datasets = [
    'duration_ms' => ['descr' => 'duration_ms', 'colour' => '337AB7'],
    'requests' => ['descr' => 'requests', 'colour' => '5CB85C'],
    'pages' => ['descr' => 'pages', 'colour' => '9467BD'],
    'endpoints' => ['descr' => 'endpoints', 'colour' => 'F0AD4E'],
    'complete' => ['descr' => 'complete', 'colour' => '2CA02C'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
