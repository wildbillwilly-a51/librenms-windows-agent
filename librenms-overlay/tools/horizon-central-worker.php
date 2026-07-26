#!/usr/bin/env php
<?php

declare(strict_types=1);

use WindowsAgentOverlay\Horizon\HorizonFailure;
use WindowsAgentOverlay\Horizon\RedisHorizonCoordination;

require_once __DIR__ . '/horizon-central-collector.php';

final class HorizonCentralWorker
{
    /** @param array<string,mixed> $options */
    public static function run(array $options): int
    {
        $root = rtrim((string) ($options['librenms-root'] ?? getenv('LIBRENMS_ROOT') ?: '/opt/librenms'), '/');
        $configPath = (string) ($options['config'] ?? $root . '/.horizon-pods.json');
        $stateDir = (string) ($options['state-dir'] ?? $root . '/storage/app/windows-agent-horizon');
        $once = isset($options['once']);
        $idleSeconds = max(1, min(10, (int) ($options['idle-seconds'] ?? 2)));
        $maxIterations = max(0, (int) ($options['max-iterations'] ?? 0));

        if (! is_file($configPath)) {
            self::log('configuration_missing');

            return 2;
        }

        HorizonCentralRuntime::bootLibreNms($root);
        $coordination = new RedisHorizonCoordination();
        self::reconcileRegistrations($configPath, $coordination);
        $running = true;
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$running): void {
                $running = false;
            });
            pcntl_signal(SIGINT, static function () use (&$running): void {
                $running = false;
            });
        }

        $iterations = 0;
        while ($running) {
            try {
                $site = $coordination->consume();
                if ($site !== null) {
                    HorizonCentralRuntime::run([
                        'librenms-root' => $root,
                        'config' => $configPath,
                        'state-dir' => $stateDir,
                        'site' => $site,
                    ], $coordination);
                    if ($once) {
                        return 0;
                    }
                } elseif ($once) {
                    return 0;
                } else {
                    sleep($idleSeconds);
                }
            } catch (Throwable) {
                self::log('coordination_unavailable');
                if ($once) {
                    return 1;
                }
                sleep($idleSeconds);
            }

            $iterations++;
            if ($maxIterations > 0 && $iterations >= $maxIterations) {
                break;
            }
        }

        return 0;
    }

    private static function reconcileRegistrations(
        string $configPath,
        RedisHorizonCoordination $coordination
    ): void {
        try {
            $config = json_decode((string) file_get_contents($configPath), true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new HorizonFailure('invalid_configuration_json');
        }
        if (! is_array($config) || ! is_array($config['pods'] ?? null)) {
            throw new HorizonFailure('invalid_configuration_json');
        }
        foreach ($config['pods'] as $pod) {
            if (! is_array($pod) || ! ($pod['enabled'] ?? true)) {
                continue;
            }
            HorizonCentralRuntime::registerPod($pod, $coordination);
        }
    }

    private static function log(string $state): void
    {
        fwrite(
            STDOUT,
            gmdate('c') . ' horizon-central-worker state=' .
            preg_replace('/[^a-z0-9_-]/i', '', $state) . PHP_EOL
        );
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = getopt('', [
        'librenms-root:', 'config:', 'state-dir:', 'idle-seconds:',
        'max-iterations:', 'once',
    ]);
    exit(HorizonCentralWorker::run(is_array($options) ? $options : []));
}
