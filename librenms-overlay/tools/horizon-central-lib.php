<?php

declare(strict_types=1);

namespace WindowsAgentOverlay\Horizon;

use RuntimeException;

final class HorizonFailure extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

interface ApiSession
{
    /** @return array<mixed> */
    public function get(string $path): array;

    public function close(): void;
}

final class CurlApiSession implements ApiSession
{
    private string $token;

    /** @param array{username:string,password:string,domain?:string} $credential */
    public function __construct(
        private readonly string $endpoint,
        private readonly array $credential,
        private readonly int $connectTimeout = 5,
        private readonly int $requestTimeout = 20,
        private readonly int $maxBytes = 2097152,
    ) {
        if (! function_exists('curl_init')) {
            throw new HorizonFailure('curl_unavailable');
        }

        $login = $this->request('POST', 'rest/login', [
            'username' => $credential['username'],
            'password' => $credential['password'],
            'domain' => $credential['domain'] ?? '',
        ], false);
        $this->token = trim((string) ($login['access_token'] ?? ''));
        if ($this->token === '') {
            throw new HorizonFailure('login_token_missing');
        }
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path, null, true);
    }

    public function close(): void
    {
        if (! isset($this->token) || $this->token === '') {
            return;
        }

        try {
            $this->request('POST', 'rest/logout', null, true);
        } catch (HorizonFailure) {
            // Logout is best-effort and never changes the completed snapshot.
        } finally {
            $this->token = '';
        }
    }

    /** @param array<string,string>|null $body @return array<mixed> */
    private function request(string $method, string $path, ?array $body, bool $authenticated): array
    {
        $url = 'https://' . $this->endpoint . '/' . ltrim($path, '/');
        $curl = curl_init($url);
        if ($curl === false) {
            throw new HorizonFailure('http_initialization_failed');
        }

        $response = '';
        $headers = ['Accept: application/json'];
        if ($authenticated) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        if ($body !== null) {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, $encoded);
        }

        $maxBytes = $this->maxBytes;
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, $maxBytes): int {
                if (strlen($response) + strlen($chunk) > $maxBytes) {
                    return 0;
                }
                $response .= $chunk;

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($curl);
        curl_close($curl);

        if ($ok === false || $errno !== 0) {
            throw new HorizonFailure(self::curlReason($errno, strlen($response) >= $this->maxBytes));
        }
        if ($status === 401) {
            throw new HorizonFailure('authentication_failed');
        }
        if ($status === 403) {
            throw new HorizonFailure('authorization_failed');
        }
        if ($status < 200 || $status >= 300) {
            throw new HorizonFailure('http_' . $status);
        }
        if ($response === '') {
            return [];
        }

        try {
            $decoded = json_decode($response, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HorizonFailure('invalid_json');
        }
        if (! is_array($decoded)) {
            throw new HorizonFailure('invalid_response_shape');
        }

        return $decoded;
    }

    private static function curlReason(int $errno, bool $atLimit): string
    {
        if ($atLimit || $errno === CURLE_WRITE_ERROR) {
            return 'response_too_large';
        }
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return 'timeout';
        }
        if ($errno === CURLE_COULDNT_RESOLVE_HOST) {
            return 'dns_failed';
        }
        if (in_array($errno, [CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CACERT_BADFILE], true)) {
            return 'tls_failed';
        }

        return 'connection_failed';
    }
}

final class PodCollector
{
    /** @var callable(string,array<string,string>,array<string,mixed>):ApiSession */
    private $sessionFactory;
    private int $requestCount = 0;
    private int $pageCount = 0;
    private int $sessionPages = 0;
    private int $machinePages = 0;
    private int $sessionRows = 0;
    private int $machineRows = 0;

    /** @param callable(string,array<string,string>,array<string,mixed>):ApiSession|null $sessionFactory */
    public function __construct(?callable $sessionFactory = null)
    {
        $this->sessionFactory = $sessionFactory ?? static fn (string $endpoint, array $credential, array $config): ApiSession => new CurlApiSession(
            $endpoint,
            $credential,
            (int) ($config['connect_timeout_seconds'] ?? 5),
            (int) ($config['request_timeout_seconds'] ?? 20),
            (int) ($config['max_response_bytes'] ?? 2097152),
        );
    }

    /**
     * @param array<string,mixed> $config
     * @param array{username:string,password:string,domain?:string} $credential
     * @param array<string,mixed> $previous
     * @return array<string,mixed>
     */
    public function collect(array $config, array $credential, array $previous = []): array
    {
        self::validateConfig($config);
        $started = microtime(true);
        $attemptedAt = gmdate('c');
        $failures = [];
        $endpointAttempts = 0;
        $this->resetObservability();
        foreach (array_slice(self::candidateEndpoints($config, $previous), 0, 10) as $endpoint) {
            $endpointAttempts++;
            $session = null;
            try {
                $session = ($this->sessionFactory)($endpoint, $credential, $config);
                $snapshot = $this->collectEndpoint($session, $endpoint, $config, $previous);
                $snapshot['horizon_central_meta'] = [
                    'source' => 'central',
                    'site' => strtolower((string) $config['site']),
                    'source_endpoint' => $endpoint,
                    'last_attempt_utc' => $attemptedAt,
                    'last_success_utc' => gmdate('c'),
                    'snapshot_age_seconds' => 0,
                    'stale' => 0,
                    'reason' => 'none',
                    'configured_endpoints' => self::seedEndpoints($config),
                    'discovered_endpoints' => $snapshot['discovered_connection_servers'],
                    'pod_identity' => $snapshot['pod_identity'],
                    'outcome' => 'fresh',
                    'collection_duration_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
                    'endpoints_attempted' => $endpointAttempts,
                    'requests_total' => $this->requestCount,
                    'pages_total' => $this->pageCount,
                    'session_pages' => $this->sessionPages,
                    'machine_pages' => $this->machinePages,
                    'session_rows' => $this->sessionRows,
                    'machine_rows' => $this->machineRows,
                    'inventory_complete' => ((int) ($snapshot['horizon_api_summary']['sessions_truncated'] ?? 0) === 0
                        && (int) ($snapshot['horizon_api_summary']['machines_truncated'] ?? 0) === 0
                        && (int) ($snapshot['horizon_api_summary']['machine_details_truncated'] ?? 0) === 0
                        && (int) ($snapshot['horizon_api_summary']['machine_issues_truncated'] ?? 0) === 0
                        && (int) ($snapshot['horizon_api_summary']['service_details_truncated'] ?? 0) === 0) ? 1 : 0,
                ];
                $snapshot['horizon_central_meta']['snapshot_inventory_complete'] = $snapshot['horizon_central_meta']['inventory_complete'];

                return $snapshot;
            } catch (HorizonFailure $failure) {
                $failures[] = $endpoint . ':' . $failure->reason;
            } finally {
                $session?->close();
            }
        }

        $reason = $failures === [] ? 'no_valid_endpoints' : substr(implode(',', $failures), 0, 512);
        if ($previous !== [] && isset($previous['horizon_central_meta'])) {
            $stale = $previous;
            $lastSuccess = (string) ($stale['horizon_central_meta']['last_success_utc'] ?? '');
            $age = $lastSuccess === '' ? -1 : max(0, time() - (int) strtotime($lastSuccess));
            $stale['horizon_central_meta']['last_attempt_utc'] = $attemptedAt;
            $stale['horizon_central_meta']['snapshot_age_seconds'] = $age;
            $stale['horizon_central_meta']['stale'] = 1;
            $stale['horizon_central_meta']['reason'] = $reason;
            $stale['horizon_central_meta']['outcome'] = 'stale';
            $stale['horizon_central_meta']['collection_duration_ms'] = max(0, (int) round((microtime(true) - $started) * 1000));
            $stale['horizon_central_meta']['endpoints_attempted'] = $endpointAttempts;
            $stale['horizon_central_meta']['requests_total'] = $this->requestCount;
            $stale['horizon_central_meta']['pages_total'] = $this->pageCount;
            $stale['horizon_central_meta']['session_pages'] = $this->sessionPages;
            $stale['horizon_central_meta']['machine_pages'] = $this->machinePages;
            $stale['horizon_central_meta']['session_rows'] = $this->sessionRows;
            $stale['horizon_central_meta']['machine_rows'] = $this->machineRows;
            $stale['horizon_central_meta']['snapshot_inventory_complete'] = (int) (
                $stale['horizon_central_meta']['snapshot_inventory_complete']
                ?? $stale['horizon_central_meta']['inventory_complete']
                ?? 0
            );
            $stale['horizon_central_meta']['inventory_complete'] = 0;
            $stale['horizon_api_summary']['state'] = 'stale';
            $stale['horizon_api_summary']['reason'] = $reason;
            $collectorState = $age < 0 ? 'incomplete' : ($age >= 1800 ? 'critical' : ($age >= 600 ? 'warning' : 'info'));
            $stale['horizon_api_summary']['collector_health_state'] = $collectorState;
            $stale['horizon_health_summary']['collector_health_state'] = $collectorState;
            $overallState = self::worstState([
                (string) ($stale['horizon_api_summary']['platform_health_state'] ?? 'incomplete'),
                (string) ($stale['horizon_api_summary']['dependency_health_state'] ?? 'incomplete'),
                (string) ($stale['horizon_api_summary']['capacity_health_state'] ?? 'incomplete'),
                $collectorState,
            ]);
            $stale['horizon_api_summary']['overall_health_state'] = $overallState;
            $stale['horizon_api_summary']['health_state'] = $overallState;
            $stale['horizon_health_summary']['overall_health_state'] = $overallState;
            $stale['horizon_health_summary']['health_state'] = $overallState;
            $conditions = is_array($stale['horizon_conditions'] ?? null) ? $stale['horizon_conditions'] : [];
            $conditions = array_values(array_filter($conditions, static fn (array $item): bool => (string) ($item['scope'] ?? '') !== 'collector'));
            if ($collectorState !== 'info') {
                $conditions[] = self::condition('collector', $collectorState, 'collector_snapshot_stale', 'central-collector', 'The last-good snapshot is aging because the current collection failed.');
            }
            [$conditions, $history] = self::mergeConditionHistory(
                $conditions,
                is_array($stale['horizon_condition_history'] ?? null) ? $stale['horizon_condition_history'] : []
            );
            $stale['horizon_conditions'] = $conditions;
            $stale['horizon_condition_history'] = $history;

            return $stale;
        }

        throw new HorizonFailure($reason);
    }

