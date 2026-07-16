<?php

$windows_agent_rrd_family = 'windows-agent-factorytalk-linx-traffic';
$windows_agent_unit_text = 'Packets/s';
$windows_agent_graph_datasets = [
    'packets_recv' => ['descr' => 'received', 'colour' => '337AB7'],
    'packets_sent' => ['descr' => 'sent', 'colour' => '5CB85C'],
    'send_fail' => ['descr' => 'send_failures', 'colour' => 'D9534F'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
