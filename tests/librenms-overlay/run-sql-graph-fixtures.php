<?php

declare(strict_types=1);

namespace App\Facades {
    final class Rrd
    {
        public static string $filename;

        public static function name(string $host, array $parts): string
        {
            if ($parts[1] !== 'windows-agent-sql-server-performance') {
                throw new \RuntimeException('SQL views must read the existing performance family');
            }
            return self::$filename;
        }

        public static function safeDescr(string $value): string
        {
            return str_replace(':', '\\:', $value);
        }
    }
}

namespace {
    // Integration test: requires RRDtool, without a LibreNMS installation or data.
    $options = getopt('', ['rrdtool:', 'output-dir:']);
    $rrdtool = (string) ($options['rrdtool'] ?? 'rrdtool');
    $output = (string) ($options['output-dir'] ?? (sys_get_temp_dir() . '/windows-agent-sql-graphs-' . bin2hex(random_bytes(5))));
    @mkdir($output . '/stubs/includes/html/graphs', 0777, true);
    file_put_contents($output . '/stubs/includes/html/graphs/common.inc.php', '<?php');
    set_include_path($output . '/stubs' . PATH_SEPARATOR . get_include_path());
    \App\Facades\Rrd::$filename = $output . '/synthetic-sql-' . bin2hex(random_bytes(5)) . '.rrd';
    $run = static function (array $args) use ($rrdtool): string {
        $process = proc_open(array_merge([$rrdtool], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start RRDtool');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException($stderr ?: 'RRDtool failed');
        }
        return $stdout;
    };
    $loadGraph = static function (string $file): array {
        $device = ['hostname' => 'sql-fixture.example.test'];
        $app = (object) ['app_id' => 1];
        $rrd_options = [];
        include $file;
        return [$unit_text, $rrd_options, array_column($windows_agent_sql_series, 'ds')];
    };

    try {
        $base = 1800000000;
        $datasets = ['blocked', 'grants', 'batches', 'compiles', 'recompiles', 'ple_min', 'mem_percent'];
        $args = ['create', \App\Facades\Rrd::$filename, '--start', (string) ($base - 601), '--step', '300'];
        foreach ($datasets as $ds) {
            $args[] = 'DS:' . $ds . ':GAUGE:600:-1:U';
        }
        foreach (['AVERAGE', 'MIN', 'MAX'] as $cf) {
            $args[] = 'RRA:' . $cf . ':0.5:1:200';
            $args[] = 'RRA:' . $cf . ':0.5:4:100';
        }
        $run($args);
        $updates = ['update', \App\Facades\Rrd::$filename];
        for ($i = -1; $i <= 48; $i++) {
            $phase = ($i + 12) % 12;
            $values = $phase < 2 ? array_fill(0, 7, -1) : ($phase === 2 ? array_fill(0, 7, 0) : [$phase % 3, $phase % 2, 800 + $phase * 30, 4 + $phase, 0.5, 1400000 + $i * 1000, 95 + $phase * 0.2]);
            $updates[] = ($base + $i * 300) . ':' . implode(':', $values);
        }
        $run($updates);
        $graphs = [
            'queues' => ['Count', ['blocked', 'grants']],
            'workload' => ['Operations/sec', ['batches']],
            'compilation' => ['Operations/sec', ['compiles', 'recompiles']],
            'page_life' => ['Seconds', ['ple_min']],
            'memory' => ['Percent of target', ['mem_percent']],
        ];
        $provenance = ['source_sha256' => [], 'png_sha256' => []];
        $graphRoot = dirname(__DIR__, 2) . '/librenms-overlay/includes/html/graphs/application/';
        $provenance['source_sha256']['windows_agent_sql_performance_view.inc.php'] = hash_file('sha256', $graphRoot . 'windows_agent_sql_performance_view.inc.php');
        foreach ($graphs as $name => [$expectedUnit, $expectedDs]) {
            $definition = 'windows-agent_sql_server_' . $name . '.inc.php';
            [$unit, $commands, $ds] = $loadGraph($graphRoot . $definition);
            $provenance['source_sha256'][$definition] = hash_file('sha256', $graphRoot . $definition);
            if ($unit !== $expectedUnit || $ds !== $expectedDs) {
                throw new RuntimeException($name . ': incorrect units or dataset subset');
            }
            $png = $output . '/' . $name . '.png';
            $run(array_merge(['graph', $png, '--start', (string) $base, '--end', (string) ($base + 14400), '--width', '780', '--height', '180', '--lower-limit', '0', '--vertical-label', $unit, '--title', 'Synthetic SQL evidence — ' . $name], $commands));
            if (file_get_contents($png, false, null, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                throw new RuntimeException($name . ': no rendered PNG');
            }
            $provenance['png_sha256'][$name . '.png'] = hash_file('sha256', $png);
            $definitions = array_values(array_filter($commands, static fn (string $value): bool => str_starts_with($value, 'DEF:') || str_starts_with($value, 'CDEF:')));
            foreach ([300, 1200] as $step) {
                $export = json_decode($run(array_merge(['xport', '--json', '--start', (string) $base, '--end', (string) ($base + 14400), '--step', (string) $step], $definitions, ['XPORT:sql0avg:raw', 'XPORT:sql0min:minimum', 'XPORT:sql0validavg:visible'])), true, flags: JSON_THROW_ON_ERROR);
                $zero = $positive = $missing = $mixed = false;
                foreach ($export['data'] as [$raw, $minimum, $visible]) {
                    if (($minimum !== null && $minimum < 0) || ($raw !== null && $raw < 0)) {
                        if ($visible !== null) {
                            throw new RuntimeException($name . ': unavailable sample became a plotted value');
                        }
                        $missing = true;
                        $mixed = $mixed || ($raw !== null && $raw > 0);
                    } elseif ($raw !== null) {
                        if ($visible === null || abs($raw - $visible) > 0.001) {
                            throw new RuntimeException($name . ': measured data changed');
                        }
                        $zero = $zero || $visible == 0;
                        $positive = $positive || $visible > 0;
                    }
                }
                if (! $positive || ! $missing || ($step === 300 && ! $zero) || ($step === 1200 && ! $mixed)) {
                    throw new RuntimeException($name . ': fixture did not exercise zero, positive and unavailable consolidation');
                }
            }
            echo 'PASS SQL graph ' . $name . ' (PNG, measured zero, unavailable and consolidated gaps)' . PHP_EOL;
        }
        file_put_contents($output . '/graph-provenance.json', json_encode($provenance, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
