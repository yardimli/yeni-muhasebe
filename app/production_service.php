<?php
declare(strict_types=1);

require_once APP_ROOT . '/app/product_metrics.php';

function production_field_groups(): array
{
    return [
        'Kitap ve paket' => [
            'KitabinAdi'=>['label'=>'Kitap adı','type'=>'text','required'=>true],
            'YazarinAdi'=>['label'=>'Yazar adı','type'=>'text'],
            'YazarinEpostasi'=>['label'=>'Yazar e-postası','type'=>'email'],
            'Paket'=>['label'=>'Paket','type'=>'select','options'=>['Premius','Premius Ekopress','Poetikus','Maksimus','Mikro','Telifli','Trend','Özel Paket']],
            'KitabinTuru'=>['label'=>'Kitap türü','type'=>'text'],
            'ISBN'=>['label'=>'ISBN','type'=>'text'],
            'Boyut'=>['label'=>'Boyut','type'=>'text'],
            'Font'=>['label'=>'Font','type'=>'text'],
            'Sayfa'=>['label'=>'Sayfa','type'=>'number'],
            'BaskiAdedi'=>['label'=>'Baskı adedi','type'=>'number'],
            'YazarinTalepEttigiAdet'=>['label'=>'Yazarın talep ettiği adet','type'=>'number'],
            'Etiket'=>['label'=>'Etiket fiyatı','type'=>'money'],
            'SozlesmeTarihi'=>['label'=>'Sözleşme tarihi','type'=>'date'],
        ],
        'Proje takibi' => [
            'Sorumlu'=>['label'=>'Sorumlu','type'=>'text'],
            'OkumaDurum'=>['label'=>'Okuma durumu','type'=>'select','options'=>['Başlanmadı','Devam Ediyor','Tamamlandı','Okunmayacak']],
            'DizgiDurum'=>['label'=>'Dizgi durumu','type'=>'select','options'=>['Başlanmadı','Devam Ediyor','Onay Bekliyor','Tamamlandı','Yapılmayacak']],
            'KapakDurum'=>['label'=>'Kapak durumu','type'=>'select','options'=>['Başlanmadı','Devam Ediyor','Onay Bekliyor','Tamamlandı','Yapılmayacak']],
            'AyracDurum'=>['label'=>'Ayraç durumu','type'=>'text'],
            'PosterDurum'=>['label'=>'Poster durumu','type'=>'text'],
            'KartvizitDurum'=>['label'=>'Kartvizit durumu','type'=>'text'],
            'WebDurum'=>['label'=>'Web durumu','type'=>'text'],
            'DizgiSorumlu'=>['label'=>'Dizgi sorumlusu','type'=>'text'],
            'KapakSorumlu'=>['label'=>'Kapak sorumlusu','type'=>'text'],
            'BaskiyaVerilisTarihi'=>['label'=>'Baskıya veriliş','type'=>'date'],
            'BaskidanGelisTarihi'=>['label'=>'Baskıdan geliş','type'=>'date'],
            'BaskidanGeldi'=>['label'=>'Baskıdan geldi','type'=>'boolean'],
            'passif'=>['label'=>'Pasif kayıt','type'=>'boolean'],
        ],
        'Yazar bilgileri' => [
            'YazarinAdresi'=>['label'=>'Adres','type'=>'textarea'],
            'YazarinTelefonNumarasi'=>['label'=>'Telefon','type'=>'text'],
            'YazarinGSM'=>['label'=>'GSM','type'=>'text'],
            'YazarinFaksNumarasi'=>['label'=>'Doğum tarihi / eski faks alanı','type'=>'text'],
            'KimlikNo'=>['label'=>'Kimlik no','type'=>'text'],
        ],
        'Baskı özellikleri' => [
            'KapakGofreli'=>['label'=>'Kapak gofreli','type'=>'boolean'],
            'LokalLak'=>['label'=>'Lokal lak','type'=>'boolean'],
            'MatSelofon'=>['label'=>'Mat selefon','type'=>'boolean'],
            'Ayrac'=>['label'=>'Ayraç','type'=>'boolean'],
            'Kuse'=>['label'=>'Kuşe','type'=>'boolean'],
            'BirinciHamurKagit'=>['label'=>'Birinci hamur kağıt','type'=>'boolean'],
            'SivamaCilt'=>['label'=>'Sıvama cilt','type'=>'boolean'],
            'Poster'=>['label'=>'Poster','type'=>'boolean'],
            'Yaldiz'=>['label'=>'Yaldız','type'=>'boolean'],
            'yaldizTxt'=>['label'=>'Yaldız açıklaması','type'=>'text'],
            'RenkliForma'=>['label'=>'Renkli forma','type'=>'boolean'],
            'RenkliFormaMetin'=>['label'=>'Renkli forma açıklaması','type'=>'text'],
        ],
        'Ödeme tercihleri' => [
            'OdemeKrediKartiPesin'=>['label'=>'Kredi kartı peşin','type'=>'boolean'],
            'OdemeKrediKarti4Taksit'=>['label'=>'Kredi kartı 4 taksit','type'=>'boolean'],
            'OdemeHavale'=>['label'=>'Havale','type'=>'boolean'],
            'OdemePesin'=>['label'=>'Peşin','type'=>'boolean'],
            'OdemeDiger'=>['label'=>'Diğer ödeme','type'=>'text'],
            'OdemeTamamlandi'=>['label'=>'Ödeme tamamlandı','type'=>'boolean'],
        ],
        'Notlar ve metinler' => [
            'NOTLAR'=>['label'=>'Notlar','type'=>'textarea'],
            'KapakYazisi'=>['label'=>'Kapak yazısı','type'=>'textarea'],
            'Kanca'=>['label'=>'Kanca','type'=>'textarea'],
            'OkumaNotu'=>['label'=>'Hesap / okuma notu','type'=>'textarea'],
            'Yasamoykusu'=>['label'=>'Yaşam öyküsü','type'=>'textarea'],
        ],
    ];
}

