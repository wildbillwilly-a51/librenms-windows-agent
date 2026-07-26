<?php

declare(strict_types=1);

use WindowsAgentOverlay\Horizon\ApiSession;
use WindowsAgentOverlay\Horizon\HorizonCollectionCoordinator;
use WindowsAgentOverlay\Horizon\HorizonCoordination;
use WindowsAgentOverlay\Horizon\HorizonFailure;
use WindowsAgentOverlay\Horizon\HorizonPodDiscovery;
use WindowsAgentOverlay\Horizon\HorizonTriggerProducer;
use WindowsAgentOverlay\Horizon\PodCollector;

require_once dirname(__DIR__, 2) . '/librenms-overlay/tools/horizon-central-lib.php';
require_once dirname(__DIR__, 2) . '/librenms-overlay/tools/horizon-central-collector.php';
require_once dirname(__DIR__, 2) . '/librenms-overlay/tools/horizon-central-config.php';

final class FakeHorizonSession implements ApiSession
{
    /** @param array<string,array<mixed>|HorizonFailure> $responses */
    public function __construct(private readonly array $responses)
    {
    }

    public function get(string $path): array
    {
        $response = $this->responses[$path] ?? new HorizonFailure('fixture_missing');
        if ($response instanceof HorizonFailure) throw $response;

        return $response;
    }

    public function close(): void
    {
    }
}

final class FakeHorizonCoordination implements HorizonCoordination
{
    /** @var array<int,array{site:string,display_device:string,device_id:int}> */
    public array $registrations = [];
    /** @var list<string> */
    public array $pending = [];
    /** @var array<string,string> */
    public array $locks = [];
    /** @var array<string,bool> */
    public array $cooldowns = [];
    public bool $failReads = false;

    public function register(array $registration): void
    {
        $this->registrations[$registration['device_id']] = $registration;
    }

    public function unregister(string $site, int $deviceId, string $hostname): void
    {
        unset($this->registrations[$deviceId]);
    }

    public function registrationForDevice(int $deviceId, string $hostname): ?array
    {
        if ($this->failReads) throw new RuntimeException('redis unavailable');

        return $this->registrations[$deviceId] ?? null;
    }

    public function emit(string $site): bool
    {
        if (in_array($site, $this->pending, true)) return false;
        $this->pending[] = $site;

        return true;
    }

    public function consume(): ?string
    {
        return array_shift($this->pending);
    }

    public function acquire(string $site, int $ttlSeconds): ?string
    {
        if (isset($this->locks[$site])) return null;
        $this->locks[$site] = 'fixture-token';

        return 'fixture-token';
    }

    public function release(string $site, string $token): void
    {
        if (($this->locks[$site] ?? null) === $token) unset($this->locks[$site]);
    }

    public function cooldownActive(string $site): bool
    {
        return $this->cooldowns[$site] ?? false;
    }

    public function markCooldown(string $site, int $seconds): void
    {
        $this->cooldowns[$site] = true;
    }
}

