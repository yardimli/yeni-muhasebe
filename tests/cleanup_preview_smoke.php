<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/cleanup_service.php';

function cleanup_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$products = cleanup_product_page(false, 1, 100);
cleanup_assert(count($products['rows']) <= 100, 'Product preview exceeded its page limit.');
foreach ($products['rows'] as $row) cleanup_assert((int) $row['CinYazarID'] === 0, 'Delete preview included a linked author product.');

$neverInvoiced = cleanup_product_page(false, 1, 100, 'never_invoiced');
cleanup_assert(cleanup_product_candidate_count('never_invoiced') === $neverInvoiced['total'], 'Automatic cleanup count differs from its candidate query.');
$automaticBatch = cleanup_product_candidate_ids('never_invoiced', 5000);
cleanup_assert(count($automaticBatch) <= 5000, 'Automatic cleanup batch exceeded 5,000 products.');
cleanup_assert(count($neverInvoiced['rows']) <= 100, 'Never-invoiced preview exceeded its page limit.');
foreach ($neverInvoiced['rows'] as $row) {
    cleanup_assert((int) $row['CinYazarID'] === 0, 'Never-invoiced preview included a linked-author product.');
    cleanup_assert((int) $row['invoice_lines'] === 0, 'Never-invoiced preview included an invoiced product.');
}
$neverInvoicedIds = array_map(fn (array $row): int => (int) $row['ID'], $neverInvoiced['rows']);
$revalidatedNeverInvoicedIds = cleanup_select_valid_products($neverInvoicedIds, 'never_invoiced');
sort($neverInvoicedIds);
sort($revalidatedNeverInvoicedIds);
cleanup_assert(
    $revalidatedNeverInvoicedIds === $neverInvoicedIds,
    'Never-invoiced candidates failed deletion-time revalidation.'
);
cleanup_assert(cleanup_product_rule('invalid') === 'non_cinius', 'Invalid product cleanup rule was not rejected.');
$ciniusProducts = db()->query("SELECT u.`ID` FROM `asp_urun` u WHERE NOT (" . cleanup_product_condition('u') . ")")->fetchAll(PDO::FETCH_COLUMN);
cleanup_assert($ciniusProducts !== [], 'No CINIUS-related products were recognized by the protection predicate.');
foreach (array_chunk(array_map('intval', $ciniusProducts), 5000) as $ciniusChunk) {
    cleanup_assert(cleanup_select_valid_products($ciniusChunk, 'non_cinius') === [], 'A CINIUS-related product passed non-Cinius deletion revalidation.');
    cleanup_assert(cleanup_select_valid_products($ciniusChunk, 'never_invoiced') === [], 'A CINIUS-related product passed never-invoiced deletion revalidation.');
}
$producerStatus = cleanup_orphan_producer_status();
cleanup_assert(isset($producerStatus['exists'], $producerStatus['count'], $producerStatus['reference_columns']), 'Orphan producer preview status is incomplete.');
$orphanProducerPage = cleanup_orphan_producer_page(1, 100);
cleanup_assert($orphanProducerPage['total'] === $producerStatus['count'], 'Orphan producer page count differs from its status count.');
cleanup_assert(count($orphanProducerPage['rows']) <= 100, 'Orphan producer preview exceeded its page limit.');
foreach ($producerStatus['reference_columns'] as $reference) {
    cleanup_assert(preg_match('/^[A-Za-z0-9_$]+$/', $reference['TABLE_NAME']) === 1, 'Unsafe producer reference table name.');
    cleanup_assert(preg_match('/^[A-Za-z0-9_$]+$/', $reference['COLUMN_NAME']) === 1, 'Unsafe producer reference column name.');
}
$knownProducerReferences = array_map(static fn (array $reference): string => $reference['TABLE_NAME'] . '.' . $reference['COLUMN_NAME'], $producerStatus['reference_columns']);
cleanup_assert(in_array('asp_urun.ureticiID', $knownProducerReferences, true), 'asp_urun.ureticiID is missing from orphan producer reference checks.');
$socialProducts = db()->query("SELECT u.`ID` FROM `asp_urun` u WHERE NOT (" . cleanup_social_publisher_exclusion('u') . ')')->fetchAll(PDO::FETCH_COLUMN);
cleanup_assert($socialProducts !== [], 'No Sosyal publisher products were recognized by the protection predicate.');
foreach (array_chunk(array_map('intval', $socialProducts), 5000) as $socialChunk) {
    cleanup_assert(cleanup_select_valid_products($socialChunk, 'non_cinius') === [], 'A Sosyal publisher product passed non-Cinius deletion revalidation.');
    cleanup_assert(cleanup_select_valid_products($socialChunk, 'never_invoiced') === [], 'A Sosyal publisher product passed never-invoiced deletion revalidation.');
}

$linked = cleanup_product_page(true, 1, 100);
cleanup_assert(count($linked['rows']) <= 100, 'Linked-author preview exceeded its page limit.');
foreach ($linked['rows'] as $row) cleanup_assert((int) $row['CinYazarID'] > 0, 'Linked-author preview included a zero author ID.');

