<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

function app_env(): array
{
    static $env;
    if ($env === null) {
        $env = parse_ini_file(APP_ROOT . '/.env', false, INI_SCANNER_RAW) ?: [];
    }
    return $env;
}

function env_value(string $key, mixed $default = null): mixed
{
    return app_env()[$key] ?? $default;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('cinius_accounting');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function storage_path(string $file = ''): string
{
    $directory = APP_ROOT . '/storage';
    if (!is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    return $directory . ($file !== '' ? '/' . ltrim($file, '/') : '');
}

function users_file(): string
{
    return storage_path('users.json');
}

function users(): array
{
    if (!is_file(users_file())) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents(users_file()), true);
    return is_array($decoded) ? ($decoded['users'] ?? []) : [];
}

function environment_login_user(): ?array
{
    $username = trim((string)env_value('APP_LOGIN_USERNAME', ''));
    $password = (string)env_value('APP_LOGIN_PASSWORD', '');
    if ($username === '' || $password === '') return null;
    return ['id'=>'environment', 'username'=>$username, 'password'=>$password, 'role'=>'administrator'];
}

function app_is_configured(): bool
{
    return environment_login_user() !== null || count(users()) > 0;
}

function create_initial_user(string $username, string $password): void
{
    if (app_is_configured()) {
        throw new RuntimeException('Initial setup has already been completed.');
    }
    $username = trim($username);
    if (!preg_match('/^[\pL\pN_.@-]{3,80}$/u', $username)) {
        throw new InvalidArgumentException('Username must be 3-80 characters.');
    }
    if (mb_strlen($password) < 12) {
        throw new InvalidArgumentException('Password must be at least 12 characters.');
    }
    $payload = ['users' => [[
        'id' => 1,
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'administrator',
        'created_at' => date(DATE_ATOM),
    ]]];
    file_put_contents(users_file(), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function attempt_login(string $username, string $password): bool
{
    $environmentUser = environment_login_user();
    if ($environmentUser !== null
        && hash_equals((string)$environmentUser['username'], trim($username))
        && hash_equals((string)$environmentUser['password'], $password)) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>$environmentUser['id'], 'username'=>$environmentUser['username'], 'role'=>$environmentUser['role']];
        audit_log('login', null, null, ['source'=>'environment']);
        return true;
    }
    foreach (users() as $user) {
        if (hash_equals((string) $user['username'], trim($username)) && password_verify($password, (string) $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']];
            audit_log('login', null, null, []);
            return true;
        }
    }
    usleep(350000);
    return false;
}

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_auth(): void
{
    if (current_user() === null) {
        json_response(['error' => 'Authentication required.'], 401);
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): void
{
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        json_response(['error' => 'Your session token is invalid. Refresh the page and try again.'], 419);
    }
}

function db(): PDO
{
    static $pdo;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env_value('DB_HOST', '127.0.0.1'),
            env_value('DB_PORT', '3306'),
            env_value('DB_DATABASE', '')
        );
        $pdo = new PDO($dsn, (string) env_value('DB_USERNAME', ''), (string) env_value('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function cup_db(): PDO
{
    static $pdo;
    if ($pdo === null) {
        // DB_DATABSE_CUP is retained because existing installations use this spelling.
        $database = (string) env_value('DB_DATABSE_CUP', env_value('DB_DATABASE_CUP', 'cup_veri'));
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env_value('DB_HOST', '127.0.0.1'),
            env_value('DB_PORT', '3306'),
            $database
        );
        $pdo = new PDO($dsn, (string) env_value('DB_USERNAME', ''), (string) env_value('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function database_search_terms(string $query): array
{
    return array_values(array_filter(
        preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        fn (string $term): bool => $term !== ''
    ));
}

function request_json(): array
{
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

function audit_log(string $action, ?string $table, int|string|null $recordId, array $details): void
{
    $line = json_encode([
        'time' => date(DATE_ATOM),
        'user' => current_user()['username'] ?? 'system',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'action' => $action,
        'table' => $table,
        'record_id' => $recordId,
        'details' => $details,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    file_put_contents(storage_path('audit.log'), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require_once APP_ROOT . '/app/settings.php';
ensure_settings_table();
