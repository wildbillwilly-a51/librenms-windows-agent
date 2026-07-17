<?php

$windows_agent_rrd_family = 'windows-agent-horizon-platform';
$windows_agent_unit_text = 'Count';
$windows_agent_graph_datasets = [
    'pools' => ['descr' => 'clone_pools', 'colour' => '337AB7'],
    'pools_warn' => ['descr' => 'pools_warning', 'colour' => 'F0AD4E'],
    'pools_crit' => ['descr' => 'pools_critical', 'colour' => 'D9534F'],
    'spare_ready' => ['descr' => 'spares_ready', 'colour' => '5CB85C'],
    'spare_unready' => ['descr' => 'spares_unready', 'colour' => '8A6D3B'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
