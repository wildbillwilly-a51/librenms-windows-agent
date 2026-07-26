#!/usr/bin/env php
<?php

declare(strict_types=1);

use WindowsAgentOverlay\Horizon\CurlApiSession;
use WindowsAgentOverlay\Horizon\HorizonFailure;
use WindowsAgentOverlay\Horizon\HorizonPodDiscovery;
use WindowsAgentOverlay\Horizon\PodCollector;
use WindowsAgentOverlay\Horizon\RedisHorizonCoordination;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/horizon-central-lib.php';
require_once __DIR__ . '/horizon-central-coordination.php';
require_once __DIR__ . '/horizon-central-discovery.php';
require_once __DIR__ . '/horizon-central-collector.php';

final class HorizonCentralConfiguration
{
    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        [$command, $action, $options] = self::arguments($argv);
        $root = rtrim((string) ($options['librenms-root'] ?? getenv('LIBRENMS_ROOT') ?: '/opt/librenms'), '/');
        $configPath = (string) ($options['config'] ?? $root . '/.horizon-pods.json');
        $envPath = (string) ($options['env'] ?? $root . '/.env');
        try {
            return match ("{$command}:{$action}") {
                'credential:set', 'credential:rotate' => self::setCredential($envPath),
                'credential:remove' => self::removeCredential($envPath),
                'pod:add' => self::addPod($configPath, $options),
                'pod:remove' => self::changePod($configPath, $options, 'remove'),
                'pod:enable' => self::changePod($configPath, $options, 'enable'),
                'pod:disable' => self::changePod($configPath, $options, 'disable'),
                'pod:discover' => self::discoverPods($root, $configPath, $envPath, $options),
                'pod:list' => self::status($configPath, $envPath),
                'config:validate' => self::validate($configPath, $envPath),
                'config:status' => self::status($configPath, $envPath),
                'test:network' => self::testNetwork($configPath, $options),
                'test:api' => self::testApi($configPath, $envPath, $options),
                'worker:enable', 'schedule:enable' => self::enableWorker($root, $configPath, $options),
                'worker:disable', 'schedule:disable' => self::disableWorker($options),
                'worker:status', 'schedule:status' => self::workerStatus($options),
                'capability:show' => self::showCapability(),
                default => self::usage(),
            };
        } catch (HorizonFailure $failure) {
            fwrite(STDERR, 'ERROR: ' . $failure->reason . PHP_EOL);

            return 2;
        } catch (Throwable) {
            fwrite(STDERR, "ERROR: internal_error\n");

            return 2;
        }
    }

    /** @param list<string> $argv @return array{string,string,array<string,string|bool>} */
    private static function arguments(array $argv): array
    {
        $command = strtolower((string) ($argv[1] ?? 'help'));
        $action = strtolower((string) ($argv[2] ?? ''));
        $options = [];
        for ($i = 3; $i < count($argv); $i++) {
            if (! str_starts_with($argv[$i], '--')) throw new HorizonFailure('invalid_argument');
            $key = substr($argv[$i], 2);
            if (str_contains($key, '=')) {
                [$key, $value] = explode('=', $key, 2);
                $options[$key] = $value;
            } elseif (isset($argv[$i + 1]) && ! str_starts_with($argv[$i + 1], '--')) {
                $options[$key] = $argv[++$i];
            } else {
                $options[$key] = true;
            }
        }
        foreach (['password', 'username', 'domain'] as $forbidden) {
            if (array_key_exists($forbidden, $options)) throw new HorizonFailure('credentials_not_allowed_on_command_line');
        }

        return [$command, $action, $options];
    }

    private static function setCredential(string $envPath): int
    {
        if (! is_file($envPath) || ! is_writable($envPath)) throw new HorizonFailure('env_not_writable');
        $username = trim(self::prompt('Horizon API username: ', false));
        $domain = trim(self::prompt('Horizon login domain (blank if username includes it): ', false));
        $password = self::prompt('Horizon API password: ', true);
        if ($username === '' || $password === '') throw new HorizonFailure('credential_empty');
        self::updateEnv($envPath, [
            'WINDOWS_AGENT_HORIZON_API_USERNAME' => $username,
            'WINDOWS_AGENT_HORIZON_API_DOMAIN' => $domain,
            'WINDOWS_AGENT_HORIZON_API_PASSWORD' => $password,
        ]);
        fwrite(STDOUT, "Credential updated atomically; secret value was not displayed.\n");

        return 0;
    }

    private static function removeCredential(string $envPath): int
    {
        self::updateEnv($envPath, [
            'WINDOWS_AGENT_HORIZON_API_USERNAME' => null,
            'WINDOWS_AGENT_HORIZON_API_DOMAIN' => null,
            'WINDOWS_AGENT_HORIZON_API_PASSWORD' => null,
        ]);
        fwrite(STDOUT, "Credential removed.\n");

        return 0;
    }

    /** @param array<string,string|bool> $options */
    private static function addPod(string $path, array $options): int
    {
        foreach (['site', 'dns-suffix', 'display-device'] as $required) {
            if (! is_string($options[$required] ?? null) || trim((string) $options[$required]) === '') throw new HorizonFailure('missing_' . str_replace('-', '_', $required));
        }
        $pod = [
            'site' => strtolower(trim((string) $options['site'])),
            'dns_suffix' => strtolower(trim((string) $options['dns-suffix'])),
            'display_device' => strtolower(trim((string) $options['display-device'])),
            'enabled' => true,
            'pool_warning_percent' => (int) ($options['warning-percent'] ?? 50),
            'pool_critical_percent' => (int) ($options['critical-percent'] ?? 90),
            'pool_minimum_spares' => (int) ($options['minimum-spares'] ?? 2),
            'page_size' => 500,
            'max_pages' => 20,
        ];
        PodCollector::validateConfig($pod);
        $config = self::readConfig($path);
        $pods = is_array($config['pods'] ?? null) ? $config['pods'] : [];
        $replaced = false;
        foreach ($pods as $index => $existing) {
            if (is_array($existing) && strcasecmp((string) ($existing['site'] ?? ''), $pod['site']) === 0) {
                $pods[$index] = $pod;
                $replaced = true;
            }
        }
        if (! $replaced) $pods[] = $pod;
        $config['version'] = 2;
        $config['pods'] = array_values($pods);
        self::writeConfig($path, $config);
        fwrite(STDOUT, 'Pod ' . $pod['site'] . ($replaced ? " updated.\n" : " added.\n"));

        return 0;
    }

    /** @param array<string,string|bool> $options */
    private static function changePod(
        string $path,
        array $options,
        string $operation
    ): int
    {
        $site = strtolower(trim((string) ($options['site'] ?? '')));
        if ($site === '') throw new HorizonFailure('missing_site');
        $config = self::readConfig($path);
        $pods = is_array($config['pods'] ?? null) ? $config['pods'] : [];
        $found = false;
        foreach ($pods as $index => &$pod) {
            if (! is_array($pod) || strcasecmp((string) ($pod['site'] ?? ''), $site) !== 0) continue;
            $found = true;
            if ($operation === 'remove') unset($pods[$index]);
            else $pod['enabled'] = $operation === 'enable';
        }
        unset($pod);
        if (! $found) throw new HorizonFailure('site_not_found');
        $config['version'] = 2;
        $config['pods'] = array_values($pods);
        self::writeConfig($path, $config);
        fwrite(STDOUT, "Pod {$site} {$operation}d.\n");

        return 0;
    }

    /** @param array<string,string|bool> $options */
    private static function discoverPods(
        string $root,
        string $configPath,
        string $envPath,
        array $options
    ): int {
        $dnsSuffix = strtolower(rtrim(trim((string) ($options['dns-suffix'] ?? '')), '.'));
        if ($dnsSuffix === '') {
            throw new HorizonFailure('missing_dns_suffix');
        }
        $env = self::readEnv($envPath);
        $credential = [
            'username' => (string) ($env['WINDOWS_AGENT_HORIZON_API_USERNAME'] ?? ''),
            'password' => (string) ($env['WINDOWS_AGENT_HORIZON_API_PASSWORD'] ?? ''),
            'domain' => (string) ($env['WINDOWS_AGENT_HORIZON_API_DOMAIN'] ?? ''),
        ];
        if ($credential['username'] === '' || $credential['password'] === '') {
            throw new HorizonFailure('credentials_missing');
        }

        HorizonCentralRuntime::bootLibreNms($root);
        $rows = DB::select(
            <<<'SQL'
SELECT
    d.device_id,
    d.hostname,
    d.status,
    d.disabled,
    MAX(CASE WHEN a.app_id IS NULL THEN 0 ELSE 1 END) AS has_application,
    MAX(CASE WHEN am.metric = 'horizon_detected' AND am.value > 0 THEN 1 ELSE 0 END) AS horizon_detected
FROM devices AS d
LEFT JOIN applications AS a
    ON a.device_id = d.device_id
    AND a.app_type = 'windows-agent'
    AND a.app_instance = ''
    AND a.deleted_at IS NULL
LEFT JOIN application_metrics AS am
    ON am.app_id = a.app_id
WHERE LOWER(d.hostname) LIKE ?
GROUP BY d.device_id, d.hostname, d.status, d.disabled
ORDER BY d.hostname
SQL,
            ['%-vcs%.' . $dnsSuffix]
        );
        $devices = array_map(
            static fn (object $row): array => [
                'device_id' => (int) $row->device_id,
                'hostname' => (string) $row->hostname,
                'status' => (int) $row->status,
                'disabled' => (int) $row->disabled,
                'has_application' => (int) $row->has_application === 1,
                'horizon_detected' => (int) $row->horizon_detected === 1,
            ],
            $rows
        );
        $config = self::readConfig($configPath);
        $collector = new PodCollector();
        $results = HorizonPodDiscovery::discover(
            $devices,
            is_array($config['pods'] ?? null) ? $config['pods'] : [],
            $dnsSuffix,
            static fn (array $pod, string $seed): array => $collector->discoverFromEndpoint(
                $pod,
                $credential,
                $seed
            )
        );

        foreach ($results as $result) {
            $parts = [
                'site=' . ($result['site'] ?? 'unknown'),
                'state=' . ($result['state'] ?? 'unknown'),
                'reason=' . ($result['reason'] ?? 'unknown'),
            ];
            foreach (['seed', 'display_device'] as $key) {
                if (! empty($result[$key])) {
                    $parts[] = str_replace('_', '-', $key) . '=' . $result[$key];
                }
            }
            if (is_array($result['members'] ?? null)) {
                $parts[] = 'members=' . count($result['members']);
            }
            fwrite(STDOUT, implode(' ', $parts) . PHP_EOL);
        }

        if (! isset($options['apply'])) {
            fwrite(STDOUT, "Preview only; no configuration or shared registration changed.\n");

            return 0;
        }

        $newPods = [];
        foreach ($results as $result) {
            if (($result['state'] ?? '') !== 'ready' || ! is_array($result['pod'] ?? null)) {
                continue;
            }
            $pod = $result['pod'];
            $pod['display_device_id'] = (int) ($result['display_device_id'] ?? 0);
            $newPods[] = $pod;
        }
        if ($newPods === []) {
            fwrite(STDOUT, "No validated new pods to add.\n");

            return 0;
        }

        $original = is_file($configPath) ? (string) file_get_contents($configPath) : null;
        $config['version'] = 2;
        $config['pods'] = array_values(array_merge($config['pods'] ?? [], $newPods));
        self::writeConfig($configPath, $config);
        try {
            $coordination = new RedisHorizonCoordination();
            foreach ($newPods as $pod) {
                HorizonCentralRuntime::registerPod($pod, $coordination);
            }
        } catch (Throwable $failure) {
            if ($original === null) {
                @unlink($configPath);
            } else {
                self::atomicWrite($configPath, $original, 0600, false);
            }
            throw $failure;
        }
        fwrite(STDOUT, 'Added ' . count($newPods) . " validated pod(s); existing pods were unchanged.\n");

        return 0;
    }

    private static function validate(string $configPath, string $envPath): int
    {
        $config = self::readConfig($configPath);
        foreach (($config['pods'] ?? []) as $pod) {
            if (! is_array($pod)) throw new HorizonFailure('invalid_pod');
            PodCollector::validateConfig($pod);
        }
        $version = (int) ($config['version'] ?? 1);
        if ($version < 1 || $version > 2) throw new HorizonFailure('unsupported_configuration_schema');
        $env = self::readEnv($envPath);
        $credential = isset($env['WINDOWS_AGENT_HORIZON_API_USERNAME'], $env['WINDOWS_AGENT_HORIZON_API_PASSWORD']) && $env['WINDOWS_AGENT_HORIZON_API_USERNAME'] !== '' && $env['WINDOWS_AGENT_HORIZON_API_PASSWORD'] !== '';
        fwrite(STDOUT, 'Configuration valid. schema=' . $version . ' pods=' . count($config['pods'] ?? []) . ' credential=' . ($credential ? 'present' : 'absent') . PHP_EOL);

        return 0;
    }

    private static function status(string $configPath, string $envPath): int
    {
        $config = self::readConfig($configPath);
        $env = self::readEnv($envPath);
        $credential = ($env['WINDOWS_AGENT_HORIZON_API_USERNAME'] ?? '') !== '' && ($env['WINDOWS_AGENT_HORIZON_API_PASSWORD'] ?? '') !== '';
        echo 'schema=' . (int) ($config['version'] ?? 1) . PHP_EOL;
        echo 'credential=' . ($credential ? 'present' : 'absent') . PHP_EOL;
        foreach (($config['pods'] ?? []) as $pod) {
            if (! is_array($pod)) continue;
            $seeds = PodCollector::seedEndpoints($pod);
            echo 'site=' . $pod['site'] . ' enabled=' . (($pod['enabled'] ?? true) ? 'yes' : 'no') . ' display=' . $pod['display_device'] . ' preferred=' . $seeds[0] . ' fallback=' . $seeds[1] . PHP_EOL;
        }

        return 0;
    }

    /** @param array<string,string|bool> $options */
    private static function testNetwork(string $configPath, array $options): int
    {
        $pod = self::selectedPod($configPath, (string) ($options['site'] ?? ''));
        $failed = 0;
        foreach (PodCollector::seedEndpoints($pod) as $endpoint) {
            $resolved = gethostbyname($endpoint) !== $endpoint;
            $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $endpoint], 'http' => ['follow_location' => 0]]);
            $socket = $resolved ? @stream_socket_client('ssl://' . $endpoint . ':443', $errno, $error, 5, STREAM_CLIENT_CONNECT, $context) : false;
            $tls = is_resource($socket);
            if ($tls) fclose($socket);
            if (! $resolved || ! $tls) $failed++;
            fwrite(STDOUT, "endpoint={$endpoint} dns=" . ($resolved ? 'ok' : 'failed') . ' tls=' . ($tls ? 'ok' : 'failed') . PHP_EOL);
        }

        return $failed === 0 ? 0 : 1;
    }

    /** @param array<string,string|bool> $options */
    private static function testApi(string $configPath, string $envPath, array $options): int
    {
        $pod = self::selectedPod($configPath, (string) ($options['site'] ?? ''));
        $env = self::readEnv($envPath);
        $credential = [
            'username' => (string) ($env['WINDOWS_AGENT_HORIZON_API_USERNAME'] ?? ''),
            'password' => (string) ($env['WINDOWS_AGENT_HORIZON_API_PASSWORD'] ?? ''),
            'domain' => (string) ($env['WINDOWS_AGENT_HORIZON_API_DOMAIN'] ?? ''),
        ];
        if ($credential['username'] === '' || $credential['password'] === '') throw new HorizonFailure('credentials_missing');
        $snapshot = (new PodCollector())->collect($pod, $credential);
        $meta = $snapshot['horizon_central_meta'];
        $api = $snapshot['horizon_api_summary'];
        fwrite(STDOUT, 'Read-only API test succeeded. site=' . $pod['site'] . ' source=' . $meta['source_endpoint'] . ' members=' . $api['connection_servers_total'] . ' pools=' . $snapshot['horizon_pools_summary']['pools_total'] . PHP_EOL);

        return 0;
    }

    /** @param array<string,string|bool> $options */
    private static function enableWorker(
        string $root,
        string $configPath,
        array $options
    ): int {
        $unitDir = rtrim((string) ($options['unit-dir'] ?? '/etc/systemd/system'), '/');
        $localWriteOnly = isset($options['no-systemctl']) && $unitDir !== '/etc/systemd/system';
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0 && ! $localWriteOnly) {
            throw new HorizonFailure('worker_enable_requires_root');
        }
        if (! is_file($configPath)) {
            throw new HorizonFailure('configuration_missing');
        }
        $config = self::readConfig($configPath);
        if (($config['pods'] ?? []) === []) {
            throw new HorizonFailure('no_pods_configured');
        }
        $worker = $root . '/windows-agent-overlay/horizon-central-worker.php';
        $collector = $root . '/windows-agent-overlay/horizon-central-collector.php';
        if (! is_file($worker) || ! is_file($collector)) {
            throw new HorizonFailure('central_worker_not_installed');
        }

        $workerUnit = <<<'UNIT'
