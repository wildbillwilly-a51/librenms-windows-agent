<?php

$windows_agent_rrd_family = 'windows-agent-performance-depth';
$windows_agent_unit_text = 'Queue';
$windows_agent_graph_datasets = [
    'cpu_queue' => ['descr' => 'cpu_queue', 'colour' => '337AB7'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
