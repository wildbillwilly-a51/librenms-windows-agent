<?php

$windows_agent_sql_unit = 'Percent of target';
$windows_agent_sql_series = [
    ['ds' => 'mem_percent', 'label' => 'Memory/target (max)', 'colour' => '8A6D3B'],
];
require __DIR__ . '/windows_agent_sql_performance_view.inc.php';
