<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/production_service.php';
require_auth();

$action = (string)($_GET['action'] ?? '');

try {
    if ($action === 'list' || $action === 'balances') {
        $page = max(1,(int)($_GET['page']??1));
        $perPage = (int)($_GET['per_page']??250);
        $query = trim((string)($_GET['q']??''));
        if ($action === 'balances') json_response(production_balances($page,$perPage,$query));
        json_response(production_list($page,$perPage,$query,(string)($_GET['view']??'all'),(string)($_GET['sort']??'ID'),(string)($_GET['dir']??'desc')));
    }

    if ($action === 'metadata') {
        json_response(['groups'=>production_available_field_groups(),'currency'=>setting_get('default_currency','TL')]);
    }

    if ($action === 'report') {
        $range = production_report_range((string)($_GET['preset']??'this_year'), $_GET['start']??null, $_GET['end']??null);
        json_response(production_report_run(
            (string)($_GET['report']??''), (string)($_GET['grouping']??'monthly'), $range,
            max(1,(int)($_GET['page']??1)), (int)($_GET['per_page']??250),
            (string)($_GET['sort']??''), (string)($_GET['dir']??'asc')
        ));
    }

    if ($action === 'detail') {
        json_response(production_detail(max(1,(int)($_GET['id']??0))));
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $id = production_save(request_json()['record'] ?? []);
        json_response(['saved'=>true,'id'=>$id]);
    }

    if ($action === 'save_transaction' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $payload = request_json();
        $id = production_save_transaction((string)($payload['type']??''),$payload['record']??[]);
        json_response(['saved'=>true,'id'=>$id]);
    }

    json_response(['error'=>'Bilinmeyen üretim işlemi.'],404);
} catch (InvalidArgumentException $exception) {
    json_response(['error'=>$exception->getMessage()],422);
} catch (Throwable $exception) {
    error_log($exception->__toString());
    json_response(['error'=>'Üretim verisi işlenirken hata oluştu: '.$exception->getMessage()],500);
}
