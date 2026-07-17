<?php

$windows_agent_rrd_family = 'windows-agent-horizon-platform';
$windows_agent_unit_text = 'Count';
$windows_agent_graph_datasets = [
    'members' => ['descr' => 'pod_members', 'colour' => '337AB7'],
    'members_bad' => ['descr' => 'members_unhealthy', 'colour' => 'D9534F'],
    'repl_bad' => ['descr' => 'config_replication_bad', 'colour' => '8A6D3B'],
    'domain_bad' => ['descr' => 'domain_access_bad', 'colour' => 'F0AD4E'],
    'gateways_bad' => ['descr' => 'gateways_unhealthy', 'colour' => '9C27B0'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