$addresses = cleanup_address_page(1, 100);
cleanup_assert(count($addresses['rows']) <= 100, 'Address preview exceeded its page limit.');
if ($addresses['rows'] !== []) {
    $addressSearch = cleanup_address_page(1, 100, (string) $addresses['rows'][0]['ID']);
    cleanup_assert($addressSearch['total'] >= 1, 'Address cleanup search could not find a candidate by ID.');
}
$protectedAddressId = (int) db()->query("SELECT a.`ID` FROM `adresdefteri` a
    JOIN `kasa` k ON k.`MusteriID` = a.`ID` WHERE a.`ID` > 0 ORDER BY a.`ID` DESC LIMIT 1")->fetchColumn();
if ($protectedAddressId > 0) {
    $referenceCount = cleanup_address_financial_references([$protectedAddressId])[$protectedAddressId] ?? 0;
    $referenceDetails = cleanup_address_financial_reference_details($protectedAddressId);
    cleanup_assert($referenceDetails['total'] === $referenceCount, 'Financial detail groups do not match the protection count.');
    cleanup_assert($referenceDetails['groups'] !== [], 'Protected address has no financial detail groups.');
    foreach ($referenceDetails['groups'] as $group) {
        cleanup_assert($group['count'] > 0 && $group['rows'] !== [], 'Financial detail group is empty.');
        cleanup_assert(count($group['rows']) <= 1000, 'Financial detail group exceeded its display limit.');
    }
}

$duplicates = cleanup_duplicate_address_page(1, 100);
cleanup_assert(count($duplicates['rows']) <= 100, 'Duplicate-address preview exceeded its page limit.');
foreach ($duplicates['rows'] as $row) {
    cleanup_assert(($row['matches'] ?? []) !== [], 'Duplicate candidate has no protected matching record.');
    $candidateLinks = cleanup_address_link_details([(int) $row['ID']]);
    cleanup_assert(($candidateLinks[(int) $row['ID']]['total_links'] ?? -1) === 0, 'Selectable duplicate has one or more links.');
    foreach ($row['matches'] as $match) {
        cleanup_assert((int) $match['total_links'] > 0, 'Protected duplicate counterpart has no links.');
        cleanup_assert((bool) $match['email_match'] || (bool) $match['name_match'], 'Duplicate pair has no email or name match.');
    }
}
$gurselRows = db()->query('SELECT `ID` FROM `adresdefteri` WHERE `ID` IN (5316,5546)')->fetchAll(PDO::FETCH_COLUMN);
if (count($gurselRows) === 2) {
    $allDuplicates = cleanup_duplicate_address_page(1, PHP_INT_MAX);
    $gurselCandidate = null;
    foreach ($allDuplicates['rows'] as $row) if ((int) $row['ID'] === 5546) $gurselCandidate = $row;
    cleanup_assert($gurselCandidate !== null, 'Tree-only duplicate #5546 was incorrectly protected.');
    cleanup_assert(in_array(5316, array_map(fn (array $match): int => (int) $match['ID'], $gurselCandidate['matches']), true), 'Duplicate #5546 is not paired with linked record #5316.');
}

$limitRejected = false;
try {
    cleanup_ids(range(1, 5001));
} catch (InvalidArgumentException) {
    $limitRejected = true;
}
cleanup_assert($limitRejected, 'The 5,000-selection execution limit was not enforced.');
cleanup_assert(count(cleanup_ids(range(1, 5000))) === 5000, 'A 5,000-row selection was rejected.');
cleanup_assert(count(cleanup_serialized_ids(implode(',', range(1, 5000)))) === 5000, 'A serialized 5,000-row selection was truncated.');
cleanup_assert(cleanup_page_size(5000) === 5000, 'The 5,000-row preview size is unavailable.');
cleanup_assert(count(cleanup_legacy_status()) === count(cleanup_legacy_manifest()), 'Legacy field audit is incomplete.');
$legacy = cleanup_legacy_manifest();
cleanup_assert(in_array('Bakiye', $legacy['adresdefteri.unused_fields']['columns'] ?? [], true), 'Cari Bakiye cleanup is missing.');
cleanup_assert(($legacy['cari_agac.tables']['tables'] ?? []) === ['adres_defter_agac_iliskileri','adres_agac'], 'Cari tree table cleanup is incomplete or ordered incorrectly.');
cleanup_assert(($legacy['currency.tables_and_references']['columns'] ?? []) === ['asp_urun.kurID','banka_kasa.kurID','hesaplar.kurID','kasa.KurID'], 'Currency reference cleanup is incomplete.');
cleanup_assert(($legacy['currency.tables_and_references']['tables'] ?? []) === ['kurhareketleri','kurlar'], 'Currency table cleanup is incomplete or ordered incorrectly.');
cleanup_assert(($legacy['payments.tables']['tables'] ?? []) === ['odemefatura','odemeler'], 'Payment table cleanup is incomplete or ordered incorrectly.');
cleanup_assert(($legacy['obsolete.tables']['tables'] ?? []) === ['adresdefteri2','faturabaski','gelir','gider','nakitakis','stoktablo'], 'Obsolete table cleanup is incomplete or ordered incorrectly.');
$cleanupUi = file_get_contents(APP_ROOT . '/public/cleanup.php');
cleanup_assert(str_contains($cleanupUi, "'structure_cleanup'=>'4. Tablo / alan temizliği'"), 'Combined structure cleanup tab is missing.');
cleanup_assert(str_contains($cleanupUi, "'currency'=>'Döviz yapısı'"), 'Currency cleanup subsection is missing.');
cleanup_assert(str_contains($cleanupUi, 'name="action" value="cleanup_currency"'), 'Currency cleanup action is missing.');
cleanup_assert(str_contains($cleanupUi, "cleanup_drop_legacy_fields(['currency.tables_and_references'])"), 'Currency cleanup action is not restricted to the currency manifest entry.');
cleanup_assert(str_contains($cleanupUi, "'payments'=>'Ödeme tabloları'"), 'Payment cleanup subsection is missing.');
cleanup_assert(str_contains($cleanupUi, 'name="action" value="cleanup_payments"'), 'Payment cleanup action is missing.');
cleanup_assert(str_contains($cleanupUi, "cleanup_drop_legacy_fields(['payments.tables'])"), 'Payment cleanup action is not restricted to the payment manifest entry.');
cleanup_assert(str_contains($cleanupUi, "'obsolete'=>'Eski tablolar'"), 'Obsolete-table cleanup subsection is missing.');
cleanup_assert(str_contains($cleanupUi, 'name="action" value="cleanup_obsolete_tables"'), 'Obsolete-table cleanup action is missing.');
cleanup_assert(str_contains($cleanupUi, 'name="action" value="delete_orphan_producers"'), 'Orphan producer cleanup button is missing.');
cleanup_assert(str_contains($cleanupUi, "'product_view'=>'orphan_producers'"), 'Orphan producers are not a separate product cleanup view.');
cleanup_assert(str_contains($cleanupUi, 'name="selected_ids" data-selected-ids'), 'Orphan producer cleanup does not serialize selected rows.');
cleanup_assert(str_contains($cleanupUi, 'CİNİUS / korunan üreticilerle ilişkili olmadığı yeniden doğrulanır'), 'Never-invoiced cleanup does not explain its CINIUS deletion safeguard.');
cleanup_assert(str_contains($cleanupUi, 'name="action" value="delete_all_never_invoiced_products"'), 'Never-invoiced bulk cleanup button is missing.');
cleanup_assert(str_contains($cleanupUi, "cleanup_drop_legacy_fields(['obsolete.tables'])"), 'Obsolete-table cleanup action is not restricted to its manifest entry.');
cleanup_assert(str_contains($cleanupUi, "'engine'=>'5. InnoDB dönüşümü'"), 'Dedicated engine-conversion tab is missing.');
cleanup_assert(str_contains($cleanupUi, 'name="action" value="convert_myisam_to_innodb"'), 'InnoDB conversion action is missing.');
$cleanupServiceSource = file_get_contents(APP_ROOT . '/app/cleanup_service.php');
cleanup_assert(str_contains($cleanupServiceSource, 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC'), 'InnoDB conversion does not override incompatible MyISAM row formats.');
$engineStatus = cleanup_myisam_status();
cleanup_assert(isset($engineStatus['tables'], $engineStatus['count'], $engineStatus['estimated_rows'], $engineStatus['total_bytes']), 'MyISAM preview status is incomplete.');
cleanup_assert($engineStatus['count'] === count($engineStatus['tables']), 'MyISAM table count does not match its preview rows.');
foreach ($engineStatus['tables'] as $engineTable) cleanup_assert(preg_match('/^[A-Za-z0-9_]+$/', $engineTable['TABLE_NAME']) === 1, 'Unsafe table name in MyISAM preview.');
cleanup_assert(($legacy['asp_urun.relational_text_fields']['columns'] ?? []) === ['yazarlistetxt', 'yayinevitxt'], 'Product relational text cleanup is missing.');
cleanup_assert(($legacy['asp_urun.relational_text_fields']['equivalent_ids'] ?? []) === ['CinYazarID', 'ureticiID'], 'Product relational ID safeguards are missing.');
$productFieldStatus = cleanup_product_fields_status();
cleanup_assert($productFieldStatus['configured_columns'] === cleanup_product_archive_columns(), 'Product field cleanup manifest is inconsistent.');
cleanup_assert($productFieldStatus['description_available'], 'Product descriptions are unavailable for field archiving.');
cleanup_assert(count($productFieldStatus['field_counts']) === count(cleanup_product_archive_columns()), 'Product field cleanup counts are incomplete.');

echo "Cleanup preview smoke test passed: {$products['total']} non-Cinius products, {$neverInvoiced['total']} never-invoiced products, {$linked['total']} linked-author reviews, {$addresses['total']} address candidates, {$duplicates['total']} unlinked duplicates.\n";
