<?php

$windows_agent_sql_unit = 'Count';
$windows_agent_sql_series = [
    ['ds' => 'blocked', 'label' => 'Blocked (instance max)', 'colour' => 'D9534F'],
    ['ds' => 'grants', 'label' => 'Grants (instance max)', 'colour' => '337AB7'],
];
require __DIR__ . '/windows_agent_sql_performance_view.inc.php';
