<?php
declare(strict_types=1);

function business_report_flat_catalog(): array
{
    $flat = [];
    foreach (require APP_ROOT . '/app/business_reports.php' as $group => $reports) {
        foreach ($reports as $id => $label) $flat[$id] = ['id' => $id, 'label' => $label, 'group' => $group];
    }
    return $flat;
}

function business_report_range(string $preset, ?string $customStart, ?string $customEnd): array
{
    $today = new DateTimeImmutable('today');
    $preset = in_array($preset, ['current_month','last_month','last_3_months','this_year','last_year','custom'], true) ? $preset : 'this_year';
    [$start, $end] = match ($preset) {
        'current_month' => [$today->modify('first day of this month'), $today],
        'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
        'last_3_months' => [$today->modify('first day of this month')->modify('-2 months'), $today],
        'last_year' => [$today->modify('first day of january last year'), $today->modify('last day of december last year')],
        'custom' => [DateTimeImmutable::createFromFormat('!Y-m-d', (string) $customStart), DateTimeImmutable::createFromFormat('!Y-m-d', (string) $customEnd)],
        default => [$today->modify('first day of january this year'), $today],
    };
    if (!$start || !$end || $start > $end) throw new InvalidArgumentException('Geçerli bir başlangıç ve bitiş tarihi seçin.');
    return ['preset' => $preset, 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
}

function business_report_period(string $column, string $grouping): string
{
    return $grouping === 'daily' ? "DATE($column)" : "DATE_FORMAT($column, '%Y-%m')";
}

function business_report_author_copy_condition(string $invoiceAlias = 'fb', string $productAlias = 'u'): string
{
    return "NOT (COALESCE($productAlias.`CinYazarID`, 0) > 0 AND CAST($invoiceAlias.`MusteriID` AS UNSIGNED) = $productAlias.`CinYazarID`)";
}

function business_report_line_total(string $detailAlias = 'fd'): string
{
    return "(COALESCE($detailAlias.`miktar`,0) * COALESCE($detailAlias.`urunEder`,0) *
        (1 - COALESCE($detailAlias.`urunIndirim`,0) / 100) * (1 + COALESCE($detailAlias.`urunKDV`,0) / 100))";
}

