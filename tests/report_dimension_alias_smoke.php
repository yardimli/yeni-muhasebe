<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/report_service.php';

$range = business_report_range('current_month', null, null);
$reports = [
    'sales_by_customer',
    'sales_by_location',
    'items_sold',
    'product_performance',
    'author_performance',
    'publisher_performance',
];

foreach ($reports as $reportId) {
    $definition = business_report_definition($reportId, 'monthly', $range['start'], $range['end']);
    $sql = $definition['sql'];
    if (!str_contains($sql, ' RaporBoyutu')) {
        throw new RuntimeException("$reportId does not use the collision-safe report dimension alias.");
    }
    if (preg_match('/GROUP\s+BY[\s\S]*,\s*Boyut\b/i', $sql) === 1) {
        throw new RuntimeException("$reportId still groups by asp_urun.boyut.");
    }

    $statement = db()->prepare("SELECT * FROM ($sql) dimension_alias_smoke LIMIT 1");
    $statement->execute($definition['params']);
    $statement->fetch();
}

echo count($reports) . " report dimension aliases passed.\n";
