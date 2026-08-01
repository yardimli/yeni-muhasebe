<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/report_service.php';

$range = business_report_range('this_year', null, null);
$catalog = business_report_flat_catalog();
$cashflowSql = business_report_definition('combined_cashflow', 'monthly', $range['start'], $range['end'])['sql'];
$overviewSql = business_report_definition('executive_overview', 'monthly', $range['start'], $range['end'])['sql'];
$overview = business_report_definition('executive_overview', 'monthly', $range['start'], $range['end']);
foreach (['GelenFatura'=>'Gelen Fatura Toplamı','GidenFatura'=>'Giden Fatura Toplamı'] as $key=>$label) {
    if (($overview['columns'][$key] ?? null) !== $label) throw new RuntimeException("Executive overview is missing $label.");
    if (!in_array($key, $overview['money'], true)) throw new RuntimeException("$label is not formatted as money.");
}
foreach (['fb.FaturaTuru=3','fb.FaturaTuru=4','GelenFatura','GidenFatura'] as $contract) {
    if (!str_contains($overviewSql, $contract)) throw new RuntimeException("Executive invoice-total SQL is missing: $contract");
}
foreach ([$cashflowSql,$overviewSql] as $sql) {
    if (!str_contains($sql,'k.Islem=1') || !str_contains($sql,'k.Islem=2')) {
        throw new RuntimeException('Cash report direction must use Islem=1 for income and Islem=2 for expense.');
    }
}
$failures = [];
foreach ($catalog as $id => $report) {
    try {
        foreach (['daily', 'monthly'] as $grouping) {
            $definition = business_report_definition($id, $grouping, $range['start'], $range['end']);
            $statement = db()->prepare('SELECT * FROM (' . $definition['sql'] . ') smoke_report LIMIT 1');
            $statement->execute($definition['params']);
            $statement->fetch();
        }
        $result = business_report_run($id, 'monthly', $range, 1, 50, '', 'asc');
        if ($result['columns'] === [] || $result['title'] !== $report['label']) {
            throw new RuntimeException('Shared report response is incomplete.');
        }
    } catch (Throwable $exception) {
        $failures[$id] = $exception->getMessage();
    }
}
if ($failures !== []) {
    foreach ($failures as $id => $message) fwrite(STDERR, "$id: $message\n");
    throw new RuntimeException(count($failures) . ' business report SQL definitions failed.');
}

echo count($catalog) . ' business reports / ' . (count($catalog) * 2) . " daily-monthly SQL variants passed.\n";