function business_report_definition(string $id, string $grouping, string $start, string $end): array
{
    $period = fn (string $column): string => business_report_period($column, $grouping);
    $range = fn (string $column): string => "$column >= ? AND $column < DATE_ADD(?, INTERVAL 1 DAY)";
    $dateParams = fn (int $times = 1): array => array_merge(...array_fill(0, $times, [$start, $end]));
    $eligibleSale = business_report_author_copy_condition('fb', 'u');
    $lineTotal = business_report_line_total('fd');
    $money = ['NetTutar','BrutTutar','Iade','Indirim','KDV','Toplam','Tahsilat','Odeme','NetNakit','Ortalama','Bakiye','Degisim','DonemOrtalamasi','Sapma','GelenFatura','GidenFatura','AlinanCekSenet','VerilenCekSenet'];
    $definition = null;

    if (in_array($id, ['executive_overview','period_comparison','performance_anomalies'], true)) {
        $p1 = $period('fb.FaturaTarih'); $p2 = $period('k.islemTarihi'); $p3 = $period('b.tarih'); $p4 = $period('c.bas_tarih');
        $base = "SELECT Donem, ROUND(SUM(NetTutar),2) NetTutar, SUM(Fatura) Fatura, SUM(Urun) Urun,
                    ROUND(SUM(GelenFatura),2) GelenFatura, ROUND(SUM(GidenFatura),2) GidenFatura,
                    ROUND(SUM(Tahsilat),2) Tahsilat, ROUND(SUM(Odeme),2) Odeme,
                    ROUND(SUM(AlinanCekSenet),2) AlinanCekSenet, ROUND(SUM(VerilenCekSenet),2) VerilenCekSenet,
                    ROUND(SUM(Tahsilat)-SUM(Odeme),2) NetNakit, SUM(Musteri) Musteri
                FROM (
                    SELECT $p1 Donem,
                        SUM(CASE WHEN fb.FaturaTuru=1 AND $eligibleSale THEN $lineTotal WHEN fb.FaturaTuru=2 AND $eligibleSale THEN -$lineTotal ELSE 0 END) NetTutar,
                        COUNT(DISTINCT CASE WHEN fb.FaturaTuru IN (1,2) AND $eligibleSale THEN fb.ID END) Fatura,
                        SUM(CASE WHEN fb.FaturaTuru=1 AND $eligibleSale THEN fd.miktar WHEN fb.FaturaTuru=2 AND $eligibleSale THEN -fd.miktar ELSE 0 END) Urun,
                        SUM(CASE WHEN fb.FaturaTuru=3 THEN $lineTotal WHEN fb.FaturaTuru=4 THEN -$lineTotal ELSE 0 END) GelenFatura,
                        SUM(CASE WHEN fb.FaturaTuru=1 AND $eligibleSale THEN $lineTotal WHEN fb.FaturaTuru=2 AND $eligibleSale THEN -$lineTotal ELSE 0 END) GidenFatura,
                        0 Tahsilat, 0 Odeme, 0 AlinanCekSenet, 0 VerilenCekSenet,
                        COUNT(DISTINCT CASE WHEN fb.FaturaTuru IN (1,2) AND $eligibleSale THEN fb.MusteriID END) Musteri
                    FROM faturadetay fd JOIN faturabaslik fb ON fb.ID=fd.faturaID LEFT JOIN asp_urun u ON u.ID=fd.urunID
                    WHERE {$range('fb.FaturaTarih')} AND fb.FaturaTuru IN (1,2,3,4) GROUP BY $p1
                    UNION ALL
                    SELECT $p2,0,0,0,0,0,SUM(CASE WHEN k.Islem=1 THEN k.Miktar ELSE 0 END),SUM(CASE WHEN k.Islem=2 THEN k.Miktar ELSE 0 END),0,0,0
                    FROM kasa k WHERE {$range('k.islemTarihi')} GROUP BY $p2
                    UNION ALL
                    SELECT $p3,0,0,0,0,0,SUM(CASE WHEN b.yatirildi=1 THEN b.para ELSE 0 END),SUM(CASE WHEN b.cekildi=1 THEN b.para ELSE 0 END),0,0,0
                    FROM banka_kasa b WHERE {$range('b.tarih')} GROUP BY $p3
                    UNION ALL
                    SELECT $p4,0,0,0,0,0,0,0,
                        SUM(CASE WHEN c.alindi=1 THEN c.miktar ELSE 0 END),SUM(CASE WHEN c.verildi=1 THEN c.miktar ELSE 0 END),0
                    FROM cek_senet_kasa c WHERE {$range('c.bas_tarih')} GROUP BY $p4
                ) x GROUP BY Donem";
        $sql = $base;
        $columns = ['Donem'=>'Dönem','NetTutar'=>'Net Hasılat','Fatura'=>'Fatura','Urun'=>'Ürün Adedi','Tahsilat'=>'Tahsilat','Odeme'=>'Ödeme','NetNakit'=>'Net Nakit','Musteri'=>'Aktif Müşteri'];
        if ($id === 'executive_overview') {
            $columns = ['Donem'=>'Dönem','NetTutar'=>'Net Hasılat','Fatura'=>'Fatura','Urun'=>'Ürün Adedi',
                'GelenFatura'=>'Gelen Fatura Toplamı','GidenFatura'=>'Giden Fatura Toplamı',
                'Tahsilat'=>'Tahsilat','Odeme'=>'Ödeme','AlinanCekSenet'=>'Alınan Çek/Senet',
                'VerilenCekSenet'=>'Verilen Çek/Senet','NetNakit'=>'Net Nakit','Musteri'=>'Aktif Müşteri'];
        }
        if ($id === 'period_comparison') {
            $sql = "SELECT z.*, ROUND(z.NetTutar-LAG(z.NetTutar) OVER (ORDER BY z.Donem),2) Degisim,
                    ROUND(CASE WHEN LAG(z.NetTutar) OVER (ORDER BY z.Donem)<>0 THEN (z.NetTutar/LAG(z.NetTutar) OVER (ORDER BY z.Donem)-1)*100 ELSE NULL END,2) DegisimYuzde FROM ($base) z";
            $columns += ['Degisim'=>'Hasılat Değişimi','DegisimYuzde'=>'Değişim %'];
        } elseif ($id === 'performance_anomalies') {
            $sql = "SELECT z.*, ROUND(AVG(z.NetTutar) OVER (),2) DonemOrtalamasi,
                    ROUND(z.NetTutar-AVG(z.NetTutar) OVER (),2) Sapma FROM ($base) z";
            $columns += ['DonemOrtalamasi'=>'Dönem Ortalaması','Sapma'=>'Sapma'];
        }
        $definition = compact('sql','columns') + ['params'=>$dateParams(4),'chart'=>['NetTutar','Tahsilat','Odeme']];
    }

    if ($id === 'revenue') {
        $p=$period('fb.FaturaTarih');
        $sql="SELECT $p Donem, COUNT(DISTINCT CASE WHEN fb.FaturaTuru=1 THEN fb.ID END) SatisFatura,
            ROUND(SUM(CASE WHEN fb.FaturaTuru=1 THEN $lineTotal ELSE 0 END),2) BrutTutar,
            ROUND(SUM(CASE WHEN fb.FaturaTuru=2 THEN $lineTotal ELSE 0 END),2) Iade,
            ROUND(SUM(CASE WHEN fb.FaturaTuru=1 THEN $lineTotal WHEN fb.FaturaTuru=2 THEN -$lineTotal ELSE 0 END),2) NetTutar
            FROM faturadetay fd JOIN faturabaslik fb ON fb.ID=fd.faturaID LEFT JOIN asp_urun u ON u.ID=fd.urunID
            WHERE {$range('fb.FaturaTarih')} AND fb.FaturaTuru IN (1,2) AND $eligibleSale GROUP BY $p";
        $definition=['sql'=>$sql,'params'=>$dateParams(),'columns'=>['Donem'=>'Dönem','SatisFatura'=>'Satış Faturası','BrutTutar'=>'Brüt Satış','Iade'=>'İade','NetTutar'=>'Net Hasılat'],'chart'=>['BrutTutar','Iade','NetTutar']];
    }
    if ($id === 'invoice_performance') {
        $p=$period('fb.FaturaTarih');
        $sql="SELECT $p Donem, COUNT(DISTINCT fb.ID) Fatura,
            SUM(CASE WHEN fb.FaturaTuru=1 THEN fd.miktar ELSE -fd.miktar END) Urun,
            ROUND(SUM(CASE WHEN fb.FaturaTuru=1 THEN $lineTotal ELSE -$lineTotal END),2) Toplam,
            ROUND(SUM(CASE WHEN fb.FaturaTuru=1 THEN $lineTotal ELSE -$lineTotal END)/NULLIF(COUNT(DISTINCT fb.ID),0),2) Ortalama,
            ROUND(SUM(CASE WHEN fb.FaturaTuru=1 THEN fd.miktar ELSE -fd.miktar END)/NULLIF(COUNT(DISTINCT fb.ID),0),2) FaturaBasinaUrun
            FROM faturadetay fd JOIN faturabaslik fb ON fb.ID=fd.faturaID LEFT JOIN asp_urun u ON u.ID=fd.urunID
            WHERE {$range('fb.FaturaTarih')} AND fb.FaturaTuru IN (1,2) AND $eligibleSale GROUP BY $p";
        $definition=['sql'=>$sql,'params'=>$dateParams(),'columns'=>['Donem'=>'Dönem','Fatura'=>'Fatura','Urun'=>'Ürün Adedi','Toplam'=>'Toplam','Ortalama'=>'Ortalama Fatura','FaturaBasinaUrun'=>'Fatura Başına Ürün'],'chart'=>['Fatura','Ortalama']];
    }
    if ($id === 'sales_returns') {
        $p=$period('fb.FaturaTarih');
        $subEligible=business_report_author_copy_condition('sfb','su');
        $subTotal=business_report_line_total('sfd');
        $sql="SELECT $p Donem, COUNT(DISTINCT fb.ID) IadeFatura, SUM(fd.miktar) IadeAdedi, ROUND(SUM($lineTotal),2) Iade,
            ROUND(100*SUM($lineTotal)/NULLIF((SELECT SUM($subTotal) FROM faturadetay sfd JOIN faturabaslik sfb ON sfb.ID=sfd.faturaID LEFT JOIN asp_urun su ON su.ID=sfd.urunID WHERE sfb.FaturaTuru=1 AND {$range('sfb.FaturaTarih')} AND $subEligible),0),2) IadeOrani
            FROM faturadetay fd JOIN faturabaslik fb ON fb.ID=fd.faturaID LEFT JOIN asp_urun u ON u.ID=fd.urunID
            WHERE {$range('fb.FaturaTarih')} AND fb.FaturaTuru=2 AND $eligibleSale GROUP BY $p";
        $definition=['sql'=>$sql,'params'=>[...$dateParams(),...$dateParams()],'columns'=>['Donem'=>'Dönem','IadeFatura'=>'İade Faturası','IadeAdedi'=>'İade Adedi','Iade'=>'İade Tutarı','IadeOrani'=>'İade %'],'chart'=>['Iade','IadeAdedi']];
    }
    if ($id === 'discount_vat') {
        $p=$period('fb.FaturaTarih');
        $gross="(COALESCE(fd.miktar,0)*COALESCE(fd.urunEder,0))";
        $discount="($gross*COALESCE(fd.urunIndirim,0)/100)";
        $tax="(($gross-$discount)*COALESCE(fd.urunKDV,0)/100)";
        $sign="CASE WHEN fb.FaturaTuru=1 THEN 1 ELSE -1 END";
        $sql="SELECT $p Donem, ROUND(SUM(($sign)*$gross),2) BrutTutar, ROUND(SUM(($sign)*$discount),2) Indirim,
            ROUND(100*SUM(($sign)*$discount)/NULLIF(SUM(($sign)*$gross),0),2) IndirimOrani,
            ROUND(SUM(($sign)*$tax),2) KDV, ROUND(SUM(($sign)*$lineTotal),2) Toplam
            FROM faturadetay fd JOIN faturabaslik fb ON fb.ID=fd.faturaID LEFT JOIN asp_urun u ON u.ID=fd.urunID
            WHERE {$range('fb.FaturaTarih')} AND fb.FaturaTuru IN (1,2) AND $eligibleSale GROUP BY $p";
        $definition=['sql'=>$sql,'params'=>$dateParams(),'columns'=>['Donem'=>'Dönem','BrutTutar'=>'Ara Toplam','Indirim'=>'İndirim','IndirimOrani'=>'İndirim %','KDV'=>'KDV','Toplam'=>'Genel Toplam'],'chart'=>['Indirim','KDV','Toplam']];
    }
    if ($id === 'sales_payment_method') {
        $p=$period('fb.FaturaTarih');
        $sql="SELECT $p Donem, CASE fb.odemeTuru WHEN 1 THEN 'Nakit' WHEN 2 THEN 'Kart' ELSE 'Cari' END Yontem,
            COUNT(DISTINCT fb.ID) Fatura, ROUND(SUM(CASE WHEN fb.FaturaTuru=1 THEN $lineTotal ELSE -$lineTotal END),2) NetTutar,
            COUNT(DISTINCT CASE WHEN fb.odemeYapildi=1 THEN fb.ID END) Odendi
            FROM faturadetay fd JOIN faturabaslik fb ON fb.ID=fd.faturaID LEFT JOIN asp_urun u ON u.ID=fd.urunID
            WHERE {$range('fb.FaturaTarih')} AND fb.FaturaTuru IN (1,2) AND $eligibleSale GROUP BY $p,fb.odemeTuru";
        $definition=['sql'=>$sql,'params'=>$dateParams(),'columns'=>['Donem'=>'Dönem','Yontem'=>'Ödeme Yöntemi','Fatura'=>'Fatura','NetTutar'=>'Net Tutar','Odendi'=>'Ödenen'],'chart'=>['NetTutar']];
    }

    if (in_array($id, ['sales_by_customer','sales_by_location'], true)) {
        $p=$period('fb.FaturaTarih');
        $dimension = $id === 'sales_by_customer'
            ? "COALESCE(NULLIF(a.FirmaAd,''),NULLIF(TRIM(CONCAT_WS(' ',a.Ad,a.Soyad)),''),NULLIF(fb.AliciAd,''),CONCAT('#',fb.MusteriID))"
            : "COALESCE(NULLIF(s.sehir,''),NULLIF(fb.AliciSehir,''),NULLIF(a.sehirAD,''),'Belirsiz')";
        $joins = $id === 'sales_by_location' ? ' LEFT JOIN sehirler s ON s.ID=a.Sehir' : '';
        $sql="SELECT $p Donem, $dimension RaporBoyutu, COUNT(DISTINCT fb.ID) Fatura, COUNT(DISTINCT fb.MusteriID) Musteri,
            SUM(CASE WHEN fb.FaturaTuru=1 THEN fd.miktar ELSE -fd.miktar END) Urun,
            ROUND(SUM(CASE WHEN fb.FaturaTuru=1 THEN $lineTotal ELSE -$lineTotal END),2) NetTutar
            FROM faturadetay fd JOIN faturabaslik fb ON fb.ID=fd.faturaID LEFT JOIN asp_urun u ON u.ID=fd.urunID
            LEFT JOIN adresdefteri a ON a.ID=CAST(fb.MusteriID AS UNSIGNED) $joins
            WHERE {$range('fb.FaturaTarih')} AND fb.FaturaTuru IN (1,2) AND $eligibleSale GROUP BY $p,RaporBoyutu";
        $label=$id==='sales_by_customer'?'Müşteri':'Şehir / Ülke';
        $definition=['sql'=>$sql,'params'=>$dateParams(),'columns'=>['Donem'=>'Dönem','RaporBoyutu'=>$label,'Fatura'=>'Fatura','Musteri'=>'Müşteri','Urun'=>'Ürün Adedi','NetTutar'=>'Net Tutar'],'chart'=>['NetTutar']];
    }

    if (in_array($id, ['items_sold','product_performance','author_performance','publisher_performance'], true)) {
        $p=$period('fb.FaturaTarih');
        $dimension = match($id) {
            'author_performance' => "CASE
                WHEN COALESCE(u.CinYazarID,0)=0 THEN 'Yazar bağlantısı yok'
                WHEN a.ID IS NULL THEN CONCAT('Yazar bulunamadı (#',u.CinYazarID,')')
                ELSE COALESCE(NULLIF(CONVERT(a.FirmaAd USING utf8mb4),''),NULLIF(CONVERT(TRIM(CONCAT_WS(' ',a.Ad,a.Soyad)) USING utf8mb4),''),CONCAT('Adsız yazar (#',u.CinYazarID,')')) END",
            'publisher_performance' => "CASE
                WHEN COALESCE(u.ureticiID,0)=0 THEN 'Yayınevi bağlantısı yok'
                WHEN ur.ID IS NULL THEN CONCAT('Yayınevi bulunamadı (#',u.ureticiID,')')
                ELSE COALESCE(NULLIF(CONVERT(ur.uretici USING utf8mb4),''),CONCAT('Adsız yayınevi (#',u.ureticiID,')')) END",
            default => "COALESCE(NULLIF(CONVERT(u.kitapadi USING utf8mb4),''),NULLIF(CONVERT(fd.urunAD USING utf8mb4),''),CONCAT('Ürün #',fd.urunID))",
        };
        $joins="LEFT JOIN asp_urun u ON u.ID=fd.urunID";
        if($id==='author_performance') $joins.=' LEFT JOIN adresdefteri a ON a.ID=u.CinYazarID';
        if($id==='publisher_performance') $joins.=' LEFT JOIN ureticiler ur ON ur.ID=u.ureticiID';
        $sql="SELECT $p Donem, $dimension RaporBoyutu,
            SUM(CASE WHEN fb.FaturaTuru=1 THEN fd.miktar ELSE -fd.miktar END) Urun,
            COUNT(DISTINCT fb.ID) Fatura, COUNT(DISTINCT fb.MusteriID) Musteri,
            ROUND(SUM(CASE WHEN fb.FaturaTuru=1 THEN $lineTotal ELSE -$lineTotal END),2) NetTutar,
            ROUND(AVG(fd.urunEder),2) Ortalama
            FROM faturadetay fd JOIN faturabaslik fb ON fb.ID=fd.faturaID $joins
            WHERE {$range('fb.FaturaTarih')} AND fb.FaturaTuru IN (1,2) AND $eligibleSale GROUP BY $p,RaporBoyutu";
        $label=match($id){'author_performance'=>'Yazar','publisher_performance'=>'Yayınevi / Üretici',default=>'Ürün'};
        $definition=['sql'=>$sql,'params'=>$dateParams(),'columns'=>['Donem'=>'Dönem','RaporBoyutu'=>$label,'Urun'=>'Net Satılan','Fatura'=>'Fatura','Musteri'=>'Müşteri','NetTutar'=>'Net Tutar','Ortalama'=>'Ort. Fiyat'],'chart'=>['Urun','NetTutar']];
    }
    if ($id === 'slow_products') {
        $sql="SELECT CONCAT(?,' / ',?) Donem, u.ID UrunID, u.kitapadi UrunAdi,
            COALESCE(SUM(CASE WHEN fb.FaturaTuru=1 THEN fd.miktar WHEN fb.FaturaTuru=2 THEN -fd.miktar ELSE 0 END),0) Satilan,
            MAX(fb.FaturaTarih) SonSatis
            FROM asp_urun u LEFT JOIN faturadetay fd ON fd.urunID=u.ID
            LEFT JOIN faturabaslik fb ON fb.ID=fd.faturaID AND {$range('fb.FaturaTarih')} AND fb.FaturaTuru IN (1,2) AND $eligibleSale
            GROUP BY u.ID,u.kitapadi HAVING Satilan<=5";
        $definition=['sql'=>$sql,'params'=>[$start,$end,...$dateParams()],'columns'=>['Donem'=>'Dönem','UrunID'=>'Ürün ID','UrunAdi'=>'Ürün','Satilan'=>'Satılan','SonSatis'=>'Son Satış'],'chart'=>['Satilan']];
    }
    if ($id === 'stock_movement') {
        $p=$period('fb.FaturaTarih');
        $sql="SELECT $p Donem,u.ID UrunID,COALESCE(u.kitapadi,fd.urunAD) Urun,
            SUM(CASE WHEN fb.FaturaTuru=1 THEN fd.miktar ELSE 0 END) Satis,
            SUM(CASE WHEN fb.FaturaTuru=2 THEN fd.miktar ELSE 0 END) SatisIade,
            SUM(CASE WHEN fb.FaturaTuru=3 THEN fd.miktar ELSE 0 END) Alis,
            SUM(CASE WHEN fb.FaturaTuru=4 THEN fd.miktar ELSE 0 END) AlisIade,
            SUM(CASE WHEN fb.FaturaTuru IN (2,3) THEN fd.miktar ELSE -fd.miktar END) NetHareket
            FROM faturadetay fd JOIN faturabaslik fb ON fb.ID=fd.faturaID LEFT JOIN asp_urun u ON u.ID=fd.urunID
            WHERE {$range('fb.FaturaTarih')} AND fb.FaturaTuru IN (1,2,3,4)
                AND (fb.FaturaTuru IN (3,4) OR $eligibleSale) GROUP BY $p,u.ID,Urun";
        $definition=['sql'=>$sql,'params'=>$dateParams(),'columns'=>['Donem'=>'Dönem','UrunID'=>'Ürün ID','Urun'=>'Ürün','Satis'=>'Satış Çıkışı','SatisIade'=>'Satış İadesi','Alis'=>'Alış Girişi','AlisIade'=>'Alış İadesi','NetHareket'=>'Net Hareket'],'chart'=>['Satis','SatisIade','NetHareket']];
    }
    if ($id === 'low_stock') {
        $sql="SELECT CONCAT(?,' / ',?) Donem,u.ID UrunID,u.kitapadi Urun,
            COALESCE(SUM(CASE WHEN fb.FaturaTuru=1 THEN fd.miktar WHEN fb.FaturaTuru=2 THEN -fd.miktar ELSE 0 END),0) NetSatilan,
            CASE WHEN COALESCE(SUM(CASE WHEN fb.FaturaTuru=1 THEN fd.miktar WHEN fb.FaturaTuru=2 THEN -fd.miktar ELSE 0 END),0)<=0 THEN 'Hareketsiz / iadeli' ELSE 'Düşük hareket' END Durum
            FROM asp_urun u LEFT JOIN faturadetay fd ON fd.urunID=u.ID
            LEFT JOIN faturabaslik fb ON fb.ID=fd.faturaID AND {$range('fb.FaturaTarih')} AND fb.FaturaTuru IN (1,2) AND $eligibleSale
            GROUP BY u.ID,u.kitapadi HAVING NetSatilan<=5";
        $definition=['sql'=>$sql,'params'=>[$start,$end,...$dateParams()],'columns'=>['Donem'=>'Dönem','UrunID'=>'Ürün ID','Urun'=>'Ürün','NetSatilan'=>'Dönemde Net Satılan','Durum'=>'Durum'],'chart'=>['NetSatilan']];
    }

    if (in_array($id, ['income_collections','outgoing_payments','expenses','combined_cashflow','cash_bank_channels','expense_categories'], true)) {
        $pk=$period('k.islemTarihi'); $pb=$period('b.tarih');
        $events="SELECT $pk Donem,'Kasa' Kanal,k.KategoriID,
                    SUM(CASE WHEN k.Islem=1 THEN k.Miktar ELSE 0 END) Tahsilat,SUM(CASE WHEN k.Islem=2 THEN k.Miktar ELSE 0 END) Odeme,
                    SUM(CASE WHEN k.Islem=1 THEN 1 ELSE 0 END) GelirIslem,SUM(CASE WHEN k.Islem=2 THEN 1 ELSE 0 END) GiderIslem,COUNT(*) Islem
                FROM kasa k WHERE {$range('k.islemTarihi')} GROUP BY $pk,k.KategoriID
                UNION ALL
                SELECT $pb,'Banka',b.kategoriID,SUM(CASE WHEN b.yatirildi=1 THEN b.para ELSE 0 END),SUM(CASE WHEN b.cekildi=1 THEN b.para ELSE 0 END),
                    SUM(CASE WHEN b.yatirildi=1 THEN 1 ELSE 0 END),SUM(CASE WHEN b.cekildi=1 THEN 1 ELSE 0 END),COUNT(*)
                FROM banka_kasa b WHERE {$range('b.tarih')} GROUP BY $pb,b.kategoriID";
        $params=$dateParams(2);
        if ($id==='cash_bank_channels') {
            $sql="SELECT Donem,Kanal,SUM(Islem) Islem,ROUND(SUM(Tahsilat),2) Tahsilat,ROUND(SUM(Odeme),2) Odeme,ROUND(SUM(Tahsilat)-SUM(Odeme),2) NetNakit FROM ($events) e GROUP BY Donem,Kanal";
            $columns=['Donem'=>'Dönem','Kanal'=>'Kanal','Islem'=>'İşlem','Tahsilat'=>'Giriş','Odeme'=>'Çıkış','NetNakit'=>'Net'];
        } elseif ($id==='expense_categories') {
            $sql="SELECT e.Donem,COALESCE(k.kategori,CONCAT('Kategori #',e.KategoriID)) Kategori,SUM(e.GiderIslem) Islem,ROUND(SUM(e.Odeme),2) Odeme,ROUND(AVG(NULLIF(e.Odeme,0)),2) Ortalama FROM ($events) e LEFT JOIN kategoriler k ON k.ID=e.KategoriID GROUP BY e.Donem,e.KategoriID,Kategori HAVING Odeme>0";
            $columns=['Donem'=>'Dönem','Kategori'=>'Kategori','Islem'=>'İşlem','Odeme'=>'Gider','Ortalama'=>'Ortalama'];
        } else {
            $sql="SELECT Donem,ROUND(SUM(Tahsilat),2) Tahsilat,ROUND(SUM(Odeme),2) Odeme,ROUND(SUM(Tahsilat)-SUM(Odeme),2) NetNakit,SUM(Islem) Islem FROM ($events) e GROUP BY Donem";
            $columns=['Donem'=>'Dönem','Tahsilat'=>'Tahsilat','Odeme'=>'Ödeme / Gider','NetNakit'=>'Net Nakit','Islem'=>'İşlem'];
            if($id==='income_collections') {$sql="SELECT Donem,ROUND(SUM(Tahsilat),2) Tahsilat,SUM(GelirIslem) Islem FROM ($events) e GROUP BY Donem HAVING Tahsilat>0";$columns=['Donem'=>'Dönem','Tahsilat'=>'Tahsilat','Islem'=>'İşlem'];}
            if(in_array($id,['outgoing_payments','expenses'],true)) {$sql="SELECT Donem,ROUND(SUM(Odeme),2) Odeme,SUM(GiderIslem) Islem FROM ($events) e GROUP BY Donem HAVING Odeme>0";$columns=['Donem'=>'Dönem','Odeme'=>$id==='expenses'?'Gider':'Ödeme','Islem'=>'İşlem'];}
        }
        $definition=['sql'=>$sql,'params'=>$params,'columns'=>$columns,'chart'=>array_values(array_intersect(['Tahsilat','Odeme','NetNakit'],array_keys($columns)))];
    }
    if ($id === 'purchases') {
        $p=$period('fb.FaturaTarih');
        $sql="SELECT $p Donem,COUNT(*) Fatura,ROUND(SUM(CASE WHEN fb.FaturaTuru=3 THEN fb.Toplam ELSE -fb.Toplam END),2) Alis,
            SUM(CASE WHEN fb.FaturaTuru=3 THEN fb.UrunSayisi ELSE -fb.UrunSayisi END) Urun
            FROM faturabaslik fb WHERE {$range('fb.FaturaTarih')} AND fb.FaturaTuru IN (3,4) GROUP BY $p";
        $definition=['sql'=>$sql,'params'=>$dateParams(),'columns'=>['Donem'=>'Dönem','Fatura'=>'Alış Faturası','Alis'=>'Net Alış','Urun'=>'Ürün Adedi'],'chart'=>['Alis','Urun'],'money'=>['Alis']];
    }

    if ($id === 'receivables') {
        $p=$period('fb.FaturaVade');
        $sql="SELECT $p Donem,fb.ID FaturaID,fb.faturaNo FaturaNo,fb.FaturaVade Vade,
            COALESCE(a.FirmaAd,fb.AliciAd,CONCAT('#',fb.MusteriID)) Musteri,ROUND(SUM($lineTotal),2) Toplam,
            GREATEST(DATEDIFF(?,DATE(fb.FaturaVade)),0) GecikmeGun
            FROM faturadetay fd JOIN faturabaslik fb ON fb.ID=fd.faturaID LEFT JOIN asp_urun u ON u.ID=fd.urunID
            LEFT JOIN adresdefteri a ON a.ID=CAST(fb.MusteriID AS UNSIGNED)
            WHERE {$range('fb.FaturaVade')} AND fb.FaturaTuru=1 AND COALESCE(fb.odemeYapildi,0)=0 AND $eligibleSale
            GROUP BY fb.ID,fb.faturaNo,fb.FaturaVade,Musteri";
        $definition=['sql'=>$sql,'params'=>[$end,...$dateParams()],'columns'=>['Donem'=>'Dönem','FaturaID'=>'Fatura ID','FaturaNo'=>'Fatura No','Vade'=>'Vade','Musteri'=>'Müşteri','Toplam'=>'Alacak','GecikmeGun'=>'Gecikme Gün'],'chart'=>['Toplam']];
    }
    if ($id === 'checks_notes') {
        $p=$period('c.vade_tarih');
        $sql="SELECT $p Donem,c.ID KayitID,c.cekNo BelgeNo,c.vade_tarih Vade,COALESCE(a.FirmaAd,CONCAT('#',c.firmaID)) Musteri,
            CASE WHEN c.cek_senet=1 THEN 'Senet' ELSE 'Çek' END Tur,ROUND(c.miktar,2) Toplam,
            CASE WHEN c.iade=1 THEN 'İade' WHEN c.tahsil_et=1 THEN 'Tahsil' WHEN c.ode=1 THEN 'Ödendi' WHEN c.vade_tarih<? THEN 'Gecikmiş' ELSE 'Bekliyor' END Durum
            FROM cek_senet_kasa c LEFT JOIN adresdefteri a ON a.ID=c.firmaID WHERE {$range('c.vade_tarih')}";
        $definition=['sql'=>$sql,'params'=>[$end,...$dateParams()],'columns'=>['Donem'=>'Dönem','KayitID'=>'Kayıt ID','BelgeNo'=>'Belge No','Vade'=>'Vade','Musteri'=>'Müşteri','Tur'=>'Tür','Toplam'=>'Tutar','Durum'=>'Durum'],'chart'=>['Toplam']];
    }

    if ($id === 'new_customers') {
        $p=$period('a.KayitTarihi');
        $sql="SELECT $p Donem,COUNT(*) Musteri,SUM(CASE WHEN a.Kisisel=1 THEN 1 ELSE 0 END) Kisisel,SUM(CASE WHEN a.Kurumsal=1 THEN 1 ELSE 0 END) Kurumsal FROM adresdefteri a WHERE {$range('a.KayitTarihi')} GROUP BY $p";
        $definition=['sql'=>$sql,'params'=>$dateParams(),'columns'=>['Donem'=>'Dönem','Musteri'=>'Yeni Müşteri','Kisisel'=>'Kişisel','Kurumsal'=>'Kurumsal'],'chart'=>['Musteri','Kisisel','Kurumsal']];
    }
    if ($id === 'first_time_buyers') {
        $p=$period('x.IlkSatis');
        $firstEligible=business_report_author_copy_condition('ifb','iu');
        $firstTotal=business_report_line_total('ifd');
        $sql="SELECT $p Donem,COUNT(*) Musteri,ROUND(SUM(x.IlkTutar),2) NetTutar FROM (
            SELECT i.MusteriID,MIN(i.FaturaTarih) IlkSatis,
                SUBSTRING_INDEX(GROUP_CONCAT(i.Tutar ORDER BY i.FaturaTarih),',',1) IlkTutar
            FROM (SELECT CAST(ifb.MusteriID AS UNSIGNED) MusteriID,ifb.ID,ifb.FaturaTarih,SUM($firstTotal) Tutar
                FROM faturadetay ifd JOIN faturabaslik ifb ON ifb.ID=ifd.faturaID LEFT JOIN asp_urun iu ON iu.ID=ifd.urunID
                WHERE ifb.FaturaTuru=1 AND CAST(ifb.MusteriID AS UNSIGNED)>0 AND $firstEligible
                GROUP BY CAST(ifb.MusteriID AS UNSIGNED),ifb.ID,ifb.FaturaTarih) i GROUP BY i.MusteriID
            ) x WHERE {$range('x.IlkSatis')} GROUP BY $p";
        $definition=['sql'=>$sql,'params'=>$dateParams(),'columns'=>['Donem'=>'Dönem','Musteri'=>'İlk Kez Alan','NetTutar'=>'İlk Satış Tutarı'],'chart'=>['Musteri','NetTutar']];
    }
    if ($id === 'active_customers') {
        $p=$period('fb.FaturaTarih');
        $sql="SELECT $p Donem,COUNT(DISTINCT fb.MusteriID) Musteri,COUNT(DISTINCT fb.ID) Fatura,ROUND(SUM($lineTotal),2) NetTutar,
            ROUND(COUNT(DISTINCT fb.ID)/NULLIF(COUNT(DISTINCT fb.MusteriID),0),2) Frekans
            FROM faturadetay fd JOIN faturabaslik fb ON fb.ID=fd.faturaID LEFT JOIN asp_urun u ON u.ID=fd.urunID
            WHERE {$range('fb.FaturaTarih')} AND fb.FaturaTuru=1 AND $eligibleSale GROUP BY $p";
        $definition=['sql'=>$sql,'params'=>$dateParams(),'columns'=>['Donem'=>'Dönem','Musteri'=>'Aktif Müşteri','Fatura'=>'Fatura','NetTutar'=>'Hasılat','Frekans'=>'Fatura / Müşteri'],'chart'=>['Musteri','NetTutar']];
    }
    if ($id === 'new_vs_returning') {
        $p=$period('fb.FaturaTarih');
        $priorEligible=business_report_author_copy_condition('pfb','pu');
        $sql="SELECT $p Donem,CASE WHEN DATE(fb.FaturaTarih)=DATE(firsts.IlkSatis) THEN 'Yeni' ELSE 'Tekrar' END Tip,
            COUNT(DISTINCT fb.MusteriID) Musteri,COUNT(DISTINCT fb.ID) Fatura,ROUND(SUM($lineTotal),2) NetTutar
            FROM faturadetay fd JOIN faturabaslik fb ON fb.ID=fd.faturaID LEFT JOIN asp_urun u ON u.ID=fd.urunID
            JOIN (SELECT pfb.MusteriID,MIN(pfb.FaturaTarih) IlkSatis FROM faturadetay pfd JOIN faturabaslik pfb ON pfb.ID=pfd.faturaID LEFT JOIN asp_urun pu ON pu.ID=pfd.urunID WHERE pfb.FaturaTuru=1 AND $priorEligible GROUP BY pfb.MusteriID) firsts ON firsts.MusteriID=fb.MusteriID
            WHERE {$range('fb.FaturaTarih')} AND fb.FaturaTuru=1 AND $eligibleSale GROUP BY $p,Tip";
        $definition=['sql'=>$sql,'params'=>$dateParams(),'columns'=>['Donem'=>'Dönem','Tip'=>'Müşteri Tipi','Musteri'=>'Müşteri','Fatura'=>'Fatura','NetTutar'=>'Hasılat'],'chart'=>['Musteri','NetTutar']];
    }
    if ($id === 'customers_at_risk') {
        $sql="SELECT CONCAT(?,' / ',?) Donem,a.ID MusteriID,COALESCE(a.FirmaAd,TRIM(CONCAT_WS(' ',a.Ad,a.Soyad)),CONCAT('#',a.ID)) Musteri,
            MAX(fb.FaturaTarih) SonSatis,DATEDIFF(?,DATE(MAX(fb.FaturaTarih))) GecenGun,COUNT(DISTINCT fb.ID) Fatura,ROUND(SUM($lineTotal),2) NetTutar
            FROM adresdefteri a JOIN faturabaslik fb ON CAST(fb.MusteriID AS UNSIGNED)=a.ID AND fb.FaturaTuru=1
            JOIN faturadetay fd ON fd.faturaID=fb.ID LEFT JOIN asp_urun u ON u.ID=fd.urunID
            WHERE $eligibleSale
            GROUP BY a.ID,Musteri HAVING SonSatis<? AND GecenGun>=90";
        $definition=['sql'=>$sql,'params'=>[$start,$end,$end,$start],'columns'=>['Donem'=>'Dönem','MusteriID'=>'Müşteri ID','Musteri'=>'Müşteri','SonSatis'=>'Son Satış','GecenGun'=>'Geçen Gün','Fatura'=>'Tarihsel Fatura','NetTutar'=>'Tarihsel Hasılat'],'chart'=>['GecenGun','NetTutar']];
    }

    if ($definition === null) throw new InvalidArgumentException('Bilinmeyen iş zekâsı raporu.');
    $definition['money'] = array_values(array_unique([...$money, ...($definition['money'] ?? [])]));
    return $definition;
}