    /**
     * Bootstrap one explicitly selected LibreNMS seed during discovery.
     *
     * @param array<string,mixed> $config
     * @param array{username:string,password:string,domain?:string} $credential
     * @return array<string,mixed>
     */
    public function discoverFromEndpoint(array $config, array $credential, string $endpoint): array
    {
        self::validateConfig($config);
        $endpoint = strtolower(rtrim(trim($endpoint), '.'));
        $site = preg_quote(strtolower((string) $config['site']), '/');
        $suffix = preg_quote(strtolower((string) $config['dns_suffix']), '/');
        if (preg_match('/^' . $site . '-vcs[0-9]+\.' . $suffix . '$/', $endpoint) !== 1) {
            throw new HorizonFailure('discovery_seed_name_mismatch');
        }

        $session = null;
        try {
            $session = ($this->sessionFactory)($endpoint, $credential, $config);
            $snapshot = $this->collectEndpoint($session, $endpoint, $config, []);
        } finally {
            $session?->close();
        }
        $identity = trim((string) ($snapshot['pod_identity'] ?? ''));
        if ($identity === '') {
            throw new HorizonFailure('pod_identity_missing');
        }
        $members = $snapshot['discovered_connection_servers'] ?? [];
        if (! is_array($members) || $members === []) {
            throw new HorizonFailure('pod_members_missing');
        }
        foreach ($members as $member) {
            if (! is_string($member) || preg_match('/^' . $site . '-vcs[0-9]+\.' . $suffix . '$/', strtolower($member)) !== 1) {
                throw new HorizonFailure('pod_member_name_mismatch');
            }
        }
        $snapshot['horizon_central_meta'] = [
            'source' => 'central',
            'site' => strtolower((string) $config['site']),
            'source_endpoint' => $endpoint,
            'last_attempt_utc' => gmdate('c'),
            'last_success_utc' => gmdate('c'),
            'snapshot_age_seconds' => 0,
            'stale' => 0,
            'reason' => 'none',
            'configured_endpoints' => self::seedEndpoints($config),
            'discovered_endpoints' => array_values($members),
            'pod_identity' => $identity,
        ];

        return $snapshot;
    }

