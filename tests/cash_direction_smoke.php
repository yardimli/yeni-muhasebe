<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/report_service.php';

$schema = require APP_ROOT . '/app/schema.php';
$labels = $schema['kasa']['value_labels']['Islem'] ?? [];
if ($labels !== [1=>'Gelir',2=>'Gider']) throw new RuntimeException('Kasa Islem labels do not follow 1=income, 2=expense.');

foreach (['combined_cashflow','executive_overview','cash_bank_channels','income_collections','outgoing_payments','expenses'] as $report) {
    $sql = business_report_definition($report,'monthly','2026-01-01','2026-12-31')['sql'];
    if (!str_contains($sql,'k.Islem=1') || !str_contains($sql,'k.Islem=2')) {
        throw new RuntimeException("Report direction is incorrect: $report");
    }
}
$incomeSql = business_report_definition('income_collections','monthly','2026-01-01','2026-12-31')['sql'];
$expenseSql = business_report_definition('expenses','monthly','2026-01-01','2026-12-31')['sql'];
if (!str_contains($incomeSql,'SUM(GelirIslem) Islem')) throw new RuntimeException('Income report transaction count includes expense rows.');
if (!str_contains($expenseSql,'SUM(GiderIslem) Islem')) throw new RuntimeException('Expense report transaction count includes income rows.');
foreach (['income_collections','expenses'] as $report) {
    $definition = business_report_definition($report,'monthly','2026-07-01','2026-07-31');
    $statement = db()->prepare('SELECT * FROM (' . $definition['sql'] . ') direction_report LIMIT 1');
    $statement->execute($definition['params']);
    $statement->fetch();
}

$counts = db()->query("SELECT
    SUM(CASE WHEN `Islem`=1 THEN 1 ELSE 0 END) income_rows,
    SUM(CASE WHEN `Islem`=2 THEN 1 ELSE 0 END) expense_rows,
    SUM(CASE WHEN `Islem` NOT IN (1,2) OR `Islem` IS NULL THEN 1 ELSE 0 END) unknown_rows
    FROM `kasa`")->fetch();

echo json_encode(['ok'=>true,'labels'=>$labels,'database_counts'=>$counts],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
