#!/usr/bin/env php
<?php

declare(strict_types=1);

use WindowsAgentOverlay\Horizon\CurlApiSession;
use WindowsAgentOverlay\Horizon\HorizonFailure;
use WindowsAgentOverlay\Horizon\PodCollector;

require_once __DIR__ . '/horizon-central-lib.php';

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
                'config:validate' => self::validate($configPath, $envPath),
                'config:status' => self::status($configPath, $envPath),
                'test:network' => self::testNetwork($configPath, $options),
                'test:api' => self::testApi($configPath, $envPath, $options),
                'schedule:enable' => self::enableSchedule($root, $options),
                'schedule:disable' => self::disableSchedule($options),
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
        $config['version'] = 1;
        $config['pods'] = array_values($pods);
        self::writeConfig($path, $config);
        fwrite(STDOUT, 'Pod ' . $pod['site'] . ($replaced ? " updated.\n" : " added.\n"));

        return 0;
    }

    /** @param array<string,string|bool> $options */
    private static function changePod(string $path, array $options, string $operation): int
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
        $config['version'] = 1;
        $config['pods'] = array_values($pods);
        self::writeConfig($path, $config);
        fwrite(STDOUT, "Pod {$site} {$operation}d.\n");

        return 0;
    }

    private static function validate(string $configPath, string $envPath): int
    {
        $config = self::readConfig($configPath);
        foreach (($config['pods'] ?? []) as $pod) {
            if (! is_array($pod)) throw new HorizonFailure('invalid_pod');
            PodCollector::validateConfig($pod);
        }
        $env = self::readEnv($envPath);
        $credential = isset($env['WINDOWS_AGENT_HORIZON_API_USERNAME'], $env['WINDOWS_AGENT_HORIZON_API_PASSWORD']) && $env['WINDOWS_AGENT_HORIZON_API_USERNAME'] !== '' && $env['WINDOWS_AGENT_HORIZON_API_PASSWORD'] !== '';
        fwrite(STDOUT, 'Configuration valid. pods=' . count($config['pods'] ?? []) . ' credential=' . ($credential ? 'present' : 'absent') . PHP_EOL);

        return 0;
    }

    private static function status(string $configPath, string $envPath): int
    {
        $config = self::readConfig($configPath);
        $env = self::readEnv($envPath);
        $credential = ($env['WINDOWS_AGENT_HORIZON_API_USERNAME'] ?? '') !== '' && ($env['WINDOWS_AGENT_HORIZON_API_PASSWORD'] ?? '') !== '';
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
    private static function enableSchedule(string $root, array $options): int
    {
        $cronPath = (string) ($options['cron-path'] ?? '/etc/cron.d/librenms-windows-agent-horizon');
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) throw new HorizonFailure('schedule_requires_root');
        $collector = $root . '/windows-agent-overlay/horizon-central-collector.php';
        if (! is_file($collector)) throw new HorizonFailure('collector_not_installed');
        $line = "# Managed by LibreNMS Windows Agent overlay; collection is inactive without local pod configuration.\n";
        $line .= "*/5 * * * * librenms /usr/bin/php " . escapeshellarg($collector) . " >/dev/null\n";
        self::atomicWrite($cronPath, $line, 0644, true);
        fwrite(STDOUT, "Five-minute schedule enabled.\n");

        return 0;
    }

    /** @param array<string,string|bool> $options */
    private static function disableSchedule(array $options): int
    {
        $cronPath = (string) ($options['cron-path'] ?? '/etc/cron.d/librenms-windows-agent-horizon');
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) throw new HorizonFailure('schedule_requires_root');
        if (is_file($cronPath) && ! unlink($cronPath)) throw new HorizonFailure('schedule_remove_failed');
        fwrite(STDOUT, "Schedule disabled.\n");

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
  pod enable|disable|remove --site abc
  config validate|status
  test network|api --site abc
  schedule enable|disable

Credentials are prompted securely and are never accepted as command-line values.
HELP
        );

        return 2;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(HorizonCentralConfiguration::main($argv));
}
