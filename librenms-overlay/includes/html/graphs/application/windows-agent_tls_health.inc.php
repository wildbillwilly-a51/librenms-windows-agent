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

$rrd_filename = Rrd::name($device['hostname'], ['app', 'windows-agent-tls-health', $app->app_id]);

$array = [
    'unhealthy' => ['descr' => 'unhealthy', 'colour' => 'D9534F'],
    'invalid_chain' => ['descr' => 'invalid chain', 'colour' => 'B52B27'],
    'weak_key' => ['descr' => 'weak key', 'colour' => 'F0AD4E'],
    'missing_key' => ['descr' => 'missing key', 'colour' => '8A6D3B'],
    'binding_missing' => ['descr' => 'missing bind', 'colour' => '5BC0DE'],
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
