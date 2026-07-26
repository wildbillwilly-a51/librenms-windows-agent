<?php

$windows_agent_rrd_family = 'windows-agent-horizon-health';
$windows_agent_unit_text = 'State';
$windows_agent_graph_datasets = [
    'overall' => ['descr' => 'overall', 'colour' => '111827'],
    'platform' => ['descr' => 'platform', 'colour' => '337AB7'],
    'dependency' => ['descr' => 'dependency', 'colour' => '9467BD'],
    'capacity' => ['descr' => 'capacity', 'colour' => 'F0AD4E'],
    'collector' => ['descr' => 'collector', 'colour' => '5CB85C'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
