<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/production_service.php';

function assert_production(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$database = (string) cup_db()->query('SELECT DATABASE()')->fetchColumn();
assert_production($database !== '', 'CUP database connection has no selected database.');

$tables = cup_db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach (['cinius_uretim','masraflar','odemeler'] as $table) {
    assert_production(in_array($table,$tables,true), "Missing CUP table: $table");
}

$list = production_list(1,50,'','all','ID','desc');
assert_production(isset($list['rows'],$list['total'],$list['columns']), 'Production list response is incomplete.');
assert_production(count($list['rows']) <= 50, 'Production list did not respect its page size.');
foreach (['UretimMaliyeti','AlinanOdeme','Kalan','CariBaglantisi'] as $hidden) {
    assert_production(!in_array($hidden,$list['columns'],true), 'Old project column is still visible: ' . $hidden);
}
foreach (['Yapılan Ödeme','Son Ödeme Tarihi'] as $column) assert_production(!in_array($column,$list['columns'],true), 'Project list must not show author-wide payments.');
assert_production(in_array('UrunBaglantisi',$list['columns'],true), 'Main-product linkage column is missing.');

$detailChecked = false;
if ($list['rows'] !== []) {
    $detail = production_detail((int)$list['rows'][0]['ID']);
    assert_production(isset($detail['record'],$detail['expenses'],$detail['payments'],$detail['main_links']), 'Production detail response is incomplete.');
    assert_production(isset($detail['main_links']['accounts'],$detail['main_links']['products']), 'Email bridge response is incomplete.');
    $detailChecked = true;
}

$source = file_get_contents(APP_ROOT . '/public/assets/app.js');
$page = file_get_contents(APP_ROOT . '/app/access.php');
assert_production(str_contains($source,'openProductionList') && str_contains($source,"descriptor.kind === 'production_project'"), 'Production window wiring or restoration is missing.');
assert_production(str_contains($page,'data-open-production-list') && str_contains($page,'data-open-production-new'), 'Production menu wiring is missing.');

echo json_encode([
    'ok'=>true,
    'database'=>$database,
    'production_rows'=>(int)$list['total'],
    'sample_detail_checked'=>$detailChecked,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . PHP_EOL;
