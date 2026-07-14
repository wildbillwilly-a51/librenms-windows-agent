<?php

require 'includes/html/graphs/common.inc.php';
$scale_min = 0;
$unit_text = 'Certs';
$unitlen = 10;
$bigdescrlen = 18;
$smalldescrlen = 18;
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 33;

$rrd_filename = Rrd::name($device['hostname'], ['app', 'windows-agent-tls-certificates', $app->app_id]);

$array = [
    'total' => ['descr' => 'total', 'colour' => '337AB7'],
    'expired' => ['descr' => 'expired', 'colour' => 'D9534F'],
    'warning' => ['descr' => 'warning', 'colour' => 'F0AD4E'],
    'critical' => ['descr' => 'critical', 'colour' => 'B52B27'],
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
