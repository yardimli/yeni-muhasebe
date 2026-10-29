<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/production_service.php';

function check_payments(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// Compare the batched list summary against individual Kasa movements; no data is written.
$emails = db()->query("SELECT DISTINCT a.eposta FROM adresdefteri a JOIN kasa k ON k.MusteriID=a.ID
    WHERE k.Islem=2 AND NULLIF(TRIM(a.eposta),'') IS NOT NULL LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
$rows = array_map(fn($email)=>['YazarinEpostasi'=>$email], $emails);
$rows[] = ['YazarinEpostasi'=>''];
$rows[] = ['YazarinEpostasi'=>'admin@ciniusyayinlari.com'];
production_enrich_link_counts($rows, true);
$checked = 0;
foreach ($rows as $row) {
    $ids = array_column($row['__accounts'], 'ID');
    if (!$ids) {
        check_payments($row['Yapılan Ödeme'] === null && $row['Son Ödeme Tarihi'] === null, 'Unlinked project has payment data.');
        continue;
    }
    $statement = db()->prepare('SELECT Islem,Miktar,islemTarihi FROM kasa WHERE MusteriID IN (' . implode(',', array_fill(0,count($ids),'?')) . ')');
    $statement->execute($ids);
    $expectedTotal = 0.0;
    $expectedDate = null;
    foreach ($statement as $movement) {
        if ((int)$movement['Islem'] !== 2) continue;
        $expectedTotal += (float)$movement['Miktar'];
        $date = $movement['islemTarihi'] === null ? null : substr($movement['islemTarihi'],0,10);
        if (($date ?? '') > ($expectedDate ?? '')) $expectedDate = $date;
    }
    check_payments(abs($row['Yapılan Ödeme'] - $expectedTotal) < 0.01, 'Outgoing payment total differs from Kasa movements.');
    check_payments($row['Son Ödeme Tarihi'] === $expectedDate, 'Last outgoing payment date differs from Kasa movements.');
    $checked++;
}
$balances = production_list(1,50,'','balances','ID','desc');
check_payments(in_array('Kalan',$balances['columns'],true) && !in_array('Yapılan Ödeme',$balances['columns'],true), 'Balances view changed.');
echo "Kasa outgoing payments (Gider) and latest dates verified for $checked linked email groups; unlinked rows and balances verified.\n";
