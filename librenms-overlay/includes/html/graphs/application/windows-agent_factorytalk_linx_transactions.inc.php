<?php

$windows_agent_rrd_family = 'windows-agent-factorytalk-linx-transactions';
$windows_agent_unit_text = 'Count';
$windows_agent_graph_datasets = [
    'in_use' => ['descr' => 'in_use', 'colour' => 'F0AD4E'],
    'pool_size' => ['descr' => 'pool_size', 'colour' => '337AB7'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
