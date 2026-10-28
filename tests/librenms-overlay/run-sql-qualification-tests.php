<?php

declare(strict_types=1);

use WindowsAgentOverlay\SqlEvaluation;

require_once dirname(__DIR__, 2) . '/librenms-overlay/includes/windows-agent/sql-evaluation.inc.php';
require_once __DIR__ . '/fixture-storage.inc.php';

$now = (int) strtotime('2026-10-28T12:00:00Z');
$summary = ['state' => 'ok', 'detected' => 1, 'instances_total' => 1];
$instance = ['instance' => 'MSSQLSERVER', 'state' => 'Running', 'start_mode' => 'Auto', 'agent_state' => 'Running', 'agent_expected' => 1, 'tcp_enabled' => 1, 'listener_active' => 1];
$performance = ['instance' => 'MSSQLSERVER', 'state' => 'ok', 'counters_read' => 38, 'counters_missing' => 0, 'processes_blocked' => 0, 'memory_grants_pending' => 0, 'page_life_expectancy' => 8000, 'pressure_issues' => 0, 'agent_failed_jobs' => 0, 'agent_jobs_delta_available' => 1, 'agent_jobs_reset' => 0];
$id = SqlEvaluation::instanceId('MSSQLSERVER');
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (! $condition) throw new RuntimeException($label);
    $checks++;
    echo "PASS $label\n";
};
$evaluate = static function (array $overrides = []) use ($summary, $instance, $performance, $now): array {
    return SqlEvaluation::evaluate(
        $overrides['summary'] ?? $summary, $overrides['instances'] ?? [$instance], [],
        $overrides['performance'] ?? [$performance], $overrides['timings'] ?? [],
        $overrides['collected'] ?? gmdate('c', $now - 60), $overrides['now'] ?? $now, $overrides['previous'] ?? []
    );
};

