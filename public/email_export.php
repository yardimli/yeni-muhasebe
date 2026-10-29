<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/email_export.php';
$source = (string)($_GET['source'] ?? '');
if (!in_array($source, ['cari','webcup'], true)) json_response(['error'=>'Geçersiz kaynak.'], 422);
require_permission('emails:' . $source);
try {
    $statement = $source === 'cari'
        ? db()->query("SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', Ad, Soyad)), ''), FirmaAd, '') AS name, eposta AS email FROM adresdefteri ORDER BY ID")
        : cup_db()->query('SELECT YazarinAdi AS name, YazarinEpostasi AS email FROM cinius_uretim ORDER BY ID');
} catch (Throwable $e) {
    error_log($e->__toString());
    json_response(['error'=>'E-posta listesi alınamadı.'], 500);
}
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="email-list-' . $source . '.csv"');
header('Cache-Control: no-store');
$output = fopen('php://output', 'wb');
fputcsv($output, ['Name','Email'], ',', '"', '', "\r\n");
foreach (newsletter_rows($statement) as $row) fputcsv($output, $row, ',', '"', '', "\r\n");
fclose($output);
