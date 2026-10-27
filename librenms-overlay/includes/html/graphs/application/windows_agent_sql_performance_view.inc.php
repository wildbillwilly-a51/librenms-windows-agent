<?php

use App\Facades\Rrd;

require 'includes/html/graphs/common.inc.php';

// Read the existing family. Never change its DS or turn missing evidence into zero.
$scale_min = 0;
$unit_text = $windows_agent_sql_unit;
$rrd_filename = Rrd::name($device['hostname'], ['app', 'windows-agent-sql-server-performance', $app->app_id]);
$rrd_options[] = 'COMMENT:' . str_repeat(' ', 28) . sprintf('%10s%10s%10s%10s', 'Now', 'Min', 'Max', 'Avg') . '\l';
foreach (array_values($windows_agent_sql_series) as $index => $series) {
    $base = 'sql' . $index;
    foreach (['avg' => 'AVERAGE', 'min' => 'MIN', 'max' => 'MAX'] as $suffix => $cf) {
        $rrd_options[] = 'DEF:' . $base . $suffix . '=' . $rrd_filename . ':' . $series['ds'] . ':' . $cf;
    }
    foreach (['avg', 'min', 'max'] as $suffix) {
        // A consolidated bin containing the -1 sentinel is incomplete too. Mask
        // its average/max instead of plotting a biased positive partial average.
        $rrd_options[] = 'CDEF:' . $base . 'valid' . $suffix . '=' . $base . 'min,0,LT,UNKN,' . $base . $suffix . ',0,LT,UNKN,' . $base . $suffix . ',IF,IF';
    }
    $description = Rrd::safeDescr(str_pad($series['label'], 23));
    $rrd_options[] = 'LINE2:' . $base . 'validavg#' . $series['colour'] . ':' . $description;
    $rrd_options[] = 'GPRINT:' . $base . 'validavg:LAST:%10.1lf';
    $rrd_options[] = 'GPRINT:' . $base . 'validmin:MIN:%10.1lf';
    $rrd_options[] = 'GPRINT:' . $base . 'validmax:MAX:%10.1lf';
    $rrd_options[] = 'GPRINT:' . $base . 'validavg:AVERAGE:%10.1lf\l';
}
