<?php

declare(strict_types=1);

$options = getopt('', [
    'librenms-root:',
    'expected-app-count:',
    'expected-metric-count:',
    'expected-device-ids:',
]);

$librenmsRoot = rtrim($options['librenms-root'] ?? '/opt/librenms', '/');
$envFile = $librenmsRoot . '/.env';

if (! is_readable($envFile)) {
    fwrite(STDERR, "Cannot read LibreNMS .env: $envFile\n");
    exit(2);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim((string) $line);
    if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
        continue;
    }

    [$key, $value] = explode('=', $line, 2);
    $env[$key] = trim($value, " \t\n\r\0\x0B\"'");
}

$host = $env['DB_HOST'] ?? 'localhost';
$port = $env['DB_PORT'] ?? '3306';
$db = $env['DB_DATABASE'] ?? 'librenms';
$user = $env['DB_USERNAME'] ?? '';
$pass = $env['DB_PASSWORD'] ?? '';

if ($user === '') {
    fwrite(STDERR, "DB_USERNAME is missing from $envFile\n");
    exit(2);
}

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$apps = $pdo->query(
    "SELECT a.device_id, d.hostname, a.app_id, a.app_state, a.app_status
     FROM applications a
     JOIN devices d ON d.device_id = a.device_id
     WHERE a.app_type = 'windows-agent' AND a.deleted_at IS NULL
     ORDER BY a.device_id"
)->fetchAll();

$metrics = $pdo->query(
    "SELECT a.device_id, m.metric, m.value
     FROM application_metrics m
     JOIN applications a ON a.app_id = m.app_id
     WHERE a.app_type = 'windows-agent' AND a.deleted_at IS NULL
     ORDER BY a.device_id, m.metric"
)->fetchAll();

foreach ($apps as $app) {
    echo "APP\t{$app['device_id']}\t{$app['hostname']}\t{$app['app_id']}\t{$app['app_state']}\t{$app['app_status']}\n";
}

foreach ($metrics as $metric) {
    echo "METRIC\t{$metric['device_id']}\t{$metric['metric']}\t{$metric['value']}\n";
}

$expectedAppCount = isset($options['expected-app-count']) ? (int) $options['expected-app-count'] : null;
$expectedMetricCount = isset($options['expected-metric-count']) ? (int) $options['expected-metric-count'] : null;
$expectedDeviceIds = array_filter(array_map('trim', explode(',', $options['expected-device-ids'] ?? '')));

if ($expectedAppCount !== null && count($apps) !== $expectedAppCount) {
    fwrite(STDERR, "Expected $expectedAppCount app rows, found " . count($apps) . "\n");
    exit(1);
}

if ($expectedMetricCount !== null && count($metrics) !== $expectedMetricCount) {
    fwrite(STDERR, "Expected $expectedMetricCount metric rows, found " . count($metrics) . "\n");
    exit(1);
}

if ($expectedDeviceIds) {
    $actual = array_map(static fn ($app) => (string) $app['device_id'], $apps);
    sort($expectedDeviceIds);
    sort($actual);
    if ($expectedDeviceIds !== $actual) {
        fwrite(STDERR, 'Expected device IDs ' . implode(',', $expectedDeviceIds) . ', found ' . implode(',', $actual) . "\n");
        exit(1);
    }
}

echo "Application validation OK\n";
