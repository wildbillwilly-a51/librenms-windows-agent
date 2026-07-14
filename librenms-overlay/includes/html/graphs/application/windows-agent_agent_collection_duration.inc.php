<?php

$windows_agent_rrd_family = 'windows-agent-performance';
$windows_agent_unit_text = 'ms';
$windows_agent_graph_datasets = [
    'duration_ms' => ['descr' => 'duration_ms', 'colour' => '337AB7'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
