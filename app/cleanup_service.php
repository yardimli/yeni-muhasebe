<?php
declare(strict_types=1);

function cleanup_product_condition(string $alias = 'u'): string
{
    $conditions = [];
    if (cleanup_column_exists('asp_urun', 'yayinevitxt')) {
        $conditions[] = "CONVERT(COALESCE($alias.`yayinevitxt`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci NOT LIKE '%cinius%'";
    }
    if (cleanup_table_exists('ureticiler')) {
        $conditions[] = "NOT EXISTS (
            SELECT 1 FROM `ureticiler` cleanup_publisher
            WHERE cleanup_publisher.`ID` = $alias.`ureticiID`
              AND CONVERT(COALESCE(cleanup_publisher.`uretici`, '') USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE '%cinius%'
        )";
    }
    return $conditions === [] ? '1 = 1' : implode(' AND ', $conditions);
}

function cleanup_product_rule(string $requested): string
{
    return in_array($requested, ['non_cinius', 'never_invoiced'], true) ? $requested : 'non_cinius';
}

function cleanup_social_publisher_exclusion(string $alias = 'u'): string
{
    return "NOT EXISTS (
        SELECT 1 FROM `ureticiler` cleanup_social_publisher
        WHERE cleanup_social_publisher.`ID` = $alias.`ureticiID`
          AND TRIM(CONVERT(COALESCE(cleanup_social_publisher.`uretici`, '') USING utf8mb4)) COLLATE utf8mb4_unicode_ci
              IN ('Sosyal Yayınları', 'Sosyal Yayınlar', 'Varsayılan Üretici')
    )";
}

function cleanup_deletable_product_condition(string $rule, string $alias = 'u'): string
{
    $rule = cleanup_product_rule($rule);
    $candidateCondition = $rule === 'never_invoiced'
        ? "NOT EXISTS (SELECT 1 FROM `faturadetay` cleanup_invoice_line WHERE cleanup_invoice_line.`urunID` = $alias.`ID`) AND " . cleanup_product_condition($alias)
        : cleanup_product_condition($alias);
    return "COALESCE($alias.`CinYazarID`, 0) = 0 AND $candidateCondition AND " . cleanup_social_publisher_exclusion($alias);
}

function cleanup_product_candidate_count(string $rule): int
{
    $where = cleanup_deletable_product_condition($rule, 'u');
    return (int) db()->query("SELECT COUNT(*) FROM `asp_urun` u WHERE $where")->fetchColumn();
}

function cleanup_product_candidate_ids(string $rule, int $limit = 5000): array
{
    $limit = max(1, min(5000, $limit));
    $where = cleanup_deletable_product_condition($rule, 'u');
    return array_map('intval', db()->query("SELECT u.`ID` FROM `asp_urun` u WHERE $where ORDER BY u.`ID` LIMIT $limit")->fetchAll(PDO::FETCH_COLUMN));
}

function cleanup_page_size(int $requested): int
{
    return in_array($requested, [100, 250, 500, 1000, 5000], true) ? $requested : 250;
}

function cleanup_ids(array $values): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $values), fn (int $id): bool => $id > 0)));
    if (count($ids) > 5000) {
        throw new InvalidArgumentException('Bir işlemde en fazla 5.000 kayıt seçilebilir.');
    }
    return $ids;
}

function cleanup_serialized_ids(string $value): array
{
    if (trim($value) === '') return [];
    return cleanup_ids(explode(',', $value));
}

function cleanup_table_exists(string $table): bool
{
    $statement = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $statement->execute([$table]);
    return (bool) $statement->fetchColumn();
}

function cleanup_column_exists(string $table, string $column): bool
{
    $statement = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $statement->execute([$table, $column]);
    return (bool) $statement->fetchColumn();
}

function cleanup_producer_reference_columns(): array
{
    if (!cleanup_table_exists('ureticiler')) return [];

    $sql = "SELECT DISTINCT c.`TABLE_NAME`, c.`COLUMN_NAME`
        FROM `INFORMATION_SCHEMA`.`COLUMNS` c
        JOIN `INFORMATION_SCHEMA`.`TABLES` t
          ON t.`TABLE_SCHEMA` = c.`TABLE_SCHEMA` AND t.`TABLE_NAME` = c.`TABLE_NAME`
        LEFT JOIN `INFORMATION_SCHEMA`.`KEY_COLUMN_USAGE` k
          ON k.`TABLE_SCHEMA` = c.`TABLE_SCHEMA`
         AND k.`TABLE_NAME` = c.`TABLE_NAME`
         AND k.`COLUMN_NAME` = c.`COLUMN_NAME`
         AND k.`REFERENCED_TABLE_SCHEMA` = c.`TABLE_SCHEMA`
         AND k.`REFERENCED_TABLE_NAME` = 'ureticiler'
        WHERE c.`TABLE_SCHEMA` = DATABASE()
          AND t.`TABLE_TYPE` = 'BASE TABLE'
          AND c.`TABLE_NAME` <> 'ureticiler'
          AND (LOWER(c.`COLUMN_NAME`) = 'ureticiid' OR k.`REFERENCED_TABLE_NAME` IS NOT NULL)
        ORDER BY c.`TABLE_NAME`, c.`ORDINAL_POSITION`";

    return array_values(array_filter(db()->query($sql)->fetchAll(), static function (array $row): bool {
        return preg_match('/^[A-Za-z0-9_$]+$/', (string) $row['TABLE_NAME']) === 1
            && preg_match('/^[A-Za-z0-9_$]+$/', (string) $row['COLUMN_NAME']) === 1;
    }));
}

function cleanup_orphan_producer_condition(string $alias = 'p', ?array $references = null): string
{
    $references ??= cleanup_producer_reference_columns();
    if ($references === []) return '1 = 1';

    return implode(' AND ', array_map(static function (array $reference) use ($alias): string {
        $table = $reference['TABLE_NAME'];
        $column = $reference['COLUMN_NAME'];
        return "NOT EXISTS (SELECT 1 FROM `$table` producer_ref WHERE producer_ref.`$column` = $alias.`ID`)";
    }, $references));
}

function cleanup_orphan_producer_status(): array
{
    if (!cleanup_table_exists('ureticiler')) {
        return ['exists' => false, 'count' => 0, 'reference_columns' => []];
    }

    $references = cleanup_producer_reference_columns();
    $condition = cleanup_orphan_producer_condition('p', $references);
    $count = (int) db()->query("SELECT COUNT(*) FROM `ureticiler` p WHERE $condition")->fetchColumn();
    return ['exists' => true, 'count' => $count, 'reference_columns' => $references];
}

