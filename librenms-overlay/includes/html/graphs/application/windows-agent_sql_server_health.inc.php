<?php

$windows_agent_rrd_family = 'windows-agent-sql-server-health';
$windows_agent_unit_text = 'Count';
$windows_agent_graph_datasets = [
    'health_issues' => ['descr' => 'health_issues', 'colour' => 'D9534F'],
    'engines_down' => ['descr' => 'engines_down', 'colour' => 'C9302C'],
    'agents_total' => ['descr' => 'agents_total', 'colour' => '337AB7'],
    'agents_down' => ['descr' => 'agents_down', 'colour' => 'F0AD4E'],
    'agents_off' => ['descr' => 'agents_not_expected', 'colour' => '8A6D3B'],
    'components' => ['descr' => 'components_total', 'colour' => '5BC0DE'],
    'components_down' => ['descr' => 'components_down', 'colour' => 'EC971F'],
    'no_listener' => ['descr' => 'no_listener', 'colour' => '9B59B6'],
    'listener_ports' => ['descr' => 'listener_ports', 'colour' => '5CB85C'],
    'unmapped_engines' => ['descr' => 'unmapped_engines', 'colour' => '777777'],
];

require 'includes/html/graphs/application/windows_agent_windows_agent_graph_common.inc.php';
