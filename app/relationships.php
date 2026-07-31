<?php
declare(strict_types=1);

/*
 * Row actions use one of two forms:
 * - target/filter/value: open a normal table window with an exact SQL filter.
 * - report/value: open a purpose-built joined report.
 */
return [
    'adresdefteri' => [
        ['label'=>'Ürünler / Kitaplar','target'=>'asp_urun','filter'=>'CinYazarID','value'=>'ID'],
        ['label'=>'Yazar Satışları','report'=>'author_sales','value'=>'ID'],
        ['label'=>'Faturalar','target'=>'faturabaslik','filter'=>'MusteriID','value'=>'ID'],
        ['label'=>'Kasa Hareketleri','target'=>'kasa','filter'=>'MusteriID','value'=>'ID'],
        ['label'=>'Banka Hareketleri','target'=>'banka_kasa','filter'=>'musteriID','value'=>'ID'],
        ['label'=>'Çek / Senet','target'=>'cek_senet_kasa','filter'=>'firmaID','value'=>'ID'],
        ['label'=>'Ciro Edilen Çekler','target'=>'cek_senet_kasa','filter'=>'ciro_firmaID','value'=>'ID'],
        ['label'=>'Cari Düzeltmeleri','target'=>'cari_duzeltme_kayitlari','filter'=>'musteriID','value'=>'ID'],
        ['label'=>'Cari Grubu','target'=>'adresgruplari','filter'=>'ID','value'=>'GrupID'],
        ['label'=>'İlçe','target'=>'ilceler','filter'=>'ID','value'=>'Ilce'],
        ['label'=>'Şehir','target'=>'sehirler','filter'=>'ID','value'=>'Sehir'],
        ['label'=>'Ülke','target'=>'ulkeler','filter'=>'ID','value'=>'ulkeID'],
    ],
    'adresgruplari' => [],
    'cari_duzeltme_kayitlari' => [
        ['label'=>'Cari Kartı','target'=>'adresdefteri','filter'=>'ID','value'=>'musteriID'],
        ['label'=>'Carinin Tüm Düzeltmeleri','target'=>'cari_duzeltme_kayitlari','filter'=>'musteriID','value'=>'musteriID'],
    ],

    'asp_urun' => [
        ['label'=>'Satış Geçmişi','report'=>'product_sales','value'=>'ID'],
        ['label'=>'Kasa Hareketleri','target'=>'kasa','filter'=>'UrunID','value'=>'ID'],
        ['label'=>'Yazar / Cari','target'=>'adresdefteri','filter'=>'ID','value'=>'CinYazarID'],
        ['label'=>'Üretici / Yayınevi','target'=>'ureticiler','filter'=>'ID','value'=>'ureticiID'],
        ['label'=>'Ürün Grubu','target'=>'urungruplari','filter'=>'ID','value'=>'urunGrupID'],
        ['label'=>'Birim','target'=>'birimler','filter'=>'ID','value'=>'birimID'],
    ],
    'birimler' => [
        ['label'=>'Bu Birimi Kullanan Ürünler','target'=>'asp_urun','filter'=>'birimID','value'=>'ID'],
        ['label'=>'Alt Birimler','target'=>'birimler','filter'=>'altBirimID','value'=>'ID'],
        ['label'=>'Üst Birim','target'=>'birimler','filter'=>'ID','value'=>'altBirimID'],
    ],
    'ureticiler' => [
        ['label'=>'Ürünler / Kitaplar','target'=>'asp_urun','filter'=>'ureticiID','value'=>'ID'],
    ],
    'urungruplari' => [
        ['label'=>'Ürünler','target'=>'asp_urun','filter'=>'urunGrupID','value'=>'ID'],
    ],
    'faturabaslik' => [
        ['label'=>'Cari Kartı','target'=>'adresdefteri','filter'=>'ID','value'=>'MusteriID'],
        ['label'=>'Fatura Türü','target'=>'faturaturleri','filter'=>'ID','value'=>'FaturaTuru'],
        ['label'=>'Kasa Hareketleri','target'=>'kasa','filter'=>'FaturaID','value'=>'ID'],
        ['label'=>'Banka Hareketleri','target'=>'banka_kasa','filter'=>'faturaID','value'=>'ID'],
        ['label'=>'Kasa Ödeme Kaydı','target'=>'kasa','filter'=>'ID','value'=>'odemeKasaID'],
        ['label'=>'Banka Ödeme Kaydı','target'=>'banka_kasa','filter'=>'ID','value'=>'odemeBankaID'],
    ],
    'faturaturleri' => [
        ['label'=>'Faturalar','target'=>'faturabaslik','filter'=>'FaturaTuru','value'=>'ID'],
    ],

    'kasa' => [
        ['label'=>'Cari Kartı','target'=>'adresdefteri','filter'=>'ID','value'=>'MusteriID'],
        ['label'=>'Fatura','target'=>'faturabaslik','filter'=>'ID','value'=>'FaturaID'],
        ['label'=>'Ürün','target'=>'asp_urun','filter'=>'ID','value'=>'UrunID'],
        ['label'=>'Kategori','target'=>'kategoriler','filter'=>'ID','value'=>'KategoriID'],
        ['label'=>'Bu Kayıtla Ödenen Faturalar','target'=>'faturabaslik','filter'=>'odemeKasaID','value'=>'ID'],
        ['label'=>'Tahsil Edilen Çekler','target'=>'cek_senet_kasa','filter'=>'tahsil_kasaID','value'=>'ID'],
        ['label'=>'Ödenen Çek / Senetler','target'=>'cek_senet_kasa','filter'=>'ode_kasaID','value'=>'ID'],
    ],
    'banka_kasa' => [
        ['label'=>'Banka Hesabı','target'=>'hesaplar','filter'=>'ID','value'=>'hesapID'],
        ['label'=>'Cari Kartı','target'=>'adresdefteri','filter'=>'ID','value'=>'musteriID'],
        ['label'=>'Fatura','target'=>'faturabaslik','filter'=>'ID','value'=>'faturaID'],
        ['label'=>'Çek / Senet','target'=>'cek_senet_kasa','filter'=>'ID','value'=>'cekID'],
        ['label'=>'Kategori','target'=>'kategoriler','filter'=>'ID','value'=>'kategoriID'],
        ['label'=>'Transfer Hesabı','target'=>'hesaplar','filter'=>'ID','value'=>'transferBankaID'],
        ['label'=>'Bu Kayıtla Ödenen Faturalar','target'=>'faturabaslik','filter'=>'odemeBankaID','value'=>'ID'],
    ],
    'hesaplar' => [
        ['label'=>'Banka Hareketleri','target'=>'banka_kasa','filter'=>'hesapID','value'=>'ID'],
        ['label'=>'Bu Hesaba Transferler','target'=>'banka_kasa','filter'=>'transferBankaID','value'=>'ID'],
        ['label'=>'Çek / Senetler','target'=>'cek_senet_kasa','filter'=>'banka_hesapID','value'=>'ID'],
        ['label'=>'Verilen Çekler','target'=>'cek_senet_kasa','filter'=>'verilen_bankaID','value'=>'ID'],
    ],
    'kategoriler' => [
        ['label'=>'Kasa Hareketleri','target'=>'kasa','filter'=>'KategoriID','value'=>'ID'],
        ['label'=>'Banka Hareketleri','target'=>'banka_kasa','filter'=>'kategoriID','value'=>'ID'],
    ],
    'cek_senet_kasa' => [
        ['label'=>'Firma / Cari','target'=>'adresdefteri','filter'=>'ID','value'=>'firmaID'],
        ['label'=>'Ciro Edilen Cari','target'=>'adresdefteri','filter'=>'ID','value'=>'ciro_firmaID'],
        ['label'=>'Banka Hesabı','target'=>'hesaplar','filter'=>'ID','value'=>'banka_hesapID'],
        ['label'=>'Verilen Banka','target'=>'hesaplar','filter'=>'ID','value'=>'verilen_bankaID'],
        ['label'=>'Banka Hareketleri','target'=>'banka_kasa','filter'=>'cekID','value'=>'ID'],
        ['label'=>'Tahsil Kasa Kaydı','target'=>'kasa','filter'=>'ID','value'=>'tahsil_kasaID'],
        ['label'=>'Ödeme Kasa Kaydı','target'=>'kasa','filter'=>'ID','value'=>'ode_kasaID'],
    ],
    'ulkeler' => [
        ['label'=>'Şehirler','target'=>'sehirler','filter'=>'ulkeID','value'=>'ID'],
        ['label'=>'Cari Kartları','target'=>'adresdefteri','filter'=>'ulkeID','value'=>'ID'],
    ],
    'sehirler' => [
        ['label'=>'Ülke','target'=>'ulkeler','filter'=>'ID','value'=>'ulkeID'],
        ['label'=>'İlçeler','target'=>'ilceler','filter'=>'sehirID','value'=>'ID'],
        ['label'=>'Cari Kartları','target'=>'adresdefteri','filter'=>'Sehir','value'=>'ID'],
    ],
    'ilceler' => [
        ['label'=>'Şehir','target'=>'sehirler','filter'=>'ID','value'=>'sehirID'],
        ['label'=>'Cari Kartları','target'=>'adresdefteri','filter'=>'Ilce','value'=>'ID'],
    ],
];
