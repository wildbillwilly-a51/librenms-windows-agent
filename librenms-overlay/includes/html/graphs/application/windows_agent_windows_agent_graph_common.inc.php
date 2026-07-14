<?php

require 'includes/html/graphs/common.inc.php';

$scale_min = $windows_agent_scale_min ?? 0;
if (isset($windows_agent_scale_max)) {
    $scale_max = $windows_agent_scale_max;
}

$unit_text = $windows_agent_unit_text ?? 'Value';
$unitlen = $windows_agent_unitlen ?? 10;
$bigdescrlen = $windows_agent_bigdescrlen ?? 18;
$smalldescrlen = $windows_agent_smalldescrlen ?? 18;
$dostack = 0;
$printtotal = 0;
$addarea = 1;
$transparency = 33;

$rrd_filename = Rrd::name($device['hostname'], ['app', $windows_agent_rrd_family, $app->app_id]);

$rrd_list = [];
$i = 0;
foreach ($windows_agent_graph_datasets as $ds => $var) {
    $rrd_list[$i]['filename'] = $rrd_filename;
    $rrd_list[$i]['descr'] = $var['descr'];
    $rrd_list[$i]['ds'] = $ds;
    $rrd_list[$i]['colour'] = $var['colour'];
    $i++;
}

require 'includes/html/graphs/generic_v3_multiline.inc.php';
