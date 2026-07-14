<?php

$windows_agent_rrd_family = 'windows-agent-performance-depth';
$windows_agent_unit_text = 'Pages/s';
$windows_agent_graph_datasets = [
    'pages_sec' => ['descr' => 'pages_sec', 'colour' => 'F0AD4E'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
