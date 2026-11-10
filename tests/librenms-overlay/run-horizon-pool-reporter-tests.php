<?php

declare(strict_types=1);

// Active pool reporter: only the first listed pod member that is up keeps the
// per-pool horizon_pool_* metrics. Runs the real ApplicationSave, projection,
// collector target selection and configuration helper against model doubles.
// The doubles assume LibreNMS core replaces an application's metric set on
// save (omitted metrics are deleted); they do not prove that native behavior.

namespace Illuminate\Support\Facades {
    final class DB
    {
        public static bool $transaction = false;
        public static function transaction(callable $callback): mixed
        {
            self::$transaction = true;
            try { return $callback(); } finally { self::$transaction = false; }
        }
        public static function table(string $name): \ParentQuery { return new \ParentQuery(); }
        public static function selectOne(string $sql): object { return (object) ['width' => 64]; }
    }
}
namespace App\Facades {
    final class LibrenmsConfig { public static function get(string $name): bool { return false; } }
}
namespace App\Models {
    final class ApplicationMetric
    {
        public static function query(): \MetricQuery { return new \MetricQuery(); }
    }
    final class Application
    {
        public static array $rows = [];
        public array $data = [];
        public string $app_type = 'windows-agent';
        public string $app_instance = '';
        public ?string $deleted_at = null;
        public int $discovered = 0;
        public function __construct(public int $app_id, public int $device_id) {}
        public static function query(): \ApplicationQuery { return new \ApplicationQuery(); }
        public function save(): void
        {
            if (! \Illuminate\Support\Facades\DB::$transaction) throw new \RuntimeException('save_outside_transaction');
            self::$rows[$this->app_id] = clone $this;
        }
    }
    final class Device
    {
        public static array $rows = [];
        public int $status = 1;
        public int $disabled = 0;
        public int $ignore = 0;
        public int $disable_notify = 0;
        public function __construct(public int $device_id, public string $hostname) {}
        public static function find(int $id): ?self { return self::$rows[$id] ?? null; }
        public static function findByHostname(string $host): ?self
        {
            foreach (self::$rows as $device) if (strcasecmp($device->hostname, $host) === 0) return $device;
            return null;
        }
    }
}
namespace {
    use WindowsAgentOverlay\ApplicationSave;
    use WindowsAgentOverlay\HorizonPoolReporter;
    use WindowsAgentOverlay\Horizon\HorizonFailure;
    use WindowsAgentOverlay\Horizon\PodCollector;

    $configurationHelper = $argv[1] ?? dirname(__DIR__, 2) . '/librenms-overlay/tools/horizon-central-config.php';
    require_once $configurationHelper;
    require_once dirname($configurationHelper) . '/../includes/windows-agent/application-save.inc.php';

    final class ApplicationQuery
    {
        private array $where = [];
        private ?int $id = null;
        public function whereKey(int $id): self { $this->id = $id; return $this; }
        public function where(string $key, mixed $value): self { $this->where[$key] = $value; return $this; }
        public function whereNull(string $key): self { $this->where[$key] = null; return $this; }
        public function lockForUpdate(): self { check(\Illuminate\Support\Facades\DB::$transaction, 'lock without transaction'); return $this; }
        public function first(): ?\App\Models\Application
        {
            foreach (\App\Models\Application::$rows as $app) {
                if ($this->id !== null && $app->app_id !== $this->id) continue;
                if ($app->deleted_at !== null) continue;
                foreach ($this->where as $key => $value) if ($app->{$key} !== $value) continue 2;
                return clone $app;
            }
            return null;
        }
    }
    final class MetricQuery
    {
        public function where(string $key, mixed $value): self { return $this; }
        public function first(): mixed { return null; }
    }
    final class ParentQuery
    {
        public function where(string $key, int $id): self { return $this; }
        public function pluck(string $key): self { return $this; }
        public function map(callable $callback): self { return $this; }
        public function all(): array { return []; }
    }
    final class TestState
    {
        public static array $metrics = [];
    }
    function update_application(\App\Models\Application $app, string $response, array $fields, string $status): void
    {
        $app->save();
        TestState::$metrics[$app->app_id] = $fields; // Core deletes omitted keys.
    }
    function check(bool $value, string $why): void { if (! $value) throw new RuntimeException($why); }
    function rejects(callable $callback, string $reason): void
    {
        try { $callback(); } catch (RuntimeException $e) { check(($e instanceof HorizonFailure ? $e->reason : $e->getMessage()) === $reason, 'wrong rejection: ' . $e->getMessage()); return; }
        throw new RuntimeException('missing rejection: ' . $reason);
    }

