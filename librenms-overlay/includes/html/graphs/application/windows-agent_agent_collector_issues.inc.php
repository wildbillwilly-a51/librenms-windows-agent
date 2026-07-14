<?php

$windows_agent_rrd_family = 'windows-agent-performance';
$windows_agent_unit_text = 'Count';
$windows_agent_graph_datasets = [
    'failed' => ['descr' => 'failed', 'colour' => 'D9534F'],
    'timed_out' => ['descr' => 'timed_out', 'colour' => 'F0AD4E'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