function cleanup_orphan_producer_page(int $page, int $perPage): array
{
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;
    if (!cleanup_table_exists('ureticiler')) {
        return ['rows' => [], 'total' => 0, 'pages' => 1, 'page' => $page, 'per_page' => $perPage];
    }

    $references = cleanup_producer_reference_columns();
    $condition = cleanup_orphan_producer_condition('p', $references);
    $total = (int) db()->query("SELECT COUNT(*) FROM `ureticiler` p WHERE $condition")->fetchColumn();
    $sql = "SELECT p.`ID`, p.`uretici`, p.`telefon`, p.`adres`, p.`aciklama`
        FROM `ureticiler` p
        WHERE $condition
        ORDER BY p.`ID` DESC
        LIMIT $perPage OFFSET $offset";
    return [
        'rows' => db()->query($sql)->fetchAll(),
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $perPage)),
        'page' => $page,
        'per_page' => $perPage,
    ];
}

function cleanup_delete_orphan_producers(array $requestedIds): array
{
    $ids = cleanup_ids($requestedIds);
    if ($ids === []) throw new InvalidArgumentException('Silinecek bağlantısız üretici seçilmedi.');
    if (!cleanup_table_exists('ureticiler')) {
        return ['selected_orphan_producers' => count($ids), 'orphan_producers' => 0, 'protected_producers' => count($ids)];
    }

    // Rebuild immediately before DELETE so producers linked after preview are preserved.
    $references = cleanup_producer_reference_columns();
    $condition = cleanup_orphan_producer_condition('p', $references);
    $in = cleanup_placeholders($ids);
    $statement = db()->prepare("DELETE p FROM `ureticiler` p WHERE p.`ID` IN ($in) AND $condition");
    $statement->execute($ids);
    $deleted = $statement->rowCount();
    $result = [
        'selected_orphan_producers' => count($ids),
        'orphan_producers' => $deleted,
        'protected_producers' => count($ids) - $deleted,
    ];
    audit_log('cleanup_orphan_producers', 'ureticiler', null, [
        'counts' => $result,
        'selection_sha256' => hash('sha256', implode(',', $ids)),
        'reference_columns' => $references,
    ]);
    return $result;
}

function cleanup_placeholders(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

function cleanup_product_page(bool $linkedAuthor, int $page, int $perPage, string $rule = 'non_cinius'): array
{
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;
    $where = $linkedAuthor
        ? cleanup_product_condition('u') . ' AND COALESCE(u.`CinYazarID`, 0) <> 0'
        : cleanup_deletable_product_condition($rule, 'u');
    $total = (int) db()->query("SELECT COUNT(*) FROM `asp_urun` u WHERE $where")->fetchColumn();
    $stockCount = cleanup_table_exists('depodetay')
        ? '(SELECT COUNT(*) FROM `depodetay` dd WHERE dd.`urunID` = u.`ID`)' : '0';
    $sql = "SELECT u.`ID`, u.`isbn`, u.`kitapadi`, u.`ureticiID`, u.`CinYazarID`,
            CASE
                WHEN COALESCE(u.`ureticiID`, 0) = 0 THEN CONVERT('Yayınevi bağlantısı yok' USING utf8mb4)
                WHEN publisher.`ID` IS NULL THEN CONCAT(CONVERT('Yayınevi bulunamadı (#' USING utf8mb4), u.`ureticiID`, ')')
                WHEN NULLIF(TRIM(publisher.`uretici`), '') IS NULL THEN CONCAT(CONVERT('Adsız yayınevi (#' USING utf8mb4), u.`ureticiID`, ')')
                ELSE CONVERT(publisher.`uretici` USING utf8mb4)
            END AS publisher_name,
            CASE
                WHEN COALESCE(u.`CinYazarID`, 0) = 0 THEN CONVERT('Yazar bağlantısı yok' USING utf8mb4)
                WHEN a.`ID` IS NULL THEN CONCAT(CONVERT('Yazar bulunamadı (#' USING utf8mb4), u.`CinYazarID`, ')')
                ELSE COALESCE(NULLIF(CONVERT(a.`FirmaAd` USING utf8mb4), ''), NULLIF(CONVERT(CONCAT_WS(' ', a.`Ad`, a.`Soyad`) USING utf8mb4), ''), CONCAT(CONVERT('Adsız yazar (#' USING utf8mb4), u.`CinYazarID`, ')'))
            END AS author_name,
            (SELECT COUNT(*) FROM `faturadetay` fd WHERE fd.`urunID` = u.`ID`) invoice_lines,
            $stockCount stock_lines,
            (SELECT COUNT(*) FROM `kasa` k WHERE k.`UrunID` = u.`ID`) cash_rows
        FROM `asp_urun` u
        LEFT JOIN `ureticiler` publisher ON publisher.`ID` = u.`ureticiID`
        LEFT JOIN `adresdefteri` a ON a.`ID` = u.`CinYazarID`
        WHERE $where
        ORDER BY u.`ID` DESC
        LIMIT $perPage OFFSET $offset";
    return [
        'rows' => db()->query($sql)->fetchAll(),
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $perPage)),
        'page' => $page,
        'per_page' => $perPage,
    ];
}

function cleanup_address_candidate_where(string $alias = 'a'): string
{
    return "NOT EXISTS (SELECT 1 FROM `asp_urun` u WHERE u.`CinYazarID` = $alias.`ID`)
        AND NOT EXISTS (SELECT 1 FROM `faturabaslik` f WHERE CAST(f.`MusteriID` AS UNSIGNED) = $alias.`ID`)";
}

function cleanup_normalized_address_value(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function cleanup_address_link_details(array $requestedIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $requestedIds), fn (int $id): bool => $id > 0)));
    $details = [];
    foreach ($ids as $id) $details[$id] = ['total_links'=>0,'author_links'=>0,'invoice_links'=>0];
    foreach (array_chunk($ids, 1000) as $chunk) {
        $in = cleanup_placeholders($chunk);
        $queries = [
            ['author_links', "SELECT `CinYazarID` ref_id,COUNT(*) qty FROM `asp_urun` WHERE `CinYazarID` IN ($in) GROUP BY `CinYazarID`"],
            ['invoice_links', "SELECT CAST(`MusteriID` AS UNSIGNED) ref_id,COUNT(*) qty FROM `faturabaslik` WHERE CAST(`MusteriID` AS UNSIGNED) IN ($in) GROUP BY CAST(`MusteriID` AS UNSIGNED)"],
        ];
        foreach ($queries as [$type, $sql]) {
            $statement = db()->prepare($sql);
            $statement->execute($chunk);
            foreach ($statement->fetchAll() as $row) {
                $id = (int) $row['ref_id'];
                $qty = (int) $row['qty'];
                if (!isset($details[$id])) continue;
                if (isset($details[$id][$type])) $details[$id][$type] += $qty;
                $details[$id]['total_links'] += $qty;
            }
        }
        foreach (cleanup_address_financial_references($chunk) as $id => $qty) {
            if (isset($details[$id])) $details[$id]['total_links'] += $qty;
        }
    }
    return $details;
}

