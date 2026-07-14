<?php

$windows_agent_rrd_family = 'windows-agent-performance-depth';
$windows_agent_unit_text = '%';
$windows_agent_scale_max = 100;
$windows_agent_graph_datasets = [
    'mem_committed' => ['descr' => 'mem_commit', 'colour' => '5CB85C'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
