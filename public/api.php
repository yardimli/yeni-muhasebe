<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
$schema = require APP_ROOT . '/app/schema.php';
$relationships = require APP_ROOT . '/app/relationships.php';
require APP_ROOT . '/app/report_service.php';
require APP_ROOT . '/app/delete_service.php';
require APP_ROOT . '/app/product_metrics.php';
require_auth();

function quoted_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid database identifier.');
    }
    return '`' . $identifier . '`';
}

function table_config(array $schema, string $table): array
{
    if (!isset($schema[$table])) {
        json_response(['error' => 'Unknown or unavailable section.'], 404);
    }
    return $schema[$table];
}

function table_columns(string $table): array
{
    static $cache = [];
    if (!isset($cache[$table])) {
        $statement = db()->query('SHOW FULL COLUMNS FROM ' . quoted_identifier($table));
        $cache[$table] = $statement->fetchAll();
    }
    return $cache[$table];
}

function column_map(string $table): array
{
    $map = [];
    foreach (table_columns($table) as $column) {
        $map[$column['Field']] = $column;
    }
    return $map;
}

function normalized_value(mixed $value, array $column): mixed
{
    if ($value === '' && $column['Null'] === 'YES') {
        return null;
    }
    if (is_string($value) && preg_match('/^(datetime|timestamp)/i', $column['Type'])) {
        return str_replace('T', ' ', $value);
    }
    return $value;
}