function cleanup_duplicate_address_page(int $page, int $perPage, string $query = ''): array
{
    $page = max(1, $page);
    $allRows = db()->query("SELECT `ID`,`FirmaAd`,`Ad`,`Soyad`,`eposta` FROM `adresdefteri`")->fetchAll();
    $emailMap = [];
    $nameMap = [];
    $byId = [];
    foreach ($allRows as $row) {
        $id = (int) $row['ID'];
        $byId[$id] = $row;
        $email = cleanup_normalized_address_value((string) $row['eposta']);
        $name = cleanup_normalized_address_value(trim((string) ($row['FirmaAd'] ?: ($row['Ad'] . ' ' . $row['Soyad']))));
        if ($email !== '') $emailMap[$email][] = $id;
        if ($name !== '') $nameMap[$name][] = $id;
    }
    $baseRows = db()->query("SELECT candidate.`ID`,candidate.`FirmaAd`,candidate.`Ad`,candidate.`Soyad`,candidate.`eposta`
        FROM `adresdefteri` candidate
        LEFT JOIN (SELECT DISTINCT `CinYazarID` FROM `asp_urun` WHERE `CinYazarID`>0) author_use ON author_use.`CinYazarID`=candidate.`ID`
        LEFT JOIN (SELECT DISTINCT CAST(`MusteriID` AS UNSIGNED) recipient_id FROM `faturabaslik` WHERE CAST(`MusteriID` AS UNSIGNED)>0) invoice_use ON invoice_use.recipient_id=candidate.`ID`
        WHERE author_use.`CinYazarID` IS NULL AND invoice_use.recipient_id IS NULL")->fetchAll();
    $candidateDetails = cleanup_address_link_details(array_map(fn (array $row): int => (int) $row['ID'], $baseRows));
    $possibleMatches = [];
    $candidateMatchIds = [];
    foreach ($baseRows as $row) {
        $id = (int) $row['ID'];
        if (($candidateDetails[$id]['total_links'] ?? 0) !== 0) continue;
        $email = cleanup_normalized_address_value((string) $row['eposta']);
        $name = cleanup_normalized_address_value(trim((string) ($row['FirmaAd'] ?: ($row['Ad'] . ' ' . $row['Soyad']))));
        $matchIds = array_values(array_unique(array_filter([
            ...($email !== '' ? ($emailMap[$email] ?? []) : []),
            ...($name !== '' ? ($nameMap[$name] ?? []) : []),
        ], fn (int $matchId): bool => $matchId !== $id)));
        if ($matchIds !== []) {
            $candidateMatchIds[$id] = ['row'=>$row,'ids'=>$matchIds,'email'=>$email,'name'=>$name];
            array_push($possibleMatches, ...$matchIds);
        }
    }
    $linkedDetails = cleanup_address_link_details($possibleMatches);
    $rows = [];
    $needle = cleanup_normalized_address_value($query);
    foreach ($candidateMatchIds as $id => $candidate) {
        $matches = [];
        foreach ($candidate['ids'] as $matchId) {
            $detail = $linkedDetails[$matchId] ?? ['total_links'=>0,'author_links'=>0,'invoice_links'=>0];
            if ($detail['total_links'] < 1 || !isset($byId[$matchId])) continue;
            $matchRow = $byId[$matchId];
            $matchEmail = cleanup_normalized_address_value((string) $matchRow['eposta']);
            $matchName = cleanup_normalized_address_value(trim((string) ($matchRow['FirmaAd'] ?: ($matchRow['Ad'] . ' ' . $matchRow['Soyad']))));
            $matches[] = $matchRow + $detail + ['email_match'=>$candidate['email'] !== '' && $candidate['email'] === $matchEmail,'name_match'=>$candidate['name'] !== '' && $candidate['name'] === $matchName];
        }
        if ($matches === []) continue;
        $row = $candidate['row'];
        if ($needle !== '' && !str_contains(cleanup_normalized_address_value(implode(' ', $row)), $needle)) continue;
        $row['matches'] = $matches;
        $rows[] = $row;
    }
    usort($rows, fn (array $a, array $b): int => (int) $b['ID'] <=> (int) $a['ID']);
    $total = count($rows);
    $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
    return ['rows'=>$rows,'total'=>$total,'pages'=>max(1,(int)ceil($total/$perPage)),'page'=>$page,'per_page'=>$perPage];
}

function cleanup_address_page(int $page, int $perPage, string $query = ''): array
{
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;
    $from = " FROM `adresdefteri` a
        LEFT JOIN (SELECT DISTINCT `CinYazarID` FROM `asp_urun` WHERE `CinYazarID` > 0) product_author ON product_author.`CinYazarID` = a.`ID`
        LEFT JOIN (SELECT DISTINCT CAST(`MusteriID` AS UNSIGNED) recipient_id FROM `faturabaslik` WHERE CAST(`MusteriID` AS UNSIGNED) > 0) invoice_recipient ON invoice_recipient.recipient_id = a.`ID`";
    $where = ' WHERE product_author.`CinYazarID` IS NULL AND invoice_recipient.recipient_id IS NULL';
    $params = [];
    $query = trim($query);
    if ($query !== '') {
        $where .= " AND (CAST(a.`ID` AS CHAR) LIKE ? OR a.`FirmaAd` LIKE ? OR a.`Ad` LIKE ? OR a.`Soyad` LIKE ? OR a.`eposta` LIKE ?)";
        $params = array_fill(0, 5, '%' . $query . '%');
    }
    $countStatement = db()->prepare('SELECT COUNT(*)' . $from . $where);
    $countStatement->execute($params);
    $total = (int) $countStatement->fetchColumn();
    $rowStatement = db()->prepare("SELECT a.`ID`, a.`FirmaAd`, a.`Ad`, a.`Soyad`, a.`eposta`" .
        $from . $where . " ORDER BY a.`ID` DESC LIMIT $perPage OFFSET $offset");
    $rowStatement->execute($params);
    $rows = $rowStatement->fetchAll();
    $ids = array_map(fn (array $row): int => (int) $row['ID'], $rows);
    $references = cleanup_address_financial_references($ids);
    foreach ($rows as &$row) {
        $row['financial_references'] = $references[(int) $row['ID']] ?? 0;
    }
    unset($row);
    return [
        'rows' => $rows,
        'total' => $total,
        'pages' => max(1, (int) ceil($total / $perPage)),
        'page' => $page,
        'per_page' => $perPage,
    ];
}

function cleanup_address_financial_references(array $ids): array
{
    if ($ids === []) return [];
    $ids = cleanup_ids($ids);
    $in = cleanup_placeholders($ids);
    $counts = array_fill_keys($ids, 0);
    $queries = [
        "SELECT `musteriID` ref_id, COUNT(*) qty FROM `faturadetay` WHERE `musteriID` IN ($in) GROUP BY ref_id",
        "SELECT `MusteriID` ref_id, COUNT(*) qty FROM `kasa` WHERE `MusteriID` IN ($in) GROUP BY ref_id",
        "SELECT `musteriID` ref_id, COUNT(*) qty FROM `banka_kasa` WHERE `musteriID` IN ($in) GROUP BY ref_id",
        "SELECT `firmaID` ref_id, COUNT(*) qty FROM `cek_senet_kasa` WHERE `firmaID` IN ($in) GROUP BY ref_id",
        "SELECT `ciro_firmaID` ref_id, COUNT(*) qty FROM `cek_senet_kasa` WHERE `ciro_firmaID` IN ($in) GROUP BY ref_id",
    ];
    if (cleanup_table_exists('odemeler')) {
        $queries[] = "SELECT CAST(`musteriID` AS UNSIGNED) ref_id, COUNT(*) qty FROM `odemeler` WHERE CAST(`musteriID` AS UNSIGNED) IN ($in) GROUP BY ref_id";
    }
    if (cleanup_table_exists('depobaslik')) {
        $queries[] = "SELECT CAST(`MusteriID` AS UNSIGNED) ref_id, COUNT(*) qty FROM `depobaslik` WHERE CAST(`MusteriID` AS UNSIGNED) IN ($in) GROUP BY ref_id";
    }
    if (cleanup_table_exists('depodetay')) {
        $queries[] = "SELECT CAST(`musteriID` AS UNSIGNED) ref_id, COUNT(*) qty FROM `depodetay` WHERE CAST(`musteriID` AS UNSIGNED) IN ($in) GROUP BY ref_id";
    }
    foreach ($queries as $sql) {
        $statement = db()->prepare($sql);
        $statement->execute($ids);
        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['ref_id'];
            if (isset($counts[$id])) $counts[$id] += (int) $row['qty'];
        }
    }
    return $counts;
}

