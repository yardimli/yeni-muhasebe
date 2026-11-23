<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require_auth();
header('Cache-Control: no-store');
try {
    $action = (string)($_GET['action'] ?? 'forms');
    $write = in_array($action, ['open', 'read', 'trash', 'restore', 'purge'], true);
    if ($_SERVER['REQUEST_METHOD'] !== ($write ? 'POST' : 'GET')) json_response(['error'=>'Geçersiz istek yöntemi.'], 405);
    if ($write) verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if ($action === 'forms') {
        $forms = array_values(array_filter(submission_forms(submission_db()), fn($form)=>can_access('submissions:' . $form['id'])));
        json_response(['forms'=>$forms]);
    }
    if ($action === 'list') {
        $form = (int)($_GET['form'] ?? 0);
        require_permission('submissions:' . $form);
        json_response(submission_list(submission_db(), $form, (int)($_GET['page'] ?? 1), (string)($_GET['filter'] ?? '')));
    }
    if ($write) {
        $input = request_json();
        $row = submission_find(submission_db(), (int)($input['id'] ?? 0), true);
        if (!$row) json_response(['error'=>'Başvuru bulunamadı.'], 404);
        require_permission('submissions:' . $row['request_form_id']);
        if (in_array($action, ['trash','restore','purge'], true)) {
            submission_trash_action(submission_db(), (int)$row['id'], $action, rtrim((string)env_value('CINIUS_LARAVEL_ROOT', '/var/www/cinius-laravel'), '/\\') . '/storage/app');
            audit_log('submission_' . $action, 'cinius_laravel.submissions', $row['id'], []);
            json_response(['saved'=>true]);
        }
        if ($action === 'read' && $row['deleted_at'] !== null) json_response(['error'=>'Önce başvuruyu çöp kutusundan geri yükleyin.'], 409);
        if ($action === 'read' && !is_bool($input['read'] ?? null)) json_response(['error'=>'Geçersiz okunma durumu.'], 422);
        $read = $action === 'open' || $input['read'];
        if ($row['deleted_at'] === null) {
            submission_mark(submission_db(), (int)$row['id'], $read);
            audit_log('submission_read', 'cinius_laravel.submissions', $row['id'], ['read'=>$read]);
        }
        if ($action === 'read') json_response(['saved'=>true]);
        $query = submission_db()->prepare('SELECT id,original_name,size,is_placeholder FROM submission_files WHERE submission_id=? ORDER BY id');
        $query->execute([$row['id']]);
        json_response(['submission'=>['id'=>$row['id'], 'title'=>$row['title'], 'created_at'=>$row['created_at'], 'deleted_at'=>$row['deleted_at'], 'data'=>json_decode($row['data'], true) ?: [], 'files'=>$query->fetchAll()]]);
    }
    if ($action === 'download') {
        $query = submission_db()->prepare('SELECT f.*,s.request_form_id FROM submission_files f JOIN submissions s ON s.id=f.submission_id WHERE f.id=? AND s.deleted_at IS NULL');
        $query->execute([(int)($_GET['id'] ?? 0)]);
        $file = $query->fetch();
        if (!$file) json_response(['error'=>'Dosya bulunamadı.'], 404);
        require_permission('submissions:' . $file['request_form_id']);
        if ($file['is_placeholder']) json_response(['error'=>'Dosya aktarılmamış.'], 404);
        $path = submission_file_path(rtrim((string)env_value('CINIUS_LARAVEL_ROOT', '/var/www/cinius-laravel'), '/\\') . '/storage/app', $file['path']);
        if (!$path) json_response(['error'=>'Dosya bulunamadı.'], 404);
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header("Content-Disposition: attachment; filename=\"attachment\"; filename*=UTF-8''" . rawurlencode(basename(str_replace('\\', '/', $file['original_name']))));
        header('Content-Length: ' . filesize($path));
        session_write_close();
        readfile($path);
        exit;
    }
    json_response(['error'=>'İşlem bulunamadı.'], 404);
} catch (InvalidArgumentException $e) {
    json_response(['error'=>$e->getMessage()], 409);
} catch (Throwable $e) {
    error_log('Submissions API: ' . $e->getMessage());
    json_response(['error'=>'Başvurular yüklenemedi. Veritabanı bağlantısını ve dosya izinlerini kontrol edin.'], 503);
}
