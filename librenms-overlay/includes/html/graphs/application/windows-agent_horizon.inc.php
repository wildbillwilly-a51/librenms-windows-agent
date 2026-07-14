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

$rrd_filename = Rrd::name($device['hostname'], ['app', 'windows-agent-horizon', $app->app_id]);

$array = [
    'detected' => ['descr' => 'detected', 'colour' => '5CB85C'],
    'services_down' => ['descr' => 'services_down', 'colour' => 'D9534F'],
    'ports_listening' => ['descr' => 'ports_listening', 'colour' => '337AB7'],
    'cert_expired' => ['descr' => 'cert_expired', 'colour' => 'B22222'],
    'cert_expiring' => ['descr' => 'cert_expiring', 'colour' => 'F0AD4E'],
    'health_issues' => ['descr' => 'health_issues', 'colour' => '8A6D3B'],
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