function production_columns(): array
{
    static $columns;
    if ($columns === null) {
        $columns = [];
        foreach (cup_db()->query('SHOW COLUMNS FROM `cinius_uretim`')->fetchAll() as $column) {
            $columns[$column['Field']] = $column;
        }
    }
    return $columns;
}

function production_available_field_groups(): array
{
    $columns = production_columns();
    $groups = [];
    foreach (production_field_groups() as $group => $fields) {
        $groups[$group] = array_intersect_key($fields, $columns);
    }
    return array_filter($groups);
}

function production_email(string $email): string
{
    return mb_strtolower(trim($email), 'UTF-8');
}

function production_main_links(string $email): array
{
    $email = production_email($email);
    if ($email === '') return ['accounts'=>[], 'products'=>[]];
    $statement = db()->prepare("SELECT `ID`,`FirmaAd`,`Ad`,`Soyad`,`eposta` FROM `adresdefteri` WHERE LOWER(TRIM(CONVERT(`eposta` USING utf8mb4))) COLLATE utf8mb4_unicode_ci = ? ORDER BY `ID`");
    $statement->execute([$email]);
    $accounts = $statement->fetchAll();
    $accountIds = array_map('intval', array_column($accounts, 'ID'));
    $products = [];
    if ($accountIds !== []) {
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $productStatement = db()->prepare("SELECT `ID`,`isbn`,`kitapadi`,`CinYazarID` FROM `asp_urun` WHERE `CinYazarID` IN ($in) ORDER BY `kitapadi`,`ID`");
        $productStatement->execute($accountIds);
        $products = $productStatement->fetchAll();
        $metrics = product_metrics_for_ids(array_column($products, 'ID'));
        foreach ($products as &$product) {
            $product['metrics'] = $metrics[(int) $product['ID']] ?? ['Satış'=>0,'İade'=>0,'Yazara Verilen'=>0];
        }
        unset($product);
    }
    foreach ($accounts as &$account) {
        $account['name'] = trim((string) ($account['FirmaAd'] ?: trim(($account['Ad'] ?? '') . ' ' . ($account['Soyad'] ?? ''))));
    }
    unset($account);
    return ['accounts'=>$accounts, 'products'=>$products];
}

