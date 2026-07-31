<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/cleanup_service.php';

$expected = ['kod','konular','dizi','ozgundili','boyut','hamur','sayfa','cilt','xbaski','ozgunad','kapakdosya','ozgunDosya','hizmetUrunu','resimDosya','stokMiktar','kaynakID','netkitapKod','birlesikad','barkod2','satisMiktar','tedarikciIDler'];
if (cleanup_product_archive_columns() !== $expected) throw new RuntimeException('Product cleanup field manifest differs from the requested fields.');
$status = cleanup_product_fields_status();
if (!$status['description_available']) throw new RuntimeException('asp_urun.aciklama is unavailable.');
if (array_diff($status['existing_columns'], $expected) !== []) throw new RuntimeException('Unexpected product cleanup column detected.');

$schema = require APP_ROOT . '/app/schema.php';
$product = $schema['asp_urun'];
foreach ($expected as $field) {
    if (in_array($field, $product['list'], true) || in_array($field, $product['search'], true)) throw new RuntimeException("$field is still exposed in the product table.");
    if (!in_array($field, $product['editor_hidden'], true)) throw new RuntimeException("$field is still editable.");
}
$reportSource = file_get_contents(APP_ROOT . '/app/report_service.php');
foreach ($expected as $field) {
    if (preg_match('/\b(?:u\.)?`?' . preg_quote($field, '/') . '`?\b/i', $reportSource)) throw new RuntimeException("Report SQL still references $field.");
}
$cleanupUi = file_get_contents(APP_ROOT . '/public/cleanup.php');
if (!str_contains($cleanupUi, "'product_fields'=>'Ürün alanları'") || !str_contains($cleanupUi, 'cleanup_product_fields')) {
    throw new RuntimeException('Combined cleanup product-fields subsection/action is missing.');
}

echo 'Product field cleanup smoke test passed: ' . count($expected) . " fields.\n";
