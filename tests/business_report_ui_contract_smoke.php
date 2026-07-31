<?php
declare(strict_types=1);

$catalog = require __DIR__ . '/../app/business_reports.php';
$index = file_get_contents(__DIR__ . '/../public/index.php');
$javascript = file_get_contents(__DIR__ . '/../public/assets/app.js');

$reports = array_merge(...array_values($catalog));
if (count($catalog) !== 6 || count($reports) !== 31) throw new RuntimeException('Grouped report catalog is incomplete.');
foreach (['business_report','data-grouping','data-preset','data-start','data-end','data-chart','data-chart-toggle','chartExpanded:false','data-export','data-print','openBusinessReport','loadBusinessReport'] as $contract) {
    if (!str_contains($javascript, $contract)) throw new RuntimeException("Missing report UI contract: $contract");
}
foreach (['current_month','last_month','last_3_months','this_year','last_year','custom'] as $preset) {
    if (!str_contains($javascript, "value=\"$preset\"")) throw new RuntimeException("Missing range preset: $preset");
}
if (!str_contains($index, 'report-submenu') || !str_contains($index, '>Raporlar</button>')) {
    throw new RuntimeException('Nested Reports menu is missing.');
}

echo "Business report UI contract smoke test passed: 6 groups, 31 reports.\n";
