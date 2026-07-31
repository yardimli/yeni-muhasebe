<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/report_service.php';

$schema = require APP_ROOT . '/app/schema.php';
$expected = [
    'cari_duzeltme_kayitlari'=>['Miktar'],
    'asp_urun'=>['eder'],
    'faturabaslik'=>['OnToplam','Indirim','KDV','Toplam'],
    'kasa'=>['Miktar'],
    'banka_kasa'=>['para'],
    'hesaplar'=>['bakiye'],
    'cek_senet_kasa'=>['miktar'],
];
foreach ($expected as $table => $columns) {
    if (($schema[$table]['money'] ?? []) !== $columns) throw new RuntimeException("Money metadata is incomplete for $table.");
}

$purchase = business_report_definition('purchases', 'monthly', '2026-01-01', '2026-12-31');
$stock = business_report_definition('stock_movement', 'monthly', '2026-01-01', '2026-12-31');
if (!in_array('Alis', $purchase['money'], true)) throw new RuntimeException('Purchase amount is not marked as money.');
foreach (['Satis','SatisIade','Alis','AlisIade','NetHareket'] as $quantity) {
    if (in_array($quantity, $stock['money'], true)) throw new RuntimeException("Stock quantity is incorrectly marked as money: $quantity");
}

$javascript = file_get_contents(APP_ROOT . '/public/assets/app.js');
foreach (['maximumFractionDigits: 0','Math.round(number)','formatMoney','money_columns','data.currency','text-right tabular-nums'] as $contract) {
    if (!str_contains($javascript, $contract)) throw new RuntimeException("Money display contract is missing: $contract");
}

echo "Money formatting UI contract smoke test passed.\n";
