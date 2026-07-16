<?php

$windows_agent_rrd_family = 'windows-agent-factorytalk-runtime';
$windows_agent_unit_text = 'Bytes/s';
$windows_agent_graph_datasets = [
    'io_read_bps' => ['descr' => 'read', 'colour' => '337AB7'],
    'io_write_bps' => ['descr' => 'write', 'colour' => '5CB85C'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
