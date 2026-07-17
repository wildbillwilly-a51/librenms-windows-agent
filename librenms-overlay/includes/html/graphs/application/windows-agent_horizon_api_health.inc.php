<?php

$windows_agent_rrd_family = 'windows-agent-horizon-api';
$windows_agent_unit_text = 'Count';
$windows_agent_graph_datasets = [
    'available' => ['descr' => 'api_available', 'colour' => '5CB85C'],
    'cs_unhealthy' => ['descr' => 'servers_unhealthy', 'colour' => 'D9534F'],
    'services_bad' => ['descr' => 'services_unhealthy', 'colour' => 'F0AD4E'],
    'repl_bad' => ['descr' => 'replication_unhealthy', 'colour' => '8A6D3B'],
    'cert_invalid' => ['descr' => 'certificates_invalid', 'colour' => '9C27B0'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
