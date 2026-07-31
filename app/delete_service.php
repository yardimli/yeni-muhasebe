<?php
declare(strict_types=1);

require_once APP_ROOT . '/app/cleanup_service.php';

function cascade_delete_allowed_tables(): array
{
    return ['adresdefteri', 'asp_urun', 'faturabaslik'];
}

function cascade_assert_target(string $table, int $id): array
{
    if (!in_array($table, cascade_delete_allowed_tables(), true) || $id < 1) {
        throw new InvalidArgumentException('Bu kayıt türü toplu ilişkili silmeyi desteklemiyor.');
    }
    $statement = db()->prepare("SELECT * FROM `$table` WHERE `ID` = ? LIMIT 1");
    $statement->execute([$id]);
    $record = $statement->fetch();
    if ($record === false) throw new RuntimeException('Silinecek kayıt artık bulunmuyor.');
    return $record;
}

function cascade_ids(string $sql, array $params = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return array_values(array_unique(array_filter(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)))));
}

function cascade_normalize_ids(array $values): array
{
    return array_values(array_unique(array_filter(array_map('intval', $values), fn (int $id): bool => $id > 0)));
}

function cascade_group(string $key, string $label, string $table, string $where, array $params, string $action = 'delete'): array
{
    if (!cleanup_table_exists($table)) {
        return ['key'=>$key, 'label'=>$label, 'table'=>$table, 'count'=>0, 'ids'=>[], 'truncated'=>false, 'action'=>$action];
    }
    $count = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE $where");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $rows = db()->prepare("SELECT `ID` FROM `$table` WHERE $where ORDER BY `ID` LIMIT 200");
    $rows->execute($params);
    return [
        'key'=>$key,
        'label'=>$label,
        'table'=>$table,
        'count'=>$total,
        'ids'=>array_map('intval', $rows->fetchAll(PDO::FETCH_COLUMN)),
        'truncated'=>$total > 200,
        'action'=>$action,
    ];
}

function cascade_in_group(string $key, string $label, string $table, string $column, array $ids, string $action = 'delete'): array
{
    if ($ids === [] || !cleanup_table_exists($table) || !cleanup_column_exists($table, $column)) {
        return ['key'=>$key, 'label'=>$label, 'table'=>$table, 'count'=>0, 'ids'=>[], 'truncated'=>false, 'action'=>$action];
    }
    return cascade_group($key, $label, $table, "`$column` IN (" . cleanup_placeholders($ids) . ')', $ids, $action);
}

function cascade_record_title(string $table, array $record): string
{
    if ($table === 'asp_urun') return trim((string) ($record['kitapadi'] ?? '')) ?: 'Adsız ürün';
    if ($table === 'faturabaslik') return 'Fatura ' . (trim((string) ($record['faturaNo'] ?? '')) ?: '#' . $record['ID']);
    return trim((string) ($record['FirmaAd'] ?? '')) ?: (trim((string) ($record['Ad'] ?? '') . ' ' . (string) ($record['Soyad'] ?? '')) ?: 'Adsız cari');
}

function cascade_invoice_payment_ids(array $invoiceIds): array
{
    if ($invoiceIds === []) return ['cash'=>[], 'bank'=>[]];
    $in = cleanup_placeholders($invoiceIds);
    $statement = db()->prepare("SELECT `odemeKasaID`, `odemeBankaID` FROM `faturabaslik` WHERE `ID` IN ($in)");
    $statement->execute($invoiceIds);
    $cash = []; $bank = [];
    foreach ($statement->fetchAll() as $row) {
        if ((int) $row['odemeKasaID'] > 0) $cash[] = (int) $row['odemeKasaID'];
        if ((int) $row['odemeBankaID'] > 0) $bank[] = (int) $row['odemeBankaID'];
    }
    return ['cash'=>array_values(array_unique($cash)), 'bank'=>array_values(array_unique($bank))];
}