    const SUFFIX = 'example.test';
    const VCS1 = 'abc-vcs1.example.test';
    const VCS2 = 'abc-vcs2.example.test';
    const GW = 'abc-gw.example.test';

    function pod(array $extra = []): array
    {
        return array_replace(['site' => 'abc', 'dns_suffix' => SUFFIX, 'display_device' => VCS2], $extra);
    }

    /** Three pod members, each holding the same central snapshot naming $reporters. */
    function resetPod(?array $reporters = ['abc-vcs1', 'abc-vcs2', 'abc-gw']): void
    {
        \App\Models\Device::$rows = [];
        \App\Models\Application::$rows = [];
        TestState::$metrics = [];
        foreach ([1 => VCS1, 2 => VCS2, 3 => GW] as $id => $host) {
            \App\Models\Device::$rows[$id] = new \App\Models\Device($id, $host);
            \App\Models\Application::$rows[10 + $id] = new \App\Models\Application(10 + $id, $id);
        }
        $snapshot = \HorizonCentralRuntime::withPoolReporters(
            pod($reporters === null ? [] : ['pool_reporters' => $reporters]),
            ['horizon_central_meta' => ['source' => 'central', 'site' => 'abc', 'stale' => 0],
             'horizon_pools_summary' => ['pools_total' => 2],
             'horizon_pools' => [
                 ['name' => 'PoolA', 'machines_total' => 10, 'spare_ready' => 1, 'health_state' => 'critical'],
                 ['name' => 'PoolB', 'machines_total' => 10, 'spare_ready' => 8, 'health_state' => 'ok']]]
        );
        foreach (\App\Models\Application::$rows as $app) $app->data = $snapshot;
    }
    function poll(int $deviceId, array $fields = []): void
    {
        check(\App\Models\Device::$rows[$deviceId]->status === 1, 'a down device does not poll');
        ApplicationSave::save(10 + $deviceId, [], 'ok', $fields);
    }
    function pools(int $deviceId): array
    {
        return array_values(array_filter(array_keys(TestState::$metrics[10 + $deviceId] ?? []), static fn (string $k): bool => str_starts_with($k, 'horizon_pool_')));
    }
    function reports(int $deviceId): bool
    {
        $keys = pools($deviceId);
        check($keys === [] || in_array('horizon_pool_ready:PoolA', $keys, true), 'partial pool metric set');
        return $keys !== [];
    }
    function down(int $deviceId): void { \App\Models\Device::$rows[$deviceId]->status = 0; }
    function up(int $deviceId): void { \App\Models\Device::$rows[$deviceId]->status = 1; }