function expect(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

/** @return array<string,mixed> */
function testConfig(): array
{
    return [
        'site' => 'abc', 'dns_suffix' => 'example.test', 'display_device' => 'abc-vcs2.example.test',
        'enabled' => true, 'pool_warning_percent' => 50, 'pool_critical_percent' => 90,
        'pool_minimum_spares' => 2, 'machine_detail_limit' => 1000, 'machine_issue_limit' => 100, 'page_size' => 100, 'max_pages' => 2,
    ];
}

/** @return array<string,array<mixed>|HorizonFailure> */
function successfulResponses(string $identity = 'ABC Pod'): array
{
    $machines = [];
    $machines[] = ['id' => 'a1', 'name' => 'abc-desktop-001', 'desktop_pool_id' => 'pool-a', 'state' => 'AVAILABLE'];
    $machines[] = ['id' => 'a2', 'name' => 'abc-desktop-002', 'desktop_pool_id' => 'pool-a', 'state' => 'ERROR'];
    $machines[] = ['id' => 'b1', 'desktop_pool_id' => 'pool-b', 'state' => 'AVAILABLE'];
    for ($i = 2; $i <= 10; $i++) $machines[] = ['id' => 'b' . $i, 'desktop_pool_id' => 'pool-b', 'state' => 'ERROR'];

    return [
        'rest/config/v1/environment-properties' => ['local_pod_name' => $identity, 'cluster_name' => 'ABC Cluster'],
        'rest/monitor/v3/connection-servers' => [
            ['id' => 'cs1', 'name' => 'abc-vcs1', 'status' => 'OK', 'connection_count' => 3, 'details' => ['version' => '8.12'], 'services' => [['status' => 'OK']], 'cs_replications' => [['server_name' => 'abc-vcs2', 'status' => 'OK']], 'certificate' => ['valid' => true]],
            ['id' => 'cs2', 'name' => 'abc-vcs2', 'status' => 'OK', 'connection_count' => 2, 'services' => [], 'cs_replications' => [['server_name' => 'abc-vcs1', 'status' => 'OK']]],
            ['id' => 'cs3', 'name' => 'abc-vcs3', 'status' => 'OK', 'connection_count' => 0, 'services' => [], 'cs_replications' => []],
        ],
        'rest/config/v2/connection-servers' => [
            ['id' => 'cs1', 'name' => 'abc-vcs1', 'enabled' => true, 'local_connection_server' => true, 'bypass_tunnel' => true, 'bypass_pcoip_gateway' => true, 'bypass_app_blast_gateway' => true],
            ['id' => 'cs2', 'name' => 'abc-vcs2', 'enabled' => true],
            ['id' => 'cs3', 'name' => 'abc-vcs3', 'enabled' => true],
        ],
        'rest/monitor/v3/ad-domains' => [[
            'dns_name' => 'ad.example.test', 'netbios_name' => 'EXAMPLE', 'domain_type' => 'PRIMARY',
            'connection_servers' => [['name' => 'abc-vcs1', 'status' => 'FULLY_ACCESSIBLE', 'trust_relationship' => 'OK'], ['name' => 'abc-vcs2', 'status' => 'FULLY_ACCESSIBLE', 'trust_relationship' => 'OK']],
            'service_accounts' => [['status' => 'ACTIVE']],
        ]],
        'rest/monitor/v3/gateways' => [['name' => 'abc-horizon-gw', 'status' => 'OK', 'details' => ['type' => 'UAG', 'version' => '23'], 'active_connection_count' => 4]],
        'rest/inventory/v1/sessions?page=1&size=100' => [['machine_id' => 'logged-in', 'session_state' => 'CONNECTED', 'session_protocol' => 'BLAST', 'user_name' => 'must-not-persist', 'client_ip' => '192.0.2.10']],
        'rest/inventory/v1/desktop-pools' => [
            ['id' => 'pool-a', 'name' => 'Instant A', 'display_name' => 'Instant A', 'source' => 'INSTANT_CLONE', 'enabled' => true],
            ['id' => 'pool-b', 'name' => 'Instant B', 'display_name' => 'Instant B', 'source' => 'INSTANT_CLONE', 'enabled' => true],
            ['id' => 'manual', 'name' => 'Manual', 'source' => 'MANUAL', 'enabled' => true],
        ],
        'rest/inventory/v1/machines?page=1&size=100' => $machines,
    ];
}

$tests = [];
$tests['seed priority and validation'] = static function (): void {
    $seeds = PodCollector::seedEndpoints(testConfig());
    expect($seeds === ['abc-vcs1.example.test', 'abc-vcs2.example.test'], 'seed priority changed');
    expect(PodCollector::candidateEndpoints(testConfig(), ['discovered_connection_servers' => ['abc-vcs3', 'abc-horizon-gw.other.test']]) === ['abc-vcs1.example.test', 'abc-vcs2.example.test', 'abc-vcs3.example.test'], 'discovered endpoint constraints failed');
};
$tests['failover discovery gateway exclusion and scoring'] = static function (): void {
    $attempts = [];
    $collector = new PodCollector(static function (string $endpoint) use (&$attempts): ApiSession {
        $attempts[] = $endpoint;
        if (str_contains($endpoint, 'vcs1')) throw new HorizonFailure('timeout');

        return new FakeHorizonSession(successfulResponses());
    });
    $fixtureValue = str_repeat('x', 12);
    $snapshot = $collector->collect(testConfig(), ['username' => 'reader', 'password' => $fixtureValue]);
    expect($attempts === ['abc-vcs1.example.test', 'abc-vcs2.example.test'], 'failover order incorrect');
    expect($snapshot['horizon_central_meta']['source_endpoint'] === 'abc-vcs2.example.test', 'fallback source not recorded');
    expect(in_array('abc-vcs3.example.test', $snapshot['discovered_connection_servers'], true), 'additional Connection Server not discovered');
    expect(! in_array('abc-horizon-gw.example.test', $snapshot['discovered_connection_servers'], true), 'gateway became API failover candidate');
    expect(count($snapshot['horizon_gateways']) === 1, 'gateway not displayed');
    expect($snapshot['horizon_pools'][0]['health_state'] === 'info', 'one unavailable spare should be informational while capacity remains');
    expect($snapshot['horizon_pools'][1]['health_state'] === 'warning', 'two or more unavailable spares should warn while ready capacity remains');
    expect($snapshot['horizon_pools_summary']['pools_informational'] === 1, 'informational pool total missing');
    expect($snapshot['horizon_pools_summary']['pools_warning'] === 1, 'warning pool total missing');
    expect(count($snapshot['horizon_pool_machines']) === 12, 'bounded all-machine inventory was not retained');
    expect($snapshot['horizon_pool_machines'][0]['issue'] === 1, 'issue-first machine ordering changed');
    expect($snapshot['horizon_pool_machine_issues'][0]['name'] === 'abc-desktop-002', 'bounded issue-machine identity was not retained');
    expect(! str_contains(json_encode($snapshot, JSON_THROW_ON_ERROR), $fixtureValue), 'credential leaked into snapshot');
    expect(! str_contains(json_encode($snapshot, JSON_THROW_ON_ERROR), 'must-not-persist'), 'user or client identity leaked into snapshot');
    expect(($snapshot['horizon_central_meta']['requests_total'] ?? 0) >= 8, 'collector request count missing');
    expect(($snapshot['horizon_central_meta']['pages_total'] ?? 0) >= 2, 'collector page count missing');
    expect(($snapshot['horizon_central_meta']['inventory_complete'] ?? 0) === 1, 'complete inventory was not recorded');
};
$tests['identity mismatch retains stale last good'] = static function (): void {
    $credential = ['username' => 'reader', 'password' => str_repeat('x', 12)];
    $good = (new PodCollector(static fn (): ApiSession => new FakeHorizonSession(successfulResponses())))->collect(testConfig(), $credential);
    $bad = new PodCollector(static fn (): ApiSession => new FakeHorizonSession(successfulResponses('Different Pod')));
    $stale = $bad->collect(testConfig(), $credential, $good);
    expect($stale['horizon_api_summary']['state'] === 'stale', 'identity mismatch did not retain stale data');
    expect($stale['horizon_api_summary']['sessions_total'] === $good['horizon_api_summary']['sessions_total'], 'last good values were overwritten');
    expect(str_contains($stale['horizon_central_meta']['reason'], 'pod_identity_mismatch'), 'sanitized mismatch reason absent');
    expect($stale['horizon_central_meta']['endpoints_attempted'] >= 2, 'failed endpoint attempts were not observed');
    expect($stale['horizon_central_meta']['inventory_complete'] === 0, 'stale refresh incorrectly retained complete inventory state');
    expect($stale['horizon_central_meta']['snapshot_inventory_complete'] === 1, 'stale refresh lost last-good snapshot coverage');
};
$tests['authentication and authorization failures are sanitized'] = static function (): void {
    $attempt = 0;
    $collector = new PodCollector(static function () use (&$attempt): ApiSession {
        $attempt++;
        throw new HorizonFailure($attempt === 1 ? 'authentication_failed' : 'authorization_failed');
    });
    try {
        $collector->collect(testConfig(), ['username' => 'reader', 'password' => str_repeat('x', 12)]);
        throw new RuntimeException('authentication failures unexpectedly succeeded');
    } catch (HorizonFailure $failure) {
        expect(str_contains($failure->reason, 'authentication_failed'), 'authentication reason missing');
        expect(str_contains($failure->reason, 'authorization_failed'), 'authorization reason missing');
        expect(! str_contains($failure->reason, str_repeat('x', 12)), 'credential leaked into failure reason');
    }
};
$tests['partial endpoint and truncation become incomplete'] = static function (): void {
    $responses = successfulResponses();
    $responses['rest/inventory/v1/sessions?page=1&size=1'] = [['machine_id' => 'x', 'session_state' => 'CONNECTED']];
    $responses['rest/inventory/v1/machines?page=1&size=1'] = [['id' => 'x', 'desktop_pool_id' => 'pool-a', 'state' => 'AVAILABLE']];
    $config = testConfig();
    $config['page_size'] = 1;
    $config['max_pages'] = 1;
    $snapshot = (new PodCollector(static fn (): ApiSession => new FakeHorizonSession($responses)))->collect($config, ['username' => 'reader', 'password' => str_repeat('x', 12)]);
    expect($snapshot['horizon_api_summary']['sessions_truncated'] === 1, 'session truncation absent');
    expect($snapshot['horizon_pools_summary']['pools_incomplete'] === 2, 'truncated pool inventories should be incomplete');
};
$tests['zero ready and no placement capacity are critical'] = static function (): void {
    $responses = successfulResponses();
    $responses['rest/inventory/v1/desktop-pools'] = [
        ['id' => 'zero-ready', 'name' => 'Zero Ready', 'source' => 'INSTANT_CLONE', 'enabled' => true],
        ['id' => 'no-unused', 'name' => 'No Unused', 'source' => 'LINKED_CLONE', 'enabled' => true],
    ];
    $responses['rest/inventory/v1/sessions?page=1&size=100'] = [
        ['machine_id' => 'u1', 'session_state' => 'CONNECTED'],
        ['machine_id' => 'u2', 'session_state' => 'CONNECTED'],
    ];
    $responses['rest/inventory/v1/machines?page=1&size=100'] = [
        ['id' => 'z1', 'desktop_pool_id' => 'zero-ready', 'state' => 'ERROR'],
        ['id' => 'z2', 'desktop_pool_id' => 'zero-ready', 'state' => 'MAINTENANCE'],
        ['id' => 'u1', 'desktop_pool_id' => 'no-unused', 'state' => 'CONNECTED'],
        ['id' => 'u2', 'desktop_pool_id' => 'no-unused', 'state' => 'CONNECTED'],
    ];
    $snapshot = (new PodCollector(static fn (): ApiSession => new FakeHorizonSession($responses)))->collect(testConfig(), ['username' => 'reader', 'password' => str_repeat('x', 12)]);
    expect($snapshot['horizon_pools'][0]['health_state'] === 'critical' && $snapshot['horizon_pools'][0]['health_reason'] === 'no_ready_spares', 'zero-ready pool was not critical');
    expect($snapshot['horizon_pools'][1]['health_state'] === 'critical' && $snapshot['horizon_pools'][1]['health_reason'] === 'no_placement_capacity', 'pool with every machine in session was not critical');
};
$tests['active machines with bad Horizon state remain selectable evidence'] = static function (): void {
    $responses = successfulResponses();
    $responses['rest/inventory/v1/sessions?page=1&size=100'] = [
        ['machine_id' => 'a2', 'session_state' => 'CONNECTED', 'user_name' => 'must-not-persist'],
    ];
    $snapshot = (new PodCollector(static fn (): ApiSession => new FakeHorizonSession($responses)))->collect(testConfig(), ['username' => 'reader', 'password' => str_repeat('x', 12)]);
    $issue = array_values(array_filter($snapshot['horizon_pool_machine_issues'], static fn (array $row): bool => ($row['id'] ?? '') === 'a2'))[0] ?? [];
    $detail = array_values(array_filter($snapshot['horizon_pool_machines'], static fn (array $row): bool => ($row['id'] ?? '') === 'a2'))[0] ?? [];
    expect(($issue['has_session'] ?? 0) === 1, 'active issue machine did not retain session-presence evidence');
    expect(($detail['has_session'] ?? 0) === 1, 'all-machine inventory lost session-presence evidence');
    expect(($issue['issue_reason'] ?? '') === 'machine_state_error', 'active issue machine reason changed');
    expect(! str_contains(json_encode($snapshot, JSON_THROW_ON_ERROR), 'must-not-persist'), 'session user identity leaked into issue evidence');
};
$tests['issue details and unhealthy service evidence are bounded'] = static function (): void {
    $responses = successfulResponses();
    $responses['rest/monitor/v3/connection-servers'][0]['services'] = [
        ['name' => 'CRL_PREFETCH', 'status' => 'DOWN'],
        ['name' => 'MESSAGE_BUS', 'status' => 'STOPPED'],
    ];
    $config = testConfig();
    $config['machine_detail_limit'] = 1;
    $config['machine_issue_limit'] = 1;
    $config['unhealthy_service_limit'] = 1;
    $snapshot = (new PodCollector(static fn (): ApiSession => new FakeHorizonSession($responses)))->collect($config, ['username' => 'reader', 'password' => str_repeat('x', 12)]);
    expect(count($snapshot['horizon_pool_machines']) === 1, 'machine detail limit was not enforced');
    expect($snapshot['horizon_api_summary']['machine_details_total'] === 12, 'authoritative machine total was reduced to the detail limit');
    expect($snapshot['horizon_api_summary']['machine_details_truncated'] === 1, 'machine detail truncation was not disclosed');
    expect(count($snapshot['horizon_pool_machine_issues']) === 1, 'machine issue detail limit was not enforced');
    expect($snapshot['horizon_api_summary']['machine_issues_total'] === 10, 'authoritative issue total was reduced to the detail limit');
    expect($snapshot['horizon_api_summary']['machine_issues_truncated'] === 1, 'machine issue truncation was not disclosed');
    expect(count($snapshot['horizon_pod_members'][0]['unhealthy_services']) === 1, 'service detail limit was not enforced');
    expect($snapshot['horizon_pod_members'][0]['unhealthy_services'][0]['name'] === 'CRL_PREFETCH', 'unhealthy service name was not retained');
    expect($snapshot['horizon_pod_members'][0]['unhealthy_services_truncated'] === 1, 'service detail truncation was not disclosed');
    expect($snapshot['horizon_api_summary']['service_details_truncated'] === 1, 'service detail truncation summary was not disclosed');
    expect($snapshot['horizon_central_meta']['inventory_complete'] === 0, 'truncated issue evidence incorrectly reported complete');
};
$tests['cluster name is used when local pod name is empty'] = static function (): void {
    $responses = successfulResponses();
    $responses['rest/config/v1/environment-properties'] = ['local_pod_name' => '', 'cluster_name' => 'ABC Cluster'];
    $snapshot = (new PodCollector(static fn (): ApiSession => new FakeHorizonSession($responses)))->collect(testConfig(), ['username' => 'reader', 'password' => str_repeat('x', 12)]);
    expect($snapshot['pod_identity'] === 'ABC Cluster', 'cluster name did not supply pod identity fallback');
    expect($snapshot['horizon_pod_summary']['pod_name'] === 'ABC Cluster', 'cluster name did not supply pod display fallback');
};
$tests['absent configuration is a safe no-op'] = static function (): void {
    $root = sys_get_temp_dir() . '/horizon-absent-' . bin2hex(random_bytes(5));
    mkdir($root, 0700, true);
    expect(HorizonCentralRuntime::run(['librenms-root' => $root]) === 0, 'absent config should not activate collection');
    rmdir($root);
};
$tests['configuration helper manages pod lifecycle without manual edits'] = static function (): void {
    $dir = sys_get_temp_dir() . '/horizon-lifecycle-' . bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);
    $envPath = $dir . '/.env';
    $configPath = $dir . '/pods.json';
    file_put_contents($envPath, "APP_KEY=unchanged\n");

    $base = ['config.php', 'pod', 'add', '--config', $configPath, '--env', $envPath];
    expect(HorizonCentralConfiguration::main([...$base, '--site', 'abc', '--dns-suffix', 'example.test', '--display-device', 'abc-vcs2.example.test']) === 0, 'pod add failed');
    expect(HorizonCentralConfiguration::main([...$base, '--site', 'abc', '--dns-suffix', 'example.test', '--display-device', 'abc-vcs1.example.test', '--warning-percent', '60']) === 0, 'pod update failed');
    $config = json_decode((string) file_get_contents($configPath), true, flags: JSON_THROW_ON_ERROR);
    expect(count($config['pods']) === 1, 'pod update created a duplicate');
    expect($config['pods'][0]['display_device'] === 'abc-vcs1.example.test' && $config['pods'][0]['pool_warning_percent'] === 60, 'pod update was not persisted');

    expect(HorizonCentralConfiguration::main(['config.php', 'pod', 'disable', '--config', $configPath, '--site', 'abc']) === 0, 'pod disable failed');
    expect(HorizonCentralConfiguration::main(['config.php', 'config', 'validate', '--config', $configPath, '--env', $envPath]) === 0, 'configuration validation failed');
    expect(HorizonCentralConfiguration::main(['config.php', 'pod', 'enable', '--config', $configPath, '--site', 'abc']) === 0, 'pod enable failed');
    expect(HorizonCentralConfiguration::main(['config.php', 'pod', 'remove', '--config', $configPath, '--site', 'abc']) === 0, 'pod remove failed');
    $config = json_decode((string) file_get_contents($configPath), true, flags: JSON_THROW_ON_ERROR);
    expect($config['pods'] === [], 'pod remove left configuration behind');

    unlink($envPath);
    unlink($configPath);
    rmdir($dir);
};
$tests['atomic env update is idempotent and secret-safe'] = static function (): void {
    $dir = sys_get_temp_dir() . '/horizon-config-' . bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);
    $path = $dir . '/.env';
    file_put_contents($path, "APP_KEY=unchanged\nWINDOWS_AGENT_HORIZON_API_USERNAME=example-old\nWINDOWS_AGENT_HORIZON_API_USERNAME=example-duplicate\n");
    $fixtureValue = implode('-', ['fixture', 'value']);
    HorizonCentralConfiguration::updateEnv($path, ['WINDOWS_AGENT_HORIZON_API_USERNAME' => 'reader', 'WINDOWS_AGENT_HORIZON_API_PASSWORD' => $fixtureValue]);
    HorizonCentralConfiguration::updateEnv($path, ['WINDOWS_AGENT_HORIZON_API_USERNAME' => 'reader', 'WINDOWS_AGENT_HORIZON_API_PASSWORD' => $fixtureValue]);
    $contents = (string) file_get_contents($path);
    $parsed = HorizonCentralConfiguration::readEnv($path);
    expect(substr_count($contents, 'WINDOWS_AGENT_HORIZON_API_PASSWORD=') === 1, 'env update duplicated secret key');
    expect(substr_count($contents, 'WINDOWS_AGENT_HORIZON_API_USERNAME=') === 1, 'env update retained a duplicate username key');
    expect($parsed['WINDOWS_AGENT_HORIZON_API_PASSWORD'] === $fixtureValue, 'env secret did not round trip');
    expect($parsed['APP_KEY'] === 'unchanged', 'unrelated env value changed');
    $configPath = $dir . '/pods.json';
    file_put_contents($configPath, json_encode(['version' => 1, 'pods' => [testConfig()]], JSON_THROW_ON_ERROR));
    ob_start();
    $status = HorizonCentralConfiguration::main(['config.php', 'config', 'status', '--config', $configPath, '--env', $path]);
    $statusOutput = (string) ob_get_clean();
    expect($status === 0 && ! str_contains($statusOutput, $fixtureValue), 'sanitized status exposed a secret');
    unlink($path);
    expect(! is_file($path . '.horizon-backup'), 'temporary credential backup was retained after validation');
    unlink($configPath);
    rmdir($dir);
};
$tests['poll trigger is device-scoped deduplicated and poll-safe'] = static function (): void {
    $coordination = new FakeHorizonCoordination();
    $coordination->register([
        'site' => 'abc',
        'display_device' => 'abc-vcs2.example.test',
        'device_id' => 42,
    ]);
    expect(HorizonTriggerProducer::emitForDevice(42, 'abc-vcs2.example.test', $coordination), 'first display-device trigger was not queued');
    expect(! HorizonTriggerProducer::emitForDevice(42, 'abc-vcs2.example.test', $coordination), 'duplicate trigger was not deduplicated');
    expect(! HorizonTriggerProducer::emitForDevice(43, 'abc-vcs1.example.test', $coordination), 'unrelated device emitted a trigger');
    expect($coordination->consume() === 'abc' && $coordination->consume() === null, 'worker did not consume one deduplicated site');
    $coordination->failReads = true;
    expect(! HorizonTriggerProducer::emitForDevice(42, 'abc-vcs2.example.test', $coordination), 'coordination failure escaped the poll-safe producer');
};
$tests['distributed lock cooldown and fallback paths deduplicate collection'] = static function (): void {
    $coordination = new FakeHorizonCoordination();
    $coordinator = new HorizonCollectionCoordinator($coordination, 60, 60, 10);
    $collections = 0;
    $collect = static function () use (&$collections): array {
        $collections++;

        return ['fresh' => true];
    };
    expect($coordinator->collect('abc', false, $collect) === 'fresh', 'initial collection did not run');
    expect($coordinator->collect('abc', false, $collect) === 'cooldown', 'cooldown did not suppress a duplicate cycle');
    expect($collections === 1, 'duplicate cycle queried the pod');
    expect($coordinator->collect('abc', true, $collect) === 'fresh', 'explicit forced diagnostics did not bypass cooldown');
    $coordination->locks['abc'] = 'other-worker';
    expect($coordinator->collect('abc', true, $collect) === 'locked', 'shared lock did not exclude a competing worker');
    unset($coordination->locks['abc']);
    $coordination->cooldowns['abc'] = false;
    expect($coordinator->collect('abc', false, $collect) === 'fresh', 'five-minute fallback path could not collect without a trigger');
    expect($collections === 3, 'unexpected effective collection count');
};
$tests['discovery is add-only supports a single seed and preserves existing sites'] = static function (): void {
    $devices = [
        ['device_id' => 1, 'hostname' => 'abc-vcs1.example.test', 'status' => 1, 'disabled' => 0, 'has_application' => true, 'horizon_detected' => true],
        ['device_id' => 2, 'hostname' => 'def-vcs1.example.test', 'status' => 0, 'disabled' => 0, 'has_application' => false, 'horizon_detected' => false],
        ['device_id' => 3, 'hostname' => 'ghi-vcs2.example.test', 'status' => 1, 'disabled' => 0, 'has_application' => true, 'horizon_detected' => true],
    ];
    $existing = [[
        'site' => 'ghi',
        'dns_suffix' => 'example.test',
        'display_device' => 'ghi-vcs2.example.test',
    ]];
    $results = HorizonPodDiscovery::discover(
        $devices,
        $existing,
        'example.test',
        static fn (array $pod, string $seed): array => [
            'pod_identity' => strtoupper($pod['site']) . ' Pod',
            'discovered_connection_servers' => [
                $seed,
                $pod['site'] . '-vcs2.example.test',
            ],
        ]
    );
    expect(count($results) === 3, 'discovery did not group all sites');
    expect($results[0]['state'] === 'ready' && $results[0]['display_device'] === 'abc-vcs1.example.test', 'single-seed ready site was not proposed');
    expect(count($results[0]['members']) === 2, 'API-discovered additional member was lost');
    expect($results[1]['state'] === 'waiting-for-agent', 'no-agent site was not deferred');
    expect($results[2]['state'] === 'existing' && $results[2]['apply'] === false, 'existing pod was not preserved');
};
$tests['discovery reports TLS auth identity and cross-site ambiguity failures'] = static function (): void {
    $base = static fn (int $id, string $host): array => [
        'device_id' => $id, 'hostname' => $host, 'status' => 1, 'disabled' => 0,
        'has_application' => true, 'horizon_detected' => true,
    ];
    $devices = [
        $base(1, 'abc-vcs1.example.test'),
        $base(2, 'def-vcs1.example.test'),
        $base(3, 'ghi-vcs1.example.test'),
        $base(4, 'jkl-vcs1.example.test'),
        $base(5, 'mno-vcs1.example.test'),
    ];
    $results = HorizonPodDiscovery::discover(
        $devices,
        [],
        'example.test',
        static function (array $pod, string $seed): array {
            return match ($pod['site']) {
                'abc' => throw new HorizonFailure('tls_failed'),
                'def' => throw new HorizonFailure('authorization_failed'),
                'ghi' => throw new HorizonFailure('pod_identity_mismatch'),
                default => [
                    'pod_identity' => 'Shared Pod',
                    'discovered_connection_servers' => [$seed],
                ],
            };
        }
    );
    $states = array_column($results, 'state', 'site');
    expect($states['abc'] === 'tls-invalid', 'TLS failure classification changed');
    expect($states['def'] === 'unauthorized', 'authorization failure classification changed');
    expect($states['ghi'] === 'ambiguous', 'identity failure classification changed');
    expect($states['jkl'] === 'ambiguous' && $states['mno'] === 'ambiguous', 'duplicate pod identity was not blocked across sites');
};
$tests['capability manifest advertises the stable private integration contract'] = static function (): void {
    $path = dirname(__DIR__, 2) . '/librenms-overlay/tools/capabilities.json';
    $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['overlay_version'] === '0.6.19', 'overlay capability version mismatch');
    expect($manifest['configuration_schema_version'] === 2, 'configuration schema version mismatch');
    expect($manifest['capabilities']['horizon_trigger_producer'] === 1, 'trigger capability missing');
    expect($manifest['capabilities']['horizon_central_worker'] === 1, 'worker capability missing');
    expect($manifest['capabilities']['horizon_pod_discovery'] === 1, 'discovery capability missing');
    expect($manifest['private_integration_api'] === ['minimum' => 1, 'maximum' => 1], 'private integration range changed');
};
$tests['worker enable creates trigger worker and independent five-minute fallback'] = static function (): void {
    $dir = sys_get_temp_dir() . '/horizon-worker-' . bin2hex(random_bytes(5));
    $root = $dir . '/librenms';
    $tools = $root . '/windows-agent-overlay';
    $units = $dir . '/units';
    mkdir($tools, 0700, true);
    mkdir($units, 0700, true);
    file_put_contents($tools . '/horizon-central-worker.php', "<?php\n");
    file_put_contents($tools . '/horizon-central-collector.php', "<?php\n");
    $config = $dir . '/pods.json';
    file_put_contents($config, json_encode(['version' => 2, 'pods' => [testConfig()]], JSON_THROW_ON_ERROR));
    $cron = $dir . '/legacy-cron';
    file_put_contents($cron, "# Managed by LibreNMS Windows Agent overlay; collection is inactive without local pod configuration.\n");
    $base = [
        'config.php', 'worker', 'enable',
        '--librenms-root', $root,
        '--config', $config,
        '--unit-dir', $units,
        '--cron-path', $cron,
        '--no-systemctl',
    ];
    expect(HorizonCentralConfiguration::main($base) === 0, 'worker enable failed');
    $worker = (string) file_get_contents($units . '/librenms-windows-agent-horizon-worker.service');
    $timer = (string) file_get_contents($units . '/librenms-windows-agent-horizon-fallback.timer');
    expect(str_contains($worker, 'horizon-central-worker.php'), 'trigger worker unit is incomplete');
    expect(str_contains($timer, 'OnUnitActiveSec=5min'), 'independent five-minute fallback is missing');
    expect(! is_file($cron), 'legacy collector cron was not retired');
    expect(HorizonCentralConfiguration::main([
        'config.php', 'worker', 'disable',
        '--unit-dir', $units,
        '--cron-path', $cron,
        '--no-systemctl',
    ]) === 0, 'worker disable failed');
    expect((glob($units . '/*') ?: []) === [], 'worker disable left unit files');
    unlink($config);
    unlink($tools . '/horizon-central-worker.php');
    unlink($tools . '/horizon-central-collector.php');
    rmdir($tools);
    rmdir($root);
    rmdir($units);
    rmdir($dir);
};
$tests['standard and explicit application polls share one credential-free trigger path'] = static function (): void {
    $root = dirname(__DIR__, 2);
    $wrapper = (string) file_get_contents($root . '/librenms-overlay/includes/polling/applications/windows-agent.inc.php');
    $parser = (string) file_get_contents($root . '/librenms-overlay/includes/polling/unix-agent/windows_agent.inc.php');
    $producer = (string) file_get_contents($root . '/librenms-overlay/tools/horizon-central-coordination.php');
    expect(str_contains($wrapper, "includes/polling/unix-agent/windows_agent.inc.php"), 'applications module no longer uses the shared parser');
    expect(str_contains($parser, 'HorizonTriggerProducer::emitForDevice'), 'shared application parser does not emit the trigger');
    expect(str_contains($parser, 'trigger failure never fails device polling'), 'poll-safe trigger boundary is missing');
    expect(! str_contains($producer, 'WINDOWS_AGENT_HORIZON_API_PASSWORD'), 'poller trigger library references the Horizon credential');
    expect(! str_contains($producer, 'CurlApiSession'), 'poller trigger library can contact Horizon');
};
$tests['offline display remains an anchor while missing display or application is bounded'] = static function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/librenms-overlay/tools/horizon-central-collector.php');
    expect(str_contains($source, "Device::findByHostname"), 'display anchor lookup is missing');
    expect(str_contains($source, "display_device_not_found"), 'deleted display device failure is not bounded');
    expect(str_contains($source, "windows_agent_application_not_found"), 'deleted display application failure is not bounded');
    expect(! preg_match('/registerPod[\\s\\S]{0,1200}->status/', $source), 'display-device down state incorrectly gates registration or collection');
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS {$name}\n";
    } catch (Throwable $failure) {
        $failed++;
        fwrite(STDERR, "FAIL {$name}: {$failure->getMessage()}\n");
    }
}
exit($failed === 0 ? 0 : 1);
