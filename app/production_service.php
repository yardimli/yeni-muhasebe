<?php
declare(strict_types=1);

require_once APP_ROOT . '/app/product_metrics.php';
require_once APP_ROOT . '/app/production_schema_cleanup.php';

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
            'BaskiyaVerilisTarihi'=>['label'=>'Baskıya veriliş','type'=>'date'],
            'BaskidanGelisTarihi'=>['label'=>'Baskıdan geliş','type'=>'date'],
            'BaskidanGeldi'=>['label'=>'Baskıdan geldi','type'=>'boolean'],
        ],
        'Yazar bilgileri' => [
            'YazarinAdresi'=>['label'=>'Adres','type'=>'textarea'],
            'YazarinTelefonNumarasi'=>['label'=>'Telefon','type'=>'text'],
            'YazarinGSM'=>['label'=>'GSM','type'=>'text'],
            'YazarinFaksNumarasi'=>['label'=>'Doğum tarihi / eski faks alanı','type'=>'text'],
        ],
        'Baskı özellikleri' => [
            'LokalLak'=>['label'=>'Lokal lak','type'=>'boolean'],
            'Ayrac'=>['label'=>'Ayraç','type'=>'boolean'],
            'Kuse'=>['label'=>'Kuşe','type'=>'boolean'],
            'BirinciHamurKagit'=>['label'=>'Birinci hamur kağıt','type'=>'boolean'],
            'SivamaCilt'=>['label'=>'Sıvama cilt','type'=>'boolean'],
            'Poster'=>['label'=>'Poster','type'=>'boolean'],
            'Yaldiz'=>['label'=>'Yaldız','type'=>'boolean'],
            'RenkliForma'=>['label'=>'Renkli forma','type'=>'boolean'],
            'RenkliFormaMetin'=>['label'=>'Renkli forma açıklaması','type'=>'text'],
        ],
        'Ödeme tercihleri' => [
            'OdemePesin'=>['label'=>'Peşin','type'=>'boolean'],
            'OdemeDiger'=>['label'=>'Diğer ödeme','type'=>'text'],
            'OdemeTamamlandi'=>['label'=>'Ödeme tamamlandı','type'=>'boolean'],
        ],
        'Notlar ve metinler' => [
            'NOTLAR'=>['label'=>'Notlar','type'=>'textarea'],
            'KapakYazisi'=>['label'=>'Kapak yazısı','type'=>'textarea'],
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
    $removed = array_flip(production_unused_field_names());
    $groups = [];
    foreach (production_field_groups() as $group => $fields) {
        $groups[$group] = array_diff_key(array_intersect_key($fields, $columns),$removed);
    }
    return array_filter($groups);
}

function production_email(string $email): string
{
    return mb_strtolower(trim($email), 'UTF-8');
}

function production_email_is_placeholder(string $email): bool
{
    return production_email($email) === 'admin@ciniusyayinlari.com';
}

function production_main_links(string $email): array
{
    $email = production_email($email);
    if ($email === '' || production_email_is_placeholder($email)) return ['accounts'=>[], 'products'=>[]];
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
    $emails = array_values(array_unique(array_filter(
        array_map(fn(array $row):string=>production_email((string)($row['YazarinEpostasi']??'')), $rows),
        fn(string $email):bool=>$email !== '' && !production_email_is_placeholder($email)
    )));
    $accountsByEmail = [];
    $productCounts = [];
    if ($emails !== []) {
        $in = implode(',', array_fill(0,count($emails),'?'));
        $statement = db()->prepare("SELECT `ID`,`FirmaAd`,`Ad`,`Soyad`,LOWER(TRIM(CONVERT(`eposta` USING utf8mb4))) COLLATE utf8mb4_unicode_ci normalized_email FROM `adresdefteri` WHERE LOWER(TRIM(CONVERT(`eposta` USING utf8mb4))) COLLATE utf8mb4_unicode_ci IN ($in)");
        $statement->execute($emails);
        $allAccountIds = [];
        foreach ($statement->fetchAll() as $account) {
            $email = (string)$account['normalized_email'];
            $name = trim((string)($account['FirmaAd'] ?: trim((string)($account['Ad'] ?? '') . ' ' . (string)($account['Soyad'] ?? ''))));
            $accountsByEmail[$email][] = ['ID'=>(int)$account['ID'], 'name'=>$name];
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
        $accounts = $accountsByEmail[production_email((string)($row['YazarinEpostasi']??''))] ?? [];
        $accountIds = array_map(static fn(array $account):int=>(int)$account['ID'], $accounts);
        $row['__accounts'] = $accounts;
        $row['CariBaglantisi'] = count($accountIds);
        $row['UrunBaglantisi'] = array_sum(array_map(fn(int $id):int=>$productCounts[$id]??0,$accountIds));
    }
    unset($row);
}

function production_view_condition(string $view): string
{
    return match ($view) {
        'balances' => "COALESCE(p.`OdemeTamamlandi`,0)=0
            AND ((SELECT COALESCE(SUM(m.`tutar`),0) FROM `masraflar` m WHERE m.`yazarID`=p.`ID`)
               - (SELECT COALESCE(SUM(o.`tutar`),0) FROM `odemeler` o WHERE o.`yazarID`=p.`ID`)) > 30",
        'active' => '1=1',
        default => '1=1',
    };
}

function production_report_range(string $preset, ?string $customStart, ?string $customEnd): array
{
    $today = new DateTimeImmutable('today');
    $preset = in_array($preset, ['current_month','last_month','last_3_months','this_year','last_year','custom'], true) ? $preset : 'this_year';
    [$start, $end] = match ($preset) {
        'current_month' => [$today->modify('first day of this month'), $today],
        'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
        'last_3_months' => [$today->modify('first day of this month')->modify('-2 months'), $today],
        'last_year' => [$today->modify('first day of january')->modify('-1 year'), $today->modify('last day of december')->modify('-1 year')],
        'custom' => [null, null],
        default => [$today->modify('first day of january'), $today],
    };
    if ($preset === 'custom') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$customStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$customEnd)) {
            throw new InvalidArgumentException('Başlangıç ve bitiş tarihlerini seçin.');
        }
        $start = new DateTimeImmutable((string)$customStart);
        $end = new DateTimeImmutable((string)$customEnd);
        if ($start > $end) throw new InvalidArgumentException('Başlangıç tarihi bitiş tarihinden sonra olamaz.');
    }
    return ['preset'=>$preset, 'start'=>$start->format('Y-m-d'), 'end'=>$end->format('Y-m-d')];
}

