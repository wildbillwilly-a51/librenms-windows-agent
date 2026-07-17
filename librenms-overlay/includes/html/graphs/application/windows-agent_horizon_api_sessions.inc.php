<?php

$windows_agent_rrd_family = 'windows-agent-horizon-api';
$windows_agent_unit_text = 'Sessions';
$windows_agent_graph_datasets = [
    'sessions' => ['descr' => 'total', 'colour' => '337AB7'],
    'connected' => ['descr' => 'connected', 'colour' => '5CB85C'],
    'disconnected' => ['descr' => 'disconnected', 'colour' => 'F0AD4E'],
    'other' => ['descr' => 'other', 'colour' => '777777'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