try {
    $healthy = $evaluate();
    $m = SqlEvaluation::metrics($healthy);
    $check($m['sql_q_jobs:' . $id] === 0.0 && $m['sql_q_readable:' . $id] === 1, 'Measured zero is distinct from unknown');
    $check($m['sql_q_expires'] === $now + 840, 'Absolute expiry follows source observation');
    $check($evaluate(['collected' => '2026-10-28T12:00:00.1234567Z'])['stamp'] === $now, 'Agent round-trip UTC timestamp with seven fractional digits is supported');
    foreach (['', 'nonsense', 'now', 'next week', '2026-02-30T12:00:00Z', gmdate('c', $now + 121), gmdate('c', $now - 900), gmdate('c', $now - 901)] as $badTime) {
        $bad = SqlEvaluation::metrics($evaluate(['collected' => $badTime]));
        $check($bad['sql_q_readable:' . $id] === 0 && $bad['sql_q_ple:' . $id] === -1.0, 'Invalid or expired timestamp suppresses values: ' . $badTime);
    }
    $future = $evaluate(['collected' => gmdate('c', $now + 60)]);
    $check($future['validUntil'] === $now + 900, 'Permitted future skew cannot extend first expiry');
    $repeated = $evaluate(['collected' => gmdate('c', $now + 60), 'now' => $now + 30, 'previous' => SqlEvaluation::mapping($future)]);
    $check($repeated['validUntil'] === $future['validUntil'], 'Repeated cached payload cannot extend accepted expiry');
    $expiredRepeat = $evaluate(['collected' => gmdate('c', $now + 60), 'now' => $now + 901, 'previous' => SqlEvaluation::mapping($future)]);
    $check(! $expiredRepeat['current'] && $expiredRepeat['state'] === 'unknown' && $expiredRepeat['gaps'] !== [], 'Accepted expiry remains expired in both UI and metrics after source clock catches up');
    foreach (['disabled', 'unsupported', 'error', 'unexpected'] as $state) {
        $m = SqlEvaluation::metrics($evaluate(['summary' => array_replace($summary, ['state' => $state])]));
        $check($m['sql_q_readable:' . $id] === 0, 'Summary ' . $state . ' cannot qualify cached counters');
    }
    $m = SqlEvaluation::metrics($evaluate(['timings' => [['collector' => 'sql_server', 'state' => 'timeout']]]));
    $check($m['sql_q_readable:' . $id] === 0, 'Collector timeout suppresses apparently good rows');
    $m = SqlEvaluation::metrics($evaluate(['performance' => [array_replace($performance, ['agent_failed_jobs' => 2, 'agent_jobs_reset' => 1])]]));
    $check($m['sql_q_jobs:' . $id] === -1.0 && $m['sql_q_readable:' . $id] === 1, 'Job reset invalidates jobs independently of engine counters');
    $m = SqlEvaluation::metrics($evaluate(['instances' => [array_replace($instance, ['state' => 'Stopped'])]]));
    $check($m['sql_q_readable:' . $id] === 0 && $m['sql_q_state:' . $id] === 3, 'Known engine outage remains visible with counters unavailable');
    $partial = array_replace($performance, ['processes_blocked' => 3, 'memory_grants_pending' => -1, 'blocking_pressure' => 1, 'state' => 'warning', 'counters_missing' => 1]);
    $m = SqlEvaluation::metrics($evaluate(['performance' => [$partial]]));
    $check($m['sql_q_blocked:' . $id] === 3.0 && $m['sql_q_grants:' . $id] === -1.0 && $m['sql_q_unknown'] > 0, 'Known bad evidence survives a separate coverage gap');
    $unreadable = array_replace($performance, ['state' => 'unavailable', 'counters_read' => 0]);
    $m = SqlEvaluation::metrics($evaluate(['performance' => [$unreadable]]));
    $check($m['sql_q_pressure:' . $id] === -1.0, 'Unreadable pressure is not a numeric all-clear');
    $invalidRow = SqlEvaluation::metrics($evaluate(['performance' => [array_replace($performance, ['state' => 'unexpected'])]]));
    $check($invalidRow['sql_q_readable:' . $id] === 0, 'Unrecognized performance states cannot qualify counters');
    $check($m['sql_q_jobs_valid:' . $id] === 1 && $m['sql_q_jobs:' . $id] === 0.0, 'Independently readable Agent job evidence is not hidden by unavailable engine counters');
    $m = SqlEvaluation::metrics($evaluate(['performance' => [array_replace($performance, ['page_life_expectancy' => INF])]]));
    $check($m['sql_q_ple:' . $id] === -1.0, 'Nonfinite counter is unavailable');
    $check(SqlEvaluation::instanceId('APP.ONE') !== SqlEvaluation::instanceId('APP_ONE'), 'New identities avoid legacy punctuation collision');
    $duplicate = $evaluate(['instances' => [$instance, array_replace($instance, ['instance' => 'mssqlserver'])]]);
    $m = SqlEvaluation::metrics($duplicate);
    $check($m['sql_q_readable:' . $id] === 0 && $m['sql_q_state:' . $id] === -2 && count($duplicate['gaps']) > 0, 'Ambiguous identity invalidates every card sharing it');
    $check(count(SqlEvaluation::mapping($duplicate)['instances'][$id]) === 2, 'Ambiguous name mapping retains both identities for diagnostics');
    $check(max(array_map('strlen', array_keys($m))) <= 64, 'Qualified metric names fit upstream 64-character schema');
    WindowsAgentFixtureStorage::$now = $now;
    $check(SqlEvaluation::now() === $now, 'Evaluation clock reads the current injected system time');
    WindowsAgentFixtureStorage::$now = $now + 600;
    $freshLater = SqlEvaluation::evaluate($summary, [$instance], [], [$performance], [], gmdate('c', $now + 600), SqlEvaluation::now());
    $check($freshLater['current'] && $freshLater['validUntil'] === $now + 1500, 'Long-lived process uses advancing time for a later device observation');
    echo "SQL qualification: $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
