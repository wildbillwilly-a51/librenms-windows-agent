<?php

$windows_agent_rrd_family = 'windows-agent-factorytalk-linx-connections';
$windows_agent_unit_text = 'Events/s';
$windows_agent_graph_datasets = [
    'accepted' => ['descr' => 'accepted', 'colour' => '337AB7'],
    'attempted' => ['descr' => 'attempted', 'colour' => '5CB85C'],
    'closed' => ['descr' => 'closed', 'colour' => 'F0AD4E'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
