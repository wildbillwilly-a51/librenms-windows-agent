<?php

require 'includes/html/graphs/common.inc.php';
$scale_min = 0;
$unit_text = 'Processes';
$unitlen = 10;
$bigdescrlen = 16;
$smalldescrlen = 16;
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 33;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'windows-agent-processes', $app->app_id]);

$array = [
    'total' => ['descr' => 'watched_total', 'colour' => '337AB7'],
    'not_running' => ['descr' => 'not_running', 'colour' => 'D9534F'],
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
