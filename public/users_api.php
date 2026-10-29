<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require_permission('users');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $list = array_map('public_user', users());
        if ($environment = environment_login_user()) array_unshift($list, public_user($environment));
        json_response(['users'=>$list, 'menus'=>menu_catalog()]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'Method not allowed.'], 405);
    verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    $user = save_managed_user(request_json(), users_file());
    audit_log('save_user', 'users', $user['id'], ['role'=>$user['role']]);
    json_response(['user'=>$user]);
} catch (InvalidArgumentException $e) {
    json_response(['error'=>$e->getMessage()], 422);
} catch (Throwable $e) {
    error_log($e->__toString());
    json_response(['error'=>'Kullanıcı kaydedilemedi.'], 500);
}
