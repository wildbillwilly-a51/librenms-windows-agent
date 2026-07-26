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

$apiRrd = Rrd::name($device['hostname'], ['app', 'windows-agent-horizon-api', $app->app_id]);
$platformRrd = Rrd::name($device['hostname'], ['app', 'windows-agent-horizon-platform', $app->app_id]);
$rrd_list = [
    ['filename' => $apiRrd, 'descr' => 'connected_sessions', 'ds' => 'connected', 'colour' => '337AB7'],
    ['filename' => $apiRrd, 'descr' => 'disconnected', 'ds' => 'disconnected', 'colour' => '9467BD'],
    ['filename' => $platformRrd, 'descr' => 'ready_capacity', 'ds' => 'spare_ready', 'colour' => '5CB85C'],
    ['filename' => $platformRrd, 'descr' => 'unavailable', 'ds' => 'spare_unready', 'colour' => 'F0AD4E'],
];

require 'includes/html/graphs/generic_v3_multiline.inc.php';
