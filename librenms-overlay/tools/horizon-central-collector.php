#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\ApplicationMetric;
use App\Models\Device;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use LibreNMS\RRD\RrdDefinition;
use WindowsAgentOverlay\Horizon\HorizonFailure;
use WindowsAgentOverlay\Horizon\PodCollector;

require_once __DIR__ . '/horizon-central-lib.php';

final class HorizonCentralRuntime
{
    /** @var list<string> */
    private const DATA_KEYS = [
        'horizon_api_summary', 'horizon_api_session_protocols', 'horizon_pod_summary',
        'horizon_pod_members', 'horizon_configuration_replications', 'horizon_directory_summary',
        'horizon_directory_domains', 'horizon_directory_member_status', 'horizon_gateways',
        'horizon_pools_summary', 'horizon_pools', 'horizon_pool_machine_states', 'horizon_central_meta',
    ];

    /** @param array<string,mixed> $options */
    public static function run(array $options): int
    {
        $root = rtrim((string) ($options['librenms-root'] ?? getenv('LIBRENMS_ROOT') ?: '/opt/librenms'), '/');
        $configPath = (string) ($options['config'] ?? $root . '/.horizon-pods.json');
        $stateDir = (string) ($options['state-dir'] ?? $root . '/storage/app/windows-agent-horizon');
        $siteFilter = strtolower((string) ($options['site'] ?? ''));
        $dryRun = isset($options['dry-run']);

        if (! is_file($configPath)) {
            return 0; // Overlay may be installed everywhere; collection is opt-in per node.
        }
        $config = self::readJson($configPath);
        $pods = is_array($config['pods'] ?? null) ? $config['pods'] : [];
        if ($pods === []) {
            return 0;
        }
        $credential = self::credential($root);
        if (! $dryRun && ($credential['username'] === '' || $credential['password'] === '')) {
            self::log('configuration_error', 'credentials_missing');

            return 2;
        }

        if (! $dryRun) {
            self::bootLibreNms($root);
        }
        if (! is_dir($stateDir) && ! mkdir($stateDir, 0700, true) && ! is_dir($stateDir)) {
            self::log('state_error', 'state_directory_unavailable');

            return 2;
        }
        $lockPath = $stateDir . '/collector.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return 0;
        }

        $failed = 0;
        try {
            foreach ($pods as $pod) {
                if (! is_array($pod) || ! ($pod['enabled'] ?? true)) continue;
                $site = strtolower((string) ($pod['site'] ?? ''));
                if ($siteFilter !== '' && $site !== $siteFilter) continue;
                try {
                    PodCollector::validateConfig($pod);
                    if ($dryRun) {
                        self::log($site, 'configuration_valid');
                        continue;
                    }
                    $statePath = $stateDir . '/' . $site . '.json';
                    $previous = is_file($statePath) ? self::readJson($statePath) : [];
                    $snapshot = (new PodCollector())->collect($pod, $credential, $previous);
                    self::atomicJson($statePath, $snapshot, 0600);
                    self::publish($pod, $snapshot);
                    $meta = $snapshot['horizon_central_meta'];
                    self::log($site, ((int) ($meta['stale'] ?? 0) === 1 ? 'stale' : 'ok') . ' source=' . ($meta['source_endpoint'] ?? 'none'));
                } catch (HorizonFailure $failure) {
                    $failed++;
                    self::log($site !== '' ? $site : 'configuration_error', $failure->reason);
                } catch (Throwable) {
                    $failed++;
                    self::log($site !== '' ? $site : 'runtime_error', 'internal_error');
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $failed === 0 ? 0 : 1;
    }

    private static function bootLibreNms(string $root): void
    {
        $autoload = $root . '/vendor/autoload.php';
        $bootstrap = $root . '/bootstrap/app.php';
        if (! is_file($autoload) || ! is_file($bootstrap)) {
            throw new HorizonFailure('librenms_bootstrap_missing');
        }
        require_once $autoload;
        if (! defined('LARAVEL_START')) define('LARAVEL_START', microtime(true));
        $app = require $bootstrap;
        $app->make(Kernel::class)->bootstrap();
    }

    /** @return array{username:string,password:string,domain:string} */
    private static function credential(string $root): array
    {
        $fileValues = self::readEnvFile($root . '/.env');
        $read = static function (string $key): string {
            $value = getenv($key);
            if ($value === false) $value = $_ENV[$key] ?? $_SERVER[$key] ?? '';

            return (string) $value;
        };

        return [
            'username' => $read('WINDOWS_AGENT_HORIZON_API_USERNAME') ?: ($fileValues['WINDOWS_AGENT_HORIZON_API_USERNAME'] ?? ''),
            'password' => $read('WINDOWS_AGENT_HORIZON_API_PASSWORD') ?: ($fileValues['WINDOWS_AGENT_HORIZON_API_PASSWORD'] ?? ''),
            'domain' => $read('WINDOWS_AGENT_HORIZON_API_DOMAIN') ?: ($fileValues['WINDOWS_AGENT_HORIZON_API_DOMAIN'] ?? ''),
        ];
    }

    /** @return array<string,string> */
    private static function readEnvFile(string $path): array
    {
        if (! is_file($path)) return [];
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (! preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $match)) continue;
            $value = trim($match[2]);
            if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                $value = stripcslashes(substr($value, 1, -1));
            }
            $values[$match[1]] = $value;
        }

