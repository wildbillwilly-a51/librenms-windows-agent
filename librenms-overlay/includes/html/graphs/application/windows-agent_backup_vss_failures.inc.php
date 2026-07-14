<?php

$windows_agent_rrd_family = 'windows-agent-backup-storage';
$windows_agent_unit_text = 'Writers';
$windows_agent_graph_datasets = [
    'vss_writers_failed' => ['descr' => 'vss_failed', 'colour' => 'D9534F'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
