<?php

require 'includes/html/graphs/common.inc.php';
$name = 'windows-agent';
$scale_min = 0;
$scale_max = 1;
$unit_text = 'Reachable';
$unitlen = 10;
$bigdescrlen = 16;
$smalldescrlen = 16;
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 33;

$rrd_filename = Rrd::name($device['hostname'], ['app', $name, $app->app_id]);

$array = [
    'agent_up' => ['descr' => 'agent_up', 'colour' => '2B9220'],
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
