<?php

$windows_agent_rrd_family = 'windows-agent-factorytalk-livedata';
$windows_agent_unit_text = 'Clients';
$windows_agent_graph_datasets = [
    'clients' => ['descr' => 'clients', 'colour' => '337AB7'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
