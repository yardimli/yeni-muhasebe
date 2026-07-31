<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/delete_service.php';

function preview_test_id(string $sql): int
{
    return (int) db()->query($sql)->fetchColumn();
}

$targets = [
    'faturabaslik' => preview_test_id('SELECT `ID` FROM `faturabaslik` ORDER BY `ID` DESC LIMIT 1'),
    'asp_urun' => preview_test_id('SELECT `ID` FROM `asp_urun` ORDER BY `ID` DESC LIMIT 1'),
    'adresdefteri' => preview_test_id('SELECT `ID` FROM `adresdefteri` ORDER BY `ID` DESC LIMIT 1'),
];

foreach ($targets as $table => $id) {
    if ($id < 1) throw new RuntimeException("$table için test kaydı bulunamadı.");
    $preview = cascade_delete_preview($table, $id);
    if ($preview['table'] !== $table || $preview['id'] !== $id || $preview['groups'] === []) {
        throw new RuntimeException("$table silme önizlemesi beklenen yapıda değil.");
    }
    foreach ($preview['groups'] as $group) {
        foreach (['key','label','table','count','ids','action'] as $field) {
            if (!array_key_exists($field, $group)) throw new RuntimeException("$table önizleme grubunda $field eksik.");
        }
    }
    echo "$table #$id: " . count($preview['groups']) . " ilişki grubu (salt okunur)\n";
}

try {
    cascade_delete_preview('kasa', 1);
    throw new RuntimeException('Desteklenmeyen tablo kabul edildi.');
} catch (InvalidArgumentException) {
    echo "Silme beyaz listesi: OK\n";
}

echo "Delete preview smoke test: OK\n";