function cascade_invoice_groups(array $invoiceIds): array
{
    if ($invoiceIds === []) return [];
    $in = cleanup_placeholders($invoiceIds);
    $payments = cascade_invoice_payment_ids($invoiceIds);
    $cashWhere = "`FaturaID` IN ($in)";
    $cashParams = $invoiceIds;
    if ($payments['cash'] !== []) {
        $cashWhere .= ' OR `ID` IN (' . cleanup_placeholders($payments['cash']) . ')';
        array_push($cashParams, ...$payments['cash']);
    }
    $bankWhere = "`faturaID` IN ($in)";
    $bankParams = $invoiceIds;
    if ($payments['bank'] !== []) {
        $bankWhere .= ' OR `ID` IN (' . cleanup_placeholders($payments['bank']) . ')';
        array_push($bankParams, ...$payments['bank']);
    }
    $groups = [
        cascade_group('invoice_lines', 'Fatura satırları', 'faturadetay', "`faturaID` IN ($in)", $invoiceIds),
        cascade_group('cash_invoice', 'Faturaya bağlı kasa hareketleri', 'kasa', $cashWhere, $cashParams),
        cascade_group('bank_invoice', 'Faturaya bağlı banka hareketleri', 'banka_kasa', $bankWhere, $bankParams),
    ];
    if (cleanup_table_exists('faturabaski')) {
        $groups[] = cascade_group('invoice_prints', 'Fatura baskı geçmişi (eski tablo)', 'faturabaski', "`faturaID` IN ($in)", $invoiceIds);
    }
    if (cleanup_table_exists('odemefatura')) {
        $groups[] = cascade_group('payment_links_invoice', 'Ödeme-fatura bağlantıları (eski tablo)', 'odemefatura', "CAST(`faturaID` AS UNSIGNED) IN ($in)", $invoiceIds);
    }
    if (cleanup_table_exists('depodetay') && cleanup_column_exists('depodetay', 'faturaID')) {
        $groups[] = cascade_group('stock_invoice_lines', 'Faturaya bağlı depo satırları', 'depodetay', "CAST(`faturaID` AS UNSIGNED) IN ($in)", $invoiceIds);
    }
    $groups[] = cascade_in_group('invoice_headers', 'Fatura başlıkları', 'faturabaslik', 'ID', $invoiceIds);
    return $groups;
}

function cascade_product_invoice_ids(array $productIds): array
{
    if ($productIds === []) return [];
    $in = cleanup_placeholders($productIds);
    return cascade_ids("SELECT DISTINCT CAST(`faturaID` AS UNSIGNED) FROM `faturadetay` WHERE `urunID` IN ($in) AND `faturaID` > 0", $productIds);
}

