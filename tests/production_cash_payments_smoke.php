<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/production_service.php';

function check_payments(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// Read-only comparison with individual movements, including books without payments.
$ids = db()->query("SELECT DISTINCT u.ID FROM asp_urun u JOIN kasa k ON k.UrunID=u.ID
    WHERE u.CinYazarID > 0 LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
$ids = array_unique([...$ids, ...db()->query('SELECT ID FROM asp_urun WHERE CinYazarID > 0 LIMIT 20')->fetchAll(PDO::FETCH_COLUMN)]);
$payments = production_book_payments($ids);
check_payments(production_book_payments([]) === [], 'Empty product list must return no payments.');
foreach ($ids as $id) {
    $statement = db()->prepare('SELECT k.Islem,k.Miktar,k.islemTarihi,k.MusteriID,u.CinYazarID FROM kasa k JOIN asp_urun u ON u.ID=k.UrunID WHERE k.UrunID=?');
    $statement->execute([$id]);
    $total = 0.0;
    $date = null;
    foreach ($statement as $movement) {
        if ((int)$movement['Islem'] !== 2 || (int)$movement['MusteriID'] !== (int)$movement['CinYazarID']) continue;
        $total += (float)$movement['Miktar'];
        $movementDate = $movement['islemTarihi'] === null ? null : substr($movement['islemTarihi'], 0, 10);
        if (($movementDate ?? '') > ($date ?? '')) $date = $movementDate;
    }
    $actual = $payments[(int)$id] ?? ['total'=>0, 'last_date'=>null];
    check_payments(abs($actual['total']-$total) < 0.01, 'Book payment total differs from its author cash movements.');
    check_payments($actual['last_date'] === $date, 'Book last payment date differs.');
}
$list = production_list(1,50,'','all','ID','desc');
foreach (['Yapılan Ödeme','Son Ödeme Tarihi'] as $column) {
    check_payments(!in_array($column,$list['columns'],true), 'Payment column remains in project list.');
    foreach ($list['rows'] as $row) check_payments(!array_key_exists($column,$row), 'Author payment aggregation remains in project list.');
}
$balances = production_list(1,50,'','balances','ID','desc');
check_payments(in_array('Kalan',$balances['columns'],true), 'Balances view changed.');
echo count($ids) . " book payment totals and dates verified; project list and balances verified.\n";
