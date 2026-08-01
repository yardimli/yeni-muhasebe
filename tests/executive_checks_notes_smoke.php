<?php
declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';
require APP_ROOT.'/app/report_service.php';

$definition=business_report_definition('executive_overview','monthly','2026-07-01','2026-07-31');
foreach(['AlinanCekSenet'=>'Alınan Çek/Senet','VerilenCekSenet'=>'Verilen Çek/Senet'] as $key=>$label){
    if(($definition['columns'][$key]??null)!==$label)throw new RuntimeException("Executive Çek/Senet column is missing: $label");
    if(!in_array($key,$definition['money'],true))throw new RuntimeException("Executive Çek/Senet column is not monetary: $label");
}
foreach(['c.alindi=1','c.verildi=1','c.bas_tarih'] as $contract){
    if(!str_contains($definition['sql'],$contract))throw new RuntimeException("Executive Çek/Senet SQL is missing: $contract");
}
$statement=db()->prepare('SELECT * FROM ('.$definition['sql'].') executive_checks');
$statement->execute($definition['params']);
foreach($statement->fetchAll() as $row){
    if(!array_key_exists('AlinanCekSenet',$row)||!array_key_exists('VerilenCekSenet',$row))throw new RuntimeException('Çek/Senet totals are absent from a report row.');
}
echo "Executive Çek/Senet smoke test passed.\n";
