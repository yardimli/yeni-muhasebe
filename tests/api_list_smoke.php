<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

session_start();
$_SESSION['user'] = ['id' => 0, 'username' => 'schema-smoke', 'role' => 'administrator'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [
    'action' => 'list',
    'table' => $argv[1] ?? 'faturabaslik',
    'page' => '1',
    'per_page' => $argv[4] ?? '250',
    'sort' => $argv[2] ?? 'ID',
    'dir' => $argv[3] ?? 'desc',
];
if (isset($argv[5], $argv[6])) {
    $_GET['filter_column'] = $argv[5];
    $_GET['filter_value'] = $argv[6];
}

require __DIR__ . '/../public/api.php';
