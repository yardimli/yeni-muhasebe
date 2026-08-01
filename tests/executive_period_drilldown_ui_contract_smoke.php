<?php
declare(strict_types=1);

$api=file_get_contents(__DIR__.'/../public/api.php');
$javascript=file_get_contents(__DIR__.'/../public/assets/app.js');

foreach (['period_start','period_end','active_period','period_cash','period_bank','period_bank_category'] as $contract) {
    if (!str_contains($api,$contract)) throw new RuntimeException("Period-filter API contract is missing: $contract");
}
foreach (['faturabaslik','kasa','banka_kasa','hesaplar','kategoriler','cek_senet_kasa'] as $table) {
    if (!str_contains($javascript,"table:'$table'")) throw new RuntimeException("Executive drill-down section is missing: $table");
}
foreach (['data-executive-row-action','data-executive-period','openExecutivePeriodSection','executivePeriodRange','periodFilter'] as $contract) {
    if (!str_contains($javascript,$contract)) throw new RuntimeException("Executive drill-down UI contract is missing: $contract");
}

echo "Executive period drill-down UI contract smoke test passed.\n";
