<?php

require 'includes/html/graphs/common.inc.php';
$scale_min = 0;
$unit_text = 'Count';
$unitlen = 10;
$bigdescrlen = 16;
$smalldescrlen = 16;
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 33;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'windows-agent-performance', $app->app_id]);

$array = [
    'duration_ms' => ['descr' => 'duration_ms', 'colour' => '337AB7'],
    'payload_bytes' => ['descr' => 'payload_bytes', 'colour' => '5CB85C'],
    'failed' => ['descr' => 'failed', 'colour' => 'D9534F'],
    'timed_out' => ['descr' => 'timed_out', 'colour' => 'F0AD4E'],
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
