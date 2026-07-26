<?php

declare(strict_types=1);

namespace WindowsAgentOverlay\Horizon;

use Throwable;

final class HorizonPodDiscovery
{
    /**
     * @param list<array<string,mixed>> $devices
     * @param list<array<string,mixed>> $existingPods
     * @param callable(array<string,mixed>,string):array<string,mixed> $validate
     * @return list<array<string,mixed>>
     */
    public static function discover(
        array $devices,
        array $existingPods,
        string $dnsSuffix,
        callable $validate
    ): array {
        $dnsSuffix = strtolower(rtrim(trim($dnsSuffix), '.'));
        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $dnsSuffix) !== 1) {
            throw new HorizonFailure('invalid_dns_suffix');
        }

        $existingBySite = [];
        foreach ($existingPods as $pod) {
            if (is_array($pod) && isset($pod['site'])) {
                $existingBySite[strtolower((string) $pod['site'])] = $pod;
            }
        }

        $groups = [];
        $pattern = '/^([a-z0-9][a-z0-9-]{1,14})-vcs([0-9]+)\.' . preg_quote($dnsSuffix, '/') . '$/';
        foreach ($devices as $device) {
            if (! is_array($device)) {
                continue;
            }
            $hostname = strtolower(rtrim(trim((string) ($device['hostname'] ?? '')), '.'));
            if (preg_match($pattern, $hostname, $match) !== 1) {
                continue;
            }
            $device['hostname'] = $hostname;
            $device['site'] = $match[1];
            $device['seed_number'] = (int) $match[2];
            $device['device_id'] = (int) ($device['device_id'] ?? 0);
            $device['status'] = (int) ($device['status'] ?? 0);
            $device['disabled'] = (int) ($device['disabled'] ?? 0);
            $device['has_application'] = (bool) ($device['has_application'] ?? false);
            $device['horizon_detected'] = (bool) ($device['horizon_detected'] ?? false);
            if ($device['device_id'] > 0) {
                $groups[$match[1]][] = $device;
            }
        }
        ksort($groups, SORT_STRING);

        $results = [];
        foreach ($groups as $site => $candidates) {
            if (isset($existingBySite[$site])) {
                $pod = $existingBySite[$site];
                $results[] = [
                    'site' => $site,
                    'state' => 'existing',
                    'reason' => 'preserved',
                    'display_device' => (string) ($pod['display_device'] ?? ''),
                    'apply' => false,
                ];
                continue;
            }

            $withApplication = array_values(array_filter(
                $candidates,
                static fn (array $device): bool => $device['has_application'] && $device['disabled'] === 0
            ));
            if ($withApplication === []) {
                $results[] = [
                    'site' => $site,
                    'state' => 'waiting-for-agent',
                    'reason' => 'windows_agent_application_missing',
                    'apply' => false,
                ];
                continue;
            }

            usort($withApplication, [self::class, 'compareCandidates']);
            $display = $withApplication[0];
            $reachable = array_values(array_filter(
                $withApplication,
                static fn (array $device): bool => $device['status'] === 1
            ));
            if ($reachable === []) {
                $results[] = [
                    'site' => $site,
                    'state' => 'unreachable',
                    'reason' => 'no_enabled_up_seed',
                    'display_device' => $display['hostname'],
                    'apply' => false,
                ];
                continue;
            }
            usort($reachable, [self::class, 'compareCandidates']);
            $seed = $reachable[0];
            $pod = [
                'site' => $site,
                'dns_suffix' => $dnsSuffix,
                'display_device' => $display['hostname'],
                'enabled' => true,
                'pool_warning_percent' => 50,
                'pool_critical_percent' => 90,
                'pool_minimum_spares' => 2,
                'page_size' => 500,
                'max_pages' => 20,
            ];

            try {
                $snapshot = $validate($pod, (string) $seed['hostname']);
                $identity = trim((string) ($snapshot['pod_identity'] ?? ''));
                $members = array_values(array_filter(
                    is_array($snapshot['discovered_connection_servers'] ?? null)
                        ? $snapshot['discovered_connection_servers']
                        : [],
                    'is_string'
                ));
                if ($identity === '' || $members === []) {
                    throw new HorizonFailure('pod_identity_or_members_missing');
                }
                $pod['pod_identity'] = $identity;
                $results[] = [
                    'site' => $site,
                    'state' => 'ready',
                    'reason' => 'validated',
                    'seed' => $seed['hostname'],
                    'display_device' => $display['hostname'],
                    'display_device_id' => $display['device_id'],
                    'pod_identity' => $identity,
                    'members' => $members,
                    'pod' => $pod,
                    'apply' => true,
                ];
            } catch (HorizonFailure $failure) {
                $results[] = [
                    'site' => $site,
                    'state' => self::failureState($failure->reason),
                    'reason' => self::boundedReason($failure->reason),
                    'seed' => $seed['hostname'],
                    'display_device' => $display['hostname'],
                    'apply' => false,
                ];
            } catch (Throwable) {
                $results[] = [
                    'site' => $site,
                    'state' => 'unreachable',
                    'reason' => 'internal_validation_failure',
                    'seed' => $seed['hostname'],
                    'display_device' => $display['hostname'],
                    'apply' => false,
                ];
            }
        }

        $identitySites = [];
        foreach ($results as $index => $result) {
            if (($result['state'] ?? '') !== 'ready') {
                continue;
            }
            $identity = strtolower((string) ($result['pod_identity'] ?? ''));
            $identitySites[$identity][] = $index;
        }
        foreach ($identitySites as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            foreach ($indexes as $index) {
                $results[$index]['state'] = 'ambiguous';
                $results[$index]['reason'] = 'pod_identity_shared_by_multiple_sites';
                $results[$index]['apply'] = false;
                unset($results[$index]['pod']);
            }
        }

        return $results;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function compareCandidates(array $left, array $right): int
    {
        foreach ([
            ['horizon_detected', true],
            ['status', 1],
        ] as [$key, $preferred]) {
            $leftPreferred = ($left[$key] ?? null) === $preferred;
            $rightPreferred = ($right[$key] ?? null) === $preferred;
            if ($leftPreferred !== $rightPreferred) {
                return $leftPreferred ? -1 : 1;
            }
        }

        return [(int) $left['seed_number'], (int) $left['device_id']]
            <=> [(int) $right['seed_number'], (int) $right['device_id']];
    }

    private static function failureState(string $reason): string
    {
        $reason = strtolower($reason);
        if (str_contains($reason, 'tls') || str_contains($reason, 'certificate')) {
            return 'tls-invalid';
        }
        if (str_contains($reason, 'authentication') || str_contains($reason, 'authorization')) {
            return 'unauthorized';
        }
        if (str_contains($reason, 'identity') || str_contains($reason, 'member_name')) {
            return 'ambiguous';
        }

        return 'unreachable';
    }

    private static function boundedReason(string $reason): string
    {
        $reason = preg_replace('/[^a-z0-9_.,:=-]/i', '', strtolower($reason)) ?? 'validation_failed';

        return substr($reason, 0, 160);
    }
}
