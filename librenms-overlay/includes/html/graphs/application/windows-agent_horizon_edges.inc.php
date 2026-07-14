<?php

$windows_agent_rrd_family = 'windows-agent-horizon';
$windows_agent_unit_text = 'Count';
$windows_agent_graph_datasets = [
    'ports_listening' => ['descr' => 'ports_listening', 'colour' => '337AB7'],
    'cert_expired' => ['descr' => 'cert_expired', 'colour' => 'B22222'],
    'cert_expiring' => ['descr' => 'cert_expiring', 'colour' => 'F0AD4E'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
