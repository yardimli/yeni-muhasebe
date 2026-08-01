<?php
declare(strict_types=1);

$javascript=file_get_contents(__DIR__.'/../public/assets/app.js');
$service=file_get_contents(__DIR__.'/../app/report_service.php');
$production=file_get_contents(__DIR__.'/../app/production_service.php');
$api=file_get_contents(__DIR__.'/../public/api.php');

foreach (['reportTotalRow','reportTotalsFooter','numeric_columns','data.totals','<tfoot>'] as $contract) {
    if (!str_contains($javascript,$contract)) throw new RuntimeException("Report totals UI contract is missing: $contract");
}
foreach ([$service,$production,$api] as $source) {
    if (!str_contains($source,"'totals'") || !str_contains($source,"'numeric_columns'")) throw new RuntimeException('A report backend is missing totals metadata.');
}

echo "Report totals UI contract smoke test passed.\n";
