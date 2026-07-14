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

$rrd_filename = Rrd::name($device['hostname'], ['app', 'windows-agent-iis', $app->app_id]);

$array = [
    'sites_total' => ['descr' => 'sites_total', 'colour' => '337AB7'],
    'sites_stopped' => ['descr' => 'sites_stopped', 'colour' => 'D9534F'],
    'app_pools_total' => ['descr' => 'app_pools_total', 'colour' => '5CB85C'],
    'app_pools_stopped' => ['descr' => 'app_pools_stopped', 'colour' => 'F0AD4E'],
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
