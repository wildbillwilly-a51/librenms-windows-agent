<?php

declare(strict_types=1);

namespace LibreNMS\RRD {
    final class RrdDefinition
    {
        public array $datasets = [];

        public static function make(): self
        {
            return new self();
        }

        public function addDataset(string $name, string $type, ?int $min = null, ?int $max = null): self
        {
            $this->datasets[] = [
                'name' => $name,
                'type' => $type,
                'min' => $min,
                'max' => $max,
            ];

            return $this;
        }
    }
}

namespace App\Models {
    final class Application
    {
        public int $app_id;
        public array $data = [];

        public function __construct(int $appId)
        {
            $this->app_id = $appId;
        }

        public static function find(int $appId): ?self
        {
            return \WindowsAgentOverlayParserTestState::$application;
        }
    }
}

namespace {
    final class WindowsAgentOverlayParserTestState
    {
        public static ?\App\Models\Application $application = null;
        public static array $datastoreWrites = [];
        public static array $updatedApplication = [];
        public static int $appId = 1;

        public static function reset(int $appId): void
        {
            self::$appId = $appId;
            self::$application = new \App\Models\Application($appId);
            self::$datastoreWrites = [];
            self::$updatedApplication = [];
        }
    }

    final class WindowsAgentOverlayParserDatastore
    {
        public function put(array $device, string $type, array $tags, array $fields): void
        {
            WindowsAgentOverlayParserTestState::$datastoreWrites[] = [
                'device' => $device,
                'type' => $type,
                'tags' => $tags,
                'fields' => $fields,
            ];
        }
    }

    function dbFetchCell(string $query, array $parameters): int
    {
        return WindowsAgentOverlayParserTestState::$appId;
    }

    function dbInsert(array $values, string $table): int
    {
        throw new RuntimeException('dbInsert should not be called when fixture app_id exists');
    }

    function app(string $name): WindowsAgentOverlayParserDatastore
    {
        if ($name !== 'Datastore') {
            throw new RuntimeException("Unexpected app service requested: $name");
        }

        return new WindowsAgentOverlayParserDatastore();
    }

    function update_application(\App\Models\Application $application, string $response, array $fields, string $status): void
    {
        WindowsAgentOverlayParserTestState::$updatedApplication = [
            'app_id' => $application->app_id,
            'response' => $response,
            'fields' => $fields,
            'status' => $status,
        ];
    }

    function fixtureValueAtPath(array $value, string $path): mixed
    {
        $current = $value;
        foreach (explode('.', $path) as $part) {
            if (! is_array($current) || ! array_key_exists($part, $current)) {
                throw new RuntimeException("Missing data path: $path");
            }

            $current = $current[$part];
        }

        return $current;
    }

    function assertFixtureEqual(mixed $expected, mixed $actual, string $label): void
    {
        if (is_int($expected) || is_float($expected)) {
            if ((float) $expected !== (float) $actual) {
                throw new RuntimeException("$label expected $expected, got " . var_export($actual, true));
            }

            return;
        }

        if ($expected !== $actual) {
            throw new RuntimeException("$label expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    function datastoreFieldValue(string $lookup): mixed
    {
        [$name, $field] = explode('.', $lookup, 2);

        foreach (WindowsAgentOverlayParserTestState::$datastoreWrites as $write) {
            if (($write['tags']['name'] ?? '') === $name && array_key_exists($field, $write['fields'])) {
                return $write['fields'][$field];
            }
        }

        throw new RuntimeException("Missing datastore field: $lookup");
    }

    function runFixture(string $fixturePath, string $parserPath): void
    {
        $fixture = json_decode((string) file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
        WindowsAgentOverlayParserTestState::reset((int) $fixture['app_id']);

        $agent_data = $fixture['agent_data'];
        $device = $fixture['device'];

        ob_start();
        include $parserPath;
        ob_end_clean();

        $updated = WindowsAgentOverlayParserTestState::$updatedApplication;
        if (empty($updated)) {
            throw new RuntimeException('update_application was not called');
        }

        $expect = $fixture['expect'];
        $response = $updated['response'];
        if (! str_starts_with($response, $expect['status_prefix'])) {
            throw new RuntimeException("status prefix expected {$expect['status_prefix']}, got $response");
        }

        foreach ($expect['status_contains'] ?? [] as $needle) {
            if (! str_contains($response, $needle)) {
                throw new RuntimeException("status is missing expected text: $needle");
            }
        }

        foreach ($expect['fields'] ?? [] as $field => $expected) {
            if (! array_key_exists($field, $updated['fields'])) {
                throw new RuntimeException("Missing application field: $field");
            }

            assertFixtureEqual($expected, $updated['fields'][$field], "field $field");
        }

        $appData = WindowsAgentOverlayParserTestState::$application?->data ?? [];
        foreach ($expect['data'] ?? [] as $path => $expected) {
            assertFixtureEqual($expected, fixtureValueAtPath($appData, $path), "data $path");
        }

        foreach ($expect['datastore'] ?? [] as $lookup => $expected) {
            assertFixtureEqual($expected, datastoreFieldValue($lookup), "datastore $lookup");
        }
    }

    $repoRoot = dirname(__DIR__, 2);
    $parserPath = $repoRoot . '/librenms-overlay/includes/polling/unix-agent/windows_agent.inc.php';
    $fixtureDir = __DIR__ . '/fixtures';
    $fixtures = glob($fixtureDir . '/*.json') ?: [];
    sort($fixtures);

    if (! is_file($parserPath)) {
        fwrite(STDERR, "Parser file not found: $parserPath\n");
        exit(2);
    }

    if ($fixtures === []) {
        fwrite(STDERR, "No fixtures found in $fixtureDir\n");
        exit(2);
    }

    $failed = 0;
    foreach ($fixtures as $fixturePath) {
        try {
            runFixture($fixturePath, $parserPath);
            echo 'PASS ' . basename($fixturePath) . PHP_EOL;
        } catch (Throwable $ex) {
            $failed++;
            fwrite(STDERR, 'FAIL ' . basename($fixturePath) . ': ' . $ex->getMessage() . PHP_EOL);
        }
    }

    exit($failed === 0 ? 0 : 1);
}