function production_analytics(string $grouping, ?string $rangeStart = null, ?string $rangeEnd = null): array
{
    $grouping = $grouping === 'yearly' ? 'yearly' : 'monthly';
    $today = new DateTimeImmutable('today');
    $start = $rangeStart ?: ($grouping === 'yearly'
        ? $today->modify('first day of january')->modify('-9 years')->format('Y-m-d')
        : $today->modify('first day of this month')->modify('-23 months')->format('Y-m-d'));
    $end = $rangeEnd ?: $today->format('Y-m-d');
    $statement = cup_db()->prepare("SELECT `ID`,`SozlesmeTarihi`,`YazarinEpostasi`,`Paket` FROM `cinius_uretim`
        WHERE `SozlesmeTarihi` IS NOT NULL AND `SozlesmeTarihi`<DATE_ADD(?,INTERVAL 1 DAY)
        ORDER BY `SozlesmeTarihi`,`ID`");
    $statement->execute([$end]);
    $seenAuthors = [];
    $trendBuckets = [];
    $packageBuckets = [];
    foreach ($statement->fetchAll() as $project) {
        $date = substr((string)$project['SozlesmeTarihi'],0,10);
        $email = production_email((string)$project['YazarinEpostasi']);
        $validEmail = $email !== '' && $email !== 'admin@ciniusyayinlari.com';
        $wasSeen = $validEmail && isset($seenAuthors[$email]);
        if ($date >= $start) {
            $period = $grouping === 'yearly' ? substr($date,0,4) : substr($date,0,7);
            if ($validEmail) {
                $trendBuckets[$period] ??= ['projects'=>0,'authors'=>[],'returning'=>[]];
                $trendBuckets[$period]['projects']++;
                $trendBuckets[$period]['authors'][$email] = true;
                if ($wasSeen) $trendBuckets[$period]['returning'][$email] = true;
            }
            $package = trim((string)$project['Paket']) ?: 'Belirtilmemiş';
            $packageKey = $period . "\0" . $package;
            $packageBuckets[$packageKey] ??= ['period'=>$period,'package'=>$package,'projects'=>0,'authors'=>[]];
            $packageBuckets[$packageKey]['projects']++;
            if ($validEmail) $packageBuckets[$packageKey]['authors'][$email] = true;
        }
        if ($validEmail) $seenAuthors[$email] = true;
    }
    ksort($trendBuckets);
    ksort($packageBuckets);
    $trend = [];
    foreach ($trendBuckets as $period => $bucket) {
        $authors = count($bucket['authors']);
        $returning = count($bucket['returning']);
        $trend[] = ['Donem'=>$period,'Proje'=>$bucket['projects'],'Yazar'=>$authors,'GeriDonenYazar'=>$returning,'GeriDonusOrani'=>$authors > 0 ? round(100*$returning/$authors,1) : 0];
    }
    $packages = array_map(fn(array $bucket): array => ['Donem'=>$bucket['period'],'Paket'=>$bucket['package'],'Proje'=>$bucket['projects'],'Yazar'=>count($bucket['authors'])], array_values($packageBuckets));

    return [
        'grouping'=>$grouping,'start'=>$start,'end'=>$end,
        'returning_authors'=>$trend,
        'packages'=>$packages,
    ];
}

function production_report_catalog(): array
{
    return [
        'returning_authors' => 'Geri Dönen Yazarlar',
        'package_distribution' => 'Paket Dağılımı',
    ];
}

function production_report_run(string $report, string $grouping, array $range, int $page, int $perPage, string $sort, string $direction): array
{
    $catalog = production_report_catalog();
    if (!isset($catalog[$report])) throw new InvalidArgumentException('Bilinmeyen üretim raporu.');
    $grouping = $grouping === 'yearly' ? 'yearly' : 'monthly';
    $analytics = production_analytics($grouping, (string)$range['start'], (string)$range['end']);
    if ($report === 'returning_authors') {
        $sourceRows = $analytics['returning_authors'];
        $mapping = ['Dönem'=>'Donem','Proje'=>'Proje','Yazar'=>'Yazar','Geri Dönen Yazar'=>'GeriDonenYazar','Geri Dönüş Oranı %'=>'GeriDonusOrani'];
        $chartSeries = ['Proje','Yazar','Geri Dönen Yazar'];
    } else {
        $sourceRows = $analytics['packages'];
        $mapping = ['Dönem'=>'Donem','Paket'=>'Paket','Proje'=>'Proje','Yazar'=>'Yazar'];
        $chartSeries = ['Proje','Yazar'];
    }
    $columns = array_keys($mapping);
    if (!isset($mapping[$sort])) $sort = 'Dönem';
    $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
    $sortKey = $mapping[$sort];
    usort($sourceRows, static function (array $left, array $right) use ($sortKey, $direction): int {
        $a = $left[$sortKey] ?? null;
        $b = $right[$sortKey] ?? null;
        $comparison = is_numeric($a) && is_numeric($b) ? ((float)$a <=> (float)$b) : strnatcasecmp((string)$a, (string)$b);
        if ($comparison === 0) $comparison = strnatcasecmp((string)($left['Donem'] ?? ''), (string)($right['Donem'] ?? ''));
        return $direction === 'desc' ? -$comparison : $comparison;
    });
    $displayRows = array_map(static function (array $row) use ($mapping): array {
        $display = [];
        foreach ($mapping as $label => $key) $display[$label] = $row[$key] ?? null;
        return $display;
    }, $sourceRows);
    $page = max(1, $page);
    $perPage = in_array($perPage, [50,100,250,500,1000], true) ? $perPage : 250;
    $total = count($displayRows);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $chartRows = array_slice($displayRows, 0, 40);
    $numericColumns = [];
    $totals = [];
    foreach ($columns as $column) {
        $values = array_column($displayRows,$column);
        if ($values !== [] && count(array_filter($values,'is_numeric')) === count($values)) {
            $numericColumns[] = $column;
            $totals[$column] = array_sum(array_map('floatval',$values));
        }
    }
    return [
        'title'=>$catalog[$report], 'report'=>$report, 'grouping'=>$grouping, 'range'=>$range,
        'columns'=>$columns, 'rows'=>array_slice($displayRows, ($page - 1) * $perPage, $perPage),
        'chart_rows'=>$chartRows, 'chart_series'=>$chartSeries, 'money_columns'=>[],
        'totals'=>$totals,'numeric_columns'=>$numericColumns,
        'page'=>$page, 'per_page'=>$perPage, 'total'=>$total, 'pages'=>$pages,
        'sort'=>$sort, 'dir'=>$direction,
    ];
}

function production_list(int $page, int $perPage, string $query, string $view, string $sort, string $dir): array
{
    $page = max(1, $page);
    $perPage = in_array($perPage, [50,100,250,500,1000], true) ? $perPage : 250;
    $sortColumns = ['ID','KitabinAdi','YazarinAdi','YazarinEpostasi','Paket','SozlesmeTarihi','BaskiyaVerilisTarihi'];
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
    $sql = "SELECT p.`ID`,p.`KitabinAdi`,p.`YazarinAdi`,p.`YazarinEpostasi`,p.`Paket`,p.`SozlesmeTarihi`,p.`BaskiyaVerilisTarihi`,p.`BaskidanGeldi`,
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
    foreach ($rows as &$row) {
        if (production_email_is_placeholder((string)($row['YazarinEpostasi'] ?? ''))) $row['YazarinEpostasi'] = 'Belirtilmemiş';
    }
    unset($row);
    return [
        'rows'=>$rows,
        'columns'=>['ID','KitabinAdi','YazarinAdi','YazarinEpostasi','Paket','SozlesmeTarihi','UretimMaliyeti','AlinanOdeme','Kalan','CariBaglantisi','UrunBaglantisi'],
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