    /** @param array<string,mixed> $config */
    public static function validateConfig(array $config): void
    {
        $site = strtolower(trim((string) ($config['site'] ?? '')));
        $suffix = strtolower(trim((string) ($config['dns_suffix'] ?? '')));
        $display = strtolower(trim((string) ($config['display_device'] ?? '')));
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,14}$/', $site)) {
            throw new HorizonFailure('invalid_site');
        }
        if (! self::validDnsName($suffix) || ! str_contains($suffix, '.')) {
            throw new HorizonFailure('invalid_dns_suffix');
        }
        if (! self::validDnsName($display)) {
            throw new HorizonFailure('invalid_display_device');
        }
        foreach (['pool_warning_percent' => 50, 'pool_critical_percent' => 90] as $key => $default) {
            $value = (int) ($config[$key] ?? $default);
            if ($value < 0 || $value > 100) {
                throw new HorizonFailure('invalid_pool_threshold');
            }
        }
        if ((int) ($config['pool_warning_percent'] ?? 50) >= (int) ($config['pool_critical_percent'] ?? 90)) {
            throw new HorizonFailure('invalid_pool_threshold_order');
        }
        $issueLimit = (int) ($config['machine_issue_limit'] ?? 100);
        if ($issueLimit < 1 || $issueLimit > 500) {
            throw new HorizonFailure('invalid_machine_issue_limit');
        }
        $detailLimit = (int) ($config['machine_detail_limit'] ?? 1000);
        if ($detailLimit < 1 || $detailLimit > 5000) {
            throw new HorizonFailure('invalid_machine_detail_limit');
        }
    }

    /** @param array<string,mixed> $config @return list<string> */
    public static function seedEndpoints(array $config): array
    {
        $site = strtolower((string) $config['site']);
        $suffix = strtolower((string) $config['dns_suffix']);

        return ["{$site}-vcs1.{$suffix}", "{$site}-vcs2.{$suffix}"];
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $previous @return list<string> */
    public static function candidateEndpoints(array $config, array $previous): array
    {
        $result = self::seedEndpoints($config);
        $discovered = $previous['discovered_connection_servers'] ?? $previous['horizon_central_meta']['discovered_endpoints'] ?? [];
        foreach (is_array($discovered) ? $discovered : [] as $host) {
            $normalized = self::normalizeDiscoveredHost((string) $host, (string) $config['dns_suffix']);
            if ($normalized !== null && ! in_array($normalized, $result, true)) {
                $result[] = $normalized;
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $previous @return array<string,mixed> */
    private function collectEndpoint(ApiSession $session, string $endpoint, array $config, array $previous): array
    {
        $failures = [];
        $environment = $this->required($session, 'rest/config/v1/environment-properties');
        $identity = trim((string) ($environment['local_pod_name'] ?? ''));
        if ($identity === '') {
            $identity = trim((string) ($environment['cluster_name'] ?? ''));
        }
        $expected = trim((string) ($config['pod_identity'] ?? $previous['pod_identity'] ?? $previous['horizon_central_meta']['pod_identity'] ?? ''));
        if ($expected !== '' && $identity === '') {
            throw new HorizonFailure('pod_identity_missing');
        }
        if ($expected !== '' && $identity !== '' && strcasecmp($expected, $identity) !== 0) {
            throw new HorizonFailure('pod_identity_mismatch');
        }

        $monitorRows = self::rows($this->optional($session, 'rest/monitor/v3/connection-servers', 'connection_server_monitor', $failures));
        $configRows = self::rows($this->required($session, 'rest/config/v2/connection-servers'));
        [$members, $replications, $memberTotals] = self::connectionServers(
            $monitorRows,
            $configRows,
            $endpoint,
            max(1, min(64, (int) ($config['unhealthy_service_limit'] ?? 16))),
            is_array($previous['horizon_configuration_replications'] ?? null) ? $previous['horizon_configuration_replications'] : []
        );
        $discovered = [];
        foreach ($members as $member) {
            if ((int) ($member['enabled'] ?? 0) !== 1 || ! self::healthy((string) ($member['status'] ?? ''))) {
                continue;
            }
            $host = self::normalizeDiscoveredHost((string) ($member['name'] ?? ''), (string) $config['dns_suffix']);
            if ($host !== null && ! in_array($host, $discovered, true)) {
                $discovered[] = $host;
            }
        }

        $domainRows = self::rows($this->optional($session, 'rest/monitor/v3/ad-domains', 'horizon_domain_monitor', $failures));
        [$domains, $domainMembers, $directoryTotals] = self::domains($domainRows);
        $gateways = self::gateways(
            self::rows($this->optional($session, 'rest/monitor/v3/gateways', 'gateway_monitor', $failures)),
            is_array($previous['horizon_gateways'] ?? null) ? $previous['horizon_gateways'] : []
        );
        $vendorMetrics = self::vendorMetrics(
            $this->optionalFailSoft($session, 'rest/monitor/v1/health-metrics'),
            $this->optionalFailSoft($session, 'rest/monitor/v1/system-metrics')
        );

        $pageSize = max(1, min(1000, (int) ($config['page_size'] ?? 500)));
        $maxPages = max(1, min(100, (int) ($config['max_pages'] ?? 20)));
        [$sessionTotals, $protocols, $activeMachineIds, $sessionsTruncated] = $this->sessions($session, $pageSize, $maxPages, $failures);
        $poolRows = self::rows($this->optional($session, 'rest/inventory/v1/desktop-pools', 'desktop_pools', $failures));
        [$pools, $poolById] = self::pools($poolRows);
        [$pools, $machineStates, $machineDetails, $machineIssues, $machineDetailsTruncated, $machineIssuesTruncated, $machinesTruncated] = $this->machines(
            $session,
            $pools,
            $poolById,
            $activeMachineIds,
            $pageSize,
            $maxPages,
            $failures,
            max(1, min(5000, (int) ($config['machine_detail_limit'] ?? 1000))),
            max(1, min(500, (int) ($config['machine_issue_limit'] ?? 100))),
            gmdate('c'),
            is_array($previous['horizon_pool_machines'] ?? null) ? $previous['horizon_pool_machines'] : []
        );
        [$pools, $poolTotals] = self::scorePools($pools, $sessionsTruncated || $machinesTruncated || in_array('sessions', $failures, true) || in_array('machines', $failures, true), $config);

        $state = $failures === [] ? 'ok' : 'partial';
        $platformState = self::worstState([(string) $memberTotals['platform_state'], (string) $gateways['state']]);
        $dependencyState = (string) ($directoryTotals['state'] ?? 'incomplete');
        $capacityState = (string) ($poolTotals['state'] ?? 'incomplete');
        $collectorState = $state === 'ok' ? 'ok' : 'incomplete';
        $overallState = self::worstState([$platformState, $dependencyState, $capacityState, $collectorState]);
        $vendorMetrics['problem_machine_mismatch'] = abs(
            (int) ($vendorMetrics['problem_machines_total'] ?? 0)
            - (int) ($poolTotals['issue_machines'] ?? 0)
        );
        $summary = [
            'state' => $state,
            'platform_health_state' => $platformState,
            'dependency_health_state' => $dependencyState,
            'capacity_health_state' => $capacityState,
            'collector_health_state' => $collectorState,
            'overall_health_state' => $overallState,
            'health_state' => $overallState,
            'reason' => $failures === [] ? 'none' : implode(',', array_values(array_unique($failures))),
            'connection_servers_total' => $memberTotals['total'],
            'connection_servers_unhealthy' => $memberTotals['unhealthy'],
            'services_unhealthy' => $memberTotals['services_unhealthy'],
            'replications_unhealthy' => $memberTotals['replications_unhealthy'],
            'certificates_invalid' => $memberTotals['certificates_invalid'],
            'sessions_total' => $sessionTotals['total'],
            'sessions_connected' => $sessionTotals['connected'],
            'sessions_disconnected' => $sessionTotals['disconnected'],
            'sessions_other' => $sessionTotals['other'],
            'sessions_truncated' => $sessionsTruncated ? 1 : 0,
            'machines_truncated' => $machinesTruncated ? 1 : 0,
            'machine_details_total' => array_sum(array_map(static fn (array $pool): int => (int) ($pool['machines_total'] ?? 0), $pools)),
            'machine_details_truncated' => $machineDetailsTruncated ? 1 : 0,
            'machine_issues_total' => (int) ($poolTotals['issue_machines'] ?? count($machineIssues)),
            'machine_issues_truncated' => $machineIssuesTruncated ? 1 : 0,
            'service_details_truncated' => (int) ($memberTotals['service_details_truncated'] ?? 0),
            'vendor_warnings_total' => (int) ($vendorMetrics['warnings_total'] ?? 0),
            'vendor_errors_total' => (int) ($vendorMetrics['errors_total'] ?? 0),
            'vendor_unknown_total' => (int) ($vendorMetrics['unknown_total'] ?? 0),
            'vendor_problem_machines_total' => (int) ($vendorMetrics['problem_machines_total'] ?? 0),
            'vendor_problem_machine_mismatch' => (int) ($vendorMetrics['problem_machine_mismatch'] ?? 0),
            'source' => 'central',
        ];
        $podName = trim((string) ($environment['local_pod_name'] ?? ''));
        if ($podName === '') {
            $podName = trim((string) ($environment['cluster_name'] ?? ''));
        }
        if ($podName === '') {
            $podName = $identity;
        }

        $snapshot = [
            'pod_identity' => $identity,
            'discovered_connection_servers' => $discovered,
            'horizon_api_summary' => $summary,
            'horizon_health_summary' => [
                'platform_health_state' => $platformState,
                'dependency_health_state' => $dependencyState,
                'capacity_health_state' => $capacityState,
                'collector_health_state' => $collectorState,
                'overall_health_state' => $overallState,
                'health_state' => $overallState,
            ],
            'horizon_vendor_metrics' => $vendorMetrics,
            'horizon_api_session_protocols' => $protocols,
            'horizon_pod_summary' => [
                'state' => $platformState,
                'reason_code' => (string) ($memberTotals['platform_reason_code'] ?? 'platform_health_aggregated'),
                'pod_name' => $podName,
                'cluster_name' => (string) ($environment['cluster_name'] ?? ''),
                'members_total' => $memberTotals['total'],
                'members_unhealthy' => $memberTotals['unhealthy'],
                'configuration_replications_total' => $memberTotals['replications_total'],
                'configuration_replications_unhealthy' => $memberTotals['replications_unhealthy'],
                'gateways_total' => count($gateways['rows']),
                'gateways_unhealthy' => $gateways['unhealthy'],
            ],
            'horizon_pod_members' => $members,
            'horizon_configuration_replications' => $replications,
            'horizon_directory_summary' => [
                'state' => $dependencyState,
                'domains_total' => count($domains),
                'member_links_total' => $directoryTotals['total'],
                'member_links_unhealthy' => $directoryTotals['unhealthy'],
                'service_accounts_total' => $directoryTotals['service_accounts_total'],
                'service_accounts_unhealthy' => $directoryTotals['service_accounts_unhealthy'],
            ],
            'horizon_directory_domains' => $domains,
            'horizon_directory_member_status' => $domainMembers,
            'horizon_gateways' => $gateways['rows'],
            'horizon_pools_summary' => $poolTotals,
            'horizon_pools' => $pools,
            'horizon_pool_machine_states' => $machineStates,
            'horizon_pool_machines' => $machineDetails,
            'horizon_pool_machine_issues' => $machineIssues,
        ];
        [$conditions, $observations] = self::buildHealthEvidence($snapshot, $failures);
        [$conditions, $history] = self::mergeConditionHistory(
            $conditions,
            is_array($previous['horizon_condition_history'] ?? null) ? $previous['horizon_condition_history'] : []
        );
        $snapshot['horizon_conditions'] = $conditions;
        $snapshot['horizon_observations'] = $observations;
        $snapshot['horizon_condition_history'] = $history;

        return $snapshot;
    }

    /** @return array<mixed> */
    private function required(ApiSession $session, string $path): array
    {
        return $this->trackedGet($session, $path);
    }

    /** @param list<string> $failures @return array<mixed> */
    private function optional(ApiSession $session, string $path, string $label, array &$failures): array
    {
        try {
            return $this->trackedGet($session, $path);
        } catch (HorizonFailure) {
            $failures[] = $label;

            return [];
        }
    }

    /** Optional telemetry never makes the authoritative snapshot partial. @return array<mixed> */
    private function optionalFailSoft(ApiSession $session, string $path): array
    {
        try {
            return $this->trackedGet($session, $path);
        } catch (HorizonFailure) {
            return [];
        }
    }

    /** @return array{state:string,reason_code:string,impact:string} */
    public static function classifyConnectionServerStatus(string $status, bool $enabled = true): array
    {
        if (! $enabled) return self::classification('disabled', 'connection_server_disabled', 'none');

        return match (self::status($status)) {
            'OK' => self::classification('ok', 'connection_server_ok', 'none'),
            'RESTART_REQUIRED' => self::classification('warning', 'connection_server_restart_required', 'server'),
            'UNKNOWN', '' => self::classification('incomplete', 'connection_server_status_unknown', 'server'),
            'ERROR' => self::classification('critical', 'connection_server_error', 'server'),
            'NOT_RESPONDING' => self::classification('critical', 'connection_server_not_responding', 'server'),
            default => self::classification('incomplete', 'connection_server_status_unrecognized', 'server'),
        };
    }

    /** @return array{state:string,reason_code:string,impact:string} */
    public static function classifyApiService(string $name, string $status, bool $gatewayExpected = false): array
    {
        $normalizedName = strtoupper((string) preg_replace('/[^A-Z0-9]+/', '_', strtoupper(trim($name))));
        if (self::healthy($status)) return self::classification('ok', 'api_service_running', 'none');
        if ($normalizedName === 'CRL_PREFETCH' || $normalizedName === 'CRL_PREFETCHER') {
            return self::classification('info', 'crl_prefetch_not_running', 'observation');
        }
        if (in_array($normalizedName, ['PCOIP_SECURE_GATEWAY', 'BLAST_SECURE_GATEWAY', 'SECURITY_GATEWAY_COMPONENT'], true) && ! $gatewayExpected) {
            return self::classification('info', 'unused_gateway_service_not_running', 'observation');
        }
        if (self::status($status) === 'UNKNOWN') {
            return self::classification('incomplete', 'api_service_status_unknown', 'server');
        }

        return self::classification('warning', 'expected_api_service_not_running', 'server');
    }

    /** @param array<string,mixed> $certificate @return array{state:string,reason_code:string,impact:string} */
    public static function classifyCertificate(array $certificate): array
    {
        if (! self::boolean($certificate['valid'] ?? true, true)) {
            return self::classification('critical', 'active_certificate_invalid', 'server');
        }
        $days = isset($certificate['days_remaining']) ? (int) $certificate['days_remaining'] : null;
        if ($days !== null && $days < 0) return self::classification('critical', 'active_certificate_expired', 'server');
        if ($days !== null && $days <= 30) return self::classification('warning', 'active_certificate_expires_soon', 'server');

        return self::classification('ok', 'active_certificate_valid', 'none');
    }

    /** @return array{state:string,reason_code:string,impact:string} */
    public static function classifyReplication(string $status): array
    {
        return match (self::status($status)) {
            'OK', 'UP', 'RUNNING' => self::classification('ok', 'replication_healthy', 'none'),
            'UNKNOWN', '' => self::classification('incomplete', 'replication_status_unknown', 'pod'),
            default => self::classification('warning', 'replication_unhealthy', 'pod'),
        };
    }

    /** @return array{state:string,reason_code:string,impact:string} */
    public static function classifyDomainAccess(string $status): array
    {
        return match (self::status($status)) {
            'OK', 'ACCESSIBLE', 'FULLY_ACCESSIBLE' => self::classification('ok', 'domain_access_healthy', 'none'),
            'UNKNOWN', '' => self::classification('incomplete', 'domain_access_unknown', 'dependency'),
            default => self::classification('critical', 'domain_access_unavailable', 'dependency'),
        };
    }

    /** @return array{state:string,reason_code:string,impact:string} */
    public static function classifyServiceAccount(string $status): array
    {
        return match (self::status($status)) {
            'ACTIVE', 'OK' => self::classification('ok', 'service_account_active', 'none'),
            'UNKNOWN', '' => self::classification('incomplete', 'service_account_status_unknown', 'dependency'),
            default => self::classification('critical', 'service_account_unhealthy', 'dependency'),
        };
    }

    /** @return array{state:string,reason_code:string,impact:string} */
    public static function classifyGateway(string $status, bool $configured = true): array
    {
        if (! $configured) return self::classification('disabled', 'gateway_not_configured', 'none');

        return match (self::status($status)) {
            'OK', 'UP', 'ONLINE' => self::classification('ok', 'gateway_healthy', 'none'),
            'STALE', 'NOT_CONTACTED' => self::classification('warning', 'gateway_contact_degraded', 'gateway'),
            'PROBLEM', 'ERROR', 'DOWN', 'NOT_RESPONDING' => self::classification('critical', 'gateway_unhealthy', 'gateway'),
            'UNKNOWN', '' => self::classification('incomplete', 'gateway_status_unknown', 'gateway'),
            default => self::classification('incomplete', 'gateway_status_unrecognized', 'gateway'),
        };
    }

    /**
     * Single source of truth for machine state semantics.
     *
     * Four independent properties, because they answer different questions and
     * conflating them is what made capacity health saturate:
     *
     * - placement: `ready` counts as capacity available for a new session now;
     *   `pending` is benign and expected to become ready; `held` is intentionally
     *   withheld; `faulted` is broken; `none` does not participate.
     * - health: the severity shown for the machine itself.
     * - issue: whether the machine counts as a problem machine. Aligned with the
     *   vendor's own problem-VM definition so the vendor comparison converges.
     * - reason: stable reason code.
     *
     * A machine serving a session is healthy and is not placement capacity.
     * Utilisation is not a fault. An unrecognized state is not evidence of a
     * problem, so it reports `incomplete` and is excluded from capacity scoring.
     *
     * @return array<string,array{placement:string,health:string,issue:bool,reason:string}>
     */
    private static function machineStateTaxonomy(): array
    {
        $ready = ['placement' => 'ready', 'health' => 'ok', 'issue' => false, 'reason' => 'machine_healthy'];
        $inUse = ['placement' => 'none', 'health' => 'ok', 'issue' => false, 'reason' => 'machine_in_use'];
        $pending = ['placement' => 'pending', 'health' => 'info', 'issue' => false, 'reason' => 'machine_transitional'];
        $held = ['placement' => 'held', 'health' => 'info', 'issue' => false, 'reason' => 'machine_withheld'];
        $faulted = static fn (string $reason): array => ['placement' => 'faulted', 'health' => 'warning', 'issue' => true, 'reason' => $reason];

        return [
            // Ready for placement.
            'AVAILABLE' => $ready,

            // Serving or assigned to a user. Healthy, but not free capacity.
            'CONNECTED' => $inUse,
            'DISCONNECTED' => $inUse,

            // Benign and expected to become ready.
            'PROVISIONED' => $pending,
            'PROVISIONING' => $pending,
            'CUSTOMIZING' => $pending,
            'VALIDATING' => $pending,
            'WAITING_FOR_AGENT' => $pending,
            'STARTUP' => $pending,

            // Intentionally withheld by an operator or lifecycle action.
            'MAINTENANCE' => $held,
            'DISABLED' => ['placement' => 'held', 'health' => 'info', 'issue' => false, 'reason' => 'machine_disabled'],
            'DISABLE_IN_PROGRESS' => $held,
            'DELETING' => $held,

            // Vendor-documented problem states.
            'AGENT_UNREACHABLE' => $faulted('agent_unreachable'),
            'AGENT_CONFIG_ERROR' => $faulted('agent_config_error'),
            'AGENT_ERR_STARTUP_IN_PROGRESS' => $faulted('agent_startup_in_progress'),
            'AGENT_ERR_DISABLED' => $faulted('agent_disabled'),
            'AGENT_ERR_INVALID_IP' => $faulted('agent_invalid_ip'),
            'AGENT_ERR_NEED_REBOOT' => $faulted('agent_needs_reboot'),
            'AGENT_ERR_PROTOCOL_FAILURE' => $faulted('agent_protocol_failure'),
            'AGENT_ERR_DOMAIN_FAILURE' => $faulted('agent_domain_failure'),
            'CUSTOMIZING_ERROR' => $faulted('customizing_error'),
            'PROVISIONING_ERROR' => $faulted('provisioning_error'),
            'ERROR' => $faulted('machine_state_error'),
            'UNAVAILABLE' => $faulted('machine_unavailable'),
            'ALREADY_USED' => $faulted('machine_already_used'),

            // Reported by the vendor but not determinable by us. The vendor counts
            // these as problem machines, so `issue` follows the vendor while the
            // displayed severity stays `incomplete` rather than claiming a fault.
            'UNKNOWN' => ['placement' => 'faulted', 'health' => 'incomplete', 'issue' => true, 'reason' => 'machine_state_unknown'],
        ];
    }

    /**
     * @return array{state:string,reason_code:string,impact:string,placement:string,issue:bool,recognized:bool}
     */
    public static function classifyMachineState(string $state, bool $hasSession, bool $maintenance, int $consecutiveSamples = 1): array
    {
        $status = self::status($state);

        if ($status === '') {
            return self::machineClassification('incomplete', 'machine_state_unknown', 'capacity', 'faulted', true, true);
        }

        $taxonomy = self::machineStateTaxonomy();
        if (! isset($taxonomy[$status])) {
            // Not knowing a state is not evidence of a problem. Report it, do not
            // score it, and never let it manufacture a warning.
            return self::machineClassification('incomplete', 'machine_state_unrecognized', 'capacity', 'none', false, false);
        }

        $entry = $taxonomy[$status];
        $health = $entry['health'];
        $reason = $entry['reason'];
        $issue = $entry['issue'];
        $placement = $entry['placement'];

        // A benign transitional state that has not progressed is worth attention.
        if ($placement === 'pending' && $consecutiveSamples >= 7) {
            $health = 'warning';
            $reason = 'machine_transitional_too_long';
        }

        // Session presence and maintenance change how a machine participates in
        // capacity. Neither hides a fault: a machine reporting a bad state while
        // serving a session is still evidence, it just is not free capacity.
        if ($hasSession) {
            $placement = 'none';
            if (! $issue && $health === 'ok') $reason = 'machine_in_use';
        } elseif ($maintenance) {
            // Maintenance is a deliberate operator action, so an out-of-service
            // machine is informational rather than a problem.
            $placement = 'held';
            $health = 'info';
            $reason = 'maintenance_mode';
            $issue = false;
        }

        return self::machineClassification(
            $health,
            $reason,
            $placement === 'none' && ! $issue && $health === 'ok' ? 'none' : 'capacity',
            $placement,
            $issue,
            true
        );
    }

    /**
     * @return array{state:string,reason_code:string,impact:string,placement:string,issue:bool,recognized:bool}
     */
    private static function machineClassification(string $state, string $reasonCode, string $impact, string $placement, bool $issue, bool $recognized): array
    {
        return [
            'state' => $state,
            'reason_code' => $reasonCode,
            'impact' => $impact,
            'placement' => $placement,
            'issue' => $issue,
            'recognized' => $recognized,
        ];
    }

    /**
     * @param list<array{state:string,enabled?:int|bool}> $members
     * @return array{state:string,reason_code:string,impact:string}
     */
    public static function aggregatePodHealth(array $members): array
    {
        $enabled = array_values(array_filter($members, static fn (array $member): bool => (int) ($member['enabled'] ?? 1) === 1));
        if ($enabled === []) return self::classification('incomplete', 'no_enabled_connection_servers', 'pod');
        $healthy = count(array_filter($enabled, static fn (array $member): bool => in_array(strtolower((string) ($member['state'] ?? '')), ['ok', 'info'], true)));
        $critical = count(array_filter($enabled, static fn (array $member): bool => strtolower((string) ($member['state'] ?? '')) === 'critical'));
        $warning = count(array_filter($enabled, static fn (array $member): bool => strtolower((string) ($member['state'] ?? '')) === 'warning'));
        $incomplete = count(array_filter($enabled, static fn (array $member): bool => strtolower((string) ($member['state'] ?? '')) === 'incomplete'));
        if ($healthy <= 1) return self::classification('critical', 'connection_server_redundancy_lost', 'pod');
        if ($critical > 0 || $warning > 0) return self::classification('warning', 'connection_server_redundancy_degraded', 'pod');
        if ($incomplete > 0) return self::classification('incomplete', 'connection_server_health_unknown', 'pod');

        return self::classification('ok', 'connection_server_redundancy_healthy', 'none');
    }

    /** @return array{state:string,reason_code:string,impact:string} */
    private static function classification(string $state, string $reasonCode, string $impact): array
    {
        return ['state' => $state, 'reason_code' => $reasonCode, 'impact' => $impact];
    }

    private static function stateRank(string $state): int
    {
        return ['ok' => 0, 'disabled' => 5, 'info' => 10, 'incomplete' => 20, 'warning' => 30, 'critical' => 40][strtolower($state)] ?? 20;
    }

    /** @param list<string> $states */
    private static function worstState(array $states, string $default = 'ok'): string
    {
        $worst = $default;
        foreach ($states as $state) {
            if (self::stateRank($state) > self::stateRank($worst)) $worst = strtolower($state);
        }

        return $worst;
    }

    /** @param list<array<string,mixed>> $monitor @param list<array<string,mixed>> $configs @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:array<string,int|string>} */
    private static function connectionServers(array $monitor, array $configs, string $endpoint, int $serviceLimit, array $previousReplications = []): array
    {
        $members = [];
        $replications = [];
        $totals = [
            'total' => 0, 'enabled' => 0, 'unhealthy' => 0, 'services_unhealthy' => 0,
            'service_observations' => 0, 'service_details_truncated' => 0,
            'replications_total' => 0, 'replications_unhealthy' => 0,
            'certificates_invalid' => 0, 'platform_state' => 'incomplete',
        ];
        foreach ($monitor as $row) {
            $name = (string) ($row['name'] ?? '');
            $serviceRows = [];
            foreach (self::rows($row['services'] ?? []) as $service) {
                $serviceStatus = self::status((string) ($service['status'] ?? ''));
                $serviceName = (string) ($service['name'] ?? $service['service_name'] ?? $service['display_name'] ?? 'unknown');
                $serviceRows[] = [
                    'name' => self::boundedText($serviceName, 96, 'unknown'),
                    'status' => self::boundedText($serviceStatus, 32, 'UNKNOWN'),
                ];
            }
            $replicationRows = [];
            foreach (self::rows($row['cs_replications'] ?? []) as $replication) {
                $status = self::status((string) ($replication['status'] ?? ''));
                $classification = self::classifyReplication($status);
                $persisted = count(array_filter($previousReplications, static fn (array $previous): bool =>
                    strcasecmp((string) ($previous['source'] ?? ''), $name) === 0
                    && strcasecmp((string) ($previous['target'] ?? ''), (string) ($replication['server_name'] ?? '')) === 0
                    && self::status((string) ($previous['status'] ?? '')) === $status
                    && ! self::healthy($status)
                )) > 0;
                if (! $persisted && self::stateRank($classification['state']) >= self::stateRank('warning')) {
                    $classification = self::classification('info', 'replication_unhealthy_transient', 'observation');
                }
                $item = [
                    'source' => $name,
                    'target' => (string) ($replication['server_name'] ?? ''),
                    'status' => $status,
                    ...$classification,
                ];
                $replications[] = $item;
                $replicationRows[] = $item;
                $totals['replications_total']++;
                if (self::stateRank($classification['state']) >= self::stateRank('warning')) {
                    $totals['replications_unhealthy']++;
                }
            }
            $certificate = is_array($row['certificate'] ?? null) ? $row['certificate'] : [];
            $certificateClassification = self::classifyCertificate($certificate);
            $certValid = $certificateClassification['state'] !== 'critical';
            $status = self::status((string) ($row['status'] ?? ''));
            $member = [
                'id' => (string) ($row['id'] ?? ''), 'name' => $name, 'status' => $status,
                'server_type' => 'connection_server', 'local_api_target' => strcasecmp($name, $endpoint) === 0 ? 1 : 0,
                'enabled' => 1, 'gateway_mode' => 'none', 'version' => (string) (($row['details']['version'] ?? '')),
                'connections' => (int) ($row['connection_count'] ?? 0), 'services_unhealthy' => 0,
                'unhealthy_services' => [], 'service_observations' => [], '_service_rows' => $serviceRows,
                'unhealthy_services_truncated' => 0,
                'configuration_replications_total' => count(self::rows($row['cs_replications'] ?? [])),
                'configuration_replications_unhealthy' => count(array_filter($replicationRows, static fn (array $item): bool => self::stateRank((string) $item['state']) >= self::stateRank('warning'))),
                'certificate_valid' => $certValid ? 1 : 0,
                'certificate_state' => $certificateClassification['state'],
                'certificate_reason_code' => $certificateClassification['reason_code'],
            ];
            $members[] = $member;
            if (! $certValid) {
                $totals['certificates_invalid']++;
            }
        }
        foreach ($configs as $row) {
            $index = self::findMember($members, (string) ($row['id'] ?? ''), (string) ($row['name'] ?? ''));
            if ($index < 0) {
                $members[] = [
                    'id' => (string) ($row['id'] ?? ''), 'name' => (string) ($row['name'] ?? ''),
                    'status' => 'UNKNOWN', 'services_unhealthy' => 0, 'unhealthy_services' => [],
                    'service_observations' => [], '_service_rows' => [], 'unhealthy_services_truncated' => 0,
                    'configuration_replications_total' => 0, 'configuration_replications_unhealthy' => 0,
                    'certificate_valid' => 1, 'certificate_state' => 'incomplete',
                    'certificate_reason_code' => 'certificate_status_unavailable', 'connections' => 0,
                ];
                $index = count($members) - 1;
            }
            $gatewayModes = [];
            if (! self::boolean($row['bypass_tunnel'] ?? true, true)) $gatewayModes[] = 'tunnel';
            if (! self::boolean($row['bypass_pcoip_gateway'] ?? true, true)) $gatewayModes[] = 'pcoip';
            if (! self::boolean($row['bypass_app_blast_gateway'] ?? true, true)) $gatewayModes[] = 'blast';
            $members[$index]['enabled'] = self::boolean($row['enabled'] ?? true, true) ? 1 : 0;
            $members[$index]['local_api_target'] = self::boolean($row['local_connection_server'] ?? false, false) ? 1 : 0;
            if (trim((string) ($members[$index]['version'] ?? '')) === '') {
                $members[$index]['version'] = (string) ($row['version'] ?? '');
            }
            $members[$index]['gateway_mode'] = $gatewayModes === [] ? 'none' : implode(',', $gatewayModes);
            $members[$index]['server_type'] = $gatewayModes === [] ? 'connection_server' : 'connection_server_with_embedded_gateway';
        }
        $totals['total'] = count($members);
        foreach ($members as &$member) {
            $enabled = (int) ($member['enabled'] ?? 1) === 1;
            if ($enabled) $totals['enabled']++;
            $gatewayExpected = (string) ($member['gateway_mode'] ?? 'none') !== 'none';
            foreach ($member['_service_rows'] as $service) {
                $classification = self::classifyApiService((string) $service['name'], (string) $service['status'], $gatewayExpected);
                $detail = [...$service, ...$classification];
                if ($classification['state'] === 'info') {
                    $member['service_observations'][] = $detail;
                    $totals['service_observations']++;
                } elseif (self::stateRank($classification['state']) >= self::stateRank('incomplete')) {
                    if (count($member['unhealthy_services']) < $serviceLimit) {
                        $member['unhealthy_services'][] = $detail;
                    } else {
                        $member['unhealthy_services_truncated'] = 1;
                        $totals['service_details_truncated'] = 1;
                    }
                    $member['services_unhealthy']++;
                    $totals['services_unhealthy']++;
                }
            }
            unset($member['_service_rows']);
            $statusClassification = self::classifyConnectionServerStatus((string) ($member['status'] ?? ''), $enabled);
            $memberState = self::worstState([
                $statusClassification['state'],
                ...array_column($member['unhealthy_services'], 'state'),
                (string) ($member['certificate_state'] ?? 'ok'),
                (int) ($member['configuration_replications_unhealthy'] ?? 0) > 0 ? 'warning' : 'ok',
            ]);
            if (! $enabled) $memberState = 'disabled';
            $member['health_state'] = $memberState;
            $member['reason_code'] = $memberState === $statusClassification['state']
                ? $statusClassification['reason_code']
                : ((int) ($member['services_unhealthy'] ?? 0) > 0 ? 'connection_server_service_degraded'
                    : ((int) ($member['certificate_valid'] ?? 1) === 0 ? 'active_certificate_invalid' : 'replication_unhealthy'));
            $member['impact'] = in_array($memberState, ['ok', 'info', 'disabled'], true) ? 'none' : 'server';
            if ($enabled && self::stateRank($memberState) >= self::stateRank('warning')) {
                $totals['unhealthy']++;
            }
        }
        unset($member);
        $pod = self::aggregatePodHealth(array_map(static fn (array $member): array => [
            'state' => (string) ($member['health_state'] ?? 'incomplete'),
            'enabled' => (int) ($member['enabled'] ?? 1),
        ], $members));
        $totals['platform_state'] = $pod['state'];
        $totals['platform_reason_code'] = $pod['reason_code'];

        return [$members, $replications, $totals];
    }

    /** @param list<array<string,mixed>> $rows @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:array<string,int|string>} */
    private static function domains(array $rows): array
    {
        $domains = [];
        $members = [];
        $total = 0;
        $unhealthy = 0;
        $serviceAccountsTotal = 0;
        $serviceAccountsUnhealthy = 0;
        $states = [];
        foreach ($rows as $row) {
            $domainName = (string) ($row['dns_name'] ?? $row['netbios_name'] ?? '');
            $domainTotal = 0;
            $domainBad = 0;
            foreach (self::rows($row['connection_servers'] ?? []) as $member) {
                $status = self::status((string) ($member['status'] ?? ''));
                $classification = self::classifyDomainAccess($status);
                $domainTotal++;
                $total++;
                $states[] = $classification['state'];
                if (self::stateRank($classification['state']) >= self::stateRank('warning')) {
                    $domainBad++;
                    $unhealthy++;
                }
                $members[] = [
                    'domain' => $domainName, 'member' => (string) ($member['name'] ?? ''),
                    'status' => $status,
                    'trust_relationship' => self::status((string) ($member['trust_relationship'] ?? '')),
                    ...$classification,
                ];
            }
            $active = 0;
            $accountBad = 0;
            foreach (self::rows($row['service_accounts'] ?? []) as $account) {
                $serviceAccountsTotal++;
                $classification = self::classifyServiceAccount((string) ($account['status'] ?? ''));
                $states[] = $classification['state'];
                if ($classification['state'] === 'ok') {
                    $active++;
                } else {
                    $accountBad++;
                    if (self::stateRank($classification['state']) >= self::stateRank('warning')) $serviceAccountsUnhealthy++;
                }
            }
            $domains[] = ['dns_name' => (string) ($row['dns_name'] ?? ''), 'netbios_name' => (string) ($row['netbios_name'] ?? ''), 'domain_type' => (string) ($row['domain_type'] ?? ''), 'member_links_total' => $domainTotal, 'member_links_unhealthy' => $domainBad, 'service_accounts_active' => $active, 'service_accounts_unhealthy' => $accountBad];
        }

        return [$domains, $members, [
            'total' => $total,
            'unhealthy' => $unhealthy,
            'service_accounts_total' => $serviceAccountsTotal,
            'service_accounts_unhealthy' => $serviceAccountsUnhealthy,
            'state' => $rows === [] ? 'incomplete' : self::worstState($states),
        ]];
    }

    /** @param list<array<string,mixed>> $rows @return array{rows:list<array<string,mixed>>,unhealthy:int,state:string,reason_code:string} */
    private static function gateways(array $rows, array $previousRows = []): array
    {
        $result = [];
        $unhealthy = 0;
        $states = [];
        foreach ($rows as $row) {
            $details = is_array($row['details'] ?? null) ? $row['details'] : [];
            $status = self::status((string) ($row['status'] ?? ''));
            $classification = self::classifyGateway($status);
            $persisted = count(array_filter($previousRows, static fn (array $previous): bool =>
                strcasecmp((string) ($previous['name'] ?? ''), (string) ($row['name'] ?? '')) === 0
                && self::status((string) ($previous['status'] ?? '')) === $status
                && ! self::healthy($status)
            )) > 0;
            if (! $persisted && self::stateRank($classification['state']) >= self::stateRank('warning')) {
                $classification = self::classification('info', 'gateway_health_transient', 'observation');
            }
            $states[] = $classification['state'];
            if (self::stateRank($classification['state']) >= self::stateRank('warning')) $unhealthy++;
            $result[] = [
                'name' => (string) ($row['name'] ?? ''), 'type' => (string) ($details['type'] ?? ''),
                'status' => $status, 'version' => (string) ($details['version'] ?? ''),
                'active_connections' => (int) ($row['active_connection_count'] ?? 0),
                ...$classification,
            ];
        }

        return [
            'rows' => $result,
            'unhealthy' => $unhealthy,
            'state' => $rows === [] ? 'ok' : self::worstState($states),
            'reason_code' => $rows === [] ? 'no_standalone_gateways_configured' : ($unhealthy > 0 ? 'gateway_health_degraded' : 'gateways_healthy'),
        ];
    }

    /** @param list<string> $failures @return array{0:array<string,int>,1:list<array<string,mixed>>,2:array<string,true>,3:bool} */
    private function sessions(ApiSession $session, int $pageSize, int $maxPages, array &$failures): array
    {
        $totals = ['total' => 0, 'connected' => 0, 'disconnected' => 0, 'other' => 0];
        $protocolCounts = [];
        $machines = [];
        $truncated = false;
        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $rows = self::rows($this->trackedGet($session, "rest/inventory/v1/sessions?page={$page}&size={$pageSize}", true));
                $this->sessionPages++;
                $this->sessionRows += count($rows);
            } catch (HorizonFailure) {
                $failures[] = 'sessions';
                break;
            }
            foreach ($rows as $row) {
                $totals['total']++;
                $state = self::status((string) ($row['session_state'] ?? ''));
                if ($state === 'CONNECTED') $totals['connected']++;
                elseif ($state === 'DISCONNECTED') $totals['disconnected']++;
                else $totals['other']++;
                $machineId = trim((string) ($row['machine_id'] ?? ''));
                if ($machineId !== '') $machines[$machineId] = true;
                $protocol = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($row['session_protocol'] ?? '')));
                if ($protocol !== '') $protocolCounts[$protocol] = ($protocolCounts[$protocol] ?? 0) + 1;
            }
            if (count($rows) < $pageSize) break;
            if ($page === $maxPages) $truncated = true;
        }
        $protocols = [];
        foreach ($protocolCounts as $protocol => $count) $protocols[] = ['protocol' => substr($protocol, 0, 32), 'sessions' => $count];

        return [$totals, $protocols, $machines, $truncated];
    }

    /** @param list<array<string,mixed>> $rows @return array{0:list<array<string,mixed>>,1:array<string,int>} */
    private static function pools(array $rows): array
    {
        $pools = [];
        $byId = [];
        foreach ($rows as $row) {
            $source = self::status((string) ($row['source'] ?? ''));
            if (! in_array($source, ['INSTANT_CLONE', 'LINKED_CLONE', 'VIEW_COMPOSER'], true)) continue;
            $pool = ['id' => (string) ($row['id'] ?? ''), 'name' => (string) ($row['name'] ?? ''), 'display_name' => (string) ($row['display_name'] ?? ''), 'source' => $source, 'clone_type' => $source, 'enabled' => self::boolean($row['enabled'] ?? true, true) ? 1 : 0, 'machines_total' => 0, 'machines_with_sessions' => 0, 'spare_total' => 0, 'spare_ready' => 0, 'spare_unready' => 0, 'spare_maintenance' => 0, 'spare_pending' => 0, 'spare_faulted' => 0, 'spare_unrecognized' => 0, 'issue_machines' => 0, '_states' => []];
            if ($pool['id'] !== '') $byId[$pool['id']] = count($pools);
            $pools[] = $pool;
        }

        return [$pools, $byId];
    }

    /** @param list<array<string,mixed>> $pools @param array<string,int> $poolById @param array<string,true> $active @param list<string> $failures @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:list<array<string,mixed>>,3:list<array<string,mixed>>,4:bool,5:bool,6:bool} */
    private function machines(ApiSession $session, array $pools, array $poolById, array $active, int $pageSize, int $maxPages, array &$failures, int $detailLimit, int $issueLimit, string $collectedUtc, array $previousMachines = []): array
    {
        $truncated = false;
        $detailsTruncated = false;
        $issuesTruncated = false;
        $details = [];
        $issues = [];
        $previousById = [];
        foreach ($previousMachines as $previousMachine) {
            if (! is_array($previousMachine)) continue;
            $id = (string) ($previousMachine['id'] ?? '');
            if ($id !== '') $previousById[$id] = $previousMachine;
        }
        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $rows = self::rows($this->trackedGet($session, "rest/inventory/v1/machines?page={$page}&size={$pageSize}", true));
                $this->machinePages++;
                $this->machineRows += count($rows);
            } catch (HorizonFailure) {
                $failures[] = 'machines';
                break;
            }
            foreach ($rows as $row) {
                $poolId = (string) ($row['desktop_pool_id'] ?? '');
                if (! isset($poolById[$poolId])) continue;
                $index = $poolById[$poolId];
                $pools[$index]['machines_total']++;
                $state = self::status((string) ($row['state'] ?? 'UNKNOWN')) ?: 'UNKNOWN';
                $pools[$index]['_states'][$state] = ($pools[$index]['_states'][$state] ?? 0) + 1;
                $machineId = (string) ($row['id'] ?? '');
                $hasSession = $machineId !== '' && isset($active[$machineId]);
                $managed = is_array($row['managed_machine_data'] ?? null) ? $row['managed_machine_data'] : [];
                $maintenance = self::boolean($managed['in_maintenance_mode'] ?? false, false) || $state === 'MAINTENANCE';
                $previousMachine = $previousById[$machineId] ?? [];
                $sameState = self::status((string) ($previousMachine['state'] ?? '')) === $state
                    && (int) ($previousMachine['maintenance'] ?? 0) === ($maintenance ? 1 : 0);
                $stateFirstSeen = $sameState
                    ? (string) ($previousMachine['state_first_seen_utc'] ?? $previousMachine['collected_utc'] ?? $collectedUtc)
                    : $collectedUtc;
                $stateAge = max(0, (int) strtotime($collectedUtc) - (int) strtotime($stateFirstSeen));
                $machineClassification = self::classifyMachineState($state, $hasSession, $maintenance, $stateAge >= 1800 ? 7 : 1);
                // The taxonomy decides this, not the display severity, so a row's
                // state can never disagree with the aggregate counts.
                $isIssue = (bool) $machineClassification['issue'];
                $detail = [
                    'id' => self::boundedText($machineId, 128, 'unknown'),
                    'name' => self::boundedText((string) ($row['name'] ?? $machineId), 128, 'unknown'),
                    'pool_id' => self::boundedText($poolId, 128, 'unknown'),
                    'pool' => self::boundedText((string) ($pools[$index]['name'] ?? ''), 128, 'unknown'),
                    'pool_display_name' => self::boundedText((string) ($pools[$index]['display_name'] ?? $pools[$index]['name'] ?? ''), 128, 'unknown'),
                    'clone_type' => (string) ($pools[$index]['clone_type'] ?? ''),
                    'state' => $state,
                    'maintenance' => $maintenance ? 1 : 0,
                    'has_session' => $hasSession ? 1 : 0,
                    'issue' => $isIssue ? 1 : 0,
                    'issue_reason' => $machineClassification['reason_code'],
                    'severity' => $machineClassification['state'],
                    'impact' => $machineClassification['impact'],
                    'collected_utc' => $collectedUtc,
                    'state_first_seen_utc' => $stateFirstSeen,
                ];
                if (count($details) >= $detailLimit) {
                    $detailsTruncated = true;
                } else {
                    $details[] = $detail;
                }
                if ($isIssue) {
                    $pools[$index]['issue_machines']++;
                    if (count($issues) >= $issueLimit) {
                        $issuesTruncated = true;
                    } else {
                        $issues[] = $detail;
                    }
                }
                if ($hasSession) {
                    $pools[$index]['machines_with_sessions']++;
                    continue;
                }
                if (! $machineClassification['recognized']) {
                    // Checked before the placement shortcut: an unrecognized state
                    // is counted so the totals reconcile, but is never scored, so
                    // it cannot manufacture a capacity fault.
                    $pools[$index]['spare_total']++;
                    $pools[$index]['spare_unrecognized']++;
                    $pools[$index]['spare_unready']++;
                    continue;
                }
                if ($machineClassification['placement'] === 'none') {
                    // Recognized as not participating in placement, and not a spare.
                    continue;
                }
                $pools[$index]['spare_total']++;
                switch ($machineClassification['placement']) {
                    case 'ready':
                        $pools[$index]['spare_ready']++;
                        break;
                    case 'pending':
                        $pools[$index]['spare_pending']++;
                        $pools[$index]['spare_unready']++;
                        break;
                    case 'held':
                        $pools[$index]['spare_maintenance']++;
                        $pools[$index]['spare_unready']++;
                        break;
                    default:
                        $pools[$index]['spare_faulted']++;
                        $pools[$index]['spare_unready']++;
                        break;
                }
            }
            if (count($rows) < $pageSize) break;
            if ($page === $maxPages) $truncated = true;
        }
        // Publish how the taxonomy treats each reported state alongside the counts.
        // This is what makes a misclassified or unrecognized state visible on the
        // page without filesystem access to this collector's state directory. It
        // carries state strings and counts only, never machine identifiers.
        $taxonomy = self::machineStateTaxonomy();
        $states = [];
        foreach ($pools as $pool) {
            foreach ($pool['_states'] as $state => $count) {
                $entry = $taxonomy[$state] ?? null;
                $states[] = [
                    'pool' => $pool['name'],
                    'clone_type' => $pool['clone_type'],
                    'machine_state' => $state,
                    'count' => $count,
                    'placement' => $entry['placement'] ?? 'none',
                    'severity' => $entry['health'] ?? 'incomplete',
                    'issue' => ($entry['issue'] ?? false) ? 1 : 0,
                    'recognized' => $entry === null ? 0 : 1,
                ];
            }
        }

        usort($issues, static fn (array $left, array $right): int => strcasecmp(
            (string) ($left['pool_display_name'] ?? $left['pool'] ?? ''),
            (string) ($right['pool_display_name'] ?? $right['pool'] ?? '')
        ) ?: strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? '')));
        usort($details, static fn (array $left, array $right): int =>
            ((int) ($right['issue'] ?? 0) <=> (int) ($left['issue'] ?? 0))
            ?: strcasecmp(
                (string) ($left['pool_display_name'] ?? $left['pool'] ?? ''),
                (string) ($right['pool_display_name'] ?? $right['pool'] ?? '')
            )
            ?: strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''))
        );

        return [$pools, $states, $details, $issues, $detailsTruncated, $issuesTruncated, $truncated];
    }

    /** @param list<array<string,mixed>> $pools @param array<string,mixed> $config @return array{0:list<array<string,mixed>>,1:array<string,int>} */
    private static function scorePools(array $pools, bool $incomplete, array $config): array
    {
        $totals = ['pools_total' => count($pools), 'pools_healthy' => 0, 'pools_informational' => 0, 'pools_warning' => 0, 'pools_critical' => 0, 'pools_disabled' => 0, 'pools_incomplete' => 0, 'spare_total' => 0, 'spare_ready' => 0, 'spare_unready' => 0, 'spare_pending' => 0, 'spare_held' => 0, 'spare_faulted' => 0, 'spare_unrecognized' => 0, 'issue_machines' => 0];
        foreach ($pools as &$pool) {
            $spares = (int) $pool['spare_total'];
            $unready = (int) $pool['spare_unready'];
            $percent = $spares > 0 ? round(($unready / $spares) * 100, 1) : 0.0;
            $ready = (int) $pool['spare_ready'];
            $faulted = (int) ($pool['spare_faulted'] ?? 0);
            $pending = (int) ($pool['spare_pending'] ?? 0);
            $held = (int) ($pool['spare_maintenance'] ?? 0);

            // Order matters. Failure outranks exhaustion, and exhaustion is not a
            // failure: a fully utilised pool with nothing broken is at capacity,
            // not degraded. Benign pending and intentionally held spares never
            // score worse than informational on their own.
            if ((int) $pool['enabled'] === 0) [$state, $reason] = ['disabled', 'pool_disabled'];
            elseif ($incomplete) [$state, $reason] = ['incomplete', 'inventory_incomplete'];
            elseif ($ready === 0 && $faulted > 0) [$state, $reason] = ['critical', 'ready_spares_faulted'];
            elseif ($faulted >= 2) [$state, $reason] = ['warning', 'multiple_faulted_spares'];
            elseif ($faulted === 1) [$state, $reason] = ['info', 'faulted_spare_capacity_remains'];
            elseif ((int) $pool['machines_total'] > 0 && $spares === 0) [$state, $reason] = ['warning', 'no_placement_capacity'];
            elseif ($ready === 0 && $pending > 0) [$state, $reason] = ['info', 'spares_pending_only'];
            elseif ($ready === 0 && $held > 0) [$state, $reason] = ['info', 'spares_held_only'];
            elseif ($ready === 0 && $spares > 0) [$state, $reason] = ['incomplete', 'spare_readiness_undetermined'];
            else [$state, $reason] = ['ok', 'within_threshold'];
            $pool['health_state'] = $state;
            $pool['health_reason'] = $reason;
            $pool['spare_unready_percent'] = $percent;
            $pool['placement_headroom_percent'] = (int) ($pool['machines_total'] ?? 0) > 0
                ? round(((int) ($pool['spare_ready'] ?? 0) / (int) $pool['machines_total']) * 100, 1)
                : 0.0;
            unset($pool['_states']);
            $totals['spare_total'] += $spares;
            $totals['spare_ready'] += $ready;
            $totals['spare_unready'] += $unready;
            $totals['spare_pending'] += $pending;
            $totals['spare_held'] += $held;
            $totals['spare_faulted'] += $faulted;
            $totals['spare_unrecognized'] += (int) ($pool['spare_unrecognized'] ?? 0);
            $totals['issue_machines'] += (int) ($pool['issue_machines'] ?? 0);
            $key = ['ok' => 'pools_healthy', 'info' => 'pools_informational', 'warning' => 'pools_warning', 'critical' => 'pools_critical', 'disabled' => 'pools_disabled', 'incomplete' => 'pools_incomplete'][$state];
            $totals[$key]++;
        }
        unset($pool);
        $totals['state'] = $totals['pools_critical'] > 0 ? 'critical'
            : ($totals['pools_warning'] > 0 ? 'warning'
                : ($totals['pools_incomplete'] > 0 ? 'incomplete'
                    : ($totals['pools_informational'] > 0 ? 'info'
                        : ($totals['pools_total'] > 0 && $totals['pools_disabled'] === $totals['pools_total'] ? 'disabled' : 'ok'))));
        // Report the driver, not just the severity. Faulted capacity and exhausted
        // capacity are different operator problems and must not share a code.
        if ($totals['state'] === 'ok') $totals['reason_code'] = 'pool_capacity_healthy';
        elseif ($totals['state'] === 'disabled') $totals['reason_code'] = 'pool_capacity_healthy';
        elseif ($totals['spare_faulted'] > 0) $totals['reason_code'] = 'pool_capacity_faulted';
        elseif ($totals['spare_total'] === 0) $totals['reason_code'] = 'pool_capacity_exhausted';
        elseif ($totals['state'] === 'incomplete') $totals['reason_code'] = 'pool_inventory_incomplete';
        elseif ($totals['state'] === 'warning') $totals['reason_code'] = 'pool_capacity_degraded';
        else $totals['reason_code'] = 'pool_capacity_observation';

        return [$pools, $totals];
    }

    /** @param array<mixed> $health @param array<mixed> $system @return array<string,int> */
    private static function vendorMetrics(array $health, array $system): array
    {
        $totals = ['warnings_total' => 0, 'errors_total' => 0, 'unknown_total' => 0, 'problem_machines_total' => 0];
        $walk = static function (mixed $value, ?string $key = null) use (&$walk, &$totals): void {
            if (is_array($value)) {
                foreach ($value as $childKey => $child) $walk($child, is_string($childKey) ? strtolower($childKey) : $key);
                return;
            }
            if (is_numeric($value) && $key !== null) {
                $number = max(0, (int) $value);
                if (str_contains($key, 'warning')) $totals['warnings_total'] += $number;
                elseif (str_contains($key, 'error')) $totals['errors_total'] += $number;
                elseif (str_contains($key, 'unknown')) $totals['unknown_total'] += $number;
                elseif (str_contains($key, 'problem') && str_contains($key, 'machine')) $totals['problem_machines_total'] += $number;
            }
        };
        $walk($health);
        $walk($system);

        return $totals;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param list<string> $failures
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private static function buildHealthEvidence(array $snapshot, array $failures): array
    {
        $conditions = [];
        $observations = [];
        foreach ($snapshot['horizon_pod_members'] ?? [] as $member) {
            if (! is_array($member) || (int) ($member['enabled'] ?? 1) !== 1) continue;
            $state = strtolower((string) ($member['health_state'] ?? 'incomplete'));
            if (self::stateRank($state) >= self::stateRank('incomplete')) {
                $conditions[] = self::condition(
                    'server',
                    $state,
                    (string) ($member['reason_code'] ?? 'connection_server_health_degraded'),
                    (string) ($member['name'] ?? 'connection-server'),
                    'Connection Server health or redundancy is degraded.'
                );
            }
            foreach (is_array($member['service_observations'] ?? null) ? $member['service_observations'] : [] as $service) {
                $observations[] = [
                    'scope' => 'server',
                    'state' => 'info',
                    'reason_code' => (string) ($service['reason_code'] ?? 'service_observation'),
                    'object_ref' => (string) ($member['name'] ?? ''),
                    'component' => (string) ($service['name'] ?? 'unknown'),
                    'evidence' => 'status=' . (string) ($service['status'] ?? 'UNKNOWN'),
                ];
            }
        }
        foreach ($snapshot['horizon_directory_member_status'] ?? [] as $member) {
            if (! is_array($member)) continue;
            $state = strtolower((string) ($member['state'] ?? 'incomplete'));
            if (self::stateRank($state) >= self::stateRank('incomplete')) {
                $conditions[] = self::condition(
                    'dependency',
                    $state,
                    (string) ($member['reason_code'] ?? 'domain_access_degraded'),
                    (string) (($member['domain'] ?? '') . '/' . ($member['member'] ?? '')),
                    'Horizon cannot fully access the configured directory dependency.'
                );
            }
        }
        foreach ($snapshot['horizon_directory_domains'] ?? [] as $domain) {
            if (! is_array($domain) || (int) ($domain['service_accounts_unhealthy'] ?? 0) === 0) continue;
            $conditions[] = self::condition(
                'dependency',
                'critical',
                'service_account_unhealthy',
                (string) ($domain['dns_name'] ?? $domain['netbios_name'] ?? 'directory'),
                (int) ($domain['service_accounts_unhealthy'] ?? 0) . ' Horizon service account(s) are unhealthy.'
            );
        }
        foreach ($snapshot['horizon_configuration_replications'] ?? [] as $replication) {
            if (! is_array($replication) || (string) ($replication['state'] ?? '') !== 'info') continue;
            $observations[] = [
                'scope' => 'pod',
                'state' => 'info',
                'reason_code' => (string) ($replication['reason_code'] ?? 'replication_observation'),
                'object_ref' => (string) (($replication['source'] ?? '') . '/' . ($replication['target'] ?? '')),
                'component' => 'configuration_replication',
                'evidence' => 'status=' . (string) ($replication['status'] ?? 'UNKNOWN'),
            ];
        }
        foreach ($snapshot['horizon_gateways'] ?? [] as $gateway) {
            if (! is_array($gateway)) continue;
            $state = strtolower((string) ($gateway['state'] ?? 'incomplete'));
            if (self::stateRank($state) >= self::stateRank('incomplete')) {
                $conditions[] = self::condition(
                    'pod',
                    $state,
                    (string) ($gateway['reason_code'] ?? 'gateway_health_degraded'),
                    (string) ($gateway['name'] ?? 'gateway'),
                    'Gateway connectivity is degraded.'
                );
            } elseif ($state === 'info') {
                $observations[] = [
                    'scope' => 'pod',
                    'state' => 'info',
                    'reason_code' => (string) ($gateway['reason_code'] ?? 'gateway_observation'),
                    'object_ref' => (string) ($gateway['name'] ?? 'gateway'),
                    'component' => 'standalone_gateway',
                    'evidence' => 'status=' . (string) ($gateway['status'] ?? 'UNKNOWN'),
                ];
            }
        }
        foreach ($snapshot['horizon_pools'] ?? [] as $pool) {
            if (! is_array($pool)) continue;
            $state = strtolower((string) ($pool['health_state'] ?? 'incomplete'));
            $item = [
                'scope' => 'pool',
                'state' => $state,
                'reason_code' => (string) ($pool['health_reason'] ?? 'pool_capacity_degraded'),
                'object_ref' => (string) ($pool['id'] ?? $pool['name'] ?? 'pool'),
                'impact' => 'capacity',
                'evidence' => (int) ($pool['spare_ready'] ?? 0) . ' ready; ' . (int) ($pool['spare_unready'] ?? 0) . ' unavailable',
            ];
            if ($state === 'info') $observations[] = $item;
            elseif (self::stateRank($state) >= self::stateRank('incomplete')) $conditions[] = $item;
        }
        foreach ($snapshot['horizon_pool_machines'] ?? [] as $machine) {
            if (! is_array($machine) || (string) ($machine['issue_reason'] ?? '') !== 'machine_transitional_too_long') continue;
            $conditions[] = self::condition(
                'machine',
                'warning',
                'machine_transitional_too_long',
                (string) ($machine['id'] ?? $machine['name'] ?? 'machine'),
                'Machine has remained in ' . (string) ($machine['state'] ?? 'a transitional state') . ' for at least 30 minutes.'
            );
        }
        if ($failures !== []) {
            $conditions[] = self::condition(
                'collector',
                'incomplete',
                'collector_endpoint_partial',
                'central-collector',
                'One or more optional authoritative inventories could not be collected.'
            );
        }

        // Identical informational service observations are grouped pod-wide.
        $grouped = [];
        foreach ($observations as $observation) {
            $key = ($observation['scope'] ?? '') . '|' . ($observation['reason_code'] ?? '') . '|' . ($observation['component'] ?? '');
            $object = (string) ($observation['object_ref'] ?? '');
            if (! isset($grouped[$key])) {
                $grouped[$key] = $observation + ['objects' => []];
                $grouped[$key]['object_ref'] = 'pod';
            }
            if ($object !== '' && ! in_array($object, $grouped[$key]['objects'], true)) $grouped[$key]['objects'][] = $object;
        }
        foreach ($grouped as &$observation) {
            sort($observation['objects'], SORT_NATURAL | SORT_FLAG_CASE);
            $observation['object_count'] = count($observation['objects']);
        }
        unset($observation);

        return [array_slice($conditions, 0, 200), array_values($grouped)];
    }

    /** @return array<string,mixed> */
    private static function condition(string $scope, string $state, string $reasonCode, string $objectRef, string $evidence): array
    {
        return [
            'scope' => $scope,
            'state' => $state,
            'reason_code' => $reasonCode,
            'object_ref' => $objectRef,
            'impact' => $scope,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param list<array<string,mixed>> $current
     * @param list<array<string,mixed>> $previous
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private static function mergeConditionHistory(array $current, array $previous): array
    {
        $now = gmdate('c');
        $priorById = [];
        foreach ($previous as $item) {
            if (is_array($item) && isset($item['condition_id'])) $priorById[(string) $item['condition_id']] = $item;
        }
        $activeIds = [];
        foreach ($current as &$item) {
            $identity = strtolower((string) ($item['scope'] ?? '') . '|' . (string) ($item['reason_code'] ?? '') . '|' . (string) ($item['object_ref'] ?? ''));
            $id = substr(hash('sha256', $identity), 0, 24);
            $prior = $priorById[$id] ?? [];
            $item['condition_id'] = $id;
            $item['severity'] = (string) ($item['state'] ?? 'incomplete');
            $item['first_seen_utc'] = (string) ($prior['first_seen_utc'] ?? $now);
            $item['last_seen_utc'] = $now;
            $item['consecutive_samples'] = max(1, (int) ($prior['consecutive_samples'] ?? 0) + 1);
            unset($item['resolved_utc']);
            $activeIds[$id] = true;
            $priorById[$id] = $item;
        }
        unset($item);
        foreach ($priorById as $id => &$item) {
            if (! isset($activeIds[$id]) && ! isset($item['resolved_utc'])) $item['resolved_utc'] = $now;
        }
        unset($item);
        $history = array_values($priorById);
        usort($history, static fn (array $left, array $right): int => strcmp((string) ($right['last_seen_utc'] ?? ''), (string) ($left['last_seen_utc'] ?? '')));

        return [$current, array_slice($history, 0, 200)];
    }

    /** @param array<mixed> $value @return list<array<string,mixed>> */
    public static function rows(array $value): array
    {
        foreach (['items', 'data', 'results'] as $key) {
            if (isset($value[$key]) && is_array($value[$key])) $value = $value[$key];
        }
        if (! array_is_list($value)) return [];

        return array_values(array_filter($value, 'is_array'));
    }

    /** @param list<array<string,mixed>> $members */
    private static function findMember(array $members, string $id, string $name): int
    {
        foreach ($members as $index => $member) {
            if (($id !== '' && strcasecmp((string) ($member['id'] ?? ''), $id) === 0) || ($name !== '' && strcasecmp((string) ($member['name'] ?? ''), $name) === 0)) return $index;
        }

        return -1;
    }

    private static function status(string $value): string
    {
        return strtoupper(trim($value));
    }

    private static function healthy(string $status): bool
    {
        return in_array(self::status($status), ['OK', 'UP', 'RUNNING', 'GREEN', 'ONLINE', 'ACCESSIBLE', 'FULLY_ACCESSIBLE'], true);
    }

    private static function boolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }

    private static function normalizeDiscoveredHost(string $name, string $suffix): ?string
    {
        $host = strtolower(rtrim(trim($name), '.'));
        $suffix = strtolower(rtrim(trim($suffix), '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) return null;
        if (! str_contains($host, '.')) $host .= '.' . $suffix;
        if (! self::validDnsName($host) || ! str_ends_with($host, '.' . $suffix)) return null;

        return $host;
    }

    private static function validDnsName(string $value): bool
    {
        return strlen($value) <= 253 && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $value) === 1;
    }

    private function resetObservability(): void
    {
        $this->requestCount = 0;
        $this->pageCount = 0;
        $this->sessionPages = 0;
        $this->machinePages = 0;
        $this->sessionRows = 0;
        $this->machineRows = 0;
    }

    /** @return array<mixed> */
    private function trackedGet(ApiSession $session, string $path, bool $page = false): array
    {
        $this->requestCount++;
        if ($page) $this->pageCount++;

        return $session->get($path);
    }

    private static function boundedText(string $value, int $limit, string $fallback): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
        if ($value === '') return $fallback;

        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }
}
