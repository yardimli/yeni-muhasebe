<?php
declare(strict_types=1);

$schema = require __DIR__ . '/../app/schema.php';
$relationships = require __DIR__ . '/../app/relationships.php';

foreach (['adres_agac', 'adres_defter_agac_iliskileri', 'depolar', 'depobaslik', 'depodetay', 'faturadetay', 'kurlar', 'kurhareketleri', 'odemeler', 'odemefatura', 'adresdefteri2', 'faturabaski', 'gelir', 'gider', 'nakitakis', 'stoktablo'] as $table) {
    if (isset($schema[$table])) throw new RuntimeException("Section is still exposed: $table");
}

foreach (['asp_urun'=>'kurID','banka_kasa'=>'kurID','hesaplar'=>'kurID','kasa'=>'KurID'] as $table => $field) {
    if (in_array($field, $schema[$table]['list'] ?? [], true)) throw new RuntimeException("Currency field is still listed: $table.$field");
    if (isset($schema[$table]['relations'][$field])) throw new RuntimeException("Currency lookup is still exposed: $table.$field");
    if (!in_array($field, $schema[$table]['editor_hidden'] ?? [], true)) throw new RuntimeException("Currency field is still editable: $table.$field");
}
if (($relationships['adresgruplari'] ?? null) !== []) throw new RuntimeException('Cari Grupları row menu is still enabled.');
if (array_key_exists('adres_agac', $relationships)) throw new RuntimeException('Cari Ağacı relationship configuration still exists.');

$cariHidden = $schema['adresdefteri']['editor_hidden'] ?? [];
foreach (['Telefon1','Telefon2','Telefon3','GSMTel1','GSMTel2','Faks1','Faks2','kisaKod','oncekiFaturalardanIndirimAl','listearama','fuaraKatiliyor','caridenDus','Etkin','Kurumsal','Bakiye'] as $field) {
    if (!in_array($field, $cariHidden, true)) throw new RuntimeException("Cari legacy field is still editable: $field");
}
if (in_array('Bakiye', $schema['adresdefteri']['list'] ?? [], true)) throw new RuntimeException('Cari Bakiye is still listed.');

$cash = $schema['kasa'];
foreach (['CaridenGizle','BelgeNo','ceksenetID'] as $field) {
    if (in_array($field, $cash['list'] ?? [], true)) throw new RuntimeException("Removed cash field is still listed: $field");
    if (in_array($field, $cash['search'] ?? [], true)) throw new RuntimeException("Removed cash field is still searched: $field");
    if (isset($cash['relations'][$field])) throw new RuntimeException("Removed cash relation is still exposed: $field");
    if (!in_array($field, $cash['editor_hidden'] ?? [], true)) throw new RuntimeException("Removed cash field is still editable: $field");
}
if (($cash['value_labels']['Islem'] ?? []) !== [1=>'Gelir',2=>'Gider']) throw new RuntimeException('Cash transaction direction labels are incorrect.');

$product = $schema['asp_urun'];
foreach (['yayinevitxt', 'yazarlistetxt'] as $field) {
    if (in_array($field, $product['list'] ?? [], true)) throw new RuntimeException("Product legacy text is still listed: $field");
    if (in_array($field, $product['search'] ?? [], true)) throw new RuntimeException("Product legacy text is still searched: $field");
    if (!in_array($field, $product['editor_hidden'] ?? [], true)) throw new RuntimeException("Product legacy text is still editable: $field");
}
foreach (['ureticiID', 'CinYazarID'] as $field) {
    if (!in_array($field, $product['list'] ?? [], true)) throw new RuntimeException("Product relation is not listed: $field");
    if (!isset($product['relations'][$field])) throw new RuntimeException("Product relation lookup is missing: $field");
    if (!isset($product['relation_missing'][$field])) throw new RuntimeException("Product missing-link label is absent: $field");
}
$removedProductFields = ['kod','konular','dizi','ozgundili','boyut','hamur','sayfa','cilt','xbaski','ozgunad','kapakdosya','ozgunDosya','hizmetUrunu','resimDosya','stokMiktar','kaynakID','netkitapKod','birlesikad','barkod2','satisMiktar','tedarikciIDler'];
foreach ($removedProductFields as $field) {
    if (in_array($field, $product['list'] ?? [], true)) throw new RuntimeException("Deprecated product field is still listed: $field");
    if (in_array($field, $product['search'] ?? [], true)) throw new RuntimeException("Deprecated product field is still searched: $field");
    if (!in_array($field, $product['editor_hidden'] ?? [], true)) throw new RuntimeException("Deprecated product field is still editable: $field");
}

echo "Schema exposure smoke test passed.\n";
