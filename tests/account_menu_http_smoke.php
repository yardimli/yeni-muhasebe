<?php
declare(strict_types=1);

// Run against a local PHP development server. Only login and read-only requests.
$base = $argv[1] ?? 'http://127.0.0.1:18089';
if (!preg_match('~^http://127\.0\.0\.1:\d+$~', $base)) throw new RuntimeException('Local development server required.');
$cookie = '';
function request(string $path, ?array $form = null): array {
    global $base, $cookie;
    $headers = $cookie === '' ? [] : ['Cookie: ' . $cookie];
    if ($form !== null) $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $body = file_get_contents($base . '/' . $path, false, stream_context_create(['http'=>[
        'method'=>$form === null ? 'GET' : 'POST', 'header'=>implode("\r\n", $headers),
        'content'=>$form === null ? '' : http_build_query($form), 'ignore_errors'=>true, 'follow_location'=>0,
    ]]));
    foreach ($http_response_header as $header) if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) $cookie = $match[1];
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int)$match[1], (string)$body];
}
function check_http(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
check_http(request('users_api.php')[0] === 401, 'Anonymous user management was allowed.');
check_http(request('email_export.php?source=cari')[0] === 401, 'Anonymous export was allowed.');
[$status, $html] = request('index.php');
check_http($status === 200 && preg_match('/name="_token" value="([^"]+)"/', $html, $match) === 1, 'Login form missing.');
$env = parse_ini_file(__DIR__ . '/../.env', false, INI_SCANNER_RAW);
[$status] = request('index.php', ['action'=>'login','_token'=>$match[1],'username'=>$env['APP_LOGIN_USERNAME'],'password'=>$env['APP_LOGIN_PASSWORD']]);
check_http($status === 302, 'Administrator login failed.');
[$status, $html] = request('index.php');
check_http($status === 200, 'Desktop render failed.');
check_http(preg_match('~<script id="access-permissions" type="application/json">(.*?)</script>~s', $html, $permissionMatch) === 1, 'Permissions script was not fully rendered.');
$permissions = json_decode($permissionMatch[1], true, 512, JSON_THROW_ON_ERROR);
check_http(is_array($permissions) && in_array('users', $permissions, true), 'Administrator permissions missing.');
check_http(preg_match('~<script src="assets/app\.js\?v=[^"]+" defer></script>~', $html) === 1, 'Desktop JavaScript was not included.');
check_http(str_contains($html, '</html>'), 'Desktop rendering stopped early.');
check_http(preg_match('~<nav[^>]*>\s*<div[^>]*>\s*<button[^>]*>WebCin</button>~', $html) === 1, 'WebCin was not the first menu.');
foreach (['data-open-users', 'data-window-command="close-all"', 'email_export.php?source=cari', 'email_export.php?source=webcup'] as $text) check_http(str_contains($html, $text), 'Required menu missing.');
[$status, $body] = request('users_api.php');
$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
check_http($status === 200 && isset($data['users'], $data['menus']), 'User catalog failed.');
check_http(!str_contains($body, 'password'), 'User catalog exposed password fields.');
check_http(request('users_api.php', ['username'=>'must-not-be-created'])[0] === 419, 'User write accepted without CSRF.');
foreach (['cari', 'webcup'] as $source) {
    [$status, $csv] = request('email_export.php?source=' . $source);
    check_http($status === 200 && str_starts_with($csv, "Name,Email\r\n"), 'CSV export failed: ' . $source);
}
preg_match('/name="_token" value="([^"]+)"/', $html, $match);
check_http(request('index.php', ['action'=>'logout','_token'=>$match[1]])[0] === 302, 'Logout failed.');
echo "HTTP login, menu order, protected API, CSRF rejection, and both CSV exports passed.\n";
