<?php

$windows_agent_sql_unit = 'Operations/sec';
$windows_agent_sql_series = [
    ['ds' => 'batches', 'label' => 'Batches (readable sum)', 'colour' => '1ABC9C'],
];
require __DIR__ . '/windows_agent_sql_performance_view.inc.php';
