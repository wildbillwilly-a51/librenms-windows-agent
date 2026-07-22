<?php

declare(strict_types=1);

use WindowsAgentOverlay\Horizon\ApiSession;
use WindowsAgentOverlay\Horizon\HorizonFailure;
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
        'pool_minimum_spares' => 2, 'page_size' => 100, 'max_pages' => 2,
    ];
}

/** @return array<string,array<mixed>|HorizonFailure> */
function successfulResponses(string $identity = 'ABC Pod'): array
{
    $machines = [];
    $machines[] = ['id' => 'a1', 'name' => 'must-not-persist', 'desktop_pool_id' => 'pool-a', 'state' => 'AVAILABLE'];
    $machines[] = ['id' => 'a2', 'desktop_pool_id' => 'pool-a', 'state' => 'ERROR'];
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
    expect($snapshot['horizon_pools'][0]['health_state'] === 'warning', '50 percent pool should warn');
    expect($snapshot['horizon_pools'][1]['health_state'] === 'critical', '90 percent pool should be critical');
    expect(! str_contains(json_encode($snapshot, JSON_THROW_ON_ERROR), $fixtureValue), 'credential leaked into snapshot');
    expect(! str_contains(json_encode($snapshot, JSON_THROW_ON_ERROR), 'must-not-persist'), 'user or machine identity leaked into snapshot');
};
$tests['identity mismatch retains stale last good'] = static function (): void {
    $credential = ['username' => 'reader', 'password' => str_repeat('x', 12)];
    $good = (new PodCollector(static fn (): ApiSession => new FakeHorizonSession(successfulResponses())))->collect(testConfig(), $credential);
    $bad = new PodCollector(static fn (): ApiSession => new FakeHorizonSession(successfulResponses('Different Pod')));
    $stale = $bad->collect(testConfig(), $credential, $good);
    expect($stale['horizon_api_summary']['state'] === 'stale', 'identity mismatch did not retain stale data');
    expect($stale['horizon_api_summary']['sessions_total'] === $good['horizon_api_summary']['sessions_total'], 'last good values were overwritten');
    expect(str_contains($stale['horizon_central_meta']['reason'], 'pod_identity_mismatch'), 'sanitized mismatch reason absent');
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
$tests['zero ready and no unused capacity are explicit'] = static function (): void {
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
    expect($snapshot['horizon_pools'][1]['health_state'] === 'warning' && $snapshot['horizon_pools'][1]['health_reason'] === 'no_unused_machines', 'no-unused pool was not warning');
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
