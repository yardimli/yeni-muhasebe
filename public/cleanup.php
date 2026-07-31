<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/cleanup_service.php';

$user = current_user();
if ($user === null) {
    header('Location: ./');
    exit;
}
if (($user['role'] ?? '') !== 'administrator') {
    http_response_code(403);
    exit('Bu sayfa yalnızca yöneticiler içindir.');
}

set_time_limit(0);
$mode = ($_GET['mode'] ?? 'preview') === 'apply' ? 'apply' : 'preview';
$requestedSection = (string) ($_GET['section'] ?? 'products');
$cleanupAreas = ['legacy', 'product_fields', 'currency', 'payments', 'obsolete'];
$cleanupArea = in_array((string) ($_GET['cleanup_area'] ?? 'legacy'), $cleanupAreas, true) ? (string) $_GET['cleanup_area'] : 'legacy';
if (in_array($requestedSection, $cleanupAreas, true)) {
    $section = 'structure_cleanup';
    $cleanupArea = $requestedSection;
} else {
    $section = in_array($requestedSection, ['products', 'linked', 'addresses', 'structure_cleanup', 'engine'], true) ? $requestedSection : 'products';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = cleanup_page_size((int) ($_GET['per_page'] ?? 250));
$query = trim((string) ($_GET['q'] ?? ''));
$productRule = cleanup_product_rule((string) ($_GET['product_rule'] ?? 'non_cinius'));
$productView = ($_GET['product_view'] ?? 'products') === 'orphan_producers' ? 'orphan_producers' : 'products';
$addressRule = ($_GET['address_rule'] ?? 'unlinked') === 'duplicates' ? 'duplicates' : 'unlinked';
$message = '';
$error = '';
$operationResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'financial_reference_details') {
    try {
        json_response(cleanup_address_financial_reference_details(max(0, (int) ($_GET['id'] ?? 0))));
    } catch (Throwable $exception) {
        json_response(['error'=>$exception->getMessage()], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['_token'] ?? null);
        if ($mode !== 'apply') throw new RuntimeException('Silme işlemleri yalnızca uygulama modunda çalışır.');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'delete_products') {
            $operationResult = cleanup_delete_products(
                cleanup_serialized_ids((string) ($_POST['selected_ids'] ?? '')),
                (string) ($_POST['product_rule'] ?? 'non_cinius')
            );
            $message = 'Seçilen ürünler ve doğrulanmış ilişkili kayıtlar temizlendi.';
        } elseif ($action === 'delete_all_never_invoiced_products') {
            $operationResult = cleanup_delete_all_never_invoiced_products();
            $message = 'Faturada hiç kullanılmamış ve yazara bağlı olmayan tüm ürünler temizlendi.';
        } elseif ($action === 'delete_orphan_producers') {
            $operationResult = cleanup_delete_orphan_producers(cleanup_serialized_ids((string) ($_POST['selected_ids'] ?? '')));
            $message = 'Seçilen ve halen bağlantısız olan üreticiler temizlendi.';
        } elseif ($action === 'delete_addresses') {
            $operationResult = cleanup_delete_addresses(cleanup_serialized_ids((string) ($_POST['selected_ids'] ?? '')));
            $message = 'Korunmayan seçili cari/adres kayıtları temizlendi.';
        } elseif ($action === 'delete_duplicate_addresses') {
            $operationResult = cleanup_delete_duplicate_addresses(cleanup_serialized_ids((string) ($_POST['selected_ids'] ?? '')));
            $message = 'Bağlantısı olmayan seçili mükerrer cari/adres kayıtları temizlendi.';
        } elseif ($action === 'drop_legacy') {
            $operationResult = cleanup_drop_legacy_fields($_POST['fields'] ?? []);
            $message = 'Uygun legacy alan işlemleri tamamlandı.';
        } elseif ($action === 'cleanup_product_fields') {
            $operationResult = cleanup_archive_and_drop_product_fields();
            $message = 'Ürün alanlarındaki veriler açıklamaya aktarıldı ve eski sütunlar kaldırıldı.';
        } elseif ($action === 'cleanup_currency') {
            $operationResult = cleanup_drop_legacy_fields(['currency.tables_and_references']);
            $message = 'Eski döviz tabloları ve kur referans alanları kaldırıldı.';
        } elseif ($action === 'cleanup_payments') {
            $operationResult = cleanup_drop_legacy_fields(['payments.tables']);
            $message = 'Eski ödeme ve ödeme-fatura dağılımı tabloları kaldırıldı.';
        } elseif ($action === 'cleanup_obsolete_tables') {
            $operationResult = cleanup_drop_legacy_fields(['obsolete.tables']);
            $message = 'Seçilen eski yardımcı tablolar kaldırıldı.';
        } elseif ($action === 'convert_myisam_to_innodb') {
            $operationResult = cleanup_convert_myisam_to_innodb();
            $message = 'MyISAM → InnoDB dönüştürme işlemi tamamlandı.';
        } else {
            throw new InvalidArgumentException('Bilinmeyen temizleme işlemi.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$data = null;
$legacyStatus = null;
$productFieldsStatus = null;
$currencyStatus = null;
$paymentsStatus = null;
$obsoleteStatus = null;
$engineStatus = null;
$orphanProducerStatus = null;
try {
    if ($section === 'products') {
        $orphanProducerStatus = cleanup_orphan_producer_status();
        $data = $productView === 'orphan_producers'
            ? cleanup_orphan_producer_page($page, $perPage)
            : cleanup_product_page(false, $page, $perPage, $productRule);
    }
    if ($section === 'linked') $data = cleanup_product_page(true, $page, $perPage);
    if ($section === 'addresses') $data = $addressRule === 'duplicates'
        ? cleanup_duplicate_address_page($page, $perPage, $query)
        : cleanup_address_page($page, $perPage, $query);
    if ($section === 'structure_cleanup' && $cleanupArea === 'legacy') $legacyStatus = cleanup_legacy_status();
    if ($section === 'structure_cleanup' && $cleanupArea === 'product_fields') $productFieldsStatus = cleanup_product_fields_status();
    if ($section === 'structure_cleanup' && $cleanupArea === 'currency') $currencyStatus = cleanup_legacy_status()['currency.tables_and_references'];
    if ($section === 'structure_cleanup' && $cleanupArea === 'payments') $paymentsStatus = cleanup_legacy_status()['payments.tables'];
    if ($section === 'structure_cleanup' && $cleanupArea === 'obsolete') $obsoleteStatus = cleanup_legacy_status()['obsolete.tables'];
    if ($section === 'engine') $engineStatus = cleanup_myisam_status();
} catch (Throwable $exception) {
    $error = $error !== '' ? $error : $exception->getMessage();
}

function cleanup_url(array $changes = []): string
{
    $params = array_merge([
        'mode' => $GLOBALS['mode'],
        'section' => $GLOBALS['section'],
        'page' => $GLOBALS['page'],
        'per_page' => $GLOBALS['perPage'],
        'q' => $GLOBALS['query'],
        'product_rule' => $GLOBALS['productRule'],
        'product_view' => $GLOBALS['productView'],
        'address_rule' => $GLOBALS['addressRule'],
        'cleanup_area' => $GLOBALS['cleanupArea'],
    ], $changes);
    return 'cleanup.php?' . http_build_query($params);
}

function cleanup_pagination(?array $data): string
{
    if ($data === null || $data['pages'] <= 1) return '';
    $page = (int) $data['page'];
    $pages = (int) $data['pages'];
    $links = [];
    if ($page > 1) $links[] = '<a class="rounded border border-slate-300 bg-white px-3 py-1.5 hover:bg-slate-50" href="' . h(cleanup_url(['page' => $page - 1])) . '">← Önceki</a>';
    $links[] = '<span class="px-2 text-slate-600">' . number_format($page, 0, ',', '.') . ' / ' . number_format($pages, 0, ',', '.') . '</span>';
    if ($page < $pages) $links[] = '<a class="rounded border border-slate-300 bg-white px-3 py-1.5 hover:bg-slate-50" href="' . h(cleanup_url(['page' => $page + 1])) . '">Sonraki →</a>';
    return '<nav class="flex items-center justify-end gap-2">' . implode('', $links) . '</nav>';
}

function cleanup_result_label(string $key): string
{
    return [
        'validated_products' => 'Doğrulanan ürün', 'products' => 'Silinen ürün', 'invoice_lines' => 'Silinen fatura satırı',
        'stock_lines' => 'Silinen depo satırı', 'cash_product_rows' => 'Silinen ürün kasa hareketi',
        'invoice_headers' => 'Silinen boş fatura', 'stock_headers' => 'Silinen boş depo belgesi',
        'invoice_print_rows' => 'Silinen baskı geçmişi', 'bank_invoice_rows' => 'Silinen banka hareketi',
        'cash_invoice_rows' => 'Silinen fatura kasa hareketi', 'payment_links' => 'Silinen ödeme-fatura bağlantısı',
        'recalculated_invoice_headers' => 'Yeniden hesaplanan fatura', 'recalculated_stock_headers' => 'Yeniden hesaplanan depo belgesi',
        'tree_links' => 'Silinen ağaç bağlantısı', 'corrections' => 'Silinen cari düzeltmesi',
        'addresses' => 'Silinen cari/adres', 'protected' => 'Korunduğu için atlanan',
        'batches' => 'Tamamlanan toplu işlem',
        'orphan_producers' => 'Silinen bağlantısız üretici',
        'selected_orphan_producers' => 'Seçilen bağlantısız üretici',
        'protected_producers' => 'Bağlantı oluştuğu için korunan üretici',
        'duplicate_candidates_skipped' => 'Koşulu değiştiği için atlanan mükerrer',
        'archived_products' => 'Açıklamaya veri aktarılan ürün', 'removed_fields' => 'Kaldırılan ürün alanı',
        'already_missing' => 'Önceden kaldırılmış alan',
        'detected_myisam_tables' => 'Bulunan MyISAM tablo', 'converted_tables' => 'InnoDB yapılan tablo',
        'failed_tables' => 'Dönüştürülemeyen tablolar', 'remaining_myisam_tables' => 'Kalan MyISAM tablo',
    ][$key] ?? $key;
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Veri Temizleme — Cinius Muhasebe</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
<header class="border-b border-slate-700 bg-slate-900 text-white">
    <div class="mx-auto flex max-w-screen-2xl items-center gap-4 px-5 py-3">
        <a href="./" class="rounded border border-slate-600 px-3 py-1.5 text-sm hover:bg-slate-700">← Masaüstü</a>
        <div><h1 class="font-semibold">Veri Temizleme</h1><p class="text-xs text-slate-300">Ürün, işlem, cari ve legacy alan bakımı</p></div>
        <div class="ml-auto text-xs text-slate-300"><?= h($user['username']) ?></div>
    </div>
</header>

<main class="mx-auto max-w-screen-2xl space-y-4 p-5">
    <section class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900">
        <strong>MyISAM uyarısı:</strong> silme ve ALTER TABLE işlemleri geri alınamaz. Uygulama modundan önce doğrulanmış tam veritabanı yedeği alın ve eski Delphi uygulamasını kapatın.
    </section>

    <?php if ($message !== ''): ?><div class="rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800"><strong><?= h($message) ?></strong></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800"><strong>İşlem durduruldu:</strong> <?= h($error) ?></div><?php endif; ?>
    <?php if (is_array($operationResult)): ?>
        <section class="rounded-lg border border-slate-300 bg-white p-4">
            <h2 class="mb-3 font-semibold">Son işlem sonucu</h2>
            <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
                <?php foreach ($operationResult as $key => $value): ?>
                    <div class="rounded border border-slate-200 bg-slate-50 p-2 text-sm"><span class="block text-xs text-slate-500"><?= h(cleanup_result_label((string) $key)) ?></span><strong><?= is_array($value) ? h(implode(', ', $value) ?: '—') : h(number_format((int) $value, 0, ',', '.')) ?></strong></div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="flex flex-wrap items-center gap-2">
        <a class="rounded px-4 py-2 text-sm font-semibold <?= $mode === 'preview' ? 'bg-blue-700 text-white' : 'border border-slate-300 bg-white' ?>" href="<?= h(cleanup_url(['mode' => 'preview', 'page' => 1])) ?>">Önizleme — salt okunur</a>
        <a class="rounded px-4 py-2 text-sm font-semibold <?= $mode === 'apply' ? 'bg-red-700 text-white' : 'border border-red-300 bg-white text-red-700' ?>" href="<?= h(cleanup_url(['mode' => 'apply', 'page' => 1])) ?>">Uygulama — silme açık</a>
        <form class="ml-auto flex items-center gap-2" method="get">
            <input type="hidden" name="mode" value="<?= h($mode) ?>"><input type="hidden" name="section" value="<?= h($section) ?>"><input type="hidden" name="q" value="<?= h($query) ?>"><input type="hidden" name="product_rule" value="<?= h($productRule) ?>"><input type="hidden" name="product_view" value="<?= h($productView) ?>"><input type="hidden" name="address_rule" value="<?= h($addressRule) ?>"><input type="hidden" name="cleanup_area" value="<?= h($cleanupArea) ?>">
            <label class="text-sm text-slate-600">Sayfa başına
                <select name="per_page" class="rounded border border-slate-300 bg-white px-2 py-1.5" onchange="this.form.submit()">
                    <?php foreach ([100,250,500,1000,5000] as $size): ?><option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
                </select>
            </label>
        </form>
    </div>

    <nav class="flex flex-wrap gap-1 border-b border-slate-300">
        <?php foreach (['products'=>'1. Silinebilir ürünler','linked'=>'2. Yazara bağlı ayrı liste','addresses'=>'3. Cari/adres temizliği','structure_cleanup'=>'4. Tablo / alan temizliği','engine'=>'5. InnoDB dönüşümü'] as $key=>$label): ?>
            <a href="<?= h(cleanup_url(['section'=>$key,'page'=>1])) ?>" class="rounded-t border border-b-0 px-4 py-2 text-sm <?= $section === $key ? 'border-slate-300 bg-white font-semibold text-blue-800' : 'border-transparent text-slate-600 hover:bg-slate-200' ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($section === 'products' && $data !== null): ?>
        <section class="rounded-b-lg rounded-tr-lg border border-slate-300 bg-white">
            <div class="border-b border-slate-200 p-4">
                <div class="mb-3 flex flex-wrap gap-2">
                    <a href="<?= h(cleanup_url(['product_view'=>'products','product_rule'=>'never_invoiced','page'=>1])) ?>" class="rounded border px-3 py-2 text-sm <?= $productView === 'products' && $productRule === 'never_invoiced' ? 'border-blue-700 bg-blue-700 font-semibold text-white' : 'border-slate-300 bg-white hover:bg-slate-50' ?>">1A. Hiçbir faturada bulunmayanlar</a>
                    <a href="<?= h(cleanup_url(['product_view'=>'products','product_rule'=>'non_cinius','page'=>1])) ?>" class="rounded border px-3 py-2 text-sm <?= $productView === 'products' && $productRule === 'non_cinius' ? 'border-blue-700 bg-blue-700 font-semibold text-white' : 'border-slate-300 bg-white hover:bg-slate-50' ?>">1B. CİNİUS olmayanlar</a>
                    <a href="<?= h(cleanup_url(['product_view'=>'orphan_producers','page'=>1])) ?>" class="rounded border px-3 py-2 text-sm <?= $productView === 'orphan_producers' ? 'border-blue-700 bg-blue-700 font-semibold text-white' : 'border-slate-300 bg-white hover:bg-slate-50' ?>">1C. Bağlantısız üreticiler (<?= number_format((int)($orphanProducerStatus['count'] ?? 0),0,',','.') ?>)</a>
                </div>
                <?php if ($productView === 'orphan_producers'): ?>
                    <div class="flex flex-wrap items-center gap-4"><div><h2 class="font-semibold">Bağlantısız üreticiler</h2><p class="text-sm text-slate-500">Toplam <?= number_format($data['total'],0,',','.') ?> kayıt. Tüm <code>ureticiID</code> alanları ve tanımlı yabancı anahtarlar taranır; yalnızca hiçbir tabloda kullanılmayan üreticiler listelenir ve silme anında yeniden doğrulanır.</p></div><div class="ml-auto"><?= cleanup_pagination($data) ?></div></div>
                <?php else: ?>
                    <div class="flex flex-wrap items-center gap-4"><div><h2 class="font-semibold"><?= $productRule === 'never_invoiced' ? 'Fatura satırı olmayan, CİNİUS ile ilişkili olmayan ve CinYazarID = 0 ürünler' : 'CİNİUS olmayan ve CinYazarID = 0 ürünler' ?></h2><p class="text-sm text-slate-500">Toplam <?= number_format($data['total'],0,',','.') ?> aday. CİNİUS ile üretici ilişkisi veya legacy yayınevi metni bulunan ürünler ile Sosyal Yayınları / Sosyal Yayınlar / Varsayılan Üretici kayıtlarına bağlı ürünler bu listeden ve silme işleminden korunur. <?= $productRule === 'never_invoiced' ? 'Aşağıdaki ürünler tek tek seçilerek veya tüm adaylar güvenli gruplar halinde silinebilir.' : 'Seçilen ürünlerin doğrudan kasa ve mevcut depo hareketleri temizlenir.' ?></p></div><div class="ml-auto"><?= cleanup_pagination($data) ?></div></div>
                <?php endif; ?>
            </div>
            <?php if ($productView === 'orphan_producers'): ?>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete_orphan_producers"><input type="hidden" name="selected_ids" data-selected-ids>
                    <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-slate-200"><tr><th class="p-2 text-left"><input type="checkbox" data-check-all <?= $mode === 'preview' ? 'disabled' : '' ?>></th><th class="p-2 text-left">ID</th><th class="p-2 text-left">Üretici / Yayınevi</th><th class="p-2 text-left">Telefon</th><th class="p-2 text-left">Adres</th><th class="p-2 text-left">Açıklama</th></tr></thead><tbody>
                        <?php foreach ($data['rows'] as $row): ?><tr class="border-t border-slate-200 hover:bg-blue-50"><td class="p-2"><input type="checkbox" value="<?= (int)$row['ID'] ?>" data-row-check <?= $mode === 'preview' ? 'disabled' : '' ?>></td><td class="p-2 font-mono"><?= (int)$row['ID'] ?></td><td class="p-2 font-medium"><?= h($row['uretici'] ?: 'Adsız üretici') ?></td><td class="p-2"><?= h($row['telefon']) ?></td><td class="max-w-md truncate p-2" title="<?= h($row['adres']) ?>"><?= h($row['adres']) ?></td><td class="max-w-md truncate p-2" title="<?= h($row['aciklama']) ?>"><?= h($row['aciklama']) ?></td></tr><?php endforeach; ?>
                        <?php if ($data['rows'] === []): ?><tr><td colspan="6" class="p-8 text-center text-slate-500">Bağlantısız üretici bulunamadı.</td></tr><?php endif; ?>
                    </tbody></table></div>
                    <?php if ($mode === 'apply' && $data['rows'] !== []): ?><?php include __DIR__ . '/partials/cleanup_confirmation.php'; ?><?php endif; ?>
                </form>
            <?php else: ?>
                <?php if ($productRule === 'never_invoiced'): ?>
                <div class="p-6">
                    <div class="rounded-lg border border-amber-300 bg-amber-50 p-5 text-amber-900">
                        <div class="text-sm">Silinecek toplam ürün</div>
                        <div class="mt-1 text-2xl font-bold"><?= number_format((int)$data['total'],0,',','.') ?></div>
                        <p class="mt-2 text-sm">Her ürün silinmeden hemen önce <code>CinYazarID = 0</code>, hiçbir <code>faturadetay</code> kaydı bulunmadığı ve CİNİUS / korunan üreticilerle ilişkili olmadığı yeniden doğrulanır.</p>
                    </div>
                </div>
                <?php if ($mode === 'apply' && (int)$data['total'] > 0): ?>
                    <form method="post" class="flex justify-end border-t border-red-200 bg-red-50 p-4">
                        <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_all_never_invoiced_products">
                        <button class="rounded bg-red-700 px-4 py-2 font-semibold text-white hover:bg-red-600" type="submit">Tüm <?= number_format((int)$data['total'],0,',','.') ?> ürünü hemen sil</button>
                    </form>
                <?php endif; ?>
                <?php endif; ?>
            <form method="post">
                <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete_products"><input type="hidden" name="product_rule" value="<?= h($productRule) ?>"><input type="hidden" name="selected_ids" data-selected-ids>
                <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-slate-200"><tr><th class="p-2 text-left"><input type="checkbox" data-check-all></th><th class="p-2 text-left">ID</th><th class="p-2 text-left">ISBN</th><th class="p-2 text-left">Ürün</th><th class="p-2 text-left">Yayınevi</th><th class="p-2 text-right">Fatura satırı</th><th class="p-2 text-right">Kasa</th></tr></thead><tbody>
                    <?php foreach ($data['rows'] as $row): ?><tr class="border-t border-slate-200 hover:bg-blue-50"><td class="p-2"><input type="checkbox" value="<?= (int)$row['ID'] ?>" data-row-check <?= $mode === 'preview' ? 'disabled' : '' ?>></td><td class="p-2 font-mono"><?= (int)$row['ID'] ?></td><td class="p-2"><?= h($row['isbn']) ?></td><td class="max-w-md p-2 font-medium"><?= h($row['kitapadi']) ?></td><td class="max-w-xs p-2"><?= h($row['publisher_name']) ?></td><td class="p-2 text-right"><?= number_format((int)$row['invoice_lines'],0,',','.') ?></td><td class="p-2 text-right"><?= number_format((int)$row['cash_rows'],0,',','.') ?></td></tr><?php endforeach; ?>
                    <?php if ($data['rows'] === []): ?><tr><td colspan="7" class="p-8 text-center text-slate-500">Aday ürün bulunamadı.</td></tr><?php endif; ?>
                </tbody></table></div>
                <?php if ($mode === 'apply' && $data['rows'] !== []): ?><?php include __DIR__ . '/partials/cleanup_confirmation.php'; ?><?php endif; ?>
            </form>
            <?php endif; ?>
        </section>
    <?php elseif ($section === 'linked' && $data !== null): ?>
        <section class="rounded-b-lg rounded-tr-lg border border-amber-300 bg-white"><div class="flex items-center gap-4 border-b border-amber-200 bg-amber-50 p-4"><div><h2 class="font-semibold text-amber-900">CİNİUS olmayan fakat CinYazarID dolu ürünler</h2><p class="text-sm text-amber-800">Toplam <?= number_format($data['total'],0,',','.') ?> kayıt. Bunlar otomatik silme işlemine dahil edilmez.</p></div><?= cleanup_pagination($data) ?></div><div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-slate-200"><tr><th class="p-2 text-left">ID</th><th class="p-2 text-left">ISBN</th><th class="p-2 text-left">Ürün</th><th class="p-2 text-left">Yayınevi</th><th class="p-2 text-left">CinYazarID / Yazar</th><th class="p-2 text-right">Fatura</th></tr></thead><tbody><?php foreach($data['rows'] as $row): ?><tr class="border-t border-slate-200"><td class="p-2"><?= (int)$row['ID'] ?></td><td class="p-2"><?= h($row['isbn']) ?></td><td class="p-2 font-medium"><?= h($row['kitapadi']) ?></td><td class="p-2"><?= h($row['publisher_name']) ?></td><td class="p-2">#<?= (int)$row['CinYazarID'] ?> — <?= h($row['author_name']) ?></td><td class="p-2 text-right"><?= (int)$row['invoice_lines'] ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php elseif ($section === 'addresses' && $data !== null): ?>
        <div class="flex flex-wrap gap-2 rounded-t-lg border border-b-0 border-slate-300 bg-white p-4">
            <a href="<?= h(cleanup_url(['address_rule'=>'unlinked','page'=>1,'q'=>''])) ?>" class="rounded border px-3 py-2 text-sm <?= $addressRule === 'unlinked' ? 'border-blue-700 bg-blue-700 font-semibold text-white' : 'border-slate-300 bg-white hover:bg-slate-50' ?>">3A. Bağlantısız cari kayıtları</a>
            <a href="<?= h(cleanup_url(['address_rule'=>'duplicates','page'=>1,'q'=>''])) ?>" class="rounded border px-3 py-2 text-sm <?= $addressRule === 'duplicates' ? 'border-blue-700 bg-blue-700 font-semibold text-white' : 'border-slate-300 bg-white hover:bg-slate-50' ?>">3B. Bağlantısız mükerrerler</a>
        </div>
        <?php if ($addressRule === 'duplicates'): ?>
        <section class="rounded-b-lg border border-slate-300 bg-white">
            <div class="border-b border-slate-200 p-4"><div class="flex flex-wrap items-center gap-4"><div><h2 class="font-semibold">Bağlantısız mükerrer cari/adres kayıtları</h2><p class="text-sm text-slate-500">E-posta veya ad/firma eşleşmesine göre <?= number_format($data['total'],0,',','.') ?> silinebilir mükerrer bulundu. Yalnızca ürün, fatura veya finans bağlantısı sıfır olan soldaki kayıt seçilebilir; ilişkili kayıtlar korunur. Cari-ağaç üyelikleri ve düzeltme yardımcı kayıtları koruyucu sayılmaz ve silme sırasında temizlenir.</p></div><form method="get" class="ml-auto flex items-center gap-2"><input type="hidden" name="mode" value="<?= h($mode) ?>"><input type="hidden" name="section" value="addresses"><input type="hidden" name="address_rule" value="duplicates"><input type="hidden" name="per_page" value="<?= $perPage ?>"><input class="form-input w-80" name="q" value="<?= h($query) ?>" placeholder="ID, ad, firma veya e-posta ara…"><button class="toolbar-button" type="submit">Ara</button><?php if($query!==''): ?><a class="toolbar-button" href="<?= h(cleanup_url(['q'=>'','page'=>1])) ?>">Temizle</a><?php endif; ?></form></div><div class="mt-3"><?= cleanup_pagination($data) ?></div></div>
            <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete_duplicate_addresses"><input type="hidden" name="selected_ids" data-selected-ids>
                <div class="flex items-center gap-3 border-b border-slate-200 bg-slate-50 px-4 py-2"><button type="button" class="toolbar-button" data-flag-found <?= $mode==='preview' ? 'disabled' : '' ?>>⚑ Bulunanları İşaretle</button><span class="text-xs text-slate-500">Yalnızca bağlantısı sıfır olan mükerrer kayıtları işaretler.</span></div>
                <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-slate-200"><tr><th class="p-2"><input type="checkbox" data-check-all></th><th class="p-2 text-left">Silinebilir mükerrer (0 bağlantı)</th><th class="p-2 text-left">Eşleşme</th><th class="p-2 text-left">Korunan ilişkili kayıtlar</th></tr></thead><tbody>
                <?php foreach($data['rows'] as $row): ?><tr class="border-t border-slate-200 align-top"><td class="p-3"><input type="checkbox" value="<?= (int)$row['ID'] ?>" data-row-check <?= $mode==='preview' ? 'disabled' : '' ?>></td><td class="p-3"><strong>#<?= (int)$row['ID'] ?> — <?= h($row['FirmaAd'] ?: trim($row['Ad'].' '.$row['Soyad'])) ?></strong><div class="mt-1 text-xs text-slate-500"><?= h($row['eposta'] ?: 'E-posta yok') ?></div><span class="mt-2 inline-block rounded bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">0 bağlantı — silinebilir</span></td><td class="p-3 text-xs"><?php $reasons=[]; foreach($row['matches'] as $match){if($match['email_match'])$reasons['E-posta']=true;if($match['name_match'])$reasons['Ad / firma']=true;} echo h(implode(', ',array_keys($reasons))); ?></td><td class="p-3"><div class="space-y-2"><?php foreach($row['matches'] as $match): ?><div class="rounded border border-amber-200 bg-amber-50 p-2"><strong>#<?= (int)$match['ID'] ?> — <?= h($match['FirmaAd'] ?: trim($match['Ad'].' '.$match['Soyad'])) ?></strong><div class="text-xs text-slate-600"><?= h($match['eposta'] ?: 'E-posta yok') ?></div><div class="mt-1 text-xs font-medium text-amber-900"><?= number_format((int)$match['total_links'],0,',','.') ?> bağlantı · CinYazarID ürünleri: <?= number_format((int)$match['author_links'],0,',','.') ?> · Faturalar: <?= number_format((int)$match['invoice_links'],0,',','.') ?></div></div><?php endforeach; ?></div></td></tr><?php endforeach; ?>
                <?php if($data['rows']===[]): ?><tr><td colspan="4" class="p-8 text-center text-slate-500">Bağlantısız ve ilişkili bir karşılığı bulunan mükerrer kayıt yok.</td></tr><?php endif; ?>
                </tbody></table></div><?php if($mode==='apply'&&$data['rows']!==[]): include __DIR__.'/partials/cleanup_confirmation.php'; endif; ?></form>
        </section>
        <?php else: ?>
        <section class="rounded-b-lg rounded-tr-lg border border-slate-300 bg-white"><div class="border-b border-slate-200 p-4"><div class="flex flex-wrap items-center gap-4"><div><h2 class="font-semibold">Ürünü ve faturası olmayan cari/adres kayıtları</h2><p class="text-sm text-slate-500">Toplam <?= number_format($data['total'],0,',','.') ?> aday. Herhangi bir finans hareketinde kullanılan kayıtlar korunur ve seçilemez.</p></div><form method="get" class="ml-auto flex items-center gap-2"><input type="hidden" name="mode" value="<?= h($mode) ?>"><input type="hidden" name="section" value="addresses"><input type="hidden" name="per_page" value="<?= $perPage ?>"><input class="form-input w-80" name="q" value="<?= h($query) ?>" placeholder="ID, ad, firma veya e-posta ara…"><button class="toolbar-button" type="submit">Ara</button><?php if($query!==''): ?><a class="toolbar-button" href="<?= h(cleanup_url(['q'=>'','page'=>1])) ?>">Temizle</a><?php endif; ?></form></div><div class="mt-3"><?= cleanup_pagination($data) ?></div></div><form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete_addresses"><input type="hidden" name="selected_ids" data-selected-ids><div class="flex items-center gap-3 border-b border-slate-200 bg-slate-50 px-4 py-2"><button type="button" class="toolbar-button" data-flag-found <?= $mode==='preview' ? 'disabled' : '' ?>>⚑ Bulunanları İşaretle</button><span class="text-xs text-slate-500">Bu sayfadaki korunmayan <?= number_format(count($data['rows']),0,',','.') ?> sonuç içinde seçim yapar.</span></div><div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-slate-200"><tr><th class="p-2"><input type="checkbox" data-check-all></th><th class="p-2 text-left">ID</th><th class="p-2 text-left">Ad / Firma</th><th class="p-2 text-left">E-posta</th><th class="p-2 text-right">Koruyan diğer finans kaydı</th></tr></thead><tbody><?php foreach($data['rows'] as $row): $protected=(int)$row['financial_references']>0; ?><tr class="border-t border-slate-200 <?= $protected ? 'bg-amber-50' : '' ?>"><td class="p-2"><input type="checkbox" value="<?= (int)$row['ID'] ?>" data-row-check <?= ($mode === 'preview' || $protected) ? 'disabled' : '' ?>></td><td class="p-2"><?= (int)$row['ID'] ?></td><td class="p-2 font-medium"><?= h($row['FirmaAd'] ?: trim($row['Ad'].' '.$row['Soyad'])) ?></td><td class="p-2"><?= h($row['eposta']) ?></td><td class="p-2 text-right <?= $protected ? 'font-semibold text-amber-800' : 'text-emerald-700' ?>"><?php if($protected): ?><button type="button" class="rounded border border-amber-300 bg-white px-2 py-1 text-xs font-semibold text-amber-900 hover:bg-amber-100" data-financial-details="<?= (int)$row['ID'] ?>"><?= (int)$row['financial_references'] ?> — Kayıtları göster</button><?php else: ?>0<?php endif; ?></td></tr><?php endforeach; ?><?php if($data['rows']===[]): ?><tr><td colspan="5" class="p-8 text-center text-slate-500">Aramayla eşleşen cari/adres adayı bulunamadı.</td></tr><?php endif; ?></tbody></table></div><?php if($mode==='apply' && $data['rows']!==[]): include __DIR__.'/partials/cleanup_confirmation.php'; endif; ?></form></section>
        <?php endif; ?>
    <?php elseif ($section === 'structure_cleanup'): ?>
        <nav class="flex flex-wrap gap-2 rounded-t-lg border border-b-0 border-slate-300 bg-white p-4">
            <?php foreach (['legacy'=>'Legacy alanlar ve ilişkiler','product_fields'=>'Ürün alanları','currency'=>'Döviz yapısı','payments'=>'Ödeme tabloları','obsolete'=>'Eski tablolar'] as $key=>$label): ?>
                <a href="<?= h(cleanup_url(['section'=>'structure_cleanup','cleanup_area'=>$key,'page'=>1])) ?>" class="rounded border px-3 py-2 text-sm <?= $cleanupArea === $key ? 'border-blue-700 bg-blue-700 font-semibold text-white' : 'border-slate-300 bg-white hover:bg-slate-50' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($cleanupArea === 'legacy' && $legacyStatus !== null): ?>
        <section class="rounded-b-lg rounded-tr-lg border border-slate-300 bg-white"><div class="border-b border-slate-200 p-4"><h2 class="font-semibold">Legacy alan ve tablo temizliği</h2><p class="text-sm text-slate-500">Cari iletişim alanları kaldırılmadan önce etiketli biçimde Notlar alanına aktarılır. Depo işlemi, depo tablolarını ve diğer tablolardaki depo referans alanlarını kaldırır. <code>cari_agac.tables</code> cari ağaç tablolarını, <code>currency.tables_and_references</code> ise eski döviz tablolarını ve kur referans alanlarını kaldırır. İlişkisel metin alanları, karşılık gelen ID alanları korunarak kaldırılır.</p></div><form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="drop_legacy"><table class="min-w-full text-sm"><thead class="bg-slate-200"><tr><th class="p-2"></th><th class="p-2 text-left">Alan / işlem</th><th class="p-2 text-left">Durum</th><th class="p-2 text-right">Etkilenen kayıt</th></tr></thead><tbody><?php foreach($legacyStatus as $key=>$item): ?><tr class="border-t border-slate-200"><td class="p-2"><input type="checkbox" name="fields[]" value="<?= h($key) ?>" data-row-check <?= ($mode==='preview'||!$item['safe'])?'disabled':'' ?>></td><td class="p-2 font-mono"><?= h($key) ?></td><td class="p-2"><?= !$item['exists'] ? 'Zaten kaldırılmış' : ($item['safe'] ? (($item['kind'] ?? '') === 'cari_contact_archive' ? 'Notlar alanına aktarılıp kaldırılabilir' : (($item['kind'] ?? '') === 'drop_columns' ? 'İlişkisel ID korunarak metin alanları kaldırılabilir' : (($item['kind'] ?? '') === 'table_cleanup' ? 'İlişkili tablolar kaldırılabilir' : 'Kaldırılabilir'))) : 'Dolu olduğu için korunuyor') ?></td><td class="p-2 text-right"><?= number_format((int)$item['populated_rows'],0,',','.') ?></td></tr><?php endforeach; ?></tbody></table><?php if($mode==='apply'): include __DIR__.'/partials/cleanup_confirmation.php'; endif; ?></form></section>
        <?php elseif ($cleanupArea === 'product_fields' && $productFieldsStatus !== null): ?>
        <section class="rounded-b-lg rounded-tr-lg border border-slate-300 bg-white">
            <div class="border-b border-slate-200 p-4"><h2 class="font-semibold">asp_urun eski alanlarını açıklamaya aktar ve kaldır</h2><p class="mt-1 text-sm text-slate-500">Boş, NULL veya 0 olmayan değerler <code>alanAdı: değer</code> biçiminde mevcut <code>aciklama</code> metninin altına eklenir. Ardından mevcut eski sütunların tamamı tek ALTER TABLE işlemiyle kaldırılır.</p><div class="mt-3 rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"><strong><?= number_format((int)$productFieldsStatus['affected_rows'],0,',','.') ?></strong> üründe açıklamaya aktarılacak en az bir değer var. <strong><?= number_format(count($productFieldsStatus['existing_columns']),0,',','.') ?></strong> alan halen tabloda mevcut.</div></div>
            <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-slate-200"><tr><th class="p-2 text-left">Alan</th><th class="p-2 text-left">Durum</th><th class="p-2 text-right">Dolu ürün</th></tr></thead><tbody><?php foreach($productFieldsStatus['configured_columns'] as $field): $exists=in_array($field,$productFieldsStatus['existing_columns'],true); ?><tr class="border-t border-slate-200"><td class="p-2 font-mono"><?= h($field) ?></td><td class="p-2 <?= $exists ? 'text-amber-800' : 'text-slate-500' ?>"><?= $exists ? 'Açıklamaya aktarılıp kaldırılacak' : 'Zaten kaldırılmış' ?></td><td class="p-2 text-right"><?= number_format((int)($productFieldsStatus['field_counts'][$field] ?? 0),0,',','.') ?></td></tr><?php endforeach; ?></tbody></table></div>
            <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="cleanup_product_fields"><div class="flex items-center justify-end gap-3 border-t border-red-200 bg-red-50 p-4"><span class="mr-auto text-xs text-red-800"><?= !$productFieldsStatus['description_available'] ? 'aciklama alanı bulunamadığı için işlem yapılamaz.' : ($mode==='preview' ? 'İşlemi çalıştırmak için Uygulama moduna geçin.' : 'Bu işlem sütunları kalıcı olarak kaldırır.') ?></span><button class="rounded bg-red-700 px-4 py-2 font-semibold text-white hover:bg-red-600 disabled:cursor-not-allowed disabled:opacity-50" type="submit" <?= ($mode!=='apply'||!$productFieldsStatus['ready'])?'disabled':'' ?>>Açıklamaya aktar ve alanları kaldır</button></div></form>
        </section>
        <?php elseif ($cleanupArea === 'currency' && $currencyStatus !== null): ?>
        <section class="rounded-b-lg rounded-tr-lg border border-slate-300 bg-white">
            <div class="border-b border-slate-200 p-4">
                <h2 class="font-semibold">Eski döviz yapısını kaldır</h2>
                <p class="mt-1 text-sm text-slate-500">Uygulama artık tek bir genel para birimini <code>app_settings</code> tablosundaki <code>default_currency</code> anahtarından kullanır. Bu işlem eski kur hareketlerini, kur tanımlarını ve diğer tablolardaki kur ID alanlarını kaldırır.</p>
                <div class="mt-3 rounded border <?= $currencyStatus['exists'] ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-emerald-200 bg-emerald-50 text-emerald-900' ?> p-3 text-sm">
                    <?= $currencyStatus['exists'] ? '<strong>Eski döviz yapısı bulundu.</strong> Aşağıdaki tablolar ve alanlar kaldırılabilir.' : '<strong>Temizlik tamamlanmış.</strong> Eski döviz tablosu veya kur referans alanı bulunamadı.' ?>
                </div>
            </div>
            <div class="grid gap-4 p-4 md:grid-cols-2">
                <div class="rounded border border-slate-200">
                    <h3 class="border-b border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold">Kaldırılacak tablolar</h3>
                    <ul class="divide-y divide-slate-100 text-sm"><?php foreach ($currencyStatus['tables'] as $table): $exists=in_array($table,$currencyStatus['existing_tables'],true); ?><li class="flex items-center justify-between px-3 py-2"><code><?= h($table) ?></code><span class="<?= $exists ? 'text-amber-700' : 'text-slate-400' ?>"><?= $exists ? 'Mevcut — kaldırılacak' : 'Zaten kaldırılmış' ?></span></li><?php endforeach; ?></ul>
                </div>
                <div class="rounded border border-slate-200">
                    <h3 class="border-b border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold">Kaldırılacak referans alanları</h3>
                    <ul class="divide-y divide-slate-100 text-sm"><?php foreach ($currencyStatus['columns'] as $column): $exists=in_array($column,$currencyStatus['existing_columns'],true); ?><li class="flex items-center justify-between px-3 py-2"><code><?= h($column) ?></code><span class="<?= $exists ? 'text-amber-700' : 'text-slate-400' ?>"><?= $exists ? 'Mevcut — kaldırılacak' : 'Zaten kaldırılmış' ?></span></li><?php endforeach; ?></ul>
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="cleanup_currency">
                <div class="flex items-center justify-end gap-3 border-t border-red-200 bg-red-50 p-4">
                    <span class="mr-auto text-xs text-red-800"><?= $mode === 'preview' ? 'İşlemi çalıştırmak için Uygulama moduna geçin.' : 'Bu işlem eski döviz verilerini ve sütunlarını kalıcı olarak kaldırır.' ?></span>
                    <button class="rounded bg-red-700 px-4 py-2 font-semibold text-white hover:bg-red-600 disabled:cursor-not-allowed disabled:opacity-50" type="submit" <?= ($mode !== 'apply' || !$currencyStatus['exists']) ? 'disabled' : '' ?>>Döviz tablolarını ve kur alanlarını kaldır</button>
                </div>
            </form>
        </section>
        <?php elseif ($cleanupArea === 'payments' && $paymentsStatus !== null): ?>
        <section class="rounded-b-lg rounded-tr-lg border border-slate-300 bg-white">
            <div class="border-b border-slate-200 p-4">
                <h2 class="font-semibold">Eski ödeme tablolarını kaldır</h2>
                <p class="mt-1 text-sm text-slate-500">Bu işlem artık uygulamada kullanılmayan <code>odemefatura</code> ve <code>odemeler</code> tablolarını bağımlılık sırasıyla kaldırır. Kasa ve banka hareketleri korunur.</p>
                <div class="mt-3 rounded border <?= $paymentsStatus['exists'] ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-emerald-200 bg-emerald-50 text-emerald-900' ?> p-3 text-sm">
                    <?= $paymentsStatus['exists'] ? '<strong>Eski ödeme tabloları bulundu.</strong> Aşağıdaki tablolar kaldırılabilir.' : '<strong>Temizlik tamamlanmış.</strong> Eski ödeme tabloları bulunamadı.' ?>
                </div>
            </div>
            <div class="p-4">
                <div class="rounded border border-slate-200">
                    <h3 class="border-b border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold">Kaldırılacak tablolar</h3>
                    <ul class="divide-y divide-slate-100 text-sm"><?php foreach ($paymentsStatus['tables'] as $table): $exists=in_array($table,$paymentsStatus['existing_tables'],true); ?><li class="flex items-center justify-between px-3 py-2"><code><?= h($table) ?></code><span class="<?= $exists ? 'text-amber-700' : 'text-slate-400' ?>"><?= $exists ? 'Mevcut — kaldırılacak' : 'Zaten kaldırılmış' ?></span></li><?php endforeach; ?></ul>
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="cleanup_payments">
                <div class="flex items-center justify-end gap-3 border-t border-red-200 bg-red-50 p-4">
                    <span class="mr-auto text-xs text-red-800"><?= $mode === 'preview' ? 'İşlemi çalıştırmak için Uygulama moduna geçin.' : 'Bu işlem iki tabloyu ve içlerindeki verileri kalıcı olarak kaldırır.' ?></span>
                    <button class="rounded bg-red-700 px-4 py-2 font-semibold text-white hover:bg-red-600 disabled:cursor-not-allowed disabled:opacity-50" type="submit" <?= ($mode !== 'apply' || !$paymentsStatus['exists']) ? 'disabled' : '' ?>>Ödeme tablolarını kaldır</button>
                </div>
            </form>
        </section>
        <?php elseif ($cleanupArea === 'obsolete' && $obsoleteStatus !== null): ?>
        <section class="rounded-b-lg rounded-tr-lg border border-slate-300 bg-white">
            <div class="border-b border-slate-200 p-4">
                <h2 class="font-semibold">Kullanılmayan eski tabloları kaldır</h2>
                <p class="mt-1 text-sm text-slate-500">Bu tablolar web uygulamasından ve ilişki menülerinden çıkarılmıştır. İşlem yalnızca halen mevcut olan tabloları kaldırır.</p>
                <div class="mt-3 rounded border <?= $obsoleteStatus['exists'] ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-emerald-200 bg-emerald-50 text-emerald-900' ?> p-3 text-sm"><?= $obsoleteStatus['exists'] ? '<strong>Eski tablolar bulundu.</strong>' : '<strong>Temizlik tamamlanmış.</strong> Kaldırılacak tablo bulunamadı.' ?></div>
            </div>
            <div class="p-4"><div class="rounded border border-slate-200"><h3 class="border-b border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold">Kaldırılacak tablolar</h3><ul class="divide-y divide-slate-100 text-sm"><?php foreach ($obsoleteStatus['tables'] as $table): $exists=in_array($table,$obsoleteStatus['existing_tables'],true); ?><li class="flex items-center justify-between px-3 py-2"><code><?= h($table) ?></code><span class="<?= $exists ? 'text-amber-700' : 'text-slate-400' ?>"><?= $exists ? 'Mevcut — kaldırılacak' : 'Zaten kaldırılmış' ?></span></li><?php endforeach; ?></ul></div></div>
            <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="cleanup_obsolete_tables"><div class="flex items-center justify-end gap-3 border-t border-red-200 bg-red-50 p-4"><span class="mr-auto text-xs text-red-800"><?= $mode === 'preview' ? 'İşlemi çalıştırmak için Uygulama moduna geçin.' : 'Tablolar ve içlerindeki veriler kalıcı olarak kaldırılır.' ?></span><button class="rounded bg-red-700 px-4 py-2 font-semibold text-white hover:bg-red-600 disabled:cursor-not-allowed disabled:opacity-50" type="submit" <?= ($mode !== 'apply' || !$obsoleteStatus['exists']) ? 'disabled' : '' ?>>Eski tabloları kaldır</button></div></form>
        </section>
        <?php endif; ?>
    <?php elseif ($section === 'engine' && $engineStatus !== null): ?>
        <section class="rounded-b-lg rounded-tr-lg border border-slate-300 bg-white">
            <div class="border-b border-slate-200 p-4">
                <div class="flex flex-wrap items-start gap-4"><div class="min-w-0 flex-1"><h2 class="font-semibold">MyISAM tablolarını InnoDB'ye dönüştür</h2><p class="mt-1 text-sm text-slate-500">Her tablo ayrı bir <code>ALTER TABLE ... ENGINE=InnoDB ROW_FORMAT=DYNAMIC</code> işlemiyle dönüştürülür. Böylece eski MyISAM <code>ROW_FORMAT=FIXED</code> seçeneği taşınmaz. İşlem tabloyu kilitleyebilir, geçici disk alanı kullanabilir ve büyük tablolarda uzun sürebilir.</p></div>
                    <?php if ($mode === 'preview' && (int)$engineStatus['count'] > 0): ?>
                        <a class="rounded bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600" href="<?= h(cleanup_url(['mode'=>'apply','section'=>'engine','page'=>1])) ?>">Uygulama moduna geç ve dönüşümü aç</a>
                    <?php elseif ($mode === 'apply' && (int)$engineStatus['count'] > 0): ?>
                        <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="convert_myisam_to_innodb"><button class="rounded bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600" type="submit">Tüm MyISAM tablolarını InnoDB yap</button></form>
                    <?php endif; ?>
                </div>
                <div class="mt-3 grid gap-2 sm:grid-cols-3"><div class="rounded border border-slate-200 bg-slate-50 p-3 text-sm"><span class="block text-xs text-slate-500">MyISAM tablo</span><strong><?= number_format((int)$engineStatus['count'],0,',','.') ?></strong></div><div class="rounded border border-slate-200 bg-slate-50 p-3 text-sm"><span class="block text-xs text-slate-500">Tahmini satır</span><strong><?= number_format((int)$engineStatus['estimated_rows'],0,',','.') ?></strong></div><div class="rounded border border-slate-200 bg-slate-50 p-3 text-sm"><span class="block text-xs text-slate-500">Yaklaşık boyut</span><strong><?= number_format((int)$engineStatus['total_bytes']/1048576,1,',','.') ?> MB</strong></div></div>
            </div>
            <div class="max-h-[60vh] overflow-auto"><table class="min-w-full text-sm"><thead class="sticky top-0 bg-slate-200"><tr><th class="p-2 text-left">Tablo</th><th class="p-2 text-right">Tahmini satır</th><th class="p-2 text-right">Boyut</th><th class="p-2 text-left">Hedef</th></tr></thead><tbody><?php foreach ($engineStatus['tables'] as $table): $bytes=(int)$table['DATA_LENGTH']+(int)$table['INDEX_LENGTH']; ?><tr class="border-t border-slate-200"><td class="p-2 font-mono"><?= h($table['TABLE_NAME']) ?></td><td class="p-2 text-right"><?= number_format((int)$table['TABLE_ROWS'],0,',','.') ?></td><td class="p-2 text-right"><?= number_format($bytes/1048576,1,',','.') ?> MB</td><td class="p-2 text-emerald-700">InnoDB</td></tr><?php endforeach; ?><?php if($engineStatus['tables']===[]): ?><tr><td colspan="4" class="p-8 text-center text-emerald-700">MyISAM tablo kalmadı.</td></tr><?php endif; ?></tbody></table></div>
            <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="convert_myisam_to_innodb"><div class="flex items-center justify-end gap-3 border-t border-amber-300 bg-amber-50 p-4"><span class="mr-auto text-xs text-amber-900"><?= $mode === 'preview' ? 'Dönüştürmek için Uygulama moduna geçin.' : 'Tam yedek alın, Delphi uygulamasını kapatın ve yeterli boş disk alanı olduğundan emin olun.' ?></span><button class="rounded bg-amber-700 px-4 py-2 font-semibold text-white hover:bg-amber-600 disabled:cursor-not-allowed disabled:opacity-50" type="submit" <?= ($mode !== 'apply' || (int)$engineStatus['count'] === 0) ? 'disabled' : '' ?>>Tüm MyISAM tablolarını InnoDB yap</button></div></form>
        </section>
    <?php endif; ?>
</main>
<script>
document.querySelectorAll('[data-check-all]').forEach(master => master.addEventListener('change', () => {
    const boxes = [...(master.closest('form')?.querySelectorAll('[data-row-check]:not(:disabled)') || [])];
    boxes.forEach(box => box.checked = master.checked);
}));
document.querySelectorAll('[data-flag-found]').forEach(button => button.addEventListener('click', () => {
    button.closest('form')?.querySelectorAll('[data-row-check]:not(:disabled)').forEach(box => box.checked = true);
}));
document.querySelectorAll('[data-selected-ids]').forEach(input => input.form.addEventListener('submit', () => {
    input.value = [...input.form.querySelectorAll('[data-row-check]:checked')].map(box => box.value).join(',');
}));
const cleanupEscapeHtml = value => String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
document.querySelectorAll('[data-financial-details]').forEach(button => button.addEventListener('click', async () => {
    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = 'Yükleniyor…';
    try {
        const response = await fetch(`cleanup.php?action=financial_reference_details&id=${encodeURIComponent(button.dataset.financialDetails)}`, {headers:{Accept:'application/json'}, cache:'no-store'});
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Finans kayıtları yüklenemedi.');
        const groups = data.groups.map(group => {
            const columns = group.rows.length ? Object.keys(group.rows[0]) : [];
            const header = columns.map(column => `<th class="whitespace-nowrap border-b border-r border-slate-300 bg-slate-200 px-2 py-1.5 text-left text-xs">${cleanupEscapeHtml(column)}</th>`).join('');
            const rows = group.rows.map(row => `<tr class="border-b border-slate-100">${columns.map(column => `<td class="max-w-80 truncate whitespace-nowrap border-r border-slate-100 px-2 py-1.5 text-xs" title="${cleanupEscapeHtml(row[column])}">${cleanupEscapeHtml(row[column])}</td>`).join('')}</tr>`).join('');
            return `<section class="rounded border border-slate-300"><div class="flex items-center gap-3 border-b border-slate-300 bg-slate-100 px-3 py-2"><strong class="text-sm">${cleanupEscapeHtml(group.label)}</strong><span class="text-xs text-slate-500">${cleanupEscapeHtml(group.table)}</span><span class="ml-auto rounded bg-white px-2 py-1 text-xs font-semibold">${Number(group.count).toLocaleString('tr-TR')} kayıt</span></div><div class="overflow-auto"><table class="min-w-full"><thead><tr>${header}</tr></thead><tbody>${rows}</tbody></table></div>${group.truncated ? '<div class="border-t border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">İlk 1.000 kayıt gösteriliyor.</div>' : ''}</section>`;
        }).join('');
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 flex items-center justify-center bg-slate-950/60 p-4';
        modal.style.zIndex = '50000';
        modal.innerHTML = `<div class="flex max-h-[90vh] w-full max-w-screen-xl flex-col overflow-hidden rounded-xl border border-slate-300 bg-white shadow-2xl"><header class="flex items-center gap-3 border-b border-slate-300 px-5 py-4"><div><h2 class="font-semibold">Koruyan finans kayıtları — #${Number(data.address.ID)} ${cleanupEscapeHtml(data.address.name)}</h2><p class="text-xs text-slate-500">${cleanupEscapeHtml(data.address.email || 'E-posta yok')} · Toplam ${Number(data.total).toLocaleString('tr-TR')} bağlantı</p></div><button type="button" class="toolbar-button ml-auto" data-close-financial>× Kapat</button></header><div class="min-h-0 flex-1 space-y-4 overflow-auto p-5">${groups || '<p class="text-sm text-slate-500">Koruyan finans kaydı artık bulunmuyor.</p>'}</div></div>`;
        document.body.append(modal);
        const close = () => modal.remove();
        modal.querySelector('[data-close-financial]').onclick = close;
        modal.addEventListener('pointerdown', event => { if(event.target === modal) close(); });
    } catch (error) {
        alert(error.message);
    } finally {
        button.disabled = false;
        button.textContent = originalText;
    }
}));
</script>
</body>
</html>