function cleanup_address_financial_detail_group(
    string $key,
    string $label,
    string $table,
    string $where,
    array $params,
    array $columns
): array {
    if (!cleanup_table_exists($table)) {
        return ['key'=>$key, 'label'=>$label, 'table'=>$table, 'count'=>0, 'rows'=>[], 'truncated'=>false];
    }
    $availableColumns = array_values(array_filter($columns, fn (string $column): bool => cleanup_column_exists($table, $column)));
    if (!in_array('ID', $availableColumns, true)) array_unshift($availableColumns, 'ID');
    $select = implode(', ', array_map(fn (string $column): string => "`$column`", $availableColumns));
    $countStatement = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE $where");
    $countStatement->execute($params);
    $count = (int) $countStatement->fetchColumn();
    $rowStatement = db()->prepare("SELECT $select FROM `$table` WHERE $where ORDER BY `ID` DESC LIMIT 1000");
    $rowStatement->execute($params);
    return [
        'key'=>$key,
        'label'=>$label,
        'table'=>$table,
        'count'=>$count,
        'rows'=>$rowStatement->fetchAll(),
        'truncated'=>$count > 1000,
    ];
}

function cleanup_address_financial_reference_details(int $id): array
{
    if ($id < 1) throw new InvalidArgumentException('Geçerli bir cari ID gereklidir.');
    $addressStatement = db()->prepare("SELECT `ID`, `FirmaAd`, `Ad`, `Soyad`, `eposta` FROM `adresdefteri` WHERE `ID` = ? LIMIT 1");
    $addressStatement->execute([$id]);
    $address = $addressStatement->fetch();
    if ($address === false) throw new RuntimeException('Cari/adres kaydı bulunamadı.');

    $groups = [
        cleanup_address_financial_detail_group('invoice_lines', 'Fatura satırları', 'faturadetay', '`musteriID` = ?', [$id], ['ID','faturaID','urunID','urunAD','miktar','urunEder']),
        cleanup_address_financial_detail_group('cash', 'Kasa hareketleri', 'kasa', '`MusteriID` = ?', [$id], ['ID','islemTarihi','Miktar','Islem','BelgeNo','FaturaID','Aciklama']),
        cleanup_address_financial_detail_group('bank', 'Banka hareketleri', 'banka_kasa', '`musteriID` = ?', [$id], ['ID','tarih','para','hesapID','faturaID','aciklama']),
        cleanup_address_financial_detail_group('checks_owner', 'Çek / senet — firma', 'cek_senet_kasa', '`firmaID` = ?', [$id], ['ID','cekNo','miktar','bas_tarih','vade_tarih','cek_senet','aciklama']),
        cleanup_address_financial_detail_group('checks_endorsed', 'Çek / senet — ciro edilen cari', 'cek_senet_kasa', '`ciro_firmaID` = ?', [$id], ['ID','cekNo','miktar','ciro_date','vade_tarih','cek_senet','aciklama']),
    ];
    if (cleanup_table_exists('odemeler')) {
        $groups[] = cleanup_address_financial_detail_group('payments', 'Ödemeler (eski tablo)', 'odemeler', 'CAST(`musteriID` AS UNSIGNED) = ?', [$id], ['ID','tarih','vadetarih','tutar','turu','HesapID','Aciklama']);
    }
    if (cleanup_table_exists('depobaslik')) {
        $groups[] = cleanup_address_financial_detail_group('stock_headers', 'Depo başlıkları', 'depobaslik', 'CAST(`MusteriID` AS UNSIGNED) = ?', [$id], ['ID','faturaNo','FaturaTarih','FaturaTuru','UrunSayisi','Toplam']);
    }
    if (cleanup_table_exists('depodetay')) {
        $groups[] = cleanup_address_financial_detail_group('stock_lines', 'Depo satırları', 'depodetay', 'CAST(`musteriID` AS UNSIGNED) = ?', [$id], ['ID','faturaID','urunID','urunAD','miktar','urunEder']);
    }
    $groups = array_values(array_filter($groups, fn (array $group): bool => $group['count'] > 0));
    $name = trim((string) ($address['FirmaAd'] ?? '')) ?: trim((string) ($address['Ad'] ?? '') . ' ' . (string) ($address['Soyad'] ?? ''));
    return [
        'address'=>['ID'=>(int)$address['ID'], 'name'=>$name !== '' ? $name : 'Adsız cari', 'email'=>(string)($address['eposta'] ?? '')],
        'groups'=>$groups,
        'total'=>array_sum(array_column($groups, 'count')),
    ];
}

function cleanup_select_valid_products(array $ids, string $rule = 'non_cinius'): array
{
    if ($ids === []) return [];
    $in = cleanup_placeholders($ids);
    $statement = db()->prepare("SELECT u.`ID` FROM `asp_urun` u WHERE u.`ID` IN ($in)
        AND " . cleanup_deletable_product_condition($rule, 'u'));
    $statement->execute($ids);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function cleanup_related_header_ids(string $detailTable, array $productIds): array
{
    if ($productIds === []) return [];
    $in = cleanup_placeholders($productIds);
    $statement = db()->prepare("SELECT DISTINCT CAST(`faturaID` AS UNSIGNED) FROM `$detailTable` WHERE `urunID` IN ($in) AND `faturaID` > 0");
    $statement->execute($productIds);
    return array_values(array_filter(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN))));
}

