<?php

namespace WindowsAgentOverlay;

// Shared observation qualification. No SQL queries, event history or new thresholds.
final class SqlEvaluation
{
    public static function now(): int
    {
        return time(); // Read at evaluation time, including long-lived poller processes.
    }

    public static function instanceId(string $name): string
    {
        return substr(hash('sha256', strtolower($name)), 0, 32);
    }

    private static function timestamp(string $value): int|false
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return false;
        try {
            $date = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            return $errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0) ? false : $date->getTimestamp();
        } catch (\Exception) {
            return false;
        }
    }

    public static function evaluate(array $summary, array $instances, array $components, array $performance, array $timings, string $collected, int $now, array $previous = []): array
    {
        $number = static fn (array $row, string $key): ?float => isset($row[$key]) && is_numeric($row[$key]) && is_finite((float) $row[$key]) && (float) $row[$key] >= 0 ? (float) $row[$key] : null;
        $running = static fn ($state): bool => strtolower((string) $state) === 'running';
        $failure = '';
        foreach ($timings as $timing) {
            if (($timing['collector'] ?? '') === 'sql_server' && ! in_array(strtolower((string) ($timing['state'] ?? 'unknown')), ['ok', 'disabled', 'not_detected', 'not_applicable'], true)) {
                $failure = (string) ($timing['state'] ?? 'unknown');
            }
        }
        $rawState = strtolower((string) ($summary['state'] ?? 'not_detected'));
        if ($failure === '' && in_array($rawState, ['error', 'failed', 'timeout', 'timed_out'], true)) {
            $failure = $rawState;
        }
        $detected = (int) ($summary['detected'] ?? 0) === 1 || ! empty($instances) || (int) ($summary['instances_total'] ?? 0) > 0;
        $issues = [];
        $add = static function (string $instance, string $title, string $detail, string $action, string $severity = 'warning') use (&$issues): void {
            $severity = $severity === 'critical' ? 'critical' : 'warning';
            $issues[] = compact('instance', 'title', 'detail', 'action', 'severity');
        };
        $gaps = [];
        if ($failure !== '') {
            $gaps[] = 'SQL collection failed (' . $failure . '); state not evaluated.';
        } elseif (in_array($rawState, ['disabled', 'unsupported', 'error', 'failed', 'unknown'], true)) {
            $gaps[] = 'SQL collection: ' . str_replace('_', ' ', $rawState) . '.';
        }

        $stamp = self::timestamp($collected);
        $age = $stamp === false ? null : $now - $stamp;
        $freshness = $age === null ? 'Time not reported' : ($age < -120 ? 'Agent clock ahead' : ($age < 60 ? 'Just now' : ($age < 3600 ? intdiv($age, 60) . 'm ago' : ($age < 86400 ? intdiv($age, 3600) . 'h ago' : intdiv($age, 86400) . 'd ago'))));
        // Conservative UI freshness limit; the payload does not report the poll interval.
        if ($detected && ($age === null || $age >= 900 || $age < -120)) {
            $gaps[] = $age === null ? 'Collection time is unknown.' : ($age < -120 ? 'Agent clock is ahead; freshness cannot be established.' : 'Evidence is stale (older than 15 minutes). Values are last observed.');
        }

        $validUntil = $stamp === false || $age < -120 ? 0 : min($stamp, $now) + 900;
        if ($collected !== '' && ($previous['observed_utc'] ?? null) === $collected && isset($previous['valid_until_epoch']) && is_numeric($previous['valid_until_epoch'])) {
            $validUntil = min($validUntil, max(0, (int) $previous['valid_until_epoch']));
        }
        if ($detected && $age !== null && $age >= -120 && $age < 900 && $validUntil <= $now) {
            $gaps[] = 'Accepted payload validity expired or is unknown; values are last observed. Awaiting a new observation.';
        }
        $current = $validUntil > $now && $failure === '' && in_array($rawState, ['ok', 'partial', 'warning', 'critical', 'info', 'not_detected'], true);
        $perfByName = [];
        foreach ($performance as $row) {
            $perfByName[strtolower((string) ($row['instance'] ?? ''))] = $row;
        }
        $cards = [];
        $runningCount = 0;
        $counterNames = [];
        $listenerKnown = $listenerActive = $listenerUnknown = $tcpDisabled = 0;
        $agentExpected = $agentDown = $agentRunning = $agentUnknown = 0;
        $jobKnown = 0;
        $jobFailures = 0;
        $representedHealth = $listenerIssues = 0;
        $measures = ['processes_blocked' => [], 'memory_grants_pending' => []];
        foreach ($instances as $row) {
            $name = (string) ($row['instance'] ?? 'Unknown instance');
            $start = count($issues);
            $isRunning = $running($row['state'] ?? '');
            $runningCount += $isRunning ? 1 : 0;
            $engineExpected = array_key_exists('expected', $row) ? (int) $row['expected'] === 1 : strtolower((string) ($row['start_mode'] ?? '')) === 'auto';
            if (in_array(strtolower((string) ($row['severity'] ?? '')), ['warning', 'critical'], true) || (! $isRunning && $engineExpected)) {
                $add($name, 'Engine service is not healthy', 'State: ' . ($row['state'] ?? 'unknown') . '; startup: ' . ($row['start_mode'] ?? 'not reported') . '.', 'Check the engine service and its local dependencies.', (string) ($row['severity'] ?? 'critical'));
                $representedHealth++;
            }

            $listener = 'Not reported';
            if ($isRunning) {
                if (array_key_exists('tcp_enabled', $row) && (int) $row['tcp_enabled'] === 0) {
                    $listener = 'Not applicable — TCP disabled';
                    $tcpDisabled++;
                } elseif (array_key_exists('tcp_enabled', $row) && array_key_exists('listener_active', $row)) {
                    $listenerKnown++;
                    $active = (int) $row['listener_active'] === 1;
                    $listenerActive += $active ? 1 : 0;
                    $listener = $active ? 'Listening locally' : 'No active listener';
                    if (! $active) {
                        $add($name, 'No active listener', 'TCP is enabled, but no configured port is listening.', 'Check the instance TCP configuration and local listener.');
                        $listenerIssues++;
                    }
                } else {
                    $listenerUnknown++;
                }
            } else {
                $listener = 'Not checked — engine not running';
            }

            $express = str_contains(strtolower((string) ($row['edition'] ?? '')), 'express');
            $agentState = (string) ($row['agent_state'] ?? 'Not reported');
            $agentIsRunning = $running($agentState);
            $agentRunning += $agentIsRunning ? 1 : 0;
            $expectationKnown = array_key_exists('agent_expected', $row);
            $expected = $expectationKnown && (int) $row['agent_expected'] === 1;
            $agentExpected += $expected ? 1 : 0;
            $agentUnknown += $expectationKnown || $express ? 0 : 1;
            $agentText = $express && ! $agentIsRunning ? 'Not available in Express' : $agentState;
            if (! $agentIsRunning && ! $express && $expectationKnown && ! $expected) {
                $agentText .= ' (' . strtolower((string) ($row['agent_start_mode'] ?? 'not expected running')) . ')';
            }
            if (! $expectationKnown && ! $express) {
                $agentText .= ' · expectation not reported';
                $gaps[] = $name . ': SQL Agent expectedness is not reported.';
            }
            if ($isRunning && (in_array(strtolower((string) ($row['agent_severity'] ?? '')), ['warning', 'critical'], true) || ($expected && ! $agentIsRunning))) {
                $add($name, 'SQL Server Agent service is stopped', 'Scheduled work on this instance may not run; ' . ($row['agent_reason'] ?? 'Agent is expected to run') . '.', 'Review the Agent service and job history before restarting it.', (string) ($row['agent_severity'] ?? 'warning'));
                $agentDown++;
                $representedHealth++;
            }

            $perf = $perfByName[strtolower($name)] ?? [];
            $readable = $isRunning && ! empty($perf) && in_array(strtolower((string) ($perf['state'] ?? 'unavailable')), ['ok', 'info', 'informational', 'warning', 'critical'], true) && ($number($perf, 'counters_read') ?? 0) > 0;
            if ($readable) {
                $counterNames[] = $name;
                foreach ($measures as $key => &$values) {
                    $value = $number($perf, $key);
                    if ($value !== null) {
                        $values[$name] = $value;
                    }
                }
                unset($values);
                if (($number($perf, 'counters_missing') ?? 0) > 0 || $number($perf, 'processes_blocked') === null || $number($perf, 'memory_grants_pending') === null) {
                    $gaps[] = $name . ': some performance counters are not measured.';
                }
            } elseif ($isRunning) {
                $gaps[] = $name . ': performance counters are not measured.';
            }
            $flags = [
                'blocking_pressure' => ['Blocked processes at last sample', 'Investigate the blocking chain on this instance.'],
                'memory_pressure' => ['Pending memory grants at last sample', 'Review workspace-memory demand and OS memory context.'],
                'buffer_pressure' => ['Low page life expectancy reported', 'Review the PLE trend and workload against the configured agent threshold.'],
                'compile_pressure' => ['Compilation pressure reported', 'Compare compilation rates with batch workload on this instance.'],
                'deadlock_pressure' => ['Deadlock observed in the sample window', 'Review retained deadlock evidence on the SQL instance.'],
            ];
            $reportedFlags = 0;
            if ($readable) {
                foreach ($flags as $key => [$title, $action]) {
                    if ((int) ($perf[$key] ?? 0) === 1) {
                        $add($name, $title, 'Condition reported by the agent; sampled evidence does not establish duration.', $action);
                        $reportedFlags++;
                    }
                }
                if ((int) ($perf['pressure_issues'] ?? 0) > $reportedFlags || ($reportedFlags === 0 && strtolower((string) ($perf['state'] ?? '')) === 'warning')) {
                    $add($name, 'Additional counter pressure reported', 'The agent reported a condition without a matching reason flag.', 'Review the instance performance evidence.');
                }
            }
            $jobValue = $number($perf, 'agent_failed_jobs');
            $jobsAvailable = $isRunning && $agentIsRunning && (int) ($perf['agent_jobs_delta_available'] ?? 0) === 1 && (int) ($perf['agent_jobs_reset'] ?? 0) === 0 && $jobValue !== null;
            if ($isRunning && $agentIsRunning && ! $jobsAvailable) {
                $gaps[] = $name . ': sampled job counters are unavailable or awaiting a reset baseline.';
            }
            $jobText = ! $agentIsRunning ? 'Not measured — Agent not running' : ((int) ($perf['agent_jobs_reset'] ?? 0) === 1 ? 'Baseline reset — awaiting next sample' : 'Not measured — counter unavailable or awaiting baseline');
            if ($jobsAvailable) {
                $jobKnown++;
                $jobFailures += $jobValue;
                $jobText = (string) (int) $jobValue . ' observed failures between the latest two counter collections';
                if ($jobValue > 0) {
                    $add($name, 'SQL Server Agent job failure(s) observed', $jobText . '; sampled deltas can miss events between polls.', 'Review the actual SQL Server Agent job history.');
                }
            }
            $cardIssues = array_slice($issues, $start);
            $cardState = ! empty($cardIssues) ? (in_array('critical', array_column($cardIssues, 'severity'), true) ? 'critical' : 'warning') : ((! $current || ($isRunning && (! $readable || $listener === 'Not reported' || ($agentIsRunning && ! $jobsAvailable) || (! $expectationKnown && ! $express)))) ? 'unknown' : 'ok');
            $id = self::instanceId($name);
            $ambiguous = false;
            $qualified = $current && $readable;
            $jobsQualified = $current && $jobsAvailable;
            $cards[] = compact('id', 'ambiguous', 'qualified', 'jobsQualified', 'jobsAvailable', 'name', 'row', 'perf', 'readable', 'listener', 'agentText', 'jobText', 'cardIssues', 'cardState');
        }

        foreach ($components as $row) {
            if (in_array(strtolower((string) ($row['severity'] ?? '')), ['warning', 'critical'], true)) {
                $add((string) ($row['instance'] ?? 'Shared component'), 'SQL Server component service is stopped: ' . ($row['name'] ?? 'unknown'), 'Component: ' . ($row['component'] ?? 'unknown') . '; ' . ($row['reason'] ?? 'expected service not running') . '.', 'Review this component and its dependencies.', (string) $row['severity']);
                $representedHealth++;
            }
        }
        if (in_array(strtolower((string) ($summary['browser_severity'] ?? '')), ['warning', 'critical'], true)) {
            $add('SQL Browser', 'Required Browser service needs attention', (string) ($summary['browser_reason'] ?? 'Instance resolution may be affected.'), 'Review named-instance discovery and Browser service state.', (string) $summary['browser_severity']);
            $representedHealth++;
        }
        foreach (['health_issues' => $representedHealth, 'instances_without_listener' => $listenerIssues] as $key => $represented) {
            $remaining = (int) ($summary[$key] ?? ($key === 'health_issues' ? ($summary['instances_not_running'] ?? 0) : 0)) - $represented;
            if ($remaining > 0) {
                $add('SQL Server', $remaining . ' additional ' . ($key === 'health_issues' ? 'service condition(s)' : 'listener condition(s)') . ' reported', 'Instance details do not account for all reported summary conditions.', 'Review agent collection and the full service inventory.');
            }
        }
        if ((int) ($summary['unmapped_engine_processes'] ?? 0) > 0) {
            $add('SQL Server', 'Unmapped sqlservr.exe process(es)', (string) $summary['unmapped_engine_processes'] . ' engine process(es) are not owned by a detected SQL service; classified LocalDB processes are excluded.', 'Investigate the unowned engine process(es).');
        }
        $total = max(count($instances), (int) ($summary['instances_total'] ?? 0));
        if (count($instances) < $total) {
            $runningCount = max($runningCount, (int) ($summary['instances_running'] ?? 0));
        }
        if (($detected && (count($instances) < $total || $instances === [])) || $listenerUnknown > 0) {
            $gaps[] = 'Some instance or local-listener details are not reported.';
        }
        $identityCounts = array_count_values(array_column($cards, 'id'));
        foreach ($cards as &$card) {
            if ($identityCounts[$card['id']] > 1) {
                $card['ambiguous'] = true;
                $card['qualified'] = false;
                $card['readable'] = false;
                $card['jobsAvailable'] = false;
                $card['jobsQualified'] = false;
                $card['cardState'] = 'unknown';
                $gaps[] = 'Instance identity is ambiguous: ' . $card['name'] . '. Qualified metrics are unavailable.';
            }
        }
        unset($card);
        usort($cards, static function (array $a, array $b): int {
            $rank = ['critical' => 3, 'warning' => 2, 'unknown' => 1, 'ok' => 0];
            return ($rank[$b['cardState']] <=> $rank[$a['cardState']]) ?: strcasecmp($a['name'], $b['name']);
        });
        usort($issues, static fn (array $a, array $b): int => (($b['severity'] === 'critical' ? 1 : 0) <=> ($a['severity'] === 'critical' ? 1 : 0)) ?: strcasecmp($a['instance'], $b['instance']));
        $gaps = array_values(array_unique($gaps));
        $state = ! empty($issues) ? (in_array('critical', array_column($issues, 'severity'), true) ? 'critical' : 'warning') : (! empty($gaps) ? 'unknown' : ($detected ? 'ok' : $rawState));
        if (! $detected && $failure !== '') {
            $state = 'unknown';
        } elseif (! $detected && $issues === []) {
            $state = $rawState;
        }
        $verdict = count($issues) > 0 ? count($issues) . ' SQL condition(s) need attention.' : ($gaps === [] ? 'No issues observed in service, listener and readable counter checks.' : 'SQL Server is partially checked; some evidence is unavailable.');
        if (($age !== null && $age >= 900) || ($validUntil > 0 && $validUntil <= $now)) {
            $verdict = 'Last observed: ' . (count($issues) > 0 ? count($issues) . ' SQL condition(s). ' : 'no issues in measured checks. ') . 'Evidence is stale.';
        } elseif (! empty($gaps) && ! empty($issues)) {
            $verdict .= ' Evidence is incomplete.';
        }
        return compact('current', 'validUntil', 'stamp', 'detected', 'state', 'verdict', 'issues', 'gaps', 'failure', 'freshness', 'collected', 'cards', 'total', 'runningCount', 'counterNames', 'listenerKnown', 'listenerActive', 'listenerUnknown', 'tcpDisabled', 'agentExpected', 'agentDown', 'agentRunning', 'agentUnknown', 'jobKnown', 'jobFailures', 'measures', 'components', 'summary', 'number');
    }

    /** Additive qualified metrics; all original sql_perf_* fields remain unchanged. */
    public static function metrics(array $view): array
    {
        $ordinal = static fn (string $state): int => ['ok' => 0, 'info' => 1, 'warning' => 2, 'critical' => 3, 'disabled' => -1, 'not_detected' => -1][$state] ?? -2;
        $metrics = [
            'sql_q_observed' => $view['stamp'] === false ? 0 : $view['stamp'],
            'sql_q_expires' => $view['validUntil'],
            'sql_q_state' => $view['current'] ? $ordinal($view['state']) : -2,
            'sql_q_unknown' => count($view['gaps']),
            'sql_q_instances' => $view['total'],
            'sql_q_counters' => count(array_filter($view['cards'], static fn (array $card): bool => $card['qualified'])),
        ];
        $seen = [];
        foreach ($view['cards'] as $card) {
            $id = $card['id'];
            if (isset($seen[$id])) continue; // Ambiguous identities were invalidated for every card.
            $seen[$id] = true;
            $qualified = $card['qualified'];
            $value = static fn (string $key): float => $qualified ? (($view['number'])($card['perf'], $key) ?? -1.0) : -1.0;
            $metrics['sql_q_readable:' . $id] = $qualified ? 1 : 0;
            $metrics['sql_q_expires:' . $id] = $view['validUntil'];
            $metrics['sql_q_state:' . $id] = $view['current'] ? $ordinal($card['cardState']) : -2;
            $metrics['sql_q_blocked:' . $id] = $value('processes_blocked');
            $metrics['sql_q_grants:' . $id] = $value('memory_grants_pending');
            $metrics['sql_q_ple:' . $id] = $value('page_life_expectancy');
            $metrics['sql_q_pressure:' . $id] = $value('pressure_issues');
            $metrics['sql_q_jobs_valid:' . $id] = $card['jobsQualified'] ? 1 : 0;
            $metrics['sql_q_jobs:' . $id] = $card['jobsQualified'] ? (($view['number'])($card['perf'], 'agent_failed_jobs') ?? -1.0) : -1.0;
        }

        return $metrics;
    }

    public static function mapping(array $view): array
    {
        $mapping = [];
        foreach ($view['cards'] as $card) {
            $mapping[$card['id']][] = $card['name'];
        }

        return ['version' => 1, 'instances' => $mapping, 'observed_utc' => $view['collected'], 'valid_until_epoch' => $view['validUntil']];
    }
}
