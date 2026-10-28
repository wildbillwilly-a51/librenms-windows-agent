<?php

// Deterministic writer interleaving, not a substitute for a real database lock test.
namespace WindowsAgentOverlay {
    // Override the namespace-local builtin only in fixtures; production reads time().
    function time(): int { return \WindowsAgentFixtureStorage::$now; }
}

namespace Illuminate\Support\Facades {
    final class DB
    {
        public static function transaction(callable $callback): mixed
        {
            \WindowsAgentFixtureStorage::$transactions++;
            \WindowsAgentFixtureStorage::$transaction = true;
            try {
                return $callback();
            } finally {
                \WindowsAgentFixtureStorage::$transaction = false;
                \WindowsAgentFixtureStorage::$locked = false;
            }
        }
    }
}

namespace {
    final class WindowsAgentFixtureApplicationQuery
    {
        private int $id = 0;
        private bool $lock = false;

        public function whereKey(int $id): self { $this->id = $id; return $this; }
        public function lockForUpdate(): self { $this->lock = true; return $this; }
        public function first(): ?\App\Models\Application
        {
            if (! $this->lock || ! WindowsAgentFixtureStorage::$transaction) {
                throw new \RuntimeException('Application must be re-read under a transaction row lock');
            }
            WindowsAgentFixtureStorage::$locked = true;
            $stored = WindowsAgentFixtureStorage::$stored;
            return $stored && $stored->app_id === $this->id ? clone $stored : null;
        }
    }

    final class WindowsAgentFixtureStorage
    {
        public static ?\App\Models\Application $stored = null;
        public static array $metrics = [];
        public static bool $transaction = false;
        public static bool $locked = false;
        public static int $transactions = 0;
        public static int $now = 1800000000;
        public static ?array $concurrentData = null;
        public static array $concurrentMetrics = [];
        public static bool $interleaved = false;

        public static function reset(\App\Models\Application $app): void
        {
            self::$stored = clone $app;
            self::$metrics = [];
            self::$transaction = self::$locked = self::$interleaved = false;
            self::$transactions = 0;
            self::$concurrentData = null;
            self::$concurrentMetrics = [];
        }

        public static function datastoreWrite(): void
        {
            if (self::$transaction) throw new \RuntimeException('RRD I/O occurred inside application transaction');
            if (self::$concurrentData === null || self::$interleaved) return;
            self::$interleaved = true;
            self::$stored->data = array_replace(self::$stored->data, self::$concurrentData);
            self::applyMetrics(self::$concurrentMetrics, false);
        }

        // Mirrors pinned core update_application: float cast, previous changed value,
        // and deletion of omitted names only for nonempty updates.
        public static function applyMetrics(array $fields, bool $replace): void
        {
            if ($fields === []) return;
            $old = self::$metrics;
            if ($replace) self::$metrics = [];
            foreach ($fields as $name => $value) {
                $value = (float) $value;
                $previous = $old[$name] ?? ['value' => $value, 'value_prev' => null];
                self::$metrics[$name] = [
                    'value' => $value,
                    'value_prev' => $value != $previous['value'] ? $previous['value'] : $previous['value_prev'],
                ];
            }
        }

        public static function save(\App\Models\Application $app, array $fields): void
        {
            if (! self::$locked || ! self::$transaction) throw new \RuntimeException('Unlocked application save');
            self::$stored = clone $app;
            self::applyMetrics($fields, true);
        }
    }
}
