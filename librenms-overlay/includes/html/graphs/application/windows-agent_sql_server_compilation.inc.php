<?php

$windows_agent_sql_unit = 'Operations/sec';
$windows_agent_sql_series = [
    ['ds' => 'compiles', 'label' => 'Compilations (sum)', 'colour' => 'F0AD4E'],
    ['ds' => 'recompiles', 'label' => 'Recompilations (sum)', 'colour' => '9B59B6'],
];
require __DIR__ . '/windows_agent_sql_performance_view.inc.php';