function production_enrich_link_counts(array &$rows): void
{
    $emails = array_values(array_unique(array_filter(array_map(fn(array $row):string=>production_email((string)($row['YazarinEpostasi']??'')), $rows))));
    $accountsByEmail = [];
    $productCounts = [];
    if ($emails !== []) {
        $in = implode(',', array_fill(0,count($emails),'?'));
        $statement = db()->prepare("SELECT `ID`,LOWER(TRIM(CONVERT(`eposta` USING utf8mb4))) COLLATE utf8mb4_unicode_ci normalized_email FROM `adresdefteri` WHERE LOWER(TRIM(CONVERT(`eposta` USING utf8mb4))) COLLATE utf8mb4_unicode_ci IN ($in)");
        $statement->execute($emails);
        $allAccountIds = [];
        foreach ($statement->fetchAll() as $account) {
            $email = (string)$account['normalized_email'];
            $accountsByEmail[$email][] = (int)$account['ID'];
            $allAccountIds[] = (int)$account['ID'];
        }
        if ($allAccountIds !== []) {
            $accountIn = implode(',',array_fill(0,count($allAccountIds),'?'));
            $products = db()->prepare("SELECT `CinYazarID`,COUNT(*) qty FROM `asp_urun` WHERE `CinYazarID` IN ($accountIn) GROUP BY `CinYazarID`");
            $products->execute($allAccountIds);
            foreach ($products->fetchAll() as $product) $productCounts[(int)$product['CinYazarID']] = (int)$product['qty'];
        }
    }
    foreach ($rows as &$row) {
        $accountIds = $accountsByEmail[production_email((string)($row['YazarinEpostasi']??''))] ?? [];
        $row['CariBaglantisi'] = count($accountIds);
        $row['UrunBaglantisi'] = array_sum(array_map(fn(int $id):int=>$productCounts[$id]??0,$accountIds));
    }
    unset($row);
}

function production_view_condition(string $view): string
{
    return match ($view) {
        'reading' => "COALESCE(p.`passif`,0)=0 AND COALESCE(p.`OkumaDurum`,'') NOT IN ('Tamamlandı','Okunmayacak')",
        'layout' => "COALESCE(p.`passif`,0)=0 AND p.`OkumaDurum`='Tamamlandı' AND COALESCE(p.`DizgiDurum`,'') NOT IN ('Tamamlandı','Yapılmayacak')",
        'cover' => "COALESCE(p.`passif`,0)=0 AND COALESCE(p.`KapakDurum`,'') NOT IN ('Tamamlandı','Yapılmayacak')",
        'printing' => "COALESCE(p.`passif`,0)=0 AND p.`BaskiyaVerilisTarihi` IS NOT NULL AND COALESCE(p.`BaskidanGeldi`,0)=0",
        'completed' => "COALESCE(p.`BaskidanGeldi`,0)<>0",
        'balances' => "COALESCE(p.`passif`,0)=0 AND COALESCE(p.`OdemeTamamlandi`,0)=0
            AND ((SELECT COALESCE(SUM(m.`tutar`),0) FROM `masraflar` m WHERE m.`yazarID`=p.`ID`)
               - (SELECT COALESCE(SUM(o.`tutar`),0) FROM `odemeler` o WHERE o.`yazarID`=p.`ID`)) > 30",
        'active' => "COALESCE(p.`passif`,0)=0",
        default => '1=1',
    };
}

