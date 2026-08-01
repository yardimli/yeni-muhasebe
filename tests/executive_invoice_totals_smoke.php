<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/report_service.php';

$definition = business_report_definition('executive_overview', 'monthly', '2026-07-01', '2026-07-31');
foreach (['GelenFatura'=>'Gelen Fatura Toplamı','GidenFatura'=>'Giden Fatura Toplamı'] as $key=>$label) {
    if (($definition['columns'][$key] ?? null) !== $label) throw new RuntimeException("Missing executive column: $label");
    if (!in_array($key, $definition['money'], true)) throw new RuntimeException("Executive column is not monetary: $label");
}
foreach ([
    'fb.FaturaTuru=1 AND NOT (COALESCE(u.`CinYazarID`, 0) > 0',
    'fb.FaturaTuru=2 AND NOT (COALESCE(u.`CinYazarID`, 0) > 0',
    'COUNT(DISTINCT CASE WHEN fb.FaturaTuru IN (1,2) AND NOT (COALESCE(u.`CinYazarID`, 0) > 0',
] as $contract) {
    if (!str_contains($definition['sql'], $contract)) throw new RuntimeException("Author-copy executive exclusion is missing: $contract");
}

$statement = db()->prepare('SELECT * FROM (' . $definition['sql'] . ') executive_invoice_totals ORDER BY Donem');
$statement->execute($definition['params']);
foreach ($statement->fetchAll() as $row) {
    if (!array_key_exists('GelenFatura', $row) || !array_key_exists('GidenFatura', $row)) {
        throw new RuntimeException('Invoice totals are missing from an executive report row.');
    }
}

$result=business_report_run('executive_overview','daily',['preset'=>'custom','start'=>'2026-07-21','end'=>'2026-07-21'],1,50,'','asc');
foreach (['Net Hasılat','Fatura','Ürün Adedi','Gelen Fatura Toplamı','Giden Fatura Toplamı','Tahsilat','Ödeme','Alınan Çek/Senet','Verilen Çek/Senet','Net Nakit','Aktif Müşteri'] as $column) {
    if (!array_key_exists($column,$result['totals'])) throw new RuntimeException("Full-result total is missing: $column");
}

echo "Executive incoming/outgoing invoice totals smoke test passed.\n";