function cleanup_delete_in(string $table, string $column, array $ids): int
{
    if ($ids === []) return 0;
    $statement = db()->prepare("DELETE FROM `$table` WHERE `$column` IN (" . cleanup_placeholders($ids) . ')');
    $statement->execute($ids);
    return $statement->rowCount();
}

function cleanup_empty_headers(string $headerTable, string $detailTable, array $headerIds): array
{
    if ($headerIds === []) return [];
    $in = cleanup_placeholders($headerIds);
    $statement = db()->prepare("SELECT h.`ID` FROM `$headerTable` h WHERE h.`ID` IN ($in)
        AND NOT EXISTS (SELECT 1 FROM `$detailTable` d WHERE CAST(d.`faturaID` AS UNSIGNED) = h.`ID`)");
    $statement->execute($headerIds);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function cleanup_recalculate_invoice_headers(array $headerIds): int
{
    if ($headerIds === []) return 0;
    $in = cleanup_placeholders($headerIds);
    $sql = "UPDATE `faturabaslik` h JOIN (
            SELECT d.`faturaID`, COALESCE(SUM(COALESCE(d.`miktar`, 0)), 0) quantity,
                COALESCE(SUM(COALESCE(d.`miktar`, 0) * COALESCE(d.`urunEder`, 0)), 0) subtotal,
                COALESCE(SUM(COALESCE(d.`miktar`, 0) * COALESCE(d.`urunEder`, 0) * COALESCE(d.`urunIndirim`, 0) / 100), 0) discount,
                COALESCE(SUM((COALESCE(d.`miktar`, 0) * COALESCE(d.`urunEder`, 0)) * (1 - COALESCE(d.`urunIndirim`, 0) / 100) * COALESCE(d.`urunKDV`, 0) / 100), 0) tax
            FROM `faturadetay` d WHERE d.`faturaID` IN ($in) GROUP BY d.`faturaID`
        ) x ON x.`faturaID` = h.`ID`
        SET h.`UrunSayisi` = x.quantity, h.`OnToplam` = ROUND(x.subtotal, 2),
            h.`Indirim` = ROUND(x.discount, 2), h.`KDV` = ROUND(x.tax, 2),
            h.`Toplam` = ROUND(x.subtotal - x.discount + x.tax, 2)";
    $statement = db()->prepare($sql);
    $statement->execute($headerIds);
    return $statement->rowCount();
}

function cleanup_recalculate_stock_headers(array $headerIds): int
{
    if ($headerIds === []) return 0;
    $in = cleanup_placeholders($headerIds);
    $sql = "UPDATE `depobaslik` h JOIN (
            SELECT CAST(d.`faturaID` AS UNSIGNED) faturaID, COALESCE(SUM(COALESCE(d.`miktar`, 0)), 0) quantity,
                COALESCE(SUM(COALESCE(d.`miktar`, 0) * COALESCE(d.`urunEder`, 0)), 0) total
            FROM `depodetay` d WHERE CAST(d.`faturaID` AS UNSIGNED) IN ($in) GROUP BY CAST(d.`faturaID` AS UNSIGNED)
        ) x ON x.faturaID = h.`ID`
        SET h.`UrunSayisi` = x.quantity, h.`Toplam` = ROUND(x.total, 2)";
    $statement = db()->prepare($sql);
    $statement->execute($headerIds);
    return $statement->rowCount();
}

function cleanup_delete_products(array $requestedIds, string $rule = 'non_cinius'): array
{
    $rule = cleanup_product_rule($rule);
    $ids = cleanup_select_valid_products(cleanup_ids($requestedIds), $rule);
    if ($ids === []) throw new RuntimeException('Seçilen ürünlerden hiçbiri artık silme koşullarını karşılamıyor.');
    $invoiceIds = cleanup_related_header_ids('faturadetay', $ids);
    $stockIds = cleanup_table_exists('depodetay') ? cleanup_related_header_ids('depodetay', $ids) : [];
    $result = ['validated_products' => count($ids)];
    $result['cash_product_rows'] = cleanup_delete_in('kasa', 'UrunID', $ids);
    $result['invoice_lines'] = cleanup_delete_in('faturadetay', 'urunID', $ids);
    $result['stock_lines'] = cleanup_table_exists('depodetay') ? cleanup_delete_in('depodetay', 'urunID', $ids) : 0;

    $emptyInvoices = cleanup_empty_headers('faturabaslik', 'faturadetay', $invoiceIds);
    $result['invoice_print_rows'] = cleanup_table_exists('faturabaski') ? cleanup_delete_in('faturabaski', 'faturaID', $emptyInvoices) : 0;
    $result['bank_invoice_rows'] = cleanup_delete_in('banka_kasa', 'faturaID', $emptyInvoices);
    $result['cash_invoice_rows'] = cleanup_delete_in('kasa', 'FaturaID', $emptyInvoices);
    if ($emptyInvoices !== [] && cleanup_table_exists('odemefatura')) {
        $in = cleanup_placeholders($emptyInvoices);
        $statement = db()->prepare("DELETE FROM `odemefatura` WHERE CAST(`faturaID` AS UNSIGNED) IN ($in)");
        $statement->execute($emptyInvoices);
        $result['payment_links'] = $statement->rowCount();
    } else {
        $result['payment_links'] = 0;
    }
    $result['invoice_headers'] = cleanup_delete_in('faturabaslik', 'ID', $emptyInvoices);
    $remainingInvoices = array_values(array_diff($invoiceIds, $emptyInvoices));
    $result['recalculated_invoice_headers'] = cleanup_recalculate_invoice_headers($remainingInvoices);

    $emptyStock = cleanup_table_exists('depobaslik') && cleanup_table_exists('depodetay')
        ? cleanup_empty_headers('depobaslik', 'depodetay', $stockIds) : [];
    $result['stock_headers'] = cleanup_table_exists('depobaslik') ? cleanup_delete_in('depobaslik', 'ID', $emptyStock) : 0;
    $result['recalculated_stock_headers'] = cleanup_table_exists('depobaslik') && cleanup_table_exists('depodetay')
        ? cleanup_recalculate_stock_headers(array_values(array_diff($stockIds, $emptyStock))) : 0;
    $result['products'] = cleanup_delete_in('asp_urun', 'ID', $ids);
    audit_log('cleanup_products', 'asp_urun', null, ['rule' => $rule, 'counts' => $result, 'selection_sha256' => hash('sha256', implode(',', $ids))]);
    return $result;
}

function cleanup_delete_all_never_invoiced_products(): array
{
    $totals = ['batches' => 0];
    while (($ids = cleanup_product_candidate_ids('never_invoiced', 5000)) !== []) {
        $batch = cleanup_delete_products($ids, 'never_invoiced');
        $totals['batches']++;
        foreach ($batch as $key => $value) {
            if (is_int($value)) $totals[$key] = ($totals[$key] ?? 0) + $value;
        }
    }
    if (($totals['products'] ?? 0) === 0) {
        throw new RuntimeException('Silinecek faturasız ve yazarsız ürün bulunamadı.');
    }
    audit_log('cleanup_all_never_invoiced_products', 'asp_urun', null, ['counts' => $totals]);
    return $totals;
}