function fetch_record(string $table, int $id): ?array
{
    $statement = db()->prepare('SELECT * FROM ' . quoted_identifier($table) . ' WHERE `ID` = ? LIMIT 1');
    $statement->execute([$id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

$action = (string) ($_GET['action'] ?? '');
$table = (string) ($_GET['table'] ?? '');

try {
    if ($action === 'catalog') {
        $catalog = [];
        foreach ($schema as $name => $config) {
            $catalog[$name] = ['label' => $config['label'], 'group' => $config['group']];
        }
        json_response(['tables' => $catalog, 'csrf' => csrf_token()]);
    }

    if ($action === 'settings' && (string) ($_GET['section'] ?? '') === 'currency') {
        json_response([
            'currency' => setting_get('default_currency', 'TL'),
            'options' => currency_options(),
        ]);
    }

    if ($action === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $payload = request_json();
        if (($payload['section'] ?? '') !== 'currency') {
            json_response(['error' => 'Bilinmeyen ayar bölümü.'], 422);
        }
        $currency = strtoupper(trim((string) ($payload['currency'] ?? '')));
        $allowed = array_column(currency_options(), 'value');
        if (!in_array($currency, $allowed, true)) {
            json_response(['error' => 'Geçerli bir para birimi seçin.'], 422);
        }
        setting_set('default_currency', $currency);
        audit_log('update_settings', 'app_settings', 'default_currency', ['value' => $currency]);
        json_response(['saved' => true, 'currency' => $currency]);
    }

    if ($action === 'business_report') {
        $reportId = (string) ($_GET['report'] ?? '');
        $grouping = (string) ($_GET['grouping'] ?? 'monthly');
        $range = business_report_range(
            (string) ($_GET['preset'] ?? 'this_year'),
            isset($_GET['start']) ? (string) $_GET['start'] : null,
            isset($_GET['end']) ? (string) $_GET['end'] : null
        );
        json_response(business_report_run(
            $reportId,
            $grouping,
            $range,
            max(1, (int) ($_GET['page'] ?? 1)),
            (int) ($_GET['per_page'] ?? 250),
            (string) ($_GET['sort'] ?? ''),
            (string) ($_GET['dir'] ?? 'asc')
        ));
    }

    if ($action === 'report') {
        $report = (string) ($_GET['report'] ?? '');
        if (!in_array($report, ['product_sales', 'author_sales'], true)) {
            json_response(['error' => 'Unknown relationship report.'], 404);
        }
        $recordId = max(0, (int) ($_GET['id'] ?? 0));
        if ($recordId < 1) {
            json_response(['error' => 'A valid related record ID is required.'], 422);
        }
        $allowedPageSizes = [50, 100, 250, 500, 1000];
        $requestedPageSize = (int) ($_GET['per_page'] ?? 250);
        $perPage = in_array($requestedPageSize, $allowedPageSizes, true) ? $requestedPageSize : 250;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * $perPage;
        $direction = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $query = trim((string) ($_GET['q'] ?? ''));
        $columnsForReport = ['ID','faturaNo','FaturaTarih','Musteri','urunAD','FaturaTuru','miktar','urunEder','urunIndirim','urunKDV','urunKDVsizToplam','SatirToplami'];
        $sort = (string) ($_GET['sort'] ?? 'FaturaTarih');
        if (!in_array($sort, $columnsForReport, true)) {
            $sort = 'FaturaTarih';
        }
        $relationCondition = $report === 'product_sales' ? 'fd.`urunID` = ?' : 'u.`CinYazarID` = ?';
        $where = " WHERE $relationCondition AND fb.`FaturaTuru` IN (1, 2)
            AND NOT (COALESCE(u.`CinYazarID`, 0) > 0 AND CAST(fb.`MusteriID` AS UNSIGNED) = u.`CinYazarID`)";
        $params = [$recordId];
        if ($query !== '') {
            $where .= ' AND (fb.`faturaNo` LIKE ? OR fb.`AliciAd` LIKE ? OR fd.`urunAD` LIKE ? OR u.`kitapadi` LIKE ?)';
            array_push($params, ...array_fill(0, 4, '%' . $query . '%'));
        }
        $from = ' FROM `faturadetay` fd
            LEFT JOIN `faturabaslik` fb ON fb.`ID` = fd.`faturaID`
            LEFT JOIN `faturaturleri` ft ON ft.`ID` = fb.`FaturaTuru`
            LEFT JOIN `adresdefteri` a ON a.`ID` = CAST(fb.`MusteriID` AS UNSIGNED)
            LEFT JOIN `asp_urun` u ON u.`ID` = fd.`urunID`';
        $select = "SELECT
            fd.`ID`, fb.`ID` AS `__faturaID`, fb.`faturaNo`, fb.`FaturaTarih`,
            CASE
                WHEN CAST(fb.`MusteriID` AS UNSIGNED) > 0 AND a.`ID` IS NULL THEN CONVERT(CONCAT('Silinmiş kayıt (#', fb.`MusteriID`, ')') USING utf8mb4)
                ELSE COALESCE(
                    NULLIF(CONVERT(a.`FirmaAd` USING utf8mb4), ''),
                    NULLIF(CONVERT(CONCAT_WS(' ', a.`Ad`, a.`Soyad`) USING utf8mb4), ''),
                    NULLIF(CONVERT(fb.`AliciAd` USING utf8mb4), ''),
                    CONVERT('—' USING utf8mb4)
                )
            END AS `Musteri`,
            COALESCE(
                NULLIF(CONVERT(fd.`urunAD` USING utf8mb4), ''),
                NULLIF(CONVERT(u.`kitapadi` USING utf8mb4), ''),
                CONVERT(CONCAT('Silinmiş ürün (#', fd.`urunID`, ')') USING utf8mb4)
            ) AS `urunAD`,
            CASE WHEN ft.`ID` IS NULL THEN CONVERT(CONCAT('Silinmiş tür (#', fb.`FaturaTuru`, ')') USING utf8mb4) ELSE CONVERT(ft.`FaturaTuru` USING utf8mb4) END AS `FaturaTuru`,
            fd.`miktar`, fd.`urunEder`, fd.`urunIndirim`, fd.`urunKDV`, fd.`urunKDVsizToplam`,
            ROUND(COALESCE(fd.`miktar`, 0) * COALESCE(fd.`urunEder`, 0), 2) AS `SatirToplami`";
        $countStatement = db()->prepare('SELECT COUNT(*)' . $from . $where);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();
        $sql = $select . $from . $where . ' ORDER BY ' . quoted_identifier($sort) . " $direction, fd.`ID` DESC LIMIT $perPage OFFSET $offset";
        $statement = db()->prepare($sql);
        $statement->execute($params);
        $summaryStatement = db()->prepare("SELECT
            COALESCE(SUM(CASE WHEN fb.`FaturaTuru` = 1 THEN fd.`miktar` WHEN fb.`FaturaTuru` = 2 THEN -fd.`miktar` ELSE 0 END), 0) net_quantity,
            COALESCE(SUM(CASE WHEN fb.`FaturaTuru` = 1 THEN fd.`miktar` * fd.`urunEder` WHEN fb.`FaturaTuru` = 2 THEN -(fd.`miktar` * fd.`urunEder`) ELSE 0 END), 0) net_total" . $from . $where);
        $summaryStatement->execute($params);
        $totalsStatement = db()->prepare("SELECT
            COALESCE(SUM(fd.`miktar`),0) `miktar`,COALESCE(SUM(fd.`urunEder`),0) `urunEder`,
            COALESCE(SUM(fd.`urunIndirim`),0) `urunIndirim`,COALESCE(SUM(fd.`urunKDV`),0) `urunKDV`,
            COALESCE(SUM(fd.`urunKDVsizToplam`),0) `urunKDVsizToplam`,
            COALESCE(SUM(ROUND(COALESCE(fd.`miktar`,0)*COALESCE(fd.`urunEder`,0),2)),0) `SatirToplami`" . $from . $where);
        $totalsStatement->execute($params);
        $titleTable = $report === 'product_sales' ? 'asp_urun' : 'adresdefteri';
        $titleField = $report === 'product_sales' ? '`kitapadi`' : "COALESCE(NULLIF(`FirmaAd`, ''), NULLIF(TRIM(CONCAT_WS(' ', `Ad`, `Soyad`)), ''))";
        $titleStatement = db()->prepare('SELECT ' . $titleField . ' FROM ' . quoted_identifier($titleTable) . ' WHERE `ID` = ?');
        $titleStatement->execute([$recordId]);
        $recordTitle = $titleStatement->fetchColumn() ?: "#$recordId";
        json_response([
            'title' => ($report === 'product_sales' ? 'Satış Geçmişi — ' : 'Yazar Satışları — ') . $recordTitle,
            'rows' => $statement->fetchAll(),
            'columns' => $report === 'product_sales' ? array_values(array_diff($columnsForReport, ['urunAD'])) : $columnsForReport,
            'money_columns' => ['urunEder','urunKDVsizToplam','SatirToplami'],
            'currency' => setting_get('default_currency', 'TL'),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'sort' => $sort,
            'dir' => strtolower($direction),
            'summary' => $summaryStatement->fetch(),
            'totals' => $totalsStatement->fetch(),
            'numeric_columns' => ['miktar','urunEder','urunIndirim','urunKDV','urunKDVsizToplam','SatirToplami'],
        ]);
    }

    if ($action === 'delete_preview') {
        $id = max(0, (int) ($_GET['id'] ?? 0));
        json_response(cascade_delete_preview($table, $id));
    }

    if ($action === 'delete_record' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $payload = request_json();
        $deleteTable = (string) ($payload['table'] ?? $table);
        $id = max(0, (int) ($payload['id'] ?? 0));
        json_response(cascade_execute_delete($deleteTable, $id));
    }

    if ($action === 'invoice') {
        $invoiceId = max(0, (int) ($_GET['id'] ?? 0));
        if ($invoiceId < 1) {
            json_response(['error' => 'A valid invoice ID is required.'], 422);
        }
        $headerStatement = db()->prepare("SELECT fb.*,
            CASE
                WHEN CAST(fb.`MusteriID` AS UNSIGNED) > 0 AND customer.`ID` IS NULL THEN CONVERT(CONCAT('Silinmiş kayıt (#', fb.`MusteriID`, ')') USING utf8mb4)
                ELSE COALESCE(NULLIF(CONVERT(customer.`FirmaAd` USING utf8mb4), ''), NULLIF(CONVERT(CONCAT_WS(' ', customer.`Ad`, customer.`Soyad`) USING utf8mb4), ''), NULLIF(CONVERT(fb.`AliciAd` USING utf8mb4), ''), CONVERT('—' USING utf8mb4))
            END AS `Musteri`,
            CASE WHEN invoice_type.`ID` IS NULL THEN CONVERT(CONCAT('Silinmiş tür (#', fb.`FaturaTuru`, ')') USING utf8mb4) ELSE CONVERT(invoice_type.`FaturaTuru` USING utf8mb4) END AS `FaturaTuruAdi`
            FROM `faturabaslik` fb
            LEFT JOIN `adresdefteri` customer ON customer.`ID` = CAST(fb.`MusteriID` AS UNSIGNED)
            LEFT JOIN `faturaturleri` invoice_type ON invoice_type.`ID` = fb.`FaturaTuru`
            WHERE fb.`ID` = ?");
        $headerStatement->execute([$invoiceId]);
        $header = $headerStatement->fetch();
        if (!$header) {
            json_response(['error' => 'Invoice not found.'], 404);
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $allowedPageSizes = [50, 100, 250, 500, 1000];
        $requestedPageSize = (int) ($_GET['per_page'] ?? 250);
        $perPage = in_array($requestedPageSize, $allowedPageSizes, true) ? $requestedPageSize : 250;
        $offset = ($page - 1) * $perPage;
        $query = trim((string) ($_GET['q'] ?? ''));
        $columns = ['ID', 'urunID', 'urunAD', 'miktar', 'urunEder', 'urunIndirim', 'urunKDV', 'urunKDVsizToplam', 'SatirToplami'];
        $sort = (string) ($_GET['sort'] ?? 'ID');
        if (!in_array($sort, $columns, true)) $sort = 'ID';
        $direction = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $where = ' WHERE fd.`faturaID` = ?';
        $params = [$invoiceId];
        if ($query !== '') {
            $where .= ' AND (fd.`urunAD` LIKE ? OR fd.`urunBarKod` LIKE ? OR product.`kitapadi` LIKE ? OR product.`isbn` LIKE ?)';
            array_push($params, ...array_fill(0, 4, '%' . $query . '%'));
        }
        $from = ' FROM `faturadetay` fd LEFT JOIN `asp_urun` product ON product.`ID` = fd.`urunID`';
        $countStatement = db()->prepare('SELECT COUNT(*)' . $from . $where);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();
        $sortExpression = match ($sort) {
            'urunID' => 'product.`kitapadi`',
            'urunAD' => '`urunAD`',
            'SatirToplami' => '`SatirToplami`',
            default => 'fd.' . quoted_identifier($sort),
        };
        $select = "SELECT fd.`ID`,
            CASE
                WHEN fd.`urunID` IS NULL OR fd.`urunID` = 0 THEN CONVERT('—' USING utf8mb4)
                WHEN product.`ID` IS NULL THEN CONVERT(CONCAT('Silinmiş ürün (#', fd.`urunID`, ')') USING utf8mb4)
                ELSE CONVERT(product.`kitapadi` USING utf8mb4)
            END AS `urunID`,
            COALESCE(NULLIF(CONVERT(fd.`urunAD` USING utf8mb4), ''), NULLIF(CONVERT(product.`kitapadi` USING utf8mb4), ''), CONVERT('—' USING utf8mb4)) AS `urunAD`,
            fd.`miktar`, fd.`urunEder`, fd.`urunIndirim`, fd.`urunKDV`, fd.`urunKDVsizToplam`,
            ROUND(COALESCE(fd.`miktar`, 0) * COALESCE(fd.`urunEder`, 0) * (1 - COALESCE(fd.`urunIndirim`, 0) / 100) * (1 + COALESCE(fd.`urunKDV`, 0) / 100), 2) AS `SatirToplami`,
            fd.`urunID` AS `__urunID`";
        $statement = db()->prepare($select . $from . $where . " ORDER BY $sortExpression $direction, fd.`ID` ASC LIMIT $perPage OFFSET $offset");
        $statement->execute($params);
        json_response([
            'header' => $header,
            'title' => 'Fatura ' . ($header['faturaNo'] !== '' ? $header['faturaNo'] : '#' . $invoiceId) . ' — ' . $header['Musteri'],
            'rows' => $statement->fetchAll(),
            'columns' => $columns,
            'money_columns' => ['urunEder','urunKDVsizToplam','SatirToplami'],
            'currency' => setting_get('default_currency', 'TL'),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'sort' => $sort,
            'dir' => strtolower($direction),
        ]);
    }

    $config = table_config($schema, $table);
    $columns = column_map($table);

    if ($action === 'metadata') {
        $fields = [];
        $hiddenFields = array_flip($config['editor_hidden'] ?? []);
        foreach ($columns as $name => $column) {
            if (isset($hiddenFields[$name])) continue;
            $fields[] = [
                'name' => $name,
                'type' => $column['Type'],
                'nullable' => $column['Null'] === 'YES',
                'key' => $column['Key'],
                'default' => $column['Default'],
                'extra' => $column['Extra'],
                'comment' => $column['Comment'],
                'relation' => $config['relations'][$name] ?? null,
                'missing_label' => $config['relation_missing'][$name] ?? null,
                'value_labels' => $config['value_labels'][$name] ?? null,
            ];
        }
        $editorActions = [];
        foreach ($relationships[$table] ?? [] as $editorAction) {
            $valueField = $editorAction['value'] ?? 'ID';
            if (!isset($columns[$valueField])) continue;
            if (isset($editorAction['target'])) {
                $target = $editorAction['target'];
                $filter = $editorAction['filter'] ?? 'ID';
                if (!isset($schema[$target]) || !isset(column_map($target)[$filter])) continue;
            } elseif (!in_array($editorAction['report'] ?? '', ['product_sales', 'author_sales'], true)) {
                continue;
            }
            if ($valueField === 'ID') {
                $editorAction['title_lookup'] = ['table'=>$table, 'label'=>$config['name'] ?? 'ID'];
            } elseif (isset($config['relations'][$valueField])) {
                [$lookupTable, $lookupLabel] = $config['relations'][$valueField];
                $editorAction['title_lookup'] = ['table'=>$lookupTable, 'label'=>$lookupLabel];
            }
            $editorActions[] = $editorAction;
        }
        json_response([
            'table' => $table,
            'label' => $config['label'],
            'fields' => $fields,
            'list_columns' => array_values(array_filter($config['list'], fn ($name) => isset($columns[$name]))),
            'transactional' => (bool) ($config['transactional'] ?? false),
            'row_actions' => $editorActions,
        ]);
    }

    if ($action === 'list') {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $allowedPageSizes = [50, 100, 250, 500, 1000];
        $requestedPageSize = (int) ($_GET['per_page'] ?? 250);
        $perPage = in_array($requestedPageSize, $allowedPageSizes, true) ? $requestedPageSize : 250;
        $offset = ($page - 1) * $perPage;
        $virtualColumns = $table === 'asp_urun' ? product_metric_columns() : [];
        $configuredList = $config['list'];
        if ($virtualColumns !== []) {
            $configuredList = [];
            foreach ($config['list'] as $configuredColumn) {
                $configuredList[] = $configuredColumn;
                if ($configuredColumn === ($config['virtual_list']['after'] ?? null)) array_push($configuredList, ...$virtualColumns);
            }
        }
        $listColumns = array_values(array_filter($configuredList, fn ($name) => isset($columns[$name]) || in_array($name, $virtualColumns, true)));
        $sort = (string) ($_GET['sort'] ?? 'ID');
        if (!isset($columns[$sort]) && !in_array($sort, $virtualColumns, true)) {
            $sort = isset($columns['ID']) ? 'ID' : $listColumns[0];
        }
        $titleColumn = isset($config['name'], $columns[$config['name']]) ? $config['name'] : null;
        if ($titleColumn === null) {
            foreach ($listColumns as $candidate) {
                if ($candidate !== 'ID' && preg_match('/char|text/i', $columns[$candidate]['Type'])) {
                    $titleColumn = $candidate;
                    break;
                }
            }
        }
        if ($titleColumn === null) {
            $titleColumn = current(array_filter($listColumns, fn ($candidate) => $candidate !== 'ID')) ?: 'ID';
        }
        $rowActions = [];
        $actionValueFields = [];
        foreach ($relationships[$table] ?? [] as $rowAction) {
            $valueField = $rowAction['value'] ?? 'ID';
            if (!isset($columns[$valueField])) {
                continue;
            }
            if (isset($rowAction['target'])) {
                $target = $rowAction['target'];
                $filter = $rowAction['filter'] ?? 'ID';
                if (!isset($schema[$target]) || !isset(column_map($target)[$filter])) {
                    continue;
                }
            } elseif (!in_array($rowAction['report'] ?? '', ['product_sales', 'author_sales'], true)) {
                continue;
            }
            if ($valueField === 'ID') {
                $rowAction['title_lookup'] = ['table' => $table, 'label' => $titleColumn];
            } elseif (isset($config['relations'][$valueField])) {
                [$lookupTable, $lookupLabel] = $config['relations'][$valueField];
                if (isset($schema[$lookupTable]) && isset(column_map($lookupTable)[$lookupLabel])) {
                    $rowAction['title_lookup'] = ['table' => $lookupTable, 'label' => $lookupLabel];
                }
            } else {
                $rowAction['title_lookup'] = ['table' => $table, 'label' => $valueField];
            }
            $rowActions[] = $rowAction;
            $actionValueFields[$valueField] = true;
        }
        $selectExpressions = [];
        $joinClauses = [];
        $joinedColumns = [];
        $relationAliases = [];
        foreach ($listColumns as $index => $name) {
            if (in_array($name, $virtualColumns, true)) continue;
            $baseColumn = '`base`.' . quoted_identifier($name);
            $relation = $config['relations'][$name] ?? null;
            $valueLabels = $config['value_labels'][$name] ?? null;
            if ($relation === null && is_array($valueLabels) && $valueLabels !== []) {
                $cases = [];
                foreach ($valueLabels as $storedValue => $displayLabel) {
                    $cases[] = 'WHEN ' . $baseColumn . ' = ' . db()->quote((string) $storedValue) . ' THEN ' . db()->quote((string) $displayLabel);
                }
                $selectExpressions[] = 'CASE ' . implode(' ', $cases) . " ELSE CONCAT('Bilinmeyen işlem (#', CAST($baseColumn AS CHAR), ')') END AS " . quoted_identifier($name);
                continue;
            }
            if ($relation === null) {
                $selectExpressions[] = $baseColumn . ' AS ' . quoted_identifier($name);
                continue;
            }
            [$targetTable, $targetLabel] = $relation;
            if (!isset($schema[$targetTable]) || !isset(column_map($targetTable)[$targetLabel])) {
                $selectExpressions[] = $baseColumn . ' AS ' . quoted_identifier($name);
                continue;
            }
            $alias = 'relation_' . $index;
            $relationAliases[$name] = [$alias, $targetLabel];
            $joinedColumns[] = $name;
            $joinClauses[] = 'LEFT JOIN ' . quoted_identifier($targetTable) . ' AS ' . quoted_identifier($alias) .
                ' ON ' . $baseColumn . ' = ' . quoted_identifier($alias) . '.`ID`';
            $joinedId = quoted_identifier($alias) . '.`ID`';
            $joinedLabel = quoted_identifier($alias) . '.' . quoted_identifier($targetLabel);
            $missingLabel = $config['relation_missing'][$name] ?? null;
            $emptyRelation = $missingLabel === null ? "'—'" : db()->quote($missingLabel);
            $brokenRelation = db()->quote(($missingLabel ?? 'Silinmiş kayıt') . ' (#');
            $selectExpressions[] = "CASE
                WHEN $baseColumn IS NULL OR TRIM(CAST($baseColumn AS CHAR)) IN ('', '0') THEN $emptyRelation
                WHEN $joinedId IS NULL THEN CONCAT($brokenRelation, CAST($baseColumn AS CHAR), ')')
                WHEN NULLIF(TRIM(CAST($joinedLabel AS CHAR)), '') IS NULL THEN CONCAT('Adsız kayıt (#', CAST($joinedId AS CHAR), ')')
                ELSE CAST($joinedLabel AS CHAR)
            END AS " . quoted_identifier($name);
        }
        foreach (array_keys($actionValueFields) as $actionIndex => $valueField) {
            $selectExpressions[] = '`base`.' . quoted_identifier($valueField) . ' AS ' . quoted_identifier('__raw_' . $valueField);
            $actionTitleExpression = '`base`.' . quoted_identifier($valueField);
            if ($valueField === 'ID') {
                $actionTitleExpression = '`base`.' . quoted_identifier($titleColumn);
            } elseif (isset($config['relations'][$valueField])) {
                [$actionTarget, $actionLabel] = $config['relations'][$valueField];
                if (isset($schema[$actionTarget]) && isset(column_map($actionTarget)[$actionLabel])) {
                    $actionAlias = 'action_relation_' . $actionIndex;
                    $joinClauses[] = 'LEFT JOIN ' . quoted_identifier($actionTarget) . ' AS ' . quoted_identifier($actionAlias) .
                        ' ON `base`.' . quoted_identifier($valueField) . ' = ' . quoted_identifier($actionAlias) . '.`ID`';
                    $joinedActionId = quoted_identifier($actionAlias) . '.`ID`';
                    $joinedActionLabel = quoted_identifier($actionAlias) . '.' . quoted_identifier($actionLabel);
                    $baseActionValue = '`base`.' . quoted_identifier($valueField);
                    $missingActionLabel = $config['relation_missing'][$valueField] ?? 'Silinmiş kayıt';
                    $missingActionPrefix = db()->quote($missingActionLabel . ' (#');
                    $actionTitleExpression = "CASE
                        WHEN $joinedActionId IS NULL THEN CONCAT($missingActionPrefix, CAST($baseActionValue AS CHAR), ')')
                        WHEN NULLIF(TRIM(CAST($joinedActionLabel AS CHAR)), '') IS NULL THEN CONCAT('Adsız kayıt (#', CAST($joinedActionId AS CHAR), ')')
                        ELSE CAST($joinedActionLabel AS CHAR)
                    END";
                }
            }
            $selectExpressions[] = $actionTitleExpression . ' AS ' . quoted_identifier('__title_' . $valueField);
        }
        $countFromSql = ' FROM ' . quoted_identifier($table) . ' AS `base` ' . implode(' ', $joinClauses);
        if (in_array($sort, $virtualColumns, true)) $joinClauses[] = product_metrics_sort_join_sql();
        $fromSql = ' FROM ' . quoted_identifier($table) . ' AS `base` ' . implode(' ', $joinClauses);
        $searchColumns = $config['search'] ?? array_values(array_filter($listColumns, function ($name) use ($columns) {
            return preg_match('/char|text/i', $columns[$name]['Type']);
        }));
        $searchColumns = array_values(array_filter($searchColumns, fn ($name) => isset($columns[$name])));
        $direction = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $query = trim((string) ($_GET['q'] ?? ''));
        $whereParts = [];
        $params = [];
        $filterColumn = (string) ($_GET['filter_column'] ?? '');
        $filterValue = $_GET['filter_value'] ?? null;
        if ($filterColumn !== '') {
            if (!isset($columns[$filterColumn]) || $filterValue === null || is_array($filterValue)) {
                json_response(['error' => 'Invalid relationship filter.'], 422);
            }
            $whereParts[] = '`base`.' . quoted_identifier($filterColumn) . ' = ?';
            $params[] = (string) $filterValue;
        }
        $periodStart = trim((string)($_GET['period_start'] ?? ''));
        $periodEnd = trim((string)($_GET['period_end'] ?? ''));
        if ($periodStart !== '' || $periodEnd !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd) || $periodStart > $periodEnd) {
                json_response(['error'=>'Invalid period filter.'],422);
            }
            $periodCondition = match ($table) {
                'faturabaslik' => '`base`.`FaturaTarih` >= ? AND `base`.`FaturaTarih` < DATE_ADD(?,INTERVAL 1 DAY)',
                'kasa' => '`base`.`islemTarihi` >= ? AND `base`.`islemTarihi` < DATE_ADD(?,INTERVAL 1 DAY)',
                'banka_kasa' => '`base`.`tarih` >= ? AND `base`.`tarih` < DATE_ADD(?,INTERVAL 1 DAY)',
                'cek_senet_kasa' => '`base`.`bas_tarih` >= ? AND `base`.`bas_tarih` < DATE_ADD(?,INTERVAL 1 DAY)',
                'hesaplar' => 'EXISTS (SELECT 1 FROM `banka_kasa` period_bank WHERE period_bank.`hesapID`=`base`.`ID` AND period_bank.`tarih` >= ? AND period_bank.`tarih` < DATE_ADD(?,INTERVAL 1 DAY))',
                'kategoriler' => '(EXISTS (SELECT 1 FROM `kasa` period_cash WHERE period_cash.`KategoriID`=`base`.`ID` AND period_cash.`islemTarihi` >= ? AND period_cash.`islemTarihi` < DATE_ADD(?,INTERVAL 1 DAY)) OR EXISTS (SELECT 1 FROM `banka_kasa` period_bank_category WHERE period_bank_category.`kategoriID`=`base`.`ID` AND period_bank_category.`tarih` >= ? AND period_bank_category.`tarih` < DATE_ADD(?,INTERVAL 1 DAY)))',
                default => null,
            };
            if ($periodCondition === null) json_response(['error'=>'This section does not support period filtering.'],422);
            $whereParts[] = $periodCondition;
            if ($table === 'kategoriler') array_push($params,$periodStart,$periodEnd,$periodStart,$periodEnd);
            else array_push($params,$periodStart,$periodEnd);
        }
        if ($query !== '' && ($searchColumns !== [] || $relationAliases !== [])) {
            foreach (database_search_terms($query) as $term) {
                $parts = [];
                foreach ($searchColumns as $name) {
                    $parts[] = '`base`.' . quoted_identifier($name) . ' LIKE ?';
                    $params[] = '%' . $term . '%';
                }
                foreach ($relationAliases as [$alias, $labelColumn]) {
                    $parts[] = quoted_identifier($alias) . '.' . quoted_identifier($labelColumn) . ' LIKE ?';
                    $params[] = '%' . $term . '%';
                }
                $whereParts[] = '(' . implode(' OR ', $parts) . ')';
            }
        }
        $where = $whereParts === [] ? '' : ' WHERE ' . implode(' AND ', $whereParts);
        $countStatement = db()->prepare('SELECT COUNT(*)' . $countFromSql . $where);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();
        if (in_array($sort, $virtualColumns, true)) {
            $metricAlias = product_metric_aliases()[$sort];
            $sortExpression = 'COALESCE(`product_metrics`.' . quoted_identifier($metricAlias) . ', 0)';
        } elseif (isset($relationAliases[$sort])) {
            [$sortAlias, $sortLabel] = $relationAliases[$sort];
            $sortExpression = quoted_identifier($sortAlias) . '.' . quoted_identifier($sortLabel);
        } else {
            $sortExpression = '`base`.' . quoted_identifier($sort);
        }
        $secondarySort = $sort === 'ID' ? '' : ', `base`.`ID` DESC';
        // ORDER BY is deliberately applied before LIMIT/OFFSET so sorting covers the entire filtered table.
        $sql = 'SELECT ' . implode(', ', $selectExpressions) . $fromSql . $where .
            " ORDER BY $sortExpression $direction$secondarySort LIMIT $perPage OFFSET $offset";
        $statement = db()->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();
        if ($table === 'asp_urun') {
            $metrics = product_metrics_for_ids(array_map(fn (array $row): int => (int) $row['ID'], $rows));
            $emptyMetrics = array_fill_keys(product_metric_columns(), 0);
            foreach ($rows as &$row) {
                foreach (($metrics[(int) $row['ID']] ?? $emptyMetrics) as $metricName => $metricValue) $row[$metricName] = $metricValue;
            }
            unset($row);
        }
        json_response([
            'rows' => $rows,
            'columns' => $listColumns,
            'joined_columns' => $joinedColumns,
            'money_columns' => array_values(array_intersect($listColumns, $config['money'] ?? [])),
            'currency' => setting_get('default_currency', 'TL'),
            'row_actions' => $rowActions,
            'title_column' => $titleColumn,
            'active_filter' => $filterColumn === '' ? null : ['column' => $filterColumn, 'value' => (string) $filterValue],
            'active_period' => $periodStart === '' ? null : ['start'=>$periodStart,'end'=>$periodEnd],
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'sort' => $sort,
            'dir' => strtolower($direction),
        ]);
    }

    if ($action === 'record') {
        $id = max(0, (int) ($_GET['id'] ?? 0));
        if ($id < 1) {
            json_response(['error' => 'A valid record ID is required.'], 422);
        }
        $record = fetch_record($table, $id);
        if ($record === null) {
            json_response(['error' => 'Record not found.'], 404);
        }
        json_response(['record' => $record]);
    }

    if ($action === 'lookup') {
        $labelColumn = (string) ($_GET['label'] ?? 'ID');
        if (!isset($columns[$labelColumn])) {
            $labelColumn = 'ID';
        }
        $id = max(0, (int) ($_GET['id'] ?? 0));
        $query = trim((string) ($_GET['q'] ?? ''));
        $sql = 'SELECT `ID`, ' . quoted_identifier($labelColumn) . ' AS label FROM ' . quoted_identifier($table);
        $params = [];
        if ($id > 0) {
            $sql .= ' WHERE `ID` = ?';
            $params[] = $id;
        } elseif ($query !== '') {
            $sql .= ' WHERE ' . quoted_identifier($labelColumn) . ' LIKE ? OR CAST(`ID` AS CHAR) = ?';
            $params[] = '%' . $query . '%';
            $params[] = $query;
        }
        $sql .= ' ORDER BY ' . quoted_identifier($labelColumn) . ' LIMIT 30';
        $statement = db()->prepare($sql);
        $statement->execute($params);
        json_response(['options' => $statement->fetchAll()]);
    }

    if ($action === 'locations' && $table === 'adresdefteri') {
        $countryId = max(0, (int) ($_GET['country_id'] ?? 0));
        $cityId = max(0, (int) ($_GET['city_id'] ?? 0));
        $countries = db()->query('SELECT `ID`, `ulke` AS label FROM `ulkeler` ORDER BY `ulke`')->fetchAll();
        $cities = [];
        $districts = [];
        if ($countryId > 0) {
            $statement = db()->prepare('SELECT `ID`, `sehir` AS label FROM `sehirler` WHERE `ulkeID` = ? ORDER BY `sehir`');
            $statement->execute([$countryId]);
            $cities = $statement->fetchAll();
        }
        if ($cityId > 0) {
            $statement = db()->prepare('SELECT `ID`, `ilce` AS label FROM `ilceler` WHERE `sehirID` = ? ORDER BY `ilce`');
            $statement->execute([$cityId]);
            $districts = $statement->fetchAll();
        }
        json_response(['countries' => $countries, 'cities' => $cities, 'districts' => $districts]);
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $payload = request_json();
        $record = isset($payload['record']) && is_array($payload['record']) ? $payload['record'] : [];
        if ($table === 'adresdefteri') {
            $isPersonal = (int) ($record['Kisisel'] ?? 0) === 1;
            $record['Kisisel'] = $isPersonal ? 1 : 0;
            $record['Kurumsal'] = $isPersonal ? 0 : 1;
            if ($isPersonal) {
                $fullName = trim((string) ($record['Ad'] ?? '') . ' ' . (string) ($record['Soyad'] ?? ''));
                if ($fullName === '') json_response(['error' => 'Kişisel cari için ad veya soyad gereklidir.', 'field' => 'Ad'], 422);
                $record['FirmaAd'] = function_exists('mb_substr') ? mb_substr($fullName, 0, 145, 'UTF-8') : substr($fullName, 0, 145);
            } else {
                if (trim((string) ($record['FirmaAd'] ?? '')) === '') json_response(['error' => 'Kurumsal cari için firma adı gereklidir.', 'field' => 'FirmaAd'], 422);
                $record['Ad'] = '';
                $record['Soyad'] = '';
            }
            $record['gizle'] = (int) ($record['gizle'] ?? 0) === 1 ? 1 : 0;
            if ((int) ($record['ulkeID'] ?? 1) !== 1) {
                $record['Sehir'] = 0;
                $record['Ilce'] = 0;
            } else {
                $record['sehirAD'] = '';
            }
        }
        $id = isset($record['ID']) && (int) $record['ID'] > 0 ? (int) $record['ID'] : null;
        $before = $id ? fetch_record($table, $id) : null;
        if ($id && $before === null) {
            json_response(['error' => 'The record no longer exists.'], 409);
        }

        $values = [];
        foreach ($columns as $name => $column) {
            if ($name === 'ID' || str_contains(strtolower($column['Extra']), 'auto_increment')) {
                continue;
            }
            if (!array_key_exists($name, $record)) {
                continue;
            }
            if (str_contains(strtolower($column['Extra']), 'on update') && $record[$name] === '') {
                continue;
            }
            $value = normalized_value($record[$name], $column);
            if ($value === '' && $column['Null'] === 'NO' && $column['Default'] === null) {
                json_response(['error' => "$name is required.", 'field' => $name], 422);
            }
            $values[$name] = $value;
        }
        if ($values === []) {
            json_response(['error' => 'No editable values were supplied.'], 422);
        }

        if ($id !== null) {
            $assignments = array_map(fn ($name) => quoted_identifier($name) . ' = ?', array_keys($values));
            $statement = db()->prepare('UPDATE ' . quoted_identifier($table) . ' SET ' . implode(', ', $assignments) . ' WHERE `ID` = ? LIMIT 1');
            $statement->execute([...array_values($values), $id]);
            $changed = [];
            foreach ($values as $name => $value) {
                if ((string) ($before[$name] ?? '') !== (string) ($value ?? '')) {
                    $changed[$name] = ['from' => $before[$name] ?? null, 'to' => $value];
                }
            }
            audit_log('update', $table, $id, ['changes' => $changed]);
        } else {
            $names = array_keys($values);
            $statement = db()->prepare('INSERT INTO ' . quoted_identifier($table) .
                ' (' . implode(', ', array_map('quoted_identifier', $names)) . ')' .
                ' VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')');
            $statement->execute(array_values($values));
            $id = (int) db()->lastInsertId();
            audit_log('insert', $table, $id, ['fields' => array_keys($values)]);
        }
        json_response(['saved' => true, 'id' => $id, 'record' => fetch_record($table, $id)]);
    }

    json_response(['error' => 'Unsupported request.'], 404);
} catch (PDOException $exception) {
    $message = env_value('APP_DEBUG', 'false') === 'true' ? $exception->getMessage() : 'The database operation failed.';
    json_response(['error' => $message], 500);
} catch (Throwable $exception) {
    json_response(['error' => $exception->getMessage()], 500);
}
