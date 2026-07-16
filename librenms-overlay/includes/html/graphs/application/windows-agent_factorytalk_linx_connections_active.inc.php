<?php

$windows_agent_rrd_family = 'windows-agent-factorytalk-linx-connections';
$windows_agent_unit_text = 'Count';
$windows_agent_graph_datasets = [
    'active_in' => ['descr' => 'incoming', 'colour' => '337AB7'],
    'active_out' => ['descr' => 'outgoing', 'colour' => '5CB85C'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
