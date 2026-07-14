<?php

$windows_agent_rrd_family = 'windows-agent-performance-depth';
$windows_agent_unit_text = 'ms';
$windows_agent_graph_datasets = [
    'disk_read_ms' => ['descr' => 'read_ms', 'colour' => 'D9534F'],
    'disk_write_ms' => ['descr' => 'write_ms', 'colour' => 'A94442'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