    $tests = [];
    $tests['only the first listed server that is up reports pool metrics'] = static function (): void {
        resetPod();
        foreach ([1, 2, 3] as $id) poll($id);
        check(reports(1) && ! reports(2) && ! reports(3), 'expected vcs1 only');
        // Pod-wide metrics stay on every member; only the pool metrics have one home.
        foreach ([1, 2, 3] as $id) check(isset(TestState::$metrics[10 + $id]['horizon_pools_total']), 'pod metrics lost on member ' . $id);
    };
    $tests['failover to the second and then the third server'] = static function (): void {
        resetPod();
        foreach ([1, 2, 3] as $id) poll($id);
        down(1);
        poll(2); poll(3);
        check(reports(2) && ! reports(3), 'vcs2 did not take over');
        down(2);
        poll(3);
        check(reports(3), 'gw did not take over');
    };
    $tests['the first server takes pool metrics back when it recovers'] = static function (): void {
        resetPod();
        down(1);
        poll(2); poll(3);
        check(reports(2), 'vcs2 not reporting while vcs1 down');
        up(1);
        poll(1); poll(2);
        check(reports(1) && ! reports(2), 'pool metrics did not move back to vcs1');
    };
    $tests['disabled ignored missing and app-less servers are skipped'] = static function (): void {
        foreach (['disabled', 'ignore', 'missing', 'no-app'] as $mode) {
            resetPod();
            match ($mode) {
                'disabled' => \App\Models\Device::$rows[1]->disabled = 1,
                'ignore' => \App\Models\Device::$rows[1]->ignore = 1,
                'missing' => \App\Models\Device::$rows[1]->hostname = 'renamed.example.test',
                'no-app' => \App\Models\Application::$rows[11]->deleted_at = '2026-01-01',
            };
            poll(2); poll(3);
            check(reports(2) && ! reports(3), 'vcs2 alone should report when vcs1 is ' . $mode);
        }
    };
    $tests['when no listed server is up no member reports'] = static function (): void {
        resetPod(['abc-vcs1']);
        down(1);
        poll(2); poll(3);
        check(! reports(2) && ! reports(3), 'an unlisted server reported');
        check(HorizonPoolReporter::active(['abc-vcs1.example.test']) === 0, 'active should be none');
    };
    $tests['a single-server pod reports from that server'] = static function (): void {
        resetPod(['abc-vcs1']);
        poll(1); poll(2);
        check(reports(1) && ! reports(2), 'single-server list');
    };
    $tests['without a list every member reports as before'] = static function (): void {
        resetPod(null);
        foreach ([1, 2, 3] as $id) poll($id);
        check(reports(1) && reports(2) && reports(3), 'default behaviour changed');
        check(! array_key_exists('pool_reporters', \App\Models\Application::$rows[11]->data['horizon_central_meta']), 'list stamped without config');
    };
    $tests['stale parser fields cannot restore suppressed pool metrics'] = static function (): void {
        resetPod();
        poll(2, ['horizon_pool_ready:PoolA' => 1, 'horizon_pool_state:PoolA' => 3, 'unrelated' => 5]);
        check(! reports(2), 'parser pool fields survived on a non-reporter');
        check((TestState::$metrics[12]['unrelated'] ?? null) === 5, 'unrelated field lost');
    };
    $tests['short names, ordering and validation'] = static function (): void {
        check(HorizonPoolReporter::configured(pod(['pool_reporters' => ['ABC-VCS1', 'abc-vcs1', 'abc-gw.other.test']]))
            === ['abc-vcs1.example.test', 'abc-gw.other.test'], 'normalisation');
        check(HorizonPoolReporter::configured(pod()) === [] && HorizonPoolReporter::configured(pod(['pool_reporters' => []])) === [], 'absent list');
        foreach ([['pool_reporters' => 'abc-vcs1'], ['pool_reporters' => ['a' => 'abc-vcs1']], ['pool_reporters' => [7]], ['pool_reporters' => ['bad host']]] as $bad) {
            rejects(static fn () => PodCollector::validateConfig(pod($bad)), 'invalid_pool_reporters');
        }
        rejects(static fn () => PodCollector::validateConfig(pod(['pool_reporters' => array_map(static fn (int $i): string => "abc-vcs{$i}", range(1, 9))])), 'too_many_pool_reporters');
    };
    $tests['listed and former reporters always receive the snapshot'] = static function (): void {
        $snapshot = \HorizonCentralRuntime::withPoolReporters(pod(['pool_reporters' => ['abc-vcs1', 'abc-gw'], 'publish_to_members' => false]), ['horizon_central_meta' => []]);
        check(\HorizonCentralRuntime::publishTargets(pod(['publish_to_members' => false]), $snapshot, ['abc-old.example.test'])
            === [VCS2, VCS1, GW, 'abc-old.example.test'], 'targets');
        // A server dropped from the list hears that it no longer reports.
        resetPod(['abc-vcs2']);
        poll(1);
        check(! reports(1), 'dropped server kept reporting');
        $cleared = \HorizonCentralRuntime::withPoolReporters(pod(), $snapshot);
        check(! array_key_exists('pool_reporters', $cleared['horizon_central_meta']), 'clearing kept the list');
    };
    $tests['a dropped reporter is retried until its cleanup is delivered'] = static function (): void {
        // Run 1 lists vcs1 and gw; gw reports while vcs1 is down.
        resetPod(['abc-vcs1', 'abc-gw']);
        down(1);
        poll(3);
        check(reports(3), 'gw should report while vcs1 is down');
        $run1 = \HorizonCentralRuntime::withPoolReporters(pod(['pool_reporters' => ['abc-vcs1', 'abc-gw']]), ['horizon_central_meta' => []]);
        $run1[\HorizonCentralRuntime::REPORTER_CLEANUP_KEY] = \HorizonCentralRuntime::reporterCleanup([], pod(['pool_reporters' => ['abc-vcs1', 'abc-gw']]));
        check($run1[\HorizonCentralRuntime::REPORTER_CLEANUP_KEY] === [], 'nothing to clean on first run');
        // Run 2 drops gw from the list, and delivery to gw fails.
        $pod2 = pod(['pool_reporters' => ['abc-vcs1']]);
        $cleanup = \HorizonCentralRuntime::reporterCleanup($run1, $pod2);
        check($cleanup === [GW], 'gw not queued for cleanup');
        $state2 = \HorizonCentralRuntime::withPoolReporters($pod2, ['horizon_central_meta' => []]);
        $state2[\HorizonCentralRuntime::REPORTER_CLEANUP_KEY] = \HorizonCentralRuntime::stillPending($cleanup, [GW]);
        poll(3);
        check(reports(3), 'gw holds its old list until delivery succeeds');
        // Run 3: gw is still queued, delivery now succeeds and gw stops reporting.
        $cleanup3 = \HorizonCentralRuntime::reporterCleanup($state2, $pod2);
        check($cleanup3 === [GW], 'failed cleanup was forgotten');
        check(in_array(GW, \HorizonCentralRuntime::publishTargets($pod2, $state2, $cleanup3), true), 'gw not targeted for retry');
        \App\Models\Application::$rows[13]->data['horizon_central_meta'] = $state2['horizon_central_meta'];
        poll(3);
        check(! reports(3), 'gw kept reporting after cleanup delivery');
        check(\HorizonCentralRuntime::stillPending($cleanup3, []) === [], 'delivered cleanup still pending');
        // Run 4 has nothing left to clean.
        $state3 = $state2; $state3[\HorizonCentralRuntime::REPORTER_CLEANUP_KEY] = [];
        check(\HorizonCentralRuntime::reporterCleanup($state3, $pod2) === [], 'cleanup did not finish');
        // A host that comes back onto the list leaves the cleanup queue.
        check(\HorizonCentralRuntime::reporterCleanup($state2, pod(['pool_reporters' => ['abc-vcs1', 'abc-gw']])) === [], 'relisted host still queued');
    };
    $tests['one template orders every site, and a site list overrides it'] = static function (): void {
        $config = ['pool_reporter_template' => ['{site}-vcs1', '{site}-vcs2', '{site}-gw']];
        $abc = HorizonPoolReporter::withTemplate(pod(), $config);
        check(HorizonPoolReporter::configured($abc) === [VCS1, VCS2, GW], 'template expansion');
        check(HorizonPoolReporter::source($abc) === 'template', 'template source');
        $xyz = HorizonPoolReporter::withTemplate(['site' => 'xyz9', 'dns_suffix' => SUFFIX, 'display_device' => 'xyz9-vcs1.example.test'], $config);
        check(HorizonPoolReporter::configured($xyz) === ['xyz9-vcs1.example.test', 'xyz9-vcs2.example.test', 'xyz9-gw.example.test'], 'second site');
        $own = HorizonPoolReporter::withTemplate(pod(['pool_reporters' => ['abc-vcs2']]), $config);
        check(HorizonPoolReporter::configured($own) === [VCS2] && HorizonPoolReporter::source($own) === 'site list', 'site list did not override');
        check(HorizonPoolReporter::configured(HorizonPoolReporter::withTemplate(pod(), [])) === [], 'no template, no list');
        $stamped = \HorizonCentralRuntime::withPoolReporters($abc, ['horizon_central_meta' => []]);
        check($stamped['horizon_central_meta']['pool_reporters'] === [VCS1, VCS2, GW], 'collector did not stamp the template order');
        foreach ([['abc-vcs1'], 'x', [7], ['{site}-bad host'], array_map(static fn (int $i): string => '{site}-vcs' . $i, range(1, 9))] as $bad) {
            $thrown = false;
            try { HorizonPoolReporter::template($bad); } catch (RuntimeException) { $thrown = true; }
            check($thrown, 'invalid template accepted: ' . json_encode($bad));
        }
    };
    $tests['a template skips servers a site does not have'] = static function (): void {
        // Template lists three; this site only has vcs1 in LibreNMS.
        resetPod(null);
        unset(\App\Models\Device::$rows[2], \App\Models\Device::$rows[3], \App\Models\Application::$rows[12], \App\Models\Application::$rows[13]);
        $pod = HorizonPoolReporter::withTemplate(pod(), ['pool_reporter_template' => ['{site}-vcs1', '{site}-vcs2', '{site}-gw']]);
        \App\Models\Application::$rows[11]->data = \HorizonCentralRuntime::withPoolReporters($pod, \App\Models\Application::$rows[11]->data);
        poll(1);
        check(reports(1), 'the only server did not report');
        check(HorizonPoolReporter::active(HorizonPoolReporter::configured($pod)) === 1, 'active should be vcs1');
    };
    $tests['configuration helper sets and clears the template'] = static function (): void {
        $path = tempnam(sys_get_temp_dir(), 'pods');
        file_put_contents($path, json_encode(['version' => 2, 'pods' => [pod(), pod(['site' => 'xyz9', 'display_device' => 'xyz9-vcs1.example.test', 'pool_reporters' => ['xyz9-vcs2']])]]));
        try {
            $set = HorizonCentralConfiguration::main(['helper', 'pod', 'reporters', '--config', $path, '--template', '{site}-vcs1,{site}-vcs2,{site}-gw']);
            $config = json_decode(file_get_contents($path), true);
            check($set === 0 && $config['pool_reporter_template'] === ['{site}-vcs1', '{site}-vcs2', '{site}-gw'], 'template not stored');
            check(! array_key_exists('pool_reporter_template', $config['pods'][0]), 'template leaked into a pod');
            check($config['pods'][1]['pool_reporters'] === ['xyz9-vcs2'], 'site list changed');
            $before = file_get_contents($path);
            $bad = HorizonCentralConfiguration::main(['helper', 'pod', 'reporters', '--config', $path, '--template', 'abc-vcs1']);
            check($bad === 2 && file_get_contents($path) === $before, 'template without {site} written');
            $empty = HorizonCentralConfiguration::main(['helper', 'pod', 'reporters', '--config', $path, '--template', ' , ']);
            check($empty === 2 && file_get_contents($path) === $before, 'empty template written');
            // Clearing the site list falls back to the template, not to every member.
            HorizonCentralConfiguration::main(['helper', 'pod', 'reporters', '--config', $path, '--site', 'xyz9', '--clear']);
            $config = json_decode(file_get_contents($path), true);
            check(! array_key_exists('pool_reporters', $config['pods'][1]), 'site list not cleared');
            check(HorizonPoolReporter::configured(HorizonPoolReporter::withTemplate($config['pods'][1], $config))[0] === 'xyz9-vcs1.example.test', 'site did not fall back to template');
            $clear = HorizonCentralConfiguration::main(['helper', 'pod', 'reporters', '--config', $path, '--template-clear']);
            check($clear === 0 && ! array_key_exists('pool_reporter_template', json_decode(file_get_contents($path), true)), 'template not cleared');
        } finally { unlink($path); }
    };
    $tests['adding a site is checked against the template before writing'] = static function (): void {
        $path = tempnam(sys_get_temp_dir(), 'pods');
        // Valid for site abc (63-character label), too long once a longer site code fills it in.
        $template = '{site}-' . str_repeat('a', 55);
        file_put_contents($path, json_encode(['version' => 2, 'pods' => [pod()]]));
        try {
            check(HorizonCentralConfiguration::main(['helper', 'pod', 'reporters', '--config', $path, '--template', $template]) === 0, 'template rejected for abc');
            $before = file_get_contents($path);
            $added = HorizonCentralConfiguration::main(['helper', 'pod', 'add', '--config', $path, '--site', 'longsite', '--dns-suffix', SUFFIX, '--display-device', 'longsite-vcs1.example.test']);
            check($added === 2 && file_get_contents($path) === $before, 'pod written with an invalid template host');
        } finally { unlink($path); }
    };
    $tests['device page says who reports'] = static function (): void {
        resetPod();
        $meta = \App\Models\Application::$rows[11]->data['horizon_central_meta'];
        check(HorizonPoolReporter::describe($meta, 1) === 'This server', 'self');
        check(HorizonPoolReporter::describe($meta, 2) === 'Reported by ' . VCS1, 'other');
        down(1); down(2); down(3);
        check(str_starts_with((string) HorizonPoolReporter::describe($meta, 2), 'No listed server is up'), 'none');
        check(HorizonPoolReporter::describe([], 2) === null, 'unconfigured pod shows nothing');
    };
    $tests['configuration helper sets lists and clears reporters'] = static function (): void {
        $path = tempnam(sys_get_temp_dir(), 'pods');
        file_put_contents($path, json_encode(['version' => 2, 'pods' => [pod()]]));
        try {
            $set = HorizonCentralConfiguration::main(['helper', 'pod', 'reporters', '--config', $path, '--site', 'abc', '--hosts', 'abc-vcs1, abc-vcs2,abc-gw']);
            check($set === 0, 'set failed');
            check(json_decode(file_get_contents($path), true)['pods'][0]['pool_reporters'] === [VCS1, VCS2, GW], 'stored list');
            $before = file_get_contents($path);
            $both = HorizonCentralConfiguration::main(['helper', 'pod', 'reporters', '--config', $path, '--site', 'abc']);
            check($both === 2 && file_get_contents($path) === $before, 'missing hosts/clear accepted');
            $empty = HorizonCentralConfiguration::main(['helper', 'pod', 'reporters', '--config', $path, '--site', 'abc', '--hosts', ' , ']);
            check($empty === 2 && file_get_contents($path) === $before, 'empty host list accepted');
            $bad = HorizonCentralConfiguration::main(['helper', 'pod', 'reporters', '--config', $path, '--site', 'abc', '--hosts', 'bad host']);
            check($bad === 2 && file_get_contents($path) === $before, 'invalid host written');
            $clear = HorizonCentralConfiguration::main(['helper', 'pod', 'reporters', '--config', $path, '--site', 'abc', '--clear']);
            check($clear === 0 && ! array_key_exists('pool_reporters', json_decode(file_get_contents($path), true)['pods'][0]), 'clear failed');
        } finally { unlink($path); }
    };

    $failed = 0;
    foreach ($tests as $name => $test) {
        try { $test(); echo "PASS $name\n"; }
        catch (Throwable $e) { $failed++; fwrite(STDERR, "FAIL $name: {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n"); }
    }
    exit($failed ? 1 : 0);
}
