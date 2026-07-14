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

$rrd_filename = Rrd::name($device['hostname'], ['app', 'windows-agent-backup-storage', $app->app_id]);

$array = [
    'vss_writers_total' => ['descr' => 'vss_total', 'colour' => '337AB7'],
    'vss_writers_failed' => ['descr' => 'vss_failed', 'colour' => 'D9534F'],
    'services_total' => ['descr' => 'services_total', 'colour' => '5CB85C'],
    'services_not_running' => ['descr' => 'services_down', 'colour' => 'F0AD4E'],
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