function production_list(int $page, int $perPage, string $query, string $view, string $sort, string $dir): array
{
    $page = max(1, $page);
    $perPage = in_array($perPage, [50,100,250,500,1000], true) ? $perPage : 250;
    $sortColumns = ['ID','KitabinAdi','YazarinAdi','YazarinEpostasi','Paket','SozlesmeTarihi','OkumaDurum','DizgiDurum','KapakDurum','BaskiyaVerilisTarihi'];
    if (!in_array($sort, $sortColumns, true)) $sort = 'ID';
    $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
    $where = [production_view_condition($view)];
    $params = [];
    foreach (database_search_terms($query) as $term) {
        $where[] = '(CAST(p.`ID` AS CHAR) LIKE ? OR p.`KitabinAdi` LIKE ? OR p.`YazarinAdi` LIKE ? OR p.`YazarinEpostasi` LIKE ? OR p.`ISBN` LIKE ? OR p.`Sorumlu` LIKE ?)';
        array_push($params, ...array_fill(0, 6, '%' . $term . '%'));
    }
    $whereSql = ' WHERE ' . implode(' AND ', $where);
    $count = cup_db()->prepare('SELECT COUNT(*) FROM `cinius_uretim` p' . $whereSql);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $sql = "SELECT p.`ID`,p.`KitabinAdi`,p.`YazarinAdi`,p.`YazarinEpostasi`,p.`Paket`,p.`SozlesmeTarihi`,p.`OkumaDurum`,p.`DizgiDurum`,p.`KapakDurum`,p.`BaskiyaVerilisTarihi`,p.`BaskidanGeldi`,
        COALESCE(costs.total,0) AS UretimMaliyeti, COALESCE(payments.total,0) AS AlinanOdeme,
        COALESCE(costs.total,0)-COALESCE(payments.total,0) AS Kalan
        FROM `cinius_uretim` p
        LEFT JOIN (SELECT `yazarID`,SUM(COALESCE(`tutar`,0)) total FROM `masraflar` GROUP BY `yazarID`) costs ON costs.`yazarID`=p.`ID`
        LEFT JOIN (SELECT `yazarID`,SUM(COALESCE(`tutar`,0)) total FROM `odemeler` GROUP BY `yazarID`) payments ON payments.`yazarID`=p.`ID`
        $whereSql ORDER BY p.`$sort` $direction,p.`ID` DESC LIMIT $perPage OFFSET $offset";
    $statement = cup_db()->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
    production_enrich_link_counts($rows);
    return [
        'rows'=>$rows,
        'columns'=>['ID','KitabinAdi','YazarinAdi','YazarinEpostasi','Paket','SozlesmeTarihi','OkumaDurum','DizgiDurum','KapakDurum','UretimMaliyeti','AlinanOdeme','Kalan','CariBaglantisi','UrunBaglantisi'],
        'sortable_columns'=>$sortColumns,
        'money_columns'=>['UretimMaliyeti','AlinanOdeme','Kalan'],
        'currency'=>setting_get('default_currency','TL'),
        'page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>max(1,(int) ceil($total/$perPage)),
        'sort'=>$sort,'dir'=>strtolower($direction),'view'=>$view,
    ];
}

function production_detail(int $id): array
{
    $statement = cup_db()->prepare('SELECT * FROM `cinius_uretim` WHERE `ID`=? LIMIT 1');
    $statement->execute([$id]);
    $record = $statement->fetch();
    if (!$record) throw new RuntimeException('Üretim kaydı bulunamadı.');
    $related = [];
    foreach (['masraflar','odemeler'] as $table) {
        $sub = cup_db()->prepare("SELECT `ID`,`yazarID`,`aciklama`,`tutar`,`tarih` FROM `$table` WHERE `yazarID`=? ORDER BY `tarih`,`ID`");
        $sub->execute([$id]);
        $related[$table] = $sub->fetchAll();
    }
    $cost = array_sum(array_map(fn(array $row): float => (float) $row['tutar'], $related['masraflar']));
    $payment = array_sum(array_map(fn(array $row): float => (float) $row['tutar'], $related['odemeler']));
    return [
        'record'=>$record,
        'groups'=>production_available_field_groups(),
        'expenses'=>$related['masraflar'],'payments'=>$related['odemeler'],
        'summary'=>['cost'=>$cost,'payment'=>$payment,'balance'=>$cost-$payment],
        'main_links'=>production_main_links((string) ($record['YazarinEpostasi'] ?? '')),
        'currency'=>setting_get('default_currency','TL'),
    ];
}

