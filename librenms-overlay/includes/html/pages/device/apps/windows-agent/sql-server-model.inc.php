<?php

require_once dirname(__DIR__, 5) . '/windows-agent/sql-evaluation.inc.php';

return \WindowsAgentOverlay\SqlEvaluation::evaluate(
    $sql_server_summary, $sql_server_instances, $sql_server_components,
    $sql_server_performance, $collector_timings,
    (string) ($data['last_agent_utc'] ?? ''), \WindowsAgentOverlay\SqlEvaluation::now(),
    is_array($data['sql_server_qualification'] ?? null) ? $data['sql_server_qualification'] : []
);
