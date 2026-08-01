<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/cup_cleanup_service.php';
require APP_ROOT . '/app/production_service.php';

$placeholder = (int) cup_db()->query("SELECT COUNT(*) FROM `cinius_uretim` WHERE LOWER(TRIM(`YazarinEpostasi`))='admin@ciniusyayinlari.com'")->fetchColumn();
$page = cleanup_cup_match_page(1,100,'');
if ($page['total'] > $placeholder) throw new RuntimeException('Match count exceeds CUP placeholder-email records.');
if (count($page['rows']) > 100) throw new RuntimeException('CUP match pagination limit was ignored.');
$cupIds = [];
foreach (cleanup_cup_match_rows() as $row) {
    if ((float)$row['score'] < 82 || (float)$row['author_score'] < 75 || (float)$row['title_score'] < 60) throw new RuntimeException('Low-confidence CUP match escaped the threshold.');
    if (mb_strtolower(trim((string)$row['email']),'UTF-8') === CUP_PLACEHOLDER_EMAIL) throw new RuntimeException('Placeholder email would be copied back into CUP.');
    if (isset($cupIds[$row['cup_id']])) throw new RuntimeException('A CUP record has more than one selected best match.');
    $cupIds[$row['cup_id']] = true;
}

$monthly = production_analytics('monthly');
$yearly = production_analytics('yearly');
if (!isset($monthly['returning_authors'],$monthly['packages'],$yearly['returning_authors'],$yearly['packages'])) throw new RuntimeException('Production analytics response is incomplete.');

$cleanupUi = file_get_contents(APP_ROOT . '/public/cleanup.php');
$menu = file_get_contents(APP_ROOT . '/public/index.php');
$javascript = file_get_contents(APP_ROOT . '/public/assets/app.js');
foreach (['cup_email_matches','apply_cup_email_matches','selected_matches','sticky bottom-0','Seçilen e-posta ve tarihleri aktar'] as $contract) if (!str_contains($cleanupUi,$contract)) throw new RuntimeException("CUP cleanup UI contract is missing: $contract");
foreach (['İş Takibi','Okuma Bekleyenler','Dizgi Bekleyenler','Kapak Bekleyenler','Baskıdaki İşler','Tamamlananlar'] as $removed) if (str_contains($menu,$removed)) throw new RuntimeException("Removed production workflow menu remains: $removed");
foreach (['data-open-production-report="returning_authors"','data-open-production-report="package_distribution"','Geri Dönen Yazarlar','Paket Dağılımı'] as $contract) if (!str_contains($menu,$contract)) throw new RuntimeException("Production report menu contract is missing: $contract");
foreach (['openProductionReport','loadProductionReport','production_report','data-chart-toggle','data-export','data-print'] as $contract) if (!str_contains($javascript,$contract)) throw new RuntimeException("Production report window contract is missing: $contract");
foreach (['data-production-analytics','analyticsGrouping','loadProductionAnalytics'] as $removed) if (str_contains($javascript,$removed)) throw new RuntimeException("Embedded production analytics remains: $removed");

echo json_encode(['ok'=>true,'placeholder_records'=>$placeholder,'high_confidence_matches'=>$page['total'],'monthly_periods'=>count($monthly['returning_authors']),'yearly_periods'=>count($yearly['returning_authors'])],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
