<?php
declare(strict_types=1);

return [
    'adresdefteri' => [
        'label' => 'Cari Kartlar', 'group' => 'Cari',
        'list' => ['ID','FirmaAd','Ad','Soyad','eposta','gizle'],
        'search' => ['FirmaAd','Ad','Soyad','eposta','VergiNo'], 'name' => 'FirmaAd',
        'relations' => ['GrupID'=>['adresgruplari','grupAdi'],'Ilce'=>['ilceler','ilce'],'Sehir'=>['sehirler','sehir'],'ulkeID'=>['ulkeler','ulke']],
        'editor_hidden' => ['Telefon1','Telefon2','Telefon3','GSMTel1','GSMTel2','Faks1','Faks2','kisaKod','oncekiFaturalardanIndirimAl','listearama','fuaraKatiliyor','caridenDus','Etkin','Kurumsal','Bakiye'],
    ],
    'adresgruplari' => ['label' => 'Cari Grupları', 'group' => 'Cari', 'list' => ['ID','grupAdi','grupIndirimi','urunIndirimKullan'], 'name' => 'grupAdi'],
    'cari_duzeltme_kayitlari' => ['label' => 'Cari Düzeltmeleri', 'group' => 'Cari', 'list' => ['ID','musteriID','islemTarihi','Miktar','CariSifirla','Aciklama'], 'relations' => ['musteriID'=>['adresdefteri','FirmaAd']], 'money' => ['Miktar']],

    'asp_urun' => [
        'label' => 'Ürünler / Kitaplar', 'group' => 'Stok',
        'list' => ['ID','isbn','kitapadi','ureticiID','CinYazarID','eder','barkod1'],
        'virtual_list' => ['after'=>'eder', 'columns'=>['Satış','İade','Yazara Verilen']],
        'search' => ['kitapadi','isbn','barkod1'], 'name' => 'kitapadi',
        'relations' => ['birimID'=>['birimler','birim'],'ureticiID'=>['ureticiler','uretici'],'urunGrupID'=>['urungruplari','grup'],'CinYazarID'=>['adresdefteri','FirmaAd']],
        'relation_missing' => ['ureticiID'=>'Yayınevi bulunamadı','CinYazarID'=>'Yazar bulunamadı'],
        'money' => ['eder'],
        'editor_hidden' => ['varsayilanDepoID','kurID','yayinevitxt','yazarlistetxt','kod','konular','dizi','ozgundili','boyut','hamur','sayfa','cilt','xbaski','ozgunad','kapakdosya','ozgunDosya','hizmetUrunu','resimDosya','stokMiktar','kaynakID','netkitapKod','birlesikad','barkod2','satisMiktar','tedarikciIDler'],
    ],
    'birimler' => ['label' => 'Birimler', 'group' => 'Stok', 'list' => ['ID','birim','kisaltma','ondalikVar','altBirimID','altBirimCarpan'], 'name' => 'birim', 'relations' => ['altBirimID'=>['birimler','birim']]],
    'ureticiler' => ['label' => 'Üreticiler / Yayınevleri', 'group' => 'Stok', 'list' => ['ID','uretici','telefon','adres'], 'search' => ['uretici','telefon'], 'name' => 'uretici'],
    'urungruplari' => ['label' => 'Ürün Grupları', 'group' => 'Stok', 'list' => ['ID','grup'], 'name' => 'grup'],

    'faturabaslik' => ['label' => 'Faturalar', 'group' => 'Satış / Alış', 'list' => ['ID','faturaNo','FaturaTarih','MusteriID','AliciAd','FaturaTuru','UrunSayisi','Toplam','odemeYapildi'], 'search' => ['faturaNo','AliciAd','AliciVergiNo','AliciTelefon'], 'relations' => ['MusteriID'=>['adresdefteri','FirmaAd'],'FaturaTuru'=>['faturaturleri','FaturaTuru'],'odemeKasaID'=>['kasa','BelgeNo'],'odemeBankaID'=>['banka_kasa','aciklama']], 'transactional' => true, 'editor_hidden' => ['depoIslem'], 'money' => ['OnToplam','Indirim','KDV','Toplam']],
    'faturaturleri' => ['label' => 'Fatura Türleri', 'group' => 'Satış / Alış', 'list' => ['ID','FaturaTuru','islemYon'], 'name' => 'FaturaTuru'],

    'kasa' => ['label' => 'Kasa Hareketleri', 'group' => 'Finans', 'list' => ['ID','islemTarihi','MusteriID','Islem','Miktar','KategoriID','BelgeNo'], 'search' => ['Aciklama','BelgeNo','urunAdi'], 'relations' => ['MusteriID'=>['adresdefteri','FirmaAd'],'KategoriID'=>['kategoriler','kategori'],'FaturaID'=>['faturabaslik','faturaNo'],'ceksenetID'=>['cek_senet_kasa','cekNo'],'UrunID'=>['asp_urun','kitapadi']], 'transactional' => true, 'editor_hidden' => ['KurID'], 'money' => ['Miktar']],
    'banka_kasa' => ['label' => 'Banka Hareketleri', 'group' => 'Finans', 'list' => ['ID','tarih','hesapID','musteriID','para','yatirildi','cekildi','kategoriID','faturaID'], 'search' => ['aciklama'], 'relations' => ['hesapID'=>['hesaplar','banka_hesapad'],'musteriID'=>['adresdefteri','FirmaAd'],'transferBankaID'=>['hesaplar','banka_hesapad'],'kategoriID'=>['kategoriler','kategori'],'faturaID'=>['faturabaslik','faturaNo'],'cekID'=>['cek_senet_kasa','cekNo']], 'transactional' => true, 'editor_hidden' => ['kurID'], 'money' => ['para']],
    'hesaplar' => ['label' => 'Banka Hesapları', 'group' => 'Finans', 'list' => ['ID','banka_hesapad','hesapturu','hesapno','subeAd','bakiye'], 'name' => 'banka_hesapad', 'editor_hidden' => ['kurID'], 'money' => ['bakiye']],
    'kategoriler' => ['label' => 'Finans Kategorileri', 'group' => 'Finans', 'list' => ['ID','kategori'], 'name' => 'kategori'],
    'cek_senet_kasa' => ['label' => 'Çek / Senet', 'group' => 'Finans', 'list' => ['ID','firmaID','cekNo','cek_senet','miktar','bas_tarih','vade_tarih','alindi','verildi','tahsil_et','ode'], 'search' => ['cekNo','aciklama'], 'relations' => ['firmaID'=>['adresdefteri','FirmaAd'],'banka_hesapID'=>['hesaplar','banka_hesapad'],'ciro_firmaID'=>['adresdefteri','FirmaAd'],'verilen_bankaID'=>['hesaplar','banka_hesapad']], 'transactional' => true, 'money' => ['miktar']],

    'ulkeler' => ['label' => 'Ülkeler', 'group' => 'Tanımlar', 'list' => ['ID','ulke'], 'name' => 'ulke'],
    'sehirler' => ['label' => 'Şehirler', 'group' => 'Tanımlar', 'list' => ['ID','sehir','ulkeID'], 'name' => 'sehir', 'relations' => ['ulkeID'=>['ulkeler','ulke']]],
    'ilceler' => ['label' => 'İlçeler', 'group' => 'Tanımlar', 'list' => ['ID','ilce','sehirID'], 'name' => 'ilce', 'relations' => ['sehirID'=>['sehirler','sehir']]],
];
