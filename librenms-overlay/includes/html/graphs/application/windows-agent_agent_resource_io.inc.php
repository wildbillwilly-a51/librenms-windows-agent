<?php

$windows_agent_rrd_family = 'windows-agent-resource-impact';
$windows_agent_unit_text = 'Bytes';
$windows_agent_bigdescrlen = 14;
$windows_agent_smalldescrlen = 14;
$windows_agent_graph_datasets = [
    'io_bytes' => ['descr' => 'io_bytes', 'colour' => '5CB85C'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
