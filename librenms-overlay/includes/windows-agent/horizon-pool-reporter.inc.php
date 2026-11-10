<?php

namespace WindowsAgentOverlay;

use App\Models\Application;
use App\Models\Device;

/**
 * Which pod member publishes the per-pool `horizon_pool_*` alert metrics.
 *
 * The central collector copies one pod snapshot to every member, so without a
 * choice every member would carry the same pool metrics and one bad pool would
 * alert once per member. A pod may list its servers in priority order as
 * `pool_reporters`; only the first listed server that is up emits the pool
 * metrics. The others keep their pod pages but omit those metrics, which
 * LibreNMS then removes from them on their next poll.
 *
 * "Up" is the same test as LibreNMS's `macros.device_up` (status 1, not
 * disabled, not ignored), plus an active windows-agent application. The choice
 * is made at each device's own save, against the live device table, so failover
 * and the move back to the first server do not wait for the central collector.
 *
 * Without `pool_reporters` nothing changes: every member emits, as before.
 */
final class HorizonPoolReporter
{
    public const META_KEY = 'pool_reporters';
    public const MAX_REPORTERS = 8;

    /**
     * A configured name as a LibreNMS hostname: lowercase, with the pod's DNS
     * suffix added to a short name. The same rule as pod member names.
     */
    public static function hostname(string $name, string $suffix): string
    {
        $name = strtolower(trim($name));
        $suffix = strtolower(trim($suffix, " \t\n."));

        return $name === '' || str_contains($name, '.') || $suffix === '' ? $name : $name . '.' . $suffix;
    }

    /**
     * The pod's ordered reporter hostnames; an empty list when not configured.
     *
     * @param array<string,mixed> $pod
     * @return list<string>
     */
    public static function configured(array $pod): array
    {
        if (! array_key_exists(self::META_KEY, $pod) || $pod[self::META_KEY] === null || $pod[self::META_KEY] === []) {
            return [];
        }
        $raw = $pod[self::META_KEY];
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new \RuntimeException('invalid_pool_reporters');
        }
        $hosts = [];
        foreach ($raw as $name) {
            if (! is_string($name)) throw new \RuntimeException('invalid_pool_reporters');
            $host = self::hostname($name, (string) ($pod['dns_suffix'] ?? ''));
            if (! self::validHostname($host)) throw new \RuntimeException('invalid_pool_reporters');
            if (! in_array($host, $hosts, true)) $hosts[] = $host;
        }
        if (count($hosts) > self::MAX_REPORTERS) throw new \RuntimeException('too_many_pool_reporters');

        return $hosts;
    }

    /**
     * True when this device should emit the pool metrics for the snapshot it holds.
     *
     * @param array<string,mixed> $meta horizon_central_meta from the saved snapshot
     */
    public static function allows(array $meta, int $deviceId): bool
    {
        $hosts = self::fromMeta($meta);

        return $hosts === [] || self::active($hosts) === $deviceId;
    }

    /**
     * The device id of the first listed host that is up, or 0 when none is.
     *
     * @param list<string> $hosts
     */
    public static function active(array $hosts): int
    {
        foreach ($hosts as $host) {
            $device = Device::findByHostname($host);
            if (! $device) continue;
            if ((int) $device->status !== 1 || (int) $device->disabled !== 0 || (int) $device->ignore !== 0) continue;
            if (! self::hasApplication((int) $device->device_id)) continue;

            return (int) $device->device_id;
        }

        return 0;
    }

    /**
     * One line for the device page: who reports pool metrics now, or null when
     * the pod does not use a reporter list.
     *
     * @param array<string,mixed> $meta
     */
    public static function describe(array $meta, int $deviceId): ?string
    {
        $hosts = self::fromMeta($meta);
        if ($hosts === []) return null;
        $active = self::active($hosts);
        if ($active === 0) return 'No listed server is up (' . implode(', ', $hosts) . ')';
        if ($active === $deviceId) return 'This server';
        $device = Device::find($active);

        return 'Reported by ' . ($device ? (string) $device->hostname : 'device ' . $active);
    }

    /**
     * @param array<string,mixed> $meta
     * @return list<string>
     */
    public static function fromMeta(array $meta): array
    {
        $hosts = $meta[self::META_KEY] ?? [];
        if (! is_array($hosts)) return [];

        return array_values(array_filter($hosts, static fn ($host): bool => is_string($host) && self::validHostname($host)));
    }

    private static function hasApplication(int $deviceId): bool
    {
        return Application::query()
            ->where('device_id', $deviceId)
            ->where('app_type', 'windows-agent')
            ->where('app_instance', '')
            ->whereNull('deleted_at')
            ->first() !== null;
    }

    private static function validHostname(string $value): bool
    {
        return preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $value) === 1;
    }
}
