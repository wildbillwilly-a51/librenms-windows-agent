<?php

$windows_agent_rrd_family = 'windows-agent-factorytalk-runtime';
$windows_agent_unit_text = 'Count';
$windows_agent_graph_datasets = [
    'processes' => ['descr' => 'processes', 'colour' => '337AB7'],
    'handles' => ['descr' => 'handles', 'colour' => '5CB85C'],
    'threads' => ['descr' => 'threads', 'colour' => 'F0AD4E'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
