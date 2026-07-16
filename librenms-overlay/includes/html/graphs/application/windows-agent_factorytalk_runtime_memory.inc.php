<?php

$windows_agent_rrd_family = 'windows-agent-factorytalk-runtime';
$windows_agent_unit_text = 'Bytes';
$windows_agent_graph_datasets = [
    'working_set' => ['descr' => 'working_set', 'colour' => '337AB7'],
    'private_bytes' => ['descr' => 'private_bytes', 'colour' => '5CB85C'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
