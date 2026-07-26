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
            max(1, min(64, (int) ($config['unhealthy_service_limit'] ?? 16)))
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
        $gateways = self::gateways(self::rows($this->optional($session, 'rest/monitor/v3/gateways', 'gateway_monitor', $failures)));

        $pageSize = max(1, min(1000, (int) ($config['page_size'] ?? 500)));
        $maxPages = max(1, min(100, (int) ($config['max_pages'] ?? 20)));
        [$sessionTotals, $protocols, $activeMachineIds, $sessionsTruncated] = $this->sessions($session, $pageSize, $maxPages, $failures);
        $poolRows = self::rows($this->optional($session, 'rest/inventory/v1/desktop-pools', 'desktop_pools', $failures));
        [$pools, $poolById] = self::pools($poolRows);
        [$pools, $machineStates, $machineIssues, $machineIssuesTruncated, $machinesTruncated] = $this->machines(
            $session,
            $pools,
            $poolById,
            $activeMachineIds,
            $pageSize,
            $maxPages,
            $failures,
            max(1, min(500, (int) ($config['machine_issue_limit'] ?? 100))),
            gmdate('c')
        );
        [$pools, $poolTotals] = self::scorePools($pools, $sessionsTruncated || $machinesTruncated || in_array('sessions', $failures, true) || in_array('machines', $failures, true), $config);

        $state = $failures === [] ? 'ok' : 'partial';
        $healthState = self::healthState($memberTotals['unhealthy'], $memberTotals['replications_unhealthy'], $directoryTotals['unhealthy'], $gateways['unhealthy'], $poolTotals);
        if ($state === 'partial' && $healthState === 'ok') {
            $healthState = 'warning';
        }
        $summary = [
            'state' => $state,
            'health_state' => $healthState,
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
            'machine_issues_total' => (int) ($poolTotals['issue_machines'] ?? count($machineIssues)),
            'machine_issues_truncated' => $machineIssuesTruncated ? 1 : 0,
            'service_details_truncated' => (int) ($memberTotals['service_details_truncated'] ?? 0),
            'source' => 'central',
        ];
        $podName = trim((string) ($environment['local_pod_name'] ?? ''));
        if ($podName === '') {
            $podName = trim((string) ($environment['cluster_name'] ?? ''));
        }
        if ($podName === '') {
            $podName = $identity;
        }

        return [
            'pod_identity' => $identity,
            'discovered_connection_servers' => $discovered,
            'horizon_api_summary' => $summary,
            'horizon_api_session_protocols' => $protocols,
            'horizon_pod_summary' => [
                'state' => $healthState,
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
                'domains_total' => count($domains),
                'member_links_total' => $directoryTotals['total'],
                'member_links_unhealthy' => $directoryTotals['unhealthy'],
            ],
            'horizon_directory_domains' => $domains,
            'horizon_directory_member_status' => $domainMembers,
            'horizon_gateways' => $gateways['rows'],
            'horizon_pools_summary' => $poolTotals,
            'horizon_pools' => $pools,
            'horizon_pool_machine_states' => $machineStates,
            'horizon_pool_machine_issues' => $machineIssues,
        ];
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

    /** @param list<array<string,mixed>> $monitor @param list<array<string,mixed>> $configs @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:array<string,int>} */
    private static function connectionServers(array $monitor, array $configs, string $endpoint, int $serviceLimit): array
    {
        $members = [];
        $replications = [];
        $totals = ['total' => 0, 'unhealthy' => 0, 'services_unhealthy' => 0, 'service_details_truncated' => 0, 'replications_total' => 0, 'replications_unhealthy' => 0, 'certificates_invalid' => 0];
        foreach ($monitor as $row) {
            $name = (string) ($row['name'] ?? '');
            $servicesBad = 0;
            $unhealthyServices = [];
            $unhealthyServicesTruncated = false;
            foreach (self::rows($row['services'] ?? []) as $service) {
                $serviceStatus = self::status((string) ($service['status'] ?? ''));
                if (self::healthy($serviceStatus)) continue;
                $servicesBad++;
                if (count($unhealthyServices) >= $serviceLimit) {
                    $unhealthyServicesTruncated = true;
                    continue;
                }
                $serviceName = (string) ($service['name'] ?? $service['service_name'] ?? $service['display_name'] ?? 'unknown');
                $unhealthyServices[] = [
                    'name' => self::boundedText($serviceName, 96, 'unknown'),
                    'status' => self::boundedText($serviceStatus, 32, 'UNKNOWN'),
                ];
            }
            $replBad = 0;
            foreach (self::rows($row['cs_replications'] ?? []) as $replication) {
                $status = self::status((string) ($replication['status'] ?? ''));
                $replications[] = ['source' => $name, 'target' => (string) ($replication['server_name'] ?? ''), 'status' => $status];
                $totals['replications_total']++;
                if (! self::healthy($status)) {
                    $replBad++;
                    $totals['replications_unhealthy']++;
                }
            }
            $certificate = is_array($row['certificate'] ?? null) ? $row['certificate'] : [];
            $certValid = self::boolean($certificate['valid'] ?? true, true);
            $status = self::status((string) ($row['status'] ?? ''));
            $member = [
                'id' => (string) ($row['id'] ?? ''), 'name' => $name, 'status' => $status,
                'server_type' => 'connection_server', 'local_api_target' => strcasecmp($name, $endpoint) === 0 ? 1 : 0,
                'enabled' => 1, 'gateway_mode' => 'none', 'version' => (string) (($row['details']['version'] ?? '')),
                'connections' => (int) ($row['connection_count'] ?? 0), 'services_unhealthy' => $servicesBad,
                'unhealthy_services' => $unhealthyServices,
                'unhealthy_services_truncated' => $unhealthyServicesTruncated ? 1 : 0,
                'configuration_replications_total' => count(self::rows($row['cs_replications'] ?? [])),
                'configuration_replications_unhealthy' => $replBad, 'certificate_valid' => $certValid ? 1 : 0,
            ];
            $members[] = $member;
            $totals['services_unhealthy'] += $servicesBad;
            if ($unhealthyServicesTruncated) {
                $totals['service_details_truncated'] = 1;
            }
            if (! $certValid) {
                $totals['certificates_invalid']++;
            }
        }
        foreach ($configs as $row) {
            $index = self::findMember($members, (string) ($row['id'] ?? ''), (string) ($row['name'] ?? ''));
            if ($index < 0) {
                $members[] = ['id' => (string) ($row['id'] ?? ''), 'name' => (string) ($row['name'] ?? ''), 'status' => 'UNKNOWN', 'services_unhealthy' => 0, 'unhealthy_services' => [], 'unhealthy_services_truncated' => 0, 'configuration_replications_total' => 0, 'configuration_replications_unhealthy' => 0, 'certificate_valid' => 1, 'connections' => 0];
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
        foreach ($members as $member) {
            if (! self::healthy((string) ($member['status'] ?? '')) || (int) ($member['services_unhealthy'] ?? 0) > 0 || (int) ($member['configuration_replications_unhealthy'] ?? 0) > 0 || (int) ($member['certificate_valid'] ?? 1) === 0) {
                $totals['unhealthy']++;
            }
        }

        return [$members, $replications, $totals];
    }

    /** @param list<array<string,mixed>> $rows @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:array{total:int,unhealthy:int}} */
    private static function domains(array $rows): array
    {
        $domains = [];
        $members = [];
        $total = 0;
        $unhealthy = 0;
        foreach ($rows as $row) {
            $domainName = (string) ($row['dns_name'] ?? $row['netbios_name'] ?? '');
            $domainTotal = 0;
            $domainBad = 0;
            foreach (self::rows($row['connection_servers'] ?? []) as $member) {
                $status = self::status((string) ($member['status'] ?? ''));
                $domainTotal++;
                $total++;
                if (! self::healthy($status)) {
                    $domainBad++;
                    $unhealthy++;
                }
                $members[] = ['domain' => $domainName, 'member' => (string) ($member['name'] ?? ''), 'status' => $status, 'trust_relationship' => self::status((string) ($member['trust_relationship'] ?? ''))];
            }
            $active = 0;
            $accountBad = 0;
            foreach (self::rows($row['service_accounts'] ?? []) as $account) {
                self::status((string) ($account['status'] ?? '')) === 'ACTIVE' ? $active++ : $accountBad++;
            }
            $domains[] = ['dns_name' => (string) ($row['dns_name'] ?? ''), 'netbios_name' => (string) ($row['netbios_name'] ?? ''), 'domain_type' => (string) ($row['domain_type'] ?? ''), 'member_links_total' => $domainTotal, 'member_links_unhealthy' => $domainBad, 'service_accounts_active' => $active, 'service_accounts_unhealthy' => $accountBad];
        }

        return [$domains, $members, ['total' => $total, 'unhealthy' => $unhealthy]];
    }

    /** @param list<array<string,mixed>> $rows @return array{rows:list<array<string,mixed>>,unhealthy:int} */
    private static function gateways(array $rows): array
    {
        $result = [];
        $unhealthy = 0;
        foreach ($rows as $row) {
            $details = is_array($row['details'] ?? null) ? $row['details'] : [];
            $status = self::status((string) ($row['status'] ?? ''));
            if (! self::healthy($status)) $unhealthy++;
            $result[] = ['name' => (string) ($row['name'] ?? ''), 'type' => (string) ($details['type'] ?? ''), 'status' => $status, 'version' => (string) ($details['version'] ?? ''), 'active_connections' => (int) ($row['active_connection_count'] ?? 0)];
        }

        return ['rows' => $result, 'unhealthy' => $unhealthy];
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
            $pool = ['id' => (string) ($row['id'] ?? ''), 'name' => (string) ($row['name'] ?? ''), 'display_name' => (string) ($row['display_name'] ?? ''), 'source' => $source, 'clone_type' => $source, 'enabled' => self::boolean($row['enabled'] ?? true, true) ? 1 : 0, 'machines_total' => 0, 'machines_with_sessions' => 0, 'spare_total' => 0, 'spare_ready' => 0, 'spare_unready' => 0, 'spare_maintenance' => 0, 'issue_machines' => 0, '_states' => []];
            if ($pool['id'] !== '') $byId[$pool['id']] = count($pools);
            $pools[] = $pool;
        }

        return [$pools, $byId];
    }

    /** @param list<array<string,mixed>> $pools @param array<string,int> $poolById @param array<string,true> $active @param list<string> $failures @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:list<array<string,mixed>>,3:bool,4:bool} */
    private function machines(ApiSession $session, array $pools, array $poolById, array $active, int $pageSize, int $maxPages, array &$failures, int $issueLimit, string $collectedUtc): array
    {
        $truncated = false;
        $issuesTruncated = false;
        $issues = [];
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
                $isIssue = $maintenance || self::machineStateIsIssue($state, $hasSession);
                if ($isIssue) {
                    $pools[$index]['issue_machines']++;
                    if (count($issues) >= $issueLimit) {
                        $issuesTruncated = true;
                    } else {
                        $issues[] = [
                            'id' => self::boundedText($machineId, 128, 'unknown'),
                            'name' => self::boundedText((string) ($row['name'] ?? $machineId), 128, 'unknown'),
                            'pool_id' => self::boundedText($poolId, 128, 'unknown'),
                            'pool' => self::boundedText((string) ($pools[$index]['name'] ?? ''), 128, 'unknown'),
                            'pool_display_name' => self::boundedText((string) ($pools[$index]['display_name'] ?? $pools[$index]['name'] ?? ''), 128, 'unknown'),
                            'clone_type' => (string) ($pools[$index]['clone_type'] ?? ''),
                            'state' => $state,
                            'maintenance' => $maintenance ? 1 : 0,
                            'has_session' => $hasSession ? 1 : 0,
                            'issue_reason' => self::machineIssueReason($state, $maintenance),
                            'collected_utc' => $collectedUtc,
                        ];
                    }
                }
                if ($hasSession) {
                    $pools[$index]['machines_with_sessions']++;
                    continue;
                }
                $pools[$index]['spare_total']++;
                if ($maintenance) $pools[$index]['spare_maintenance']++;
                if ($state === 'AVAILABLE' && ! $maintenance) $pools[$index]['spare_ready']++;
                else {
                    $pools[$index]['spare_unready']++;
                }
            }
            if (count($rows) < $pageSize) break;
            if ($page === $maxPages) $truncated = true;
        }
        $states = [];
        foreach ($pools as $pool) {
            foreach ($pool['_states'] as $state => $count) $states[] = ['pool' => $pool['name'], 'clone_type' => $pool['clone_type'], 'machine_state' => $state, 'count' => $count];
        }

        usort($issues, static fn (array $left, array $right): int => strcasecmp(
            (string) ($left['pool_display_name'] ?? $left['pool'] ?? ''),
            (string) ($right['pool_display_name'] ?? $right['pool'] ?? '')
        ) ?: strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? '')));

        return [$pools, $states, $issues, $issuesTruncated, $truncated];
    }

    /** @param list<array<string,mixed>> $pools @param array<string,mixed> $config @return array{0:list<array<string,mixed>>,1:array<string,int>} */
    private static function scorePools(array $pools, bool $incomplete, array $config): array
    {
        $totals = ['pools_total' => count($pools), 'pools_healthy' => 0, 'pools_informational' => 0, 'pools_warning' => 0, 'pools_critical' => 0, 'pools_disabled' => 0, 'pools_incomplete' => 0, 'spare_total' => 0, 'spare_ready' => 0, 'spare_unready' => 0, 'issue_machines' => 0];
        foreach ($pools as &$pool) {
            $spares = (int) $pool['spare_total'];
            $unready = (int) $pool['spare_unready'];
            $percent = $spares > 0 ? round(($unready / $spares) * 100, 1) : 0.0;
            if ((int) $pool['enabled'] === 0) [$state, $reason] = ['disabled', 'pool_disabled'];
            elseif ($incomplete) [$state, $reason] = ['incomplete', 'inventory_incomplete'];
            elseif ((int) $pool['machines_total'] > 0 && $spares === 0) [$state, $reason] = ['critical', 'no_placement_capacity'];
            elseif ($spares > 0 && (int) $pool['spare_ready'] === 0) [$state, $reason] = ['critical', 'no_ready_spares'];
            elseif ($unready >= 2) [$state, $reason] = ['warning', 'multiple_unavailable_spares'];
            elseif ($unready === 1) [$state, $reason] = ['info', 'one_unavailable_capacity_remains'];
            else [$state, $reason] = ['ok', 'within_threshold'];
            $pool['health_state'] = $state;
            $pool['health_reason'] = $reason;
            $pool['spare_unready_percent'] = $percent;
            $pool['placement_headroom_percent'] = (int) ($pool['machines_total'] ?? 0) > 0
                ? round(((int) ($pool['spare_ready'] ?? 0) / (int) $pool['machines_total']) * 100, 1)
                : 0.0;
            unset($pool['_states']);
            $totals['spare_total'] += $spares;
            $totals['spare_ready'] += (int) $pool['spare_ready'];
            $totals['spare_unready'] += $unready;
            $totals['issue_machines'] += (int) ($pool['issue_machines'] ?? 0);
            $key = ['ok' => 'pools_healthy', 'info' => 'pools_informational', 'warning' => 'pools_warning', 'critical' => 'pools_critical', 'disabled' => 'pools_disabled', 'incomplete' => 'pools_incomplete'][$state];
            $totals[$key]++;
        }
        unset($pool);

        return [$pools, $totals];
    }

    /** @param array<string,int> $pools */
    private static function healthState(int $members, int $replications, int $domains, int $gateways, array $pools): string
    {
        if ($members > 0 || $replications > 0 || (int) $pools['pools_critical'] > 0) return 'critical';
        if ($domains > 0 || $gateways > 0 || (int) $pools['pools_warning'] > 0 || (int) $pools['pools_incomplete'] > 0) return 'warning';

        return 'ok';
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

    private static function machineIssueReason(string $state, bool $maintenance): string
    {
        if ($maintenance) return 'maintenance_mode';
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', trim($state)));
        $normalized = trim($normalized, '_');

        return match ($normalized) {
            'agent_unreachable' => 'agent_unreachable',
            'provisioning_error' => 'provisioning_error',
            'error' => 'machine_state_error',
            'disabled' => 'machine_disabled',
            '' => 'machine_state_unknown',
            default => 'machine_state_' . substr($normalized, 0, 48),
        };
    }

    private static function machineStateIsIssue(string $state, bool $hasSession): bool
    {
        $normalized = self::status($state);
        $knownIssues = [
            'AGENT_UNREACHABLE',
            'ALREADY_USED',
            'CUSTOMIZING_ERROR',
            'DISABLED',
            'ERROR',
            'PROVISIONING_ERROR',
            'UNAVAILABLE',
            'UNKNOWN',
        ];
        if (in_array($normalized, $knownIssues, true)) {
            return true;
        }

        return ! $hasSession && $normalized !== 'AVAILABLE';
    }

    private static function boundedText(string $value, int $limit, string $fallback): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
        if ($value === '') return $fallback;

        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }
}