function business_report_run(string $id, string $grouping, array $range, int $page, int $perPage, string $sort, string $direction): array
{
    $catalog = business_report_flat_catalog();
    if (!isset($catalog[$id])) throw new InvalidArgumentException('Bilinmeyen rapor.');
    $grouping = $grouping === 'daily' ? 'daily' : 'monthly';
    $definition = business_report_definition($id, $grouping, $range['start'], $range['end']);
    $columns = $definition['columns'];
    $sortKey = array_search($sort, $columns, true);
    if ($sortKey === false) $sortKey = array_key_first($columns);
    $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
    $page = max(1, $page);
    $perPage = in_array($perPage, [50,100,250,500,1000], true) ? $perPage : 250;
    $offset = ($page - 1) * $perPage;
    $base = $definition['sql'];
    $params = $definition['params'];
    $count = db()->prepare("SELECT COUNT(*) FROM ($base) report_count");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $statement = db()->prepare("SELECT * FROM ($base) report_rows ORDER BY `$sortKey` $direction LIMIT $perPage OFFSET $offset");
    $statement->execute($params);
    $rawRows = $statement->fetchAll();
    $numericNativeTypes = ['TINY','SHORT','LONG','LONGLONG','INT24','DECIMAL','NEWDECIMAL','FLOAT','DOUBLE'];
    $numericKeys = [];
    foreach (array_keys($columns) as $index => $key) {
        $meta = $statement->getColumnMeta($index) ?: [];
        if (in_array(strtoupper((string)($meta['native_type'] ?? '')), $numericNativeTypes, true) && !preg_match('/ID$/i', $key)) {
            $numericKeys[] = $key;
        }
    }
    $totals = [];
    if ($numericKeys !== []) {
        $expressions = array_map(fn(string $key):string=>'SUM(COALESCE(`'.$key.'`,0)) AS `'.$key.'`', $numericKeys);
        $totalsStatement = db()->prepare('SELECT '.implode(',',$expressions)." FROM ($base) report_totals");
        $totalsStatement->execute($params);
        $rawTotals = $totalsStatement->fetch() ?: [];
        foreach ($numericKeys as $key) $totals[$columns[$key]] = $rawTotals[$key] ?? 0;
    }
    $rows = [];
    foreach ($rawRows as $raw) {
        $row = [];
        foreach ($columns as $key => $label) $row[$label] = $raw[$key] ?? null;
        $rows[] = $row;
    }
    $chartRows = array_slice($rows, 0, 40);
    $moneyColumns = [];
    foreach ($definition['money'] as $key) {
        if (isset($columns[$key])) $moneyColumns[] = $columns[$key];
    }
    return [
        'id'=>$id,'title'=>$catalog[$id]['label'],'group'=>$catalog[$id]['group'],'grouping'=>$grouping,'range'=>$range,
        'columns'=>array_values($columns),'rows'=>$rows,'chart_rows'=>$chartRows,
        'totals'=>$totals,'numeric_columns'=>array_values(array_map(fn(string $key):string=>$columns[$key],$numericKeys)),
        'money_columns'=>$moneyColumns,'currency'=>setting_get('default_currency','TL'),
        'chart_series'=>array_values(array_filter(array_map(fn(string $key): ?string => $columns[$key] ?? null, $definition['chart'] ?? []))),
        'page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>max(1,(int)ceil($total/$perPage)),
        'sort'=>$columns[$sortKey],'dir'=>strtolower($direction),
    ];
}
