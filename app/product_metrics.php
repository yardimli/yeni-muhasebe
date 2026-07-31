<?php
declare(strict_types=1);

function product_metric_columns(): array
{
    return ['Satış', 'İade', 'Yazara Verilen'];
}

function product_metric_aliases(): array
{
    return ['Satış'=>'metric_sales', 'İade'=>'metric_returns', 'Yazara Verilen'=>'metric_author_copies'];
}

function product_metrics_aggregate_sql(string $where = ''): string
{
    $authorCopy = 'COALESCE(metric_product.`CinYazarID`, 0) > 0
        AND CAST(metric_invoice.`MusteriID` AS UNSIGNED) = metric_product.`CinYazarID`';
    return "SELECT metric_line.`urunID` AS product_id,
        COALESCE(SUM(CASE WHEN metric_invoice.`FaturaTuru` = 1 AND NOT ($authorCopy) THEN COALESCE(metric_line.`miktar`, 0) ELSE 0 END), 0) AS metric_sales,
        COALESCE(SUM(CASE WHEN metric_invoice.`FaturaTuru` = 2 AND NOT ($authorCopy) THEN COALESCE(metric_line.`miktar`, 0) ELSE 0 END), 0) AS metric_returns,
        COALESCE(SUM(CASE WHEN metric_invoice.`FaturaTuru` = 1 AND ($authorCopy) THEN COALESCE(metric_line.`miktar`, 0) ELSE 0 END), 0) AS metric_author_copies
        FROM `faturadetay` metric_line
        JOIN `faturabaslik` metric_invoice ON metric_invoice.`ID` = metric_line.`faturaID`
        LEFT JOIN `asp_urun` metric_product ON metric_product.`ID` = metric_line.`urunID`
        $where
        GROUP BY metric_line.`urunID`";
}

function product_metrics_sort_join_sql(): string
{
    return 'LEFT JOIN (' . product_metrics_aggregate_sql() . ') product_metrics ON product_metrics.product_id = `base`.`ID`';
}

function product_metrics_for_ids(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
    if ($ids === []) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = db()->prepare(product_metrics_aggregate_sql("WHERE metric_line.`urunID` IN ($placeholders)"));
    $statement->execute($ids);
    $result = [];
    foreach ($statement->fetchAll() as $row) {
        $result[(int) $row['product_id']] = [
            'Satış'=>(int) $row['metric_sales'],
            'İade'=>(int) $row['metric_returns'],
            'Yazara Verilen'=>(int) $row['metric_author_copies'],
        ];
    }
    return $result;
}
