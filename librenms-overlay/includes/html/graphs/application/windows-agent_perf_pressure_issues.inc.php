<?php

$windows_agent_rrd_family = 'windows-agent-performance-depth';
$windows_agent_unit_text = 'Count';
$windows_agent_graph_datasets = [
    'issues' => ['descr' => 'issues', 'colour' => '222222'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