function cascade_product_empty_invoice_ids(array $productIds, array $invoiceIds): array
{
    if ($productIds === [] || $invoiceIds === []) return [];
    $productIn = cleanup_placeholders($productIds);
    $invoiceIn = cleanup_placeholders($invoiceIds);
    return cascade_ids("SELECT fb.`ID` FROM `faturabaslik` fb WHERE fb.`ID` IN ($invoiceIn)
        AND NOT EXISTS (SELECT 1 FROM `faturadetay` fd WHERE fd.`faturaID` = fb.`ID`
            AND (fd.`urunID` IS NULL OR fd.`urunID` NOT IN ($productIn)))", [...$invoiceIds, ...$productIds]);
}

function cascade_product_groups(array $productIds, bool $includeInvoiceEffects = true): array
{
    if ($productIds === []) return [];
    $invoiceIds = cascade_product_invoice_ids($productIds);
    $emptyInvoices = cascade_product_empty_invoice_ids($productIds, $invoiceIds);
    $remainingInvoices = array_values(array_diff($invoiceIds, $emptyInvoices));
    $groups = [
        cascade_in_group('cash_product', 'Ürüne bağlı kasa hareketleri', 'kasa', 'UrunID', $productIds),
        cascade_in_group('product_invoice_lines', 'Ürünün fatura satırları', 'faturadetay', 'urunID', $productIds),
    ];
    if (cleanup_table_exists('depodetay')) $groups[] = cascade_in_group('product_stock_lines', 'Ürünün depo satırları', 'depodetay', 'urunID', $productIds);
    if ($includeInvoiceEffects) {
        foreach (cascade_invoice_groups($emptyInvoices) as $group) {
            if (!in_array($group['key'], ['invoice_lines'], true)) $groups[] = $group;
        }
        if ($remainingInvoices !== []) $groups[] = cascade_in_group('recalculated_invoices', 'Toplamı yeniden hesaplanacak faturalar', 'faturabaslik', 'ID', $remainingInvoices, 'update');
    }
    $groups[] = cascade_in_group('products', 'Ürünler / kitaplar', 'asp_urun', 'ID', $productIds);
    return $groups;
}

function cascade_address_sets(int $id): array
{
    $productIds = cascade_ids('SELECT `ID` FROM `asp_urun` WHERE `CinYazarID` = ?', [$id]);
    $recipientInvoices = cascade_ids('SELECT `ID` FROM `faturabaslik` WHERE CAST(`MusteriID` AS UNSIGNED) = ?', [$id]);
    $customerLineInvoices = cascade_ids('SELECT DISTINCT `faturaID` FROM `faturadetay` WHERE `musteriID` = ? AND `faturaID` > 0', [$id]);
    $productInvoices = cascade_product_invoice_ids($productIds);
    $candidateInvoices = array_values(array_unique([...$customerLineInvoices, ...$productInvoices]));
    $emptyInvoices = [];
    if ($candidateInvoices !== []) {
        $invoiceIn = cleanup_placeholders($candidateInvoices);
        $remainingCondition = '(fd.`musteriID` IS NULL OR fd.`musteriID` <> ?)';
        $params = [$id];
        if ($productIds !== []) {
            $remainingCondition .= ' AND (fd.`urunID` IS NULL OR fd.`urunID` NOT IN (' . cleanup_placeholders($productIds) . '))';
            array_push($params, ...$productIds);
        }
        $emptyInvoices = cascade_ids("SELECT fb.`ID` FROM `faturabaslik` fb WHERE fb.`ID` IN ($invoiceIn)
            AND NOT EXISTS (SELECT 1 FROM `faturadetay` fd WHERE fd.`faturaID` = fb.`ID` AND $remainingCondition)", [...$candidateInvoices, ...$params]);
    }
    $deletedInvoices = array_values(array_unique([...$recipientInvoices, ...$emptyInvoices]));
    return [
        'products'=>$productIds,
        'recipient_invoices'=>$recipientInvoices,
        'candidate_invoices'=>$candidateInvoices,
        'deleted_invoices'=>$deletedInvoices,
        'recalculated_invoices'=>array_values(array_diff($candidateInvoices, $deletedInvoices)),
    ];
}

function cascade_address_groups(int $id): array
{
    $sets = cascade_address_sets($id);
    $payments = cleanup_table_exists('odemeler')
        ? cascade_ids('SELECT `ID` FROM `odemeler` WHERE CAST(`musteriID` AS UNSIGNED) = ?', [$id])
        : [];
    $checks = cascade_ids('SELECT `ID` FROM `cek_senet_kasa` WHERE `firmaID` = ? OR `ciro_firmaID` = ?', [$id, $id]);
    $groups = cascade_product_groups($sets['products'], false);
    $groups[] = cascade_group('customer_invoice_lines', 'Cari bağlantılı fatura satırları', 'faturadetay', '`musteriID` = ?', [$id]);
    foreach (cascade_invoice_groups($sets['deleted_invoices']) as $group) $groups[] = $group;
    if ($sets['recalculated_invoices'] !== []) $groups[] = cascade_in_group('address_recalculated_invoices', 'Toplamı yeniden hesaplanacak faturalar', 'faturabaslik', 'ID', $sets['recalculated_invoices'], 'update');
    $groups[] = cascade_group('cash_customer', 'Cari kasa hareketleri', 'kasa', '`MusteriID` = ?', [$id]);
    $groups[] = cascade_group('bank_customer', 'Cari banka hareketleri', 'banka_kasa', '`musteriID` = ?', [$id]);
    if (cleanup_table_exists('odemefatura')) $groups[] = cascade_in_group('payment_links_customer', 'Cari ödemelerinin fatura bağlantıları (eski tablo)', 'odemefatura', 'odemeID', $payments);
    if (cleanup_table_exists('odemeler')) $groups[] = cascade_in_group('payments_customer', 'Cari ödemeleri (eski tablo)', 'odemeler', 'ID', $payments);
    $groups[] = cascade_in_group('cash_checks', 'Cari çek/senet kasa hareketleri', 'kasa', 'ceksenetID', $checks);
    $groups[] = cascade_group('bank_checks', 'Cari çek/senet banka hareketleri', 'banka_kasa', '`cekID` IN (' . ($checks === [] ? 'NULL' : cleanup_placeholders($checks)) . ')', $checks);
    $groups[] = cascade_in_group('checks_customer', 'Cari çek / senet kayıtları', 'cek_senet_kasa', 'ID', $checks);
    $groups[] = cascade_group('corrections_customer', 'Cari düzeltme kayıtları', 'cari_duzeltme_kayitlari', '`musteriID` = ?', [$id]);
    if (cleanup_table_exists('adres_defter_agac_iliskileri')) $groups[] = cascade_group('tree_customer', 'Cari-ağaç bağlantıları', 'adres_defter_agac_iliskileri', '`musteriID` = ?', [$id]);
    if (cleanup_table_exists('depobaslik')) $groups[] = cascade_group('stock_customer_headers', 'Cari depo başlıkları', 'depobaslik', 'CAST(`MusteriID` AS UNSIGNED) = ?', [$id]);
    if (cleanup_table_exists('depodetay')) $groups[] = cascade_group('stock_customer_lines', 'Cari depo satırları', 'depodetay', 'CAST(`musteriID` AS UNSIGNED) = ?', [$id]);
    $groups[] = cascade_group('address', 'Cari kart', 'adresdefteri', '`ID` = ?', [$id]);
    return $groups;
}

function cascade_delete_preview(string $table, int $id): array
{
    $record = cascade_assert_target($table, $id);
    $groups = match ($table) {
        'faturabaslik' => cascade_invoice_groups([$id]),
        'asp_urun' => cascade_product_groups([$id]),
        'adresdefteri' => cascade_address_groups($id),
    };
    $unique = [];
    foreach ($groups as $group) {
        $identity = $group['table'] . ':' . $group['key'];
        if ($group['count'] > 0 && !isset($unique[$identity])) $unique[$identity] = $group;
    }
    return [
        'table'=>$table,
        'id'=>$id,
        'title'=>cascade_record_title($table, $record),
        'groups'=>array_values($unique),
        'delete_group_count'=>count(array_filter($unique, fn (array $group): bool => $group['action'] === 'delete')),
        'warning'=>'Bu işlem geri alınamaz. İlişkili kayıtlar kalıcı olarak silinecektir.',
    ];
}

function cascade_delete_invoice_ids(array $invoiceIds): array
{
    $invoiceIds = cascade_normalize_ids($invoiceIds);
    if ($invoiceIds === []) return [];
    $payments = cascade_invoice_payment_ids($invoiceIds);
    $result = [];
    $result['invoice_lines'] = cleanup_delete_in('faturadetay', 'faturaID', $invoiceIds);
    $result['invoice_prints'] = cleanup_table_exists('faturabaski') ? cleanup_delete_in('faturabaski', 'faturaID', $invoiceIds) : 0;
    $result['cash_invoice'] = cleanup_delete_in('kasa', 'FaturaID', $invoiceIds) + cleanup_delete_in('kasa', 'ID', $payments['cash']);
    $result['bank_invoice'] = cleanup_delete_in('banka_kasa', 'faturaID', $invoiceIds) + cleanup_delete_in('banka_kasa', 'ID', $payments['bank']);
    if (cleanup_table_exists('odemefatura')) {
        $in = cleanup_placeholders($invoiceIds);
        $statement = db()->prepare("DELETE FROM `odemefatura` WHERE CAST(`faturaID` AS UNSIGNED) IN ($in)");
        $statement->execute($invoiceIds);
        $result['payment_links_invoice'] = $statement->rowCount();
    }
    if (cleanup_table_exists('depodetay') && cleanup_column_exists('depodetay', 'faturaID')) {
        $in = cleanup_placeholders($invoiceIds);
        $statement = db()->prepare("DELETE FROM `depodetay` WHERE CAST(`faturaID` AS UNSIGNED) IN ($in)");
        $statement->execute($invoiceIds);
        $result['stock_invoice_lines'] = $statement->rowCount();
    }
    $result['invoice_headers'] = cleanup_delete_in('faturabaslik', 'ID', $invoiceIds);
    return $result;
}

function cascade_merge_counts(array &$target, array $source): void
{
    foreach ($source as $key => $count) $target[$key] = ($target[$key] ?? 0) + (int) $count;
}

function cascade_delete_product_ids(array $productIds): array
{
    $productIds = cascade_normalize_ids($productIds);
    if ($productIds === []) return [];
    $invoiceIds = cascade_product_invoice_ids($productIds);
    $result = [];
    $result['cash_product'] = cleanup_delete_in('kasa', 'UrunID', $productIds);
    $result['product_invoice_lines'] = cleanup_delete_in('faturadetay', 'urunID', $productIds);
    if (cleanup_table_exists('depodetay')) $result['product_stock_lines'] = cleanup_delete_in('depodetay', 'urunID', $productIds);
    $emptyInvoices = cleanup_empty_headers('faturabaslik', 'faturadetay', $invoiceIds);
    cascade_merge_counts($result, cascade_delete_invoice_ids($emptyInvoices));
    $result['recalculated_invoices'] = cleanup_recalculate_invoice_headers(array_values(array_diff($invoiceIds, $emptyInvoices)));
    $result['products'] = cleanup_delete_in('asp_urun', 'ID', $productIds);
    return $result;
}

function cascade_execute_delete(string $table, int $id): array
{
    cascade_assert_target($table, $id);
    $preview = cascade_delete_preview($table, $id);
    $result = [];
    if ($table === 'faturabaslik') {
        $result = cascade_delete_invoice_ids([$id]);
    } elseif ($table === 'asp_urun') {
        $result = cascade_delete_product_ids([$id]);
    } else {
        $sets = cascade_address_sets($id);
        $payments = cleanup_table_exists('odemeler')
            ? cascade_ids('SELECT `ID` FROM `odemeler` WHERE CAST(`musteriID` AS UNSIGNED) = ?', [$id])
            : [];
        $checks = cascade_ids('SELECT `ID` FROM `cek_senet_kasa` WHERE `firmaID` = ? OR `ciro_firmaID` = ?', [$id, $id]);
        if (cleanup_table_exists('odemefatura')) $result['payment_links_customer'] = cleanup_delete_in('odemefatura', 'odemeID', $payments);
        if (cleanup_table_exists('odemeler')) $result['payments_customer'] = cleanup_delete_in('odemeler', 'ID', $payments);
        $result['cash_checks'] = cleanup_delete_in('kasa', 'ceksenetID', $checks);
        $result['bank_checks'] = cleanup_delete_in('banka_kasa', 'cekID', $checks);
        $result['checks_customer'] = cleanup_delete_in('cek_senet_kasa', 'ID', $checks);
        $result['cash_customer'] = cleanup_delete_in('kasa', 'MusteriID', [$id]);
        $result['bank_customer'] = cleanup_delete_in('banka_kasa', 'musteriID', [$id]);
        cascade_merge_counts($result, cascade_delete_product_ids($sets['products']));
        cascade_merge_counts($result, cascade_delete_invoice_ids($sets['recipient_invoices']));
        $remainingCandidateInvoices = cascade_ids('SELECT DISTINCT `faturaID` FROM `faturadetay` WHERE `musteriID` = ? AND `faturaID` > 0', [$id]);
        $result['customer_invoice_lines'] = cleanup_delete_in('faturadetay', 'musteriID', [$id]);
        $emptyInvoices = cleanup_empty_headers('faturabaslik', 'faturadetay', $remainingCandidateInvoices);
        cascade_merge_counts($result, cascade_delete_invoice_ids($emptyInvoices));
        $result['address_recalculated_invoices'] = cleanup_recalculate_invoice_headers(array_values(array_diff($remainingCandidateInvoices, $emptyInvoices)));
        $result['corrections_customer'] = cleanup_delete_in('cari_duzeltme_kayitlari', 'musteriID', [$id]);
        if (cleanup_table_exists('adres_defter_agac_iliskileri')) $result['tree_customer'] = cleanup_delete_in('adres_defter_agac_iliskileri', 'musteriID', [$id]);
        if (cleanup_table_exists('depodetay')) $result['stock_customer_lines'] = cleanup_delete_in('depodetay', 'musteriID', [$id]);
        if (cleanup_table_exists('depobaslik')) $result['stock_customer_headers'] = cleanup_delete_in('depobaslik', 'MusteriID', [$id]);
        $result['address'] = cleanup_delete_in('adresdefteri', 'ID', [$id]);
    }
    audit_log('cascade_delete', $table, $id, ['preview_delete_groups'=>$preview['delete_group_count'], 'counts'=>$result]);
    return ['deleted'=>true, 'table'=>$table, 'id'=>$id, 'counts'=>$result, 'total'=>array_sum($result)];
}
