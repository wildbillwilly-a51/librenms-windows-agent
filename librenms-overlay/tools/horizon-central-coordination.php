<?php

declare(strict_types=1);

namespace WindowsAgentOverlay\Horizon;

use Illuminate\Support\Facades\Redis;
use Throwable;

interface HorizonCoordination
{
    /** @param array{site:string,display_device:string,device_id:int} $registration */
    public function register(array $registration): void;

    public function unregister(string $site, int $deviceId, string $hostname): void;

    /** @return array{site:string,display_device:string,device_id:int}|null */
    public function registrationForDevice(int $deviceId, string $hostname): ?array;

    public function emit(string $site): bool;

    public function consume(): ?string;

    public function acquire(string $site, int $ttlSeconds): ?string;

    public function release(string $site, string $token): void;

    public function cooldownActive(string $site): bool;

    public function markCooldown(string $site, int $seconds): void;
}

final class RedisHorizonCoordination implements HorizonCoordination
{
    private const KEY_PREFIX = 'windows-agent:horizon:v1:';

    public function register(array $registration): void
    {
        $site = self::site((string) ($registration['site'] ?? ''));
        $hostname = self::hostname((string) ($registration['display_device'] ?? ''));
        $deviceId = (int) ($registration['device_id'] ?? 0);
        if ($deviceId < 1) {
            throw new HorizonFailure('invalid_display_device_id');
        }

        $value = json_encode([
            'site' => $site,
            'display_device' => $hostname,
            'device_id' => $deviceId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $redis = Redis::connection();
        $redis->set(self::deviceKey($deviceId), $value);
        $redis->set(self::hostKey($hostname), $value);
        $redis->sadd(self::KEY_PREFIX . 'registrations', $site);
    }

    public function unregister(string $site, int $deviceId, string $hostname): void
    {
        $site = self::site($site);
        $hostname = self::hostname($hostname);
        $redis = Redis::connection();
        foreach ([self::deviceKey($deviceId), self::hostKey($hostname)] as $key) {
            $value = $redis->get($key);
            if (! is_string($value)) {
                continue;
            }
            $registration = self::decodeRegistration($value);
            if ($registration !== null && $registration['site'] === $site) {
                $redis->del($key);
            }
        }
        $redis->srem(self::KEY_PREFIX . 'registrations', $site);
    }

    public function registrationForDevice(int $deviceId, string $hostname): ?array
    {
        $redis = Redis::connection();
        $value = $deviceId > 0 ? $redis->get(self::deviceKey($deviceId)) : null;
        if (! is_string($value) && trim($hostname) !== '') {
            $value = $redis->get(self::hostKey(self::hostname($hostname)));
        }

        return is_string($value) ? self::decodeRegistration($value) : null;
    }

    public function emit(string $site): bool
    {
        $site = self::site($site);
        $redis = Redis::connection();
        $added = (int) $redis->sadd(self::KEY_PREFIX . 'pending', $site);
        $redis->expire(self::KEY_PREFIX . 'pending', 86400);

        return $added === 1;
    }

    public function consume(): ?string
    {
        $site = Redis::connection()->spop(self::KEY_PREFIX . 'pending');
        if (! is_string($site) || $site === '') {
            return null;
        }

        try {
            return self::site($site);
        } catch (Throwable) {
            return null;
        }
    }

    public function acquire(string $site, int $ttlSeconds): ?string
    {
        $site = self::site($site);
        $token = bin2hex(random_bytes(16));
        $script = <<<'LUA'
if redis.call('exists', KEYS[1]) == 0 then
  redis.call('setex', KEYS[1], ARGV[2], ARGV[1])
  return 1
end
return 0
LUA;
        $acquired = (int) Redis::connection()->eval(
            $script,
            1,
            self::lockKey($site),
            $token,
            max(10, $ttlSeconds)
        );

        return $acquired === 1 ? $token : null;
    }

    public function release(string $site, string $token): void
    {
        $script = <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1] then
  return redis.call('del', KEYS[1])
end
return 0
LUA;
        Redis::connection()->eval($script, 1, self::lockKey(self::site($site)), $token);
    }

    public function cooldownActive(string $site): bool
    {
        return (int) Redis::connection()->exists(self::cooldownKey(self::site($site))) > 0;
    }

    public function markCooldown(string $site, int $seconds): void
    {
        Redis::connection()->setex(
            self::cooldownKey(self::site($site)),
            max(1, $seconds),
            (string) time()
        );
    }

    private static function deviceKey(int $deviceId): string
    {
        return self::KEY_PREFIX . 'display:device:' . $deviceId;
    }

    private static function hostKey(string $hostname): string
    {
        return self::KEY_PREFIX . 'display:host:' . $hostname;
    }

    private static function lockKey(string $site): string
    {
        return self::KEY_PREFIX . 'lock:' . $site;
    }

    private static function cooldownKey(string $site): string
    {
        return self::KEY_PREFIX . 'cooldown:' . $site;
    }

    private static function site(string $site): string
    {
        $site = strtolower(trim($site));
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,14}$/', $site) !== 1) {
            throw new HorizonFailure('invalid_site');
        }

        return $site;
    }

    private static function hostname(string $hostname): string
    {
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        if (strlen($hostname) > 253 || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $hostname) !== 1) {
            throw new HorizonFailure('invalid_display_device');
        }

        return $hostname;
    }

    /** @return array{site:string,display_device:string,device_id:int}|null */
    private static function decodeRegistration(string $value): ?array
    {
        try {
            $registration = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($registration)) {
                return null;
            }

            return [
                'site' => self::site((string) ($registration['site'] ?? '')),
                'display_device' => self::hostname((string) ($registration['display_device'] ?? '')),
                'device_id' => (int) ($registration['device_id'] ?? 0),
            ];
        } catch (Throwable) {
            return null;
        }
    }
}

final class HorizonTriggerProducer
{
    public static function emitForDevice(
        int $deviceId,
        string $hostname,
        ?HorizonCoordination $coordination = null
    ): bool {
        try {
            $coordination ??= new RedisHorizonCoordination();
            $registration = $coordination->registrationForDevice($deviceId, $hostname);
            if ($registration === null || $registration['device_id'] !== $deviceId) {
                return false;
            }

            return $coordination->emit($registration['site']);
        } catch (Throwable) {
            return false;
        }
    }
}

final class HorizonCollectionCoordinator
{
    public function __construct(
        private readonly HorizonCoordination $coordination,
        private readonly int $lockSeconds = 240,
        private readonly int $cooldownSeconds = 240,
        private readonly int $failureCooldownSeconds = 30,
    ) {
    }

    /**
     * @param callable():array{fresh:bool} $collect
     * @return 'fresh'|'stale'|'locked'|'cooldown'
     */
    public function collect(string $site, bool $force, callable $collect): string
    {
        $token = $this->coordination->acquire($site, $this->lockSeconds);
        if ($token === null) {
            return 'locked';
        }

        try {
            if (! $force && $this->coordination->cooldownActive($site)) {
                return 'cooldown';
            }

            try {
                $result = $collect();
            } catch (Throwable $failure) {
                $this->coordination->markCooldown($site, $this->failureCooldownSeconds);
                throw $failure;
            }
            $fresh = (bool) ($result['fresh'] ?? false);
            $this->coordination->markCooldown(
                $site,
                $fresh ? $this->cooldownSeconds : $this->failureCooldownSeconds
            );

            return $fresh ? 'fresh' : 'stale';
        } finally {
            $this->coordination->release($site, $token);
        }
    }
}
