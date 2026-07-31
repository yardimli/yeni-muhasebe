<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

session_start();
$_SESSION['user'] = ['id' => 0, 'username' => 'report-smoke', 'role' => 'administrator'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [
    'action' => 'report',
    'report' => $argv[1] ?? 'product_sales',
    'id' => $argv[2] ?? '1',
    'page' => '1',
    'per_page' => '50',
    'sort' => 'FaturaTarih',
    'dir' => 'desc',
];

require __DIR__ . '/../public/api.php';

