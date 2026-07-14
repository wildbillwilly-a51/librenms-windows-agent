<?php

$windows_agent_rrd_family = 'windows-agent-performance';
$windows_agent_unit_text = 'Bytes';
$windows_agent_bigdescrlen = 14;
$windows_agent_smalldescrlen = 14;
$windows_agent_graph_datasets = [
    'payload_bytes' => ['descr' => 'payload_bytes', 'colour' => '5CB85C'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
