<?php

$windows_agent_sql_unit = 'Seconds';
$windows_agent_sql_series = [
    ['ds' => 'ple_min', 'label' => 'PLE (instance min)', 'colour' => '337AB7'],
];
require __DIR__ . '/windows_agent_sql_performance_view.inc.php';
