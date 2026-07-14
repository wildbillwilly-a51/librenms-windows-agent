<?php

require 'includes/html/graphs/common.inc.php';
$scale_min = 0;
$unit_text = 'Value';
$unitlen = 10;
$bigdescrlen = 18;
$smalldescrlen = 18;
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 33;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'windows-agent-performance-depth', $app->app_id]);

$array = [
    'cpu_queue' => ['descr' => 'cpu_queue', 'colour' => '337AB7'],
    'mem_committed' => ['descr' => 'mem_commit', 'colour' => '5CB85C'],
    'pages_sec' => ['descr' => 'pages_sec', 'colour' => 'F0AD4E'],
    'disk_read_ms' => ['descr' => 'read_ms', 'colour' => 'D9534F'],
    'disk_write_ms' => ['descr' => 'write_ms', 'colour' => 'A94442'],
    'disk_queue' => ['descr' => 'disk_queue', 'colour' => '7A43B6'],
    'issues' => ['descr' => 'issues', 'colour' => '222222'],
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
