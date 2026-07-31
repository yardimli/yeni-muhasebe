<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/report_service.php';

$condition = business_report_author_copy_condition('fb', 'u');
if (!str_contains($condition, 'CinYazarID') || !str_contains($condition, 'MusteriID')) {
    throw new RuntimeException('Shared author-copy exclusion is incomplete.');
}

$salesReports = [
    'executive_overview','period_comparison','performance_anomalies','revenue','invoice_performance',
    'sales_returns','discount_vat','sales_payment_method','sales_by_customer','sales_by_location',
    'items_sold','product_performance','slow_products','author_performance','publisher_performance',
    'stock_movement','low_stock','receivables','first_time_buyers','active_customers',
    'new_vs_returning','customers_at_risk',
];
$range = business_report_range('this_year', null, null);
foreach ($salesReports as $id) {
    $definition = business_report_definition($id, 'monthly', $range['start'], $range['end']);
    if (!str_contains($definition['sql'], 'CinYazarID') || !str_contains($definition['sql'], 'MusteriID')) {
        throw new RuntimeException("Author-copy exclusion is missing from $id.");
    }
}

$excluded = (int) db()->query("SELECT COUNT(*) FROM faturadetay fd
    JOIN faturabaslik fb ON fb.ID=fd.faturaID JOIN asp_urun u ON u.ID=fd.urunID
    WHERE fb.FaturaTuru IN (1,2) AND u.CinYazarID>0
      AND CAST(fb.MusteriID AS UNSIGNED)=u.CinYazarID")->fetchColumn();
if ($excluded < 1) throw new RuntimeException('Test database has no author-copy rows to exercise the rule.');

$api = file_get_contents(__DIR__ . '/../public/api.php');
if (!str_contains($api, 'CinYazarID') || !str_contains($api, 'CAST(fb.`MusteriID` AS UNSIGNED)')) {
    throw new RuntimeException('Relationship sales reports do not contain the author-copy exclusion.');
}

echo "Author-copy exclusion smoke test passed for 22 reports and $excluded excluded invoice rows.\n";
