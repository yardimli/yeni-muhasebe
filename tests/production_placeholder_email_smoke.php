<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/production_service.php';

if (!production_email_is_placeholder(' ADMIN@CINIUSYAYINLARI.COM ')) throw new RuntimeException('Placeholder e-mail normalization failed.');
if (production_email_is_placeholder('author@example.com')) throw new RuntimeException('A real e-mail was treated as the placeholder.');

$links = production_main_links('admin@ciniusyayinlari.com');
if ($links !== ['accounts'=>[], 'products'=>[]]) throw new RuntimeException('Placeholder e-mail still resolves main-database links.');

$list = production_list(1, 50, 'admin@ciniusyayinlari.com', 'all', 'ID', 'desc');
foreach ($list['rows'] as $row) {
    if (production_email_is_placeholder((string)$row['YazarinEpostasi'])) throw new RuntimeException('Placeholder e-mail remains visible in the production grid response.');
    if ((string)$row['YazarinEpostasi'] !== 'Belirtilmemiş') throw new RuntimeException('Placeholder e-mail does not use the not-set label.');
}

$javascript = file_get_contents(APP_ROOT . '/public/assets/app.js');
foreach (['placeholderEmail', "admin@ciniusyayinlari.com", "const linksPanel = placeholderEmail ? ''"] as $contract) {
    if (!str_contains($javascript, $contract)) throw new RuntimeException("Project link-panel suppression contract is missing: $contract");
}

echo json_encode(['ok'=>true, 'matched_rows'=>count($list['rows'])], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
