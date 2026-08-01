<?php
declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

$queries=[
    'faturabaslik'=>'SELECT COUNT(*) FROM `faturabaslik` base WHERE base.`FaturaTarih`>=? AND base.`FaturaTarih`<DATE_ADD(?,INTERVAL 1 DAY)',
    'kasa'=>'SELECT COUNT(*) FROM `kasa` base WHERE base.`islemTarihi`>=? AND base.`islemTarihi`<DATE_ADD(?,INTERVAL 1 DAY)',
    'banka_kasa'=>'SELECT COUNT(*) FROM `banka_kasa` base WHERE base.`tarih`>=? AND base.`tarih`<DATE_ADD(?,INTERVAL 1 DAY)',
    'cek_senet_kasa'=>'SELECT COUNT(*) FROM `cek_senet_kasa` base WHERE base.`bas_tarih`>=? AND base.`bas_tarih`<DATE_ADD(?,INTERVAL 1 DAY)',
    'hesaplar'=>'SELECT COUNT(*) FROM `hesaplar` base WHERE EXISTS (SELECT 1 FROM `banka_kasa` period_bank WHERE period_bank.`hesapID`=base.`ID` AND period_bank.`tarih`>=? AND period_bank.`tarih`<DATE_ADD(?,INTERVAL 1 DAY))',
    'kategoriler'=>'SELECT COUNT(*) FROM `kategoriler` base WHERE EXISTS (SELECT 1 FROM `kasa` period_cash WHERE period_cash.`KategoriID`=base.`ID` AND period_cash.`islemTarihi`>=? AND period_cash.`islemTarihi`<DATE_ADD(?,INTERVAL 1 DAY)) OR EXISTS (SELECT 1 FROM `banka_kasa` period_bank_category WHERE period_bank_category.`kategoriID`=base.`ID` AND period_bank_category.`tarih`>=? AND period_bank_category.`tarih`<DATE_ADD(?,INTERVAL 1 DAY))',
];
$counts=[];
foreach($queries as $table=>$sql){
    $statement=db()->prepare($sql);
    $params=$table==='kategoriler'?['2026-07-01','2026-07-31','2026-07-01','2026-07-31']:['2026-07-01','2026-07-31'];
    $statement->execute($params);
    $counts[$table]=(int)$statement->fetchColumn();
}
echo json_encode(['ok'=>true,'counts'=>$counts],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
