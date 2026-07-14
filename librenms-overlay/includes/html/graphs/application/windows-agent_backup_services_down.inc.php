<?php

$windows_agent_rrd_family = 'windows-agent-backup-storage';
$windows_agent_unit_text = 'Services';
$windows_agent_graph_datasets = [
    'services_not_running' => ['descr' => 'services_down', 'colour' => 'F0AD4E'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
