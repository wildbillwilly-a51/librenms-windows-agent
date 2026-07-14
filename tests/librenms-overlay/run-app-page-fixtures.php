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

namespace App\Facades {
    final class LibrenmsConfig
    {
        public static function get(string $key): int
        {
            return 1800000000;
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
            return \LibreNMSAppPageTestState::$application;
        }
    }
}

namespace {
    final class LibreNMSAppPageTestState
    {
        public static ?\App\Models\Application $application = null;
        public static array $updatedApplication = [];
        public static int $appId = 1;

        public static function reset(int $appId): void
        {
            self::$appId = $appId;
            self::$application = new \App\Models\Application($appId);
            self::$updatedApplication = [];
        }
    }

    final class LibreNMSAppPageDatastore
    {
        public function put(array $device, string $type, array $tags, array $fields): void
        {
        }
    }

    final class LibreNMSAppPageFixtureApp implements ArrayAccess
    {
        public int $app_id;
        public array $data;

        public function __construct(int $appId, array $data)
        {
            $this->app_id = $appId;
            $this->data = $data;
        }

        public function offsetExists(mixed $offset): bool
        {
            return $offset === 'app_id';
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $offset === 'app_id' ? $this->app_id : null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            throw new RuntimeException('fixture app is read-only');
        }

        public function offsetUnset(mixed $offset): void
        {
            throw new RuntimeException('fixture app is read-only');
        }
    }

    function dbFetchCell(string $query, array $parameters): int
    {
        return LibreNMSAppPageTestState::$appId;
    }

    function dbInsert(array $values, string $table): int
    {
        throw new RuntimeException('dbInsert should not be called when fixture app_id exists');
    }

    function app(string $name): LibreNMSAppPageDatastore
    {
        if ($name !== 'Datastore') {
            throw new RuntimeException("Unexpected app service requested: $name");
        }

        return new LibreNMSAppPageDatastore();
    }

    function update_application(\App\Models\Application $application, string $response, array $fields, string $status): void
    {
        LibreNMSAppPageTestState::$updatedApplication = [
            'app_id' => $application->app_id,
            'response' => $response,
            'fields' => $fields,
            'status' => $status,
        ];
    }

    function assertContainsText(string $html, string $needle, string $fixture): void
    {
        if (! str_contains($html, $needle)) {
            throw new RuntimeException("$fixture app page is missing expected text: $needle");
        }
    }

    function assertNotContainsText(string $html, string $needle, string $fixture): void
    {
        if (str_contains($html, $needle)) {
            throw new RuntimeException("$fixture app page contains unexpected text: $needle");
        }
    }

    function renderFixture(string $fixturePath, string $parserPath, string $pagePath): string
    {
        $fixture = json_decode((string) file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
        LibreNMSAppPageTestState::reset((int) $fixture['app_id']);

        $agent_data = $fixture['agent_data'];
        $device = $fixture['device'];
        ob_start();
        include $parserPath;
        ob_end_clean();

        $app = new LibreNMSAppPageFixtureApp((int) $fixture['app_id'], LibreNMSAppPageTestState::$application?->data ?? []);
        ob_start();
        include $pagePath;
        return (string) ob_get_clean();
    }

    $repoRoot = dirname(__DIR__, 2);
    $parserPath = $repoRoot . '/librenms-overlay/includes/polling/unix-agent/windows_agent.inc.php';
    $pagePath = $repoRoot . '/librenms-overlay/includes/html/pages/device/apps/windows-agent-windows-agent.inc.php';
    $fixtureDir = __DIR__ . '/fixtures';
    $fixtures = glob($fixtureDir . '/*.json') ?: [];
    sort($fixtures);

    $stubRoot = sys_get_temp_dir() . '/windows-agent-app-page-fixture-stubs';
    @mkdir($stubRoot . '/includes/html', 0777, true);
    file_put_contents(
        $stubRoot . '/includes/html/print-graphrow.inc.php',
        '<?php echo "<div class=\"graph-stub\" data-type=\"" . htmlspecialchars((string) ($graph_array["type"] ?? ""), ENT_QUOTES, "UTF-8") . "\"></div>";'
    );
    set_include_path($stubRoot . PATH_SEPARATOR . get_include_path());

    if (! is_file($parserPath) || ! is_file($pagePath)) {
        fwrite(STDERR, "Required overlay files were not found\n");
        exit(2);
    }

    $failed = 0;
    foreach ($fixtures as $fixturePath) {
        $name = basename($fixturePath);
        try {
            $fixture = json_decode((string) file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
            $html = renderFixture($fixturePath, $parserPath, $pagePath);

            foreach ([
                'Health Overview',
                'id="windows-agent-overview"',
                'class="tab-pane active"',
                'data-toggle="tab"',
                'data-toggle="collapse"',
                'windows-agent-graph-collapse',
                'windows-agent-collapse-toggle',
                'glyphicon-chevron-down',
                'glyphicon-chevron-up',
            ] as $needle) {
                assertContainsText($html, $needle, $name);
            }

            foreach (($fixture['expect']['app_page']['contains'] ?? []) as $needle) {
                assertContainsText($html, $needle, $name);
            }

            foreach (($fixture['expect']['app_page']['not_contains'] ?? []) as $needle) {
                assertNotContainsText($html, $needle, $name);
            }

            echo 'PASS ' . $name . PHP_EOL;
        } catch (Throwable $ex) {
            $failed++;
            fwrite(STDERR, 'FAIL ' . $name . ': ' . $ex->getMessage() . PHP_EOL);
        }
    }

    exit($failed === 0 ? 0 : 1);
}
