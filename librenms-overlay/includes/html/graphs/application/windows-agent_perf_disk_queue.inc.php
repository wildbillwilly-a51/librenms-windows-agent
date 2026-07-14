<?php

$windows_agent_rrd_family = 'windows-agent-performance-depth';
$windows_agent_unit_text = 'Queue';
$windows_agent_graph_datasets = [
    'disk_queue' => ['descr' => 'disk_queue', 'colour' => '7A43B6'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