function production_normalized_value(mixed $value, array $column, string $field): mixed
{
    $definition = null;
    foreach (production_available_field_groups() as $fields) if (isset($fields[$field])) $definition = $fields[$field];
    if (($definition['type'] ?? '') === 'boolean') return in_array($value, [true,1,'1','-1','on','Evet'], true) ? -1 : 0;
    if ($value === '') return null;
    if (preg_match('/datetime|timestamp/i', (string) $column['Type'])) return str_replace('T',' ',(string) $value);
    return $value;
}

function production_save(array $input): int
{
    $columns = production_columns();
    $allowed = [];
    foreach (production_available_field_groups() as $fields) $allowed += $fields;
    $id = max(0, (int) ($input['ID'] ?? 0));
    $record = [];
    foreach ($allowed as $field => $_definition) {
        if (array_key_exists($field, $input)) $record[$field] = production_normalized_value($input[$field], $columns[$field], $field);
    }
    if (trim((string) ($record['KitabinAdi'] ?? '')) === '') throw new InvalidArgumentException('Kitap adı zorunludur.');
    if ($record === []) throw new InvalidArgumentException('Kaydedilecek üretim alanı bulunamadı.');
    if ($id > 0) {
        $set = implode(',', array_map(fn(string $field):string=>"`$field`=?", array_keys($record)));
        $statement = cup_db()->prepare("UPDATE `cinius_uretim` SET $set WHERE `ID`=?");
        $statement->execute([...array_values($record),$id]);
    } else {
        $fields = implode(',', array_map(fn(string $field):string=>"`$field`", array_keys($record)));
        $placeholders = implode(',', array_fill(0,count($record),'?'));
        $statement = cup_db()->prepare("INSERT INTO `cinius_uretim` ($fields) VALUES ($placeholders)");
        $statement->execute(array_values($record));
        $id = (int) cup_db()->lastInsertId();
    }
    audit_log('save_production_project','cup_veri.cinius_uretim',$id,['fields'=>array_keys($record)]);
    return $id;
}

function production_save_transaction(string $type, array $input): int
{
    $table = $type === 'payment' ? 'odemeler' : ($type === 'expense' ? 'masraflar' : '');
    if ($table === '') throw new InvalidArgumentException('Geçersiz üretim hareketi.');
    $id = max(0,(int)($input['ID']??0));
    $parentId = max(0,(int)($input['yazarID']??0));
    if ($parentId < 1) throw new InvalidArgumentException('Üretim kaydı seçilmedi.');
    $description = trim((string)($input['aciklama']??''));
    $amount = (float)($input['tutar']??0);
    $date = trim((string)($input['tarih']??date('Y-m-d')));
    if ($id > 0) {
        $statement = cup_db()->prepare("UPDATE `$table` SET `aciklama`=?,`tutar`=?,`tarih`=? WHERE `ID`=? AND `yazarID`=?");
        $statement->execute([$description,$amount,$date,$id,$parentId]);
    } else {
        $statement = cup_db()->prepare("INSERT INTO `$table` (`yazarID`,`aciklama`,`tutar`,`tarih`) VALUES (?,?,?,?)");
        $statement->execute([$parentId,$description,$amount,$date]);
        $id = (int) cup_db()->lastInsertId();
    }
    audit_log('save_production_'.$type,'cup_veri.'.$table,$id,['parent_id'=>$parentId]);
    return $id;
}

function production_balances(int $page, int $perPage, string $query): array
{
    return production_list($page,$perPage,$query,'balances','ID','desc');
}