[Unit]
Description=LibreNMS Windows Agent Horizon central trigger worker
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=librenms
Group=librenms
ExecStart=/usr/bin/php __WORKER__ --librenms-root __ROOT__
Restart=on-failure
RestartSec=5s
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
UNIT;
        $fallbackUnit = <<<'UNIT'
[Unit]
Description=LibreNMS Windows Agent Horizon five-minute fallback
After=network-online.target

[Service]
Type=oneshot
User=librenms
Group=librenms
ExecStart=/usr/bin/php __COLLECTOR__ --librenms-root __ROOT__ --fallback
NoNewPrivileges=true
PrivateTmp=true
UNIT;
        $timerUnit = <<<'UNIT'
[Unit]
Description=LibreNMS Windows Agent Horizon five-minute fallback timer

[Timer]
OnBootSec=5min
OnUnitActiveSec=5min
AccuracySec=15s
Persistent=true
Unit=librenms-windows-agent-horizon-fallback.service

[Install]
WantedBy=timers.target
UNIT;
        $replace = [
            '__WORKER__' => self::systemdEscape($worker),
            '__COLLECTOR__' => self::systemdEscape($collector),
            '__ROOT__' => self::systemdEscape($root),
        ];
        self::atomicWrite(
            $unitDir . '/librenms-windows-agent-horizon-worker.service',
            strtr($workerUnit, $replace) . PHP_EOL,
            0644,
            true
        );
        self::atomicWrite(
            $unitDir . '/librenms-windows-agent-horizon-fallback.service',
            strtr($fallbackUnit, $replace) . PHP_EOL,
            0644,
            true
        );
        self::atomicWrite(
            $unitDir . '/librenms-windows-agent-horizon-fallback.timer',
            $timerUnit . PHP_EOL,
            0644,
            true
        );

        $cronPath = (string) ($options['cron-path'] ?? '/etc/cron.d/librenms-windows-agent-horizon');
        if (self::isManagedLegacyCron($cronPath)) {
            @unlink($cronPath);
        }
        if (! isset($options['no-systemctl'])) {
            self::systemctl(['daemon-reload']);
            self::systemctl(['enable', '--now', 'librenms-windows-agent-horizon-worker.service']);
            self::systemctl(['enable', '--now', 'librenms-windows-agent-horizon-fallback.timer']);
        }
        fwrite(STDOUT, "Central trigger worker and five-minute fallback enabled.\n");

        return 0;
    }

    /** @param array<string,string|bool> $options */
    private static function disableWorker(array $options): int
    {
        $unitDir = rtrim((string) ($options['unit-dir'] ?? '/etc/systemd/system'), '/');
        $localWriteOnly = isset($options['no-systemctl']) && $unitDir !== '/etc/systemd/system';
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0 && ! $localWriteOnly) {
            throw new HorizonFailure('worker_disable_requires_root');
        }
        if (! isset($options['no-systemctl'])) {
            self::systemctl(['disable', '--now', 'librenms-windows-agent-horizon-worker.service'], true);
            self::systemctl(['disable', '--now', 'librenms-windows-agent-horizon-fallback.timer'], true);
        }
        foreach ([
            'librenms-windows-agent-horizon-worker.service',
            'librenms-windows-agent-horizon-fallback.service',
            'librenms-windows-agent-horizon-fallback.timer',
        ] as $unit) {
            $path = $unitDir . '/' . $unit;
            if (is_file($path) && ! unlink($path)) {
                throw new HorizonFailure('worker_unit_remove_failed');
            }
        }
        $cronPath = (string) ($options['cron-path'] ?? '/etc/cron.d/librenms-windows-agent-horizon');
        if (self::isManagedLegacyCron($cronPath)) {
            @unlink($cronPath);
        }
        if (! isset($options['no-systemctl'])) {
            self::systemctl(['daemon-reload']);
        }
        fwrite(STDOUT, "Central trigger worker and fallback disabled.\n");

        return 0;
    }

    /** @param array<string,string|bool> $options */
    private static function workerStatus(array $options): int
    {
        $unitDir = rtrim((string) ($options['unit-dir'] ?? '/etc/systemd/system'), '/');
        foreach ([
            'worker' => 'librenms-windows-agent-horizon-worker.service',
            'fallback' => 'librenms-windows-agent-horizon-fallback.service',
            'timer' => 'librenms-windows-agent-horizon-fallback.timer',
        ] as $name => $unit) {
            fwrite(STDOUT, $name . '=' . (is_file($unitDir . '/' . $unit) ? 'installed' : 'absent') . PHP_EOL);
        }

        return 0;
    }

    /** @return array<string,mixed> */
    private static function selectedPod(string $path, string $site): array
    {
        $site = strtolower(trim($site));
        if ($site === '') throw new HorizonFailure('missing_site');
        foreach ((self::readConfig($path)['pods'] ?? []) as $pod) {
            if (is_array($pod) && strcasecmp((string) ($pod['site'] ?? ''), $site) === 0) return $pod;
        }
        throw new HorizonFailure('site_not_found');
    }

    /** @return array<string,mixed> */
    private static function readConfig(string $path): array
    {
        if (! is_file($path)) return ['version' => 1, 'pods' => []];
        try {
            $config = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new HorizonFailure('invalid_configuration_json');
        }
        if (! is_array($config) || ! is_array($config['pods'] ?? null)) throw new HorizonFailure('invalid_configuration_json');

        return $config;
    }

    /** @param array<string,mixed> $config */
    private static function writeConfig(string $path, array $config): void
    {
        foreach ($config['pods'] as $pod) PodCollector::validateConfig($pod);
        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        self::atomicWrite($path, $json, 0600, false);
    }

    /** @return array<string,string> */
    public static function readEnv(string $path): array
    {
        if (! is_file($path)) return [];
        $result = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (! preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $match)) continue;
            $value = trim($match[2]);
            if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                $value = stripcslashes(substr($value, 1, -1));
            }
            $result[$match[1]] = $value;
        }

        return $result;
    }

    /** @param array<string,string|null> $updates */
    public static function updateEnv(string $path, array $updates): void
    {
        if (! is_file($path)) throw new HorizonFailure('env_not_found');
        $original = (string) file_get_contents($path);
        $lines = preg_split('/\r?\n/', $original) ?: [];
        $remaining = $updates;
        $managedKeys = array_fill_keys(array_keys($updates), true);
        $output = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $match) && isset($managedKeys[$match[1]])) {
                if (array_key_exists($match[1], $remaining)) {
                    $value = $remaining[$match[1]];
                    if ($value !== null) $output[] = $match[1] . '=' . self::envQuote($value);
                    unset($remaining[$match[1]]);
                }
                continue;
            }
            $output[] = $line;
        }
        foreach ($remaining as $key => $value) {
            if ($value !== null) $output[] = $key . '=' . self::envQuote($value);
        }
        while ($output !== [] && end($output) === '') array_pop($output);
        $new = implode(PHP_EOL, $output) . PHP_EOL;
        $backup = $path . '.horizon-backup';
        if (! copy($path, $backup)) throw new HorizonFailure('env_backup_failed');
        chmod($backup, 0600);
        try {
            self::atomicWrite($path, $new, 0600, false);
            $parsed = self::readEnv($path);
            foreach ($updates as $key => $value) {
                if ($value === null ? array_key_exists($key, $parsed) : (($parsed[$key] ?? null) !== $value)) throw new HorizonFailure('env_validation_failed');
            }
            @unlink($backup);
        } catch (Throwable $failure) {
            copy($backup, $path);
            chmod($path, 0600);
            @unlink($backup);
            throw $failure;
        }
    }

    private static function envQuote(string $value): string
    {
        return '"' . str_replace(
            ['\\', '"', '$', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\$', '\\n', '\\r', '\\t'],
            $value
        ) . '"';
    }

    private static function atomicWrite(string $path, string $content, int $mode, bool $rootOwned): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, $rootOwned ? 0755 : 0700, true) && ! is_dir($directory)) throw new HorizonFailure('configuration_directory_unavailable');
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $content, LOCK_EX) === false) throw new HorizonFailure('configuration_write_failed');
        chmod($tmp, $mode);
        if (! rename($tmp, $path)) {
            @unlink($tmp);
            throw new HorizonFailure('configuration_write_failed');
        }
    }

    private static function showCapability(): int
    {
        $path = __DIR__ . '/capabilities.json';
        if (! is_file($path)) {
            throw new HorizonFailure('capability_manifest_missing');
        }
        fwrite(STDOUT, (string) file_get_contents($path));

        return 0;
    }

    private static function isManagedLegacyCron(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }
        $marker = '# Managed by LibreNMS Windows Agent overlay; collection is inactive without local pod configuration.';

        return str_contains((string) file_get_contents($path), $marker);
    }

    private static function systemdEscape(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /** @param list<string> $arguments */
    private static function systemctl(array $arguments, bool $allowFailure = false): void
    {
        $command = array_merge(['/usr/bin/systemctl'], $arguments);
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ], $pipes);
        if (! is_resource($process)) {
            throw new HorizonFailure('systemctl_start_failed');
        }
        $exit = proc_close($process);
        if ($exit !== 0 && ! $allowFailure) {
            throw new HorizonFailure('systemctl_failed');
        }
    }

    private static function prompt(string $label, bool $secret): string
    {
        fwrite(STDOUT, $label);
        if ($secret && DIRECTORY_SEPARATOR === '/') shell_exec('stty -echo 2>/dev/null');
        try {
            $value = fgets(STDIN);
        } finally {
            if ($secret && DIRECTORY_SEPARATOR === '/') {
                shell_exec('stty echo 2>/dev/null');
                fwrite(STDOUT, PHP_EOL);
            }
        }

        return rtrim((string) $value, "\r\n");
    }

    private static function usage(): int
    {
        fwrite(STDOUT, <<<'HELP'
Horizon central collector configuration

  credential set|rotate|remove
  pod add --site abc --dns-suffix example.test --display-device abc-vcs2.example.test
  pod discover --dns-suffix example.test [--apply]
  pod list
  pod enable|disable|remove --site abc
  config validate|status
  test network|api --site abc
  worker enable|disable|status
  schedule enable|disable|status  (compatibility alias)
  capability show

Credentials are prompted securely and are never accepted as command-line values.
HELP
        );

        return 2;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(HorizonCentralConfiguration::main($argv));
}
