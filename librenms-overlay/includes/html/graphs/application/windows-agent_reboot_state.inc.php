<?php

require 'includes/html/graphs/common.inc.php';
$name = 'windows-agent';
$scale_min = 0;
$scale_max = 1;
$unit_text = 'State';
$unitlen = 10;
$bigdescrlen = 24;
$smalldescrlen = 24;
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 33;

$rrd_filename = Rrd::name($device['hostname'], ['app', $name, $app->app_id]);

$array = [
    'pending_reboot' => ['descr' => 'pending_reboot', 'colour' => 'D9534F'],
    'windows_update_reboot_required' => ['descr' => 'wu_reboot_required', 'colour' => 'F0AD4E'],
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
