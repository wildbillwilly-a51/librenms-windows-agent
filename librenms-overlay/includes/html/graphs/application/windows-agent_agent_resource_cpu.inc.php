<?php

$windows_agent_rrd_family = 'windows-agent-resource-impact';
$windows_agent_unit_text = '%';
$windows_agent_scale_max = 100;
$windows_agent_graph_datasets = [
    'cpu_percent' => ['descr' => 'cpu_percent', 'colour' => 'D9534F'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
