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

$rrd_filename = Rrd::name($device['hostname'], ['app', 'windows-agent-ad-dc-health', $app->app_id]);

$array = [
    'core_down' => ['descr' => 'core_down', 'colour' => 'D9534F'],
    'dns_issue' => ['descr' => 'dns_issue', 'colour' => 'F0AD4E'],
    'shares_missing' => ['descr' => 'shares_missing', 'colour' => 'A94442'],
    'time_issues' => ['descr' => 'time_issues', 'colour' => '337AB7'],
    'health_issues' => ['descr' => 'issues', 'colour' => '5CB85C'],
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
