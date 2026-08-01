<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/production_service.php';

$range = production_report_range('this_year', null, null);
foreach (production_report_catalog() as $report => $title) {
    foreach (['monthly','yearly'] as $grouping) {
        $result = production_report_run($report, $grouping, $range, 1, 50, '', 'asc');
        if ($result['title'] !== $title) throw new RuntimeException("Wrong title for $report.");
        if ($result['range'] !== $range) throw new RuntimeException("Wrong range for $report.");
        if ($result['grouping'] !== $grouping) throw new RuntimeException("Wrong grouping for $report.");
        if (count($result['rows']) > 50) throw new RuntimeException("Pagination failed for $report.");
        if ($result['total'] < count($result['rows'])) throw new RuntimeException("Invalid total for $report.");
        if ($result['columns'] === [] || $result['sort'] !== 'Dönem') throw new RuntimeException("Column or sorting metadata is incomplete for $report.");
        if ($result['numeric_columns'] === [] || $result['totals'] === []) throw new RuntimeException("Totals metadata is incomplete for $report.");
    }
}

$custom = production_report_range('custom', '2024-01-01', '2024-12-31');
if ($custom['start'] !== '2024-01-01' || $custom['end'] !== '2024-12-31') throw new RuntimeException('Custom production report range failed.');

echo json_encode(['ok'=>true,'range'=>$range,'reports'=>array_keys(production_report_catalog())], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
