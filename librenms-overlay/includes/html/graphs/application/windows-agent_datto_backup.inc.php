<?php

require 'includes/html/graphs/common.inc.php';
$scale_min = 0;
$unit_text = 'Count';
$unitlen = 10;
$bigdescrlen = 18;
$smalldescrlen = 18;
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 33;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'windows-agent-datto-backup', $app->app_id]);

$array = [
    'detected' => ['descr' => 'detected', 'colour' => '337AB7'],
    'service_running' => ['descr' => 'svc_running', 'colour' => '5CB85C'],
    'recent_errors' => ['descr' => 'errors', 'colour' => 'F0AD4E'],
    'critical_failures' => ['descr' => 'critical', 'colour' => 'D9534F'],
    'stale_warning' => ['descr' => 'stale_warn', 'colour' => 'F0AD4E'],
    'stale_critical' => ['descr' => 'stale_crit', 'colour' => 'D9534F'],
    'health_issues' => ['descr' => 'issues', 'colour' => 'A94442'],
];

$i = 0;
foreach ($array as $ds => $var) {
    $rrd_list[$i]['filename'] = $rrd_filename;
    $rrd_list[$i]['descr'] = $var['descr'];
    $rrd_list[$i]['ds'] = $ds;
    $rrd_list[$i]['colour'] = $var['colour'];
    $i++;
}

require 'includes/html/graphs/generic_v3_multiline.inc.php';
