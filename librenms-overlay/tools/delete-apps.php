<?php

declare(strict_types=1);

$librenmsRoot = rtrim($argv[1] ?? '/opt/librenms', '/');
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

$pdo = new PDO(
    'mysql:host=' . ($env['DB_HOST'] ?? 'localhost') .
    ';port=' . ($env['DB_PORT'] ?? '3306') .
    ';dbname=' . ($env['DB_DATABASE'] ?? 'librenms') .
    ';charset=utf8mb4',
    $env['DB_USERNAME'] ?? '',
    $env['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$appIds = $pdo->query("SELECT app_id FROM applications WHERE app_type = 'windows-agent'")->fetchAll(PDO::FETCH_COLUMN);
if (! $appIds) {
    echo "No windows-agent applications found.\n";
    exit(0);
}

$placeholders = implode(',', array_fill(0, count($appIds), '?'));
$pdo->prepare("DELETE FROM application_metrics WHERE app_id IN ($placeholders)")->execute($appIds);
$pdo->prepare("DELETE FROM applications WHERE app_id IN ($placeholders)")->execute($appIds);

echo 'Deleted windows-agent applications: ' . count($appIds) . "\n";
