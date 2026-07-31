<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/product_metrics.php';

$schema = require APP_ROOT . '/app/schema.php';
$columns = $schema['asp_urun']['list'];
if (in_array('stokMiktar', $columns, true)) throw new RuntimeException('stokMiktar is still exposed in the product table.');
$virtualColumns = $schema['asp_urun']['virtual_list']['columns'] ?? [];
foreach (product_metric_columns() as $column) {
    if (!in_array($column, $virtualColumns, true)) throw new RuntimeException("Missing product metric column: $column");
}

$productId = (int) db()->query("SELECT u.`ID` FROM `asp_urun` u
    JOIN `faturadetay` fd ON fd.`urunID` = u.`ID`
    WHERE u.`CinYazarID` > 0 ORDER BY fd.`ID` DESC LIMIT 1")->fetchColumn();
if ($productId > 0) {
    $metrics = product_metrics_for_ids([$productId])[$productId] ?? null;
    if ($metrics === null) throw new RuntimeException('Metrics were not returned for an invoiced product.');
    foreach (product_metric_columns() as $column) {
        if (!isset($metrics[$column]) || $metrics[$column] < 0) throw new RuntimeException("Invalid $column metric.");
    }
}

echo "Product metrics smoke test passed for product #$productId.\n";
