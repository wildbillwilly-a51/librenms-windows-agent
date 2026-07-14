<?php

require 'includes/html/graphs/common.inc.php';
$scale_min = 0;
$scale_max = 100;
$unit_text = '%';
$unitlen = 10;
$bigdescrlen = 16;
$smalldescrlen = 16;
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 33;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'windows-agent-vm-resources', $app->app_id]);

$array = [
    'cpu_load' => ['descr' => 'cpu_load', 'colour' => '337AB7'],
    'memory_used' => ['descr' => 'memory_used', 'colour' => '5CB85C'],
    'disk_used_max' => ['descr' => 'disk_used_max', 'colour' => 'F0AD4E'],
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