        return $values;
    }

    /** @param array<string,mixed> $pod @param array<string,mixed> $snapshot */
    private static function publish(array $pod, array $snapshot): void
    {
        $device = Device::findByHostname((string) $pod['display_device']);
        if (! $device) throw new HorizonFailure('display_device_not_found');
        $app = Application::query()->where('device_id', $device->device_id)->where('app_type', 'windows-agent')->where('app_instance', '')->whereNull('deleted_at')->first();
        if (! $app) throw new HorizonFailure('windows_agent_application_not_found');

        DB::transaction(static function () use ($app, $snapshot): void {
            /** @var Application|null $locked */
            $locked = Application::query()->whereKey($app->app_id)->lockForUpdate()->first();
            if (! $locked) throw new HorizonFailure('windows_agent_application_not_found');
            $data = is_array($locked->data) ? $locked->data : [];
            foreach (self::DATA_KEYS as $key) {
                if (array_key_exists($key, $snapshot)) $data[$key] = $snapshot[$key];
            }
            $locked->data = $data;
            $locked->save();
            self::updateMetrics($locked->app_id, self::metrics($snapshot));
        });

        if ((int) ($snapshot['horizon_central_meta']['stale'] ?? 0) === 0) {
            self::writeRrds($device->toArray(), $app->app_id, self::metrics($snapshot));
        }
    }

    /** @param array<string,mixed> $snapshot @return array<string,int|float> */
    public static function metrics(array $snapshot): array
    {
        $api = $snapshot['horizon_api_summary'] ?? [];
        $pod = $snapshot['horizon_pod_summary'] ?? [];
        $directory = $snapshot['horizon_directory_summary'] ?? [];
        $pools = $snapshot['horizon_pools_summary'] ?? [];
        $meta = $snapshot['horizon_central_meta'] ?? [];

        return [
            'horizon_api_available' => in_array(strtolower((string) ($api['state'] ?? '')), ['ok', 'partial'], true) ? 1 : 0,
            'horizon_api_connection_servers_total' => (int) ($api['connection_servers_total'] ?? 0),
            'horizon_api_connection_servers_unhealthy' => (int) ($api['connection_servers_unhealthy'] ?? 0),
            'horizon_api_services_unhealthy' => (int) ($api['services_unhealthy'] ?? 0),
            'horizon_api_replications_unhealthy' => (int) ($api['replications_unhealthy'] ?? 0),
            'horizon_api_certificates_invalid' => (int) ($api['certificates_invalid'] ?? 0),
            'horizon_api_sessions_total' => (int) ($api['sessions_total'] ?? 0),
            'horizon_api_sessions_connected' => (int) ($api['sessions_connected'] ?? 0),
            'horizon_api_sessions_disconnected' => (int) ($api['sessions_disconnected'] ?? 0),
            'horizon_api_sessions_other' => (int) ($api['sessions_other'] ?? 0),
            'horizon_api_sessions_truncated' => (int) ($api['sessions_truncated'] ?? 0),
            'horizon_pod_members_total' => (int) ($pod['members_total'] ?? 0),
            'horizon_pod_members_unhealthy' => (int) ($pod['members_unhealthy'] ?? 0),
            'horizon_pod_replications_total' => (int) ($pod['configuration_replications_total'] ?? 0),
            'horizon_pod_replications_unhealthy' => (int) ($pod['configuration_replications_unhealthy'] ?? 0),
            'horizon_directory_links_total' => (int) ($directory['member_links_total'] ?? 0),
            'horizon_directory_links_unhealthy' => (int) ($directory['member_links_unhealthy'] ?? 0),
            'horizon_gateways_total' => (int) ($pod['gateways_total'] ?? 0),
            'horizon_gateways_unhealthy' => (int) ($pod['gateways_unhealthy'] ?? 0),
            'horizon_pools_total' => (int) ($pools['pools_total'] ?? 0),
            'horizon_pools_warning' => (int) ($pools['pools_warning'] ?? 0),
            'horizon_pools_critical' => (int) ($pools['pools_critical'] ?? 0),
            'horizon_pools_incomplete' => (int) ($pools['pools_incomplete'] ?? 0),
            'horizon_spare_total' => (int) ($pools['spare_total'] ?? 0),
            'horizon_spare_ready' => (int) ($pools['spare_ready'] ?? 0),
            'horizon_spare_unready' => (int) ($pools['spare_unready'] ?? 0),
            'horizon_central_stale' => (int) ($meta['stale'] ?? 0),
            'horizon_central_snapshot_age_seconds' => (int) ($meta['snapshot_age_seconds'] ?? -1),
        ];
    }

    /** @param array<string,int|float> $metrics */
    private static function updateMetrics(int $appId, array $metrics): void
    {
        foreach ($metrics as $name => $value) {
            $metric = ApplicationMetric::query()->where('app_id', $appId)->where('metric', $name)->first();
            if (! $metric) {
                $metric = new ApplicationMetric();
                $metric->app_id = $appId;
                $metric->metric = $name;
            } elseif ((float) $metric->value !== (float) $value) {
                $metric->value_prev = $metric->value;
            }
            $metric->value = $value;
            $metric->save();
        }
    }

    /** @param array<string,mixed> $device @param array<string,int|float> $m */
    private static function writeRrds(array $device, int $appId, array $m): void
    {
        $write = static function (string $name, RrdDefinition $definition, array $fields) use ($device, $appId): void {
            app('Datastore')->put($device, 'app', ['name' => $name, 'app_id' => $appId, 'rrd_name' => ['app', $name, $appId], 'rrd_def' => $definition], $fields);
        };
        $write('windows-agent-horizon-api', RrdDefinition::make()->addDataset('available', 'GAUGE', 0, 1)->addDataset('cs_total', 'GAUGE', 0)->addDataset('cs_unhealthy', 'GAUGE', 0)->addDataset('services_bad', 'GAUGE', 0)->addDataset('repl_bad', 'GAUGE', 0)->addDataset('cert_invalid', 'GAUGE', 0)->addDataset('sessions', 'GAUGE', 0)->addDataset('connected', 'GAUGE', 0)->addDataset('disconnected', 'GAUGE', 0)->addDataset('other', 'GAUGE', 0)->addDataset('truncated', 'GAUGE', 0, 1), [
            'available' => $m['horizon_api_available'], 'cs_total' => $m['horizon_api_connection_servers_total'], 'cs_unhealthy' => $m['horizon_api_connection_servers_unhealthy'], 'services_bad' => $m['horizon_api_services_unhealthy'], 'repl_bad' => $m['horizon_api_replications_unhealthy'], 'cert_invalid' => $m['horizon_api_certificates_invalid'], 'sessions' => $m['horizon_api_sessions_total'], 'connected' => $m['horizon_api_sessions_connected'], 'disconnected' => $m['horizon_api_sessions_disconnected'], 'other' => $m['horizon_api_sessions_other'], 'truncated' => $m['horizon_api_sessions_truncated'],
        ]);
        $write('windows-agent-horizon-platform', RrdDefinition::make()->addDataset('members', 'GAUGE', 0)->addDataset('members_bad', 'GAUGE', 0)->addDataset('repl_bad', 'GAUGE', 0)->addDataset('domain_bad', 'GAUGE', 0)->addDataset('gateways_bad', 'GAUGE', 0)->addDataset('pools', 'GAUGE', 0)->addDataset('pools_warn', 'GAUGE', 0)->addDataset('pools_crit', 'GAUGE', 0)->addDataset('incomplete', 'GAUGE', 0)->addDataset('spare_total', 'GAUGE', 0)->addDataset('spare_ready', 'GAUGE', 0)->addDataset('spare_unready', 'GAUGE', 0), [
            'members' => $m['horizon_pod_members_total'], 'members_bad' => $m['horizon_pod_members_unhealthy'], 'repl_bad' => $m['horizon_pod_replications_unhealthy'], 'domain_bad' => $m['horizon_directory_links_unhealthy'], 'gateways_bad' => $m['horizon_gateways_unhealthy'], 'pools' => $m['horizon_pools_total'], 'pools_warn' => $m['horizon_pools_warning'], 'pools_crit' => $m['horizon_pools_critical'], 'incomplete' => $m['horizon_pools_incomplete'], 'spare_total' => $m['horizon_spare_total'], 'spare_ready' => $m['horizon_spare_ready'], 'spare_unready' => $m['horizon_spare_unready'],
        ]);
    }

    /** @return array<string,mixed> */
    private static function readJson(string $path): array
    {
        try {
            $value = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new HorizonFailure('invalid_configuration_json');
        }
        if (! is_array($value)) throw new HorizonFailure('invalid_configuration_json');

        return $value;
    }

    /** @param array<string,mixed> $value */
    private static function atomicJson(string $path, array $value, int $mode): void
    {
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new HorizonFailure('state_write_failed');
        chmod($tmp, $mode);
        if (! rename($tmp, $path)) {
            @unlink($tmp);
            throw new HorizonFailure('state_write_failed');
        }
    }

    private static function log(string $site, string $message): void
    {
        fwrite(STDOUT, gmdate('c') . ' horizon-central site=' . preg_replace('/[^a-z0-9_-]/i', '', $site) . ' state=' . preg_replace('/[^a-z0-9_.,:=\/-]/i', '', $message) . PHP_EOL);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = getopt('', ['librenms-root:', 'config:', 'state-dir:', 'site:', 'dry-run']);
    exit(HorizonCentralRuntime::run(is_array($options) ? $options : []));
}