function cleanup_delete_addresses(array $requestedIds): array
{
    $ids = cleanup_ids($requestedIds);
    if ($ids === []) throw new InvalidArgumentException('Silinecek cari/adres kaydı seçilmedi.');
    $in = cleanup_placeholders($ids);
    $statement = db()->prepare("SELECT a.`ID` FROM `adresdefteri` a
        LEFT JOIN (SELECT DISTINCT `CinYazarID` FROM `asp_urun` WHERE `CinYazarID` > 0) product_author ON product_author.`CinYazarID` = a.`ID`
        LEFT JOIN (SELECT DISTINCT CAST(`MusteriID` AS UNSIGNED) recipient_id FROM `faturabaslik` WHERE CAST(`MusteriID` AS UNSIGNED) > 0) invoice_recipient ON invoice_recipient.recipient_id = a.`ID`
        WHERE a.`ID` IN ($in) AND product_author.`CinYazarID` IS NULL AND invoice_recipient.recipient_id IS NULL");
    $statement->execute($ids);
    $valid = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    $references = cleanup_address_financial_references($valid);
    $valid = array_values(array_filter($valid, fn (int $id): bool => ($references[$id] ?? 0) === 0));
    if ($valid === []) throw new RuntimeException('Seçilen kayıtlar korunması gereken ürün, fatura veya finans hareketlerine bağlı.');
    $result = [];
    $result['tree_links'] = cleanup_table_exists('adres_defter_agac_iliskileri')
        ? cleanup_delete_in('adres_defter_agac_iliskileri', 'musteriID', $valid) : 0;
    $result['corrections'] = cleanup_delete_in('cari_duzeltme_kayitlari', 'musteriID', $valid);
    $result['addresses'] = cleanup_delete_in('adresdefteri', 'ID', $valid);
    $result['protected'] = count($ids) - count($valid);
    audit_log('cleanup_addresses', 'adresdefteri', null, ['counts' => $result, 'selection_sha256' => hash('sha256', implode(',', $valid))]);
    return $result;
}

function cleanup_delete_duplicate_addresses(array $requestedIds): array
{
    $requested = cleanup_ids($requestedIds);
    if ($requested === []) throw new InvalidArgumentException('Silinecek bağlantısız mükerrer cari seçilmedi.');
    $currentCandidates = cleanup_duplicate_address_page(1, PHP_INT_MAX);
    $candidateIds = array_map(fn (array $row): int => (int) $row['ID'], $currentCandidates['rows']);
    $valid = array_values(array_intersect($requested, $candidateIds));
    if ($valid === []) throw new RuntimeException('Seçilen kayıtların hiçbiri artık bağlantısız mükerrer kayıt koşullarını karşılamıyor.');
    $result = cleanup_delete_addresses($valid);
    $result['duplicate_candidates_skipped'] = count($requested) - count($valid);
    audit_log('cleanup_duplicate_addresses', 'adresdefteri', null, ['counts'=>$result,'selection_sha256'=>hash('sha256',implode(',', $valid))]);
    return $result;
}

function cleanup_product_archive_columns(): array
{
    return [
        'kod','konular','dizi','ozgundili','boyut','hamur','sayfa','cilt','xbaski','ozgunad',
        'kapakdosya','ozgunDosya','hizmetUrunu','resimDosya','stokMiktar','kaynakID','netkitapKod',
        'birlesikad','barkod2','satisMiktar','tedarikciIDler',
    ];
}

function cleanup_product_field_value_expression(string $column): string
{
    return "CONVERT(TRIM(COALESCE(CAST(`$column` AS CHAR), '')) USING latin5) COLLATE latin5_turkish_ci";
}

function cleanup_product_field_populated_condition(string $column): string
{
    $value = cleanup_product_field_value_expression($column);
    $empty = "CONVERT('' USING latin5) COLLATE latin5_turkish_ci";
    $zero = "CONVERT('0' USING latin5) COLLATE latin5_turkish_ci";
    return "$value <> $empty AND $value <> $zero";
}

function cleanup_product_fields_status(): array
{
    $configured = cleanup_product_archive_columns();
    $existing = array_values(array_filter($configured, fn (string $column): bool => cleanup_column_exists('asp_urun', $column)));
    $counts = array_fill_keys($configured, 0);
    $affectedRows = 0;
    if ($existing !== []) {
        $expressions = array_map(
            fn (string $column): string => 'SUM(CASE WHEN ' . cleanup_product_field_populated_condition($column) . " THEN 1 ELSE 0 END) AS `$column`",
            $existing
        );
        $conditions = array_map('cleanup_product_field_populated_condition', $existing);
        $statement = db()->query('SELECT ' . implode(', ', $expressions) . ', SUM(CASE WHEN (' . implode(' OR ', $conditions) . ') THEN 1 ELSE 0 END) AS affected_rows FROM `asp_urun`');
        $row = $statement->fetch() ?: [];
        foreach ($existing as $column) $counts[$column] = (int) ($row[$column] ?? 0);
        $affectedRows = (int) ($row['affected_rows'] ?? 0);
    }
    return [
        'configured_columns'=>$configured,
        'existing_columns'=>$existing,
        'missing_columns'=>array_values(array_diff($configured, $existing)),
        'field_counts'=>$counts,
        'affected_rows'=>$affectedRows,
        'description_available'=>cleanup_column_exists('asp_urun', 'aciklama'),
        'ready'=>$existing !== [] && cleanup_column_exists('asp_urun', 'aciklama'),
    ];
}

function cleanup_archive_and_drop_product_fields(): array
{
    $status = cleanup_product_fields_status();
    $columns = $status['existing_columns'];
    if ($columns === []) throw new RuntimeException('Ürün alanları daha önce kaldırılmış; yapılacak işlem yok.');
    if (!$status['description_available']) throw new RuntimeException('asp_urun.aciklama alanı bulunmadığı için veriler güvenle arşivlenemiyor.');

    $empty = "CONVERT('' USING latin5) COLLATE latin5_turkish_ci";
    $zero = "CONVERT('0' USING latin5) COLLATE latin5_turkish_ci";
    $separator = 'CONVERT(CHAR(10) USING latin5) COLLATE latin5_turkish_ci';
    $archiveMarkerText = '[asp_urun eski alan arşivi]';
    $archiveMarker = "CONVERT(" . db()->quote($archiveMarkerText) . " USING latin5) COLLATE latin5_turkish_ci";
    $parts = [$archiveMarker];
    $conditions = [];
    foreach ($columns as $column) {
        $value = cleanup_product_field_value_expression($column);
        $condition = "$value <> $empty AND $value <> $zero";
        $label = "CONVERT(" . db()->quote($column . ': ') . " USING latin5) COLLATE latin5_turkish_ci";
        $parts[] = "CASE WHEN $condition THEN CONCAT($label, $value) ELSE NULL END";
        $conditions[] = $condition;
    }
    $description = "NULLIF(TRIM(COALESCE(`aciklama`, $empty)), $empty)";
    $archivePattern = "CONVERT(" . db()->quote('%' . $archiveMarkerText . '%') . " USING latin5) COLLATE latin5_turkish_ci";
    $notArchived = "CONVERT(COALESCE(`aciklama`, '') USING latin5) COLLATE latin5_turkish_ci NOT LIKE $archivePattern";
    $update = db()->exec("UPDATE `asp_urun` SET `aciklama` = CONCAT_WS($separator, $description, " . implode(', ', $parts) . ") WHERE $notArchived AND (" . implode(' OR ', $conditions) . ')');
    $drops = implode(', ', array_map(fn (string $column): string => "DROP COLUMN `$column`", $columns));
    db()->exec("ALTER TABLE `asp_urun` $drops");
    $result = [
        'archived_products'=>(int) $update,
        'removed_fields'=>count($columns),
        'already_missing'=>count($status['missing_columns']),
    ];
    audit_log('cleanup_product_legacy_fields', 'asp_urun', null, ['columns'=>$columns, 'counts'=>$result]);
    return $result;
}

function cleanup_legacy_manifest(): array
{
    return [
        'adresdefteri.contact_archive' => [
            'kind' => 'cari_contact_archive',
            'columns' => ['Telefon1','Telefon2','Telefon3','GSMTel1','GSMTel2','Faks1','Faks2','kisaKod'],
        ],
        'adresdefteri.unused_fields' => [
            'kind' => 'cari_unused_fields',
            'table' => 'adresdefteri',
            'columns' => ['oncekiFaturalardanIndirimAl','listearama','fuaraKatiliyor','caridenDus','Etkin','Bakiye'],
        ],
        'depo.tables_and_references' => [
            'kind' => 'depot_cleanup',
            'columns' => ['asp_urun.varsayilanDepoID','faturabaslik.depoIslem'],
            'tables' => ['depodetay','depobaslik','depolar'],
        ],
        'cari_agac.tables' => [
            'kind' => 'table_cleanup',
            'tables' => ['adres_defter_agac_iliskileri','adres_agac'],
        ],
        'currency.tables_and_references' => [
            'kind' => 'table_cleanup',
            'columns' => ['asp_urun.kurID','banka_kasa.kurID','hesaplar.kurID','kasa.KurID'],
            'tables' => ['kurhareketleri','kurlar'],
        ],
        'payments.tables' => [
            'kind' => 'table_cleanup',
            'tables' => ['odemefatura','odemeler'],
        ],
        'obsolete.tables' => [
            'kind' => 'table_cleanup',
            'tables' => ['adresdefteri2','faturabaski','gelir','gider','nakitakis','stoktablo'],
        ],
        'asp_urun.relational_text_fields' => [
            'kind' => 'drop_columns',
            'table' => 'asp_urun',
            'columns' => ['yazarlistetxt', 'yayinevitxt'],
            'equivalent_ids' => ['CinYazarID', 'ureticiID'],
        ],
        'banka_kasa.assign' => ['table' => 'banka_kasa', 'column' => 'assign', 'populated' => '`assign` <> 0'],
        'banka_kasa.kasaID' => ['table' => 'banka_kasa', 'column' => 'kasaID', 'populated' => '`kasaID` <> 0'],
        'banka_kasa.giderID' => ['table' => 'banka_kasa', 'column' => 'giderID', 'populated' => '`giderID` <> 0'],
        'banka_kasa.gelirID' => ['table' => 'banka_kasa', 'column' => 'gelirID', 'populated' => '`gelirID` <> 0'],
        'banka_kasa.firmaID' => ['table' => 'banka_kasa', 'column' => 'firmaID', 'populated' => '`firmaID` <> 0'],
        'kasa.BankaIslemID' => ['table' => 'kasa', 'column' => 'BankaIslemID', 'populated' => '`BankaIslemID` <> 0'],
        'kasa.giderID' => ['table' => 'kasa', 'column' => 'giderID', 'populated' => '`giderID` <> 0'],
        'kasa.gelirID' => ['table' => 'kasa', 'column' => 'gelirID', 'populated' => '`gelirID` <> 0'],
    ];
}

function cleanup_legacy_status(): array
{
    $status = [];
    foreach (cleanup_legacy_manifest() as $key => $item) {
        if (($item['kind'] ?? '') === 'cari_contact_archive') {
            $existing = array_values(array_filter($item['columns'], fn (string $column): bool => cleanup_column_exists('adresdefteri', $column)));
            $conditions = array_map(fn (string $column): string => "NULLIF(TRIM(COALESCE(CAST(`$column` AS CHAR), '')), '') IS NOT NULL", $existing);
            $populated = $conditions === [] ? 0 : (int) db()->query('SELECT COUNT(*) FROM `adresdefteri` WHERE ' . implode(' OR ', $conditions))->fetchColumn();
            $status[$key] = $item + ['exists' => $existing !== [], 'existing_columns' => $existing, 'populated_rows' => $populated, 'safe' => $existing !== []];
            continue;
        }
        if (in_array(($item['kind'] ?? ''), ['drop_columns', 'cari_unused_fields'], true)) {
            $existing = array_values(array_filter($item['columns'], fn (string $column): bool => cleanup_column_exists($item['table'], $column)));
            $equivalentIds = $item['equivalent_ids'] ?? [];
            $idFieldsAvailable = array_reduce(
                $equivalentIds,
                fn (bool $available, string $column): bool => $available && cleanup_column_exists($item['table'], $column),
                true
            );
            $populated = 0;
            if (($item['kind'] ?? '') === 'drop_columns' && $existing !== []) {
                $conditions = array_map(fn (string $column): string => "NULLIF(TRIM(COALESCE(CAST(`$column` AS CHAR), '')), '') IS NOT NULL", $existing);
                $populated = (int) db()->query("SELECT COUNT(*) FROM `{$item['table']}` WHERE " . implode(' OR ', $conditions))->fetchColumn();
            } elseif (($item['kind'] ?? '') === 'cari_unused_fields' && $existing !== []) {
                $conditions = array_map(fn (string $column): string => "TRIM(COALESCE(CAST(`$column` AS CHAR), '')) NOT IN ('', '0')", $existing);
                $populated = (int) db()->query("SELECT COUNT(*) FROM `{$item['table']}` WHERE " . implode(' OR ', $conditions))->fetchColumn();
            }
            $status[$key] = $item + [
                'exists' => $existing !== [],
                'existing_columns' => $existing,
                'populated_rows' => $populated,
                'id_fields_available' => $idFieldsAvailable,
                'safe' => $existing !== [] && $idFieldsAvailable,
            ];
            continue;
        }
        if (in_array(($item['kind'] ?? ''), ['depot_cleanup', 'table_cleanup'], true)) {
            $existingTables = array_values(array_filter($item['tables'], 'cleanup_table_exists'));
            $existingColumns = array_values(array_filter($item['columns'] ?? [], function (string $reference): bool {
                [$table, $column] = explode('.', $reference, 2);
                return cleanup_column_exists($table, $column);
            }));
            $rows = 0;
            foreach ($existingTables as $table) $rows += (int) db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            $status[$key] = $item + ['exists' => $existingTables !== [] || $existingColumns !== [], 'existing_tables' => $existingTables, 'existing_columns' => $existingColumns, 'populated_rows' => $rows, 'safe' => $existingTables !== [] || $existingColumns !== []];
            continue;
        }
        $statement = db()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $statement->execute([$item['table'], $item['column']]);
        $exists = (bool) $statement->fetchColumn();
        $populated = $exists ? (int) db()->query("SELECT COUNT(*) FROM `{$item['table']}` WHERE {$item['populated']}")->fetchColumn() : 0;
        $status[$key] = $item + ['exists' => $exists, 'populated_rows' => $populated, 'safe' => $exists && $populated === 0];
    }
    return $status;
}

function cleanup_archive_cari_contact_fields(array $columns): int
{
    if ($columns === []) return 0;
    $empty = "CONVERT('' USING latin5) COLLATE latin5_turkish_ci";
    $separator = 'CONVERT(CHAR(10) USING latin5) COLLATE latin5_turkish_ci';
    $parts = array_map(function (string $column) use ($empty): string {
        $value = "CONVERT(TRIM(CAST(`$column` AS CHAR)) USING latin5) COLLATE latin5_turkish_ci";
        $label = "CONVERT('$column: ' USING latin5) COLLATE latin5_turkish_ci";
        return "CASE WHEN NULLIF(COALESCE($value, $empty), $empty) IS NULL THEN NULL ELSE CONCAT($label, $value) END";
    }, $columns);
    $where = implode(' OR ', array_map(fn (string $column): string => "NULLIF(CONVERT(TRIM(COALESCE(CAST(`$column` AS CHAR), '')) USING latin5) COLLATE latin5_turkish_ci, $empty) IS NOT NULL", $columns));
    $notes = "NULLIF(TRIM(COALESCE(`Notlar`, $empty)), $empty)";
    $affected = db()->exec("UPDATE `adresdefteri` SET `Notlar` = CONCAT_WS($separator, $notes, " . implode(', ', $parts) . ") WHERE $where");
    foreach ($columns as $column) db()->exec("ALTER TABLE `adresdefteri` DROP COLUMN `$column`");
    return (int) $affected;
}

function cleanup_drop_legacy_fields(array $requestedKeys): array
{
    $manifest = cleanup_legacy_manifest();
    $status = cleanup_legacy_status();
    $dropped = [];
    $skipped = [];
    foreach (array_values(array_unique(array_map('strval', $requestedKeys))) as $key) {
        if (!isset($manifest[$key], $status[$key]) || !$status[$key]['safe']) {
            $skipped[] = $key;
            continue;
        }
        $item = $manifest[$key];
        if (($item['kind'] ?? '') === 'cari_contact_archive') {
            $dropped[] = $key . ' (' . cleanup_archive_cari_contact_fields($status[$key]['existing_columns']) . ' kayıt Notlar alanına aktarıldı)';
            continue;
        }
        if (in_array(($item['kind'] ?? ''), ['drop_columns', 'cari_unused_fields'], true)) {
            if (($item['kind'] ?? '') === 'cari_unused_fields') {
                db()->exec("UPDATE `adresdefteri` SET `FirmaAd` = LEFT(TRIM(CONCAT_WS(' ', `Ad`, `Soyad`)), 145) WHERE `Kisisel` = 1 AND TRIM(CONCAT_WS(' ', `Ad`, `Soyad`)) <> ''");
            }
            foreach ($status[$key]['existing_columns'] as $column) db()->exec("ALTER TABLE `{$item['table']}` DROP COLUMN `$column`");
            $dropped[] = $key;
            continue;
        }
        if (in_array(($item['kind'] ?? ''), ['depot_cleanup', 'table_cleanup'], true)) {
            foreach ($status[$key]['existing_columns'] as $reference) {
                [$table, $column] = explode('.', $reference, 2);
                db()->exec("ALTER TABLE `$table` DROP COLUMN `$column`");
            }
            foreach ($item['tables'] as $table) {
                if (in_array($table, $status[$key]['existing_tables'], true)) db()->exec("DROP TABLE `$table`");
            }
            $dropped[] = $key;
            continue;
        }
        db()->exec("ALTER TABLE `{$item['table']}` DROP COLUMN `{$item['column']}`");
        $dropped[] = $key;
    }
    audit_log('cleanup_legacy_fields', null, null, ['source' => 'database/review_cleanup_legacy_fields.sql', 'dropped' => $dropped, 'skipped' => $skipped]);
    return ['dropped' => $dropped, 'skipped' => $skipped];
}

function cleanup_myisam_status(): array
{
    $statement = db()->query("SELECT `TABLE_NAME`, COALESCE(`TABLE_ROWS`, 0) `TABLE_ROWS`,
        COALESCE(`DATA_LENGTH`, 0) `DATA_LENGTH`, COALESCE(`INDEX_LENGTH`, 0) `INDEX_LENGTH`
        FROM `INFORMATION_SCHEMA`.`TABLES`
        WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_TYPE` = 'BASE TABLE' AND UPPER(`ENGINE`) = 'MYISAM'
        ORDER BY `TABLE_NAME`");
    $tables = $statement->fetchAll();
    return [
        'tables' => $tables,
        'count' => count($tables),
        'estimated_rows' => array_sum(array_map(fn (array $table): int => (int) $table['TABLE_ROWS'], $tables)),
        'total_bytes' => array_sum(array_map(fn (array $table): int => (int) $table['DATA_LENGTH'] + (int) $table['INDEX_LENGTH'], $tables)),
    ];
}

function cleanup_convert_myisam_to_innodb(): array
{
    $status = cleanup_myisam_status();
    $converted = [];
    $failed = [];
    foreach ($status['tables'] as $table) {
        $name = (string) $table['TABLE_NAME'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            $failed[] = "$name: geçersiz tablo adı";
            continue;
        }
        try {
            // Several legacy MyISAM tables declare ROW_FORMAT=FIXED. InnoDB does
            // not support FIXED, so override the inherited option during rebuild.
            db()->exec("ALTER TABLE `$name` ENGINE=InnoDB ROW_FORMAT=DYNAMIC");
            $converted[] = $name;
        } catch (Throwable $exception) {
            $failed[] = "$name: {$exception->getMessage()}";
        }
    }
    $result = [
        'detected_myisam_tables' => (int) $status['count'],
        'converted_tables' => count($converted),
        'failed_tables' => $failed,
        'remaining_myisam_tables' => (int) cleanup_myisam_status()['count'],
    ];
    audit_log('cleanup_convert_myisam_to_innodb', null, null, ['converted'=>$converted, 'failed'=>$failed]);
    return $result;
}
