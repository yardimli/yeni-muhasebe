<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$user = environment_login_user();
if ($user === null) throw new RuntimeException('Environment login is not configured.');
if (($user['role'] ?? '') !== 'administrator') throw new RuntimeException('Environment login is not an administrator.');
if (!app_is_configured()) throw new RuntimeException('Environment credentials did not configure the application.');

$example = parse_ini_file(APP_ROOT . '/.env.example', false, INI_SCANNER_RAW) ?: [];
foreach (['APP_LOGIN_USERNAME','APP_LOGIN_PASSWORD'] as $key) {
    if (trim((string)($example[$key] ?? '')) === '') throw new RuntimeException("Missing example environment key: $key");
}

echo "Environment login smoke test passed.\n";
