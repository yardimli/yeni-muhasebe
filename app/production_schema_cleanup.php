<?php
declare(strict_types=1);

function production_unused_field_names(): array
{
    return [
        'passif',
        'DizgiTamamlandi','DizgiBirinciOnayaGitti','DizgiBirinciOnayGeldi','DizgiDuzeltilerYapıldi',
        'DizgiIkinciOnayaGitti','DizgiIkinciOnayGeldi','DizgiIkinciDuzeltilerYapildi','DizgiBaskiyaHazir',
        'KapakTamamlandi','KapakBirinciOnayaGitti','KapakBirinciOnayGeldi','KapakDuzeltilerYapildi',
        'KapakIkinciOnayaGitti','KapakIkinciOnayGeldi','KapakIkinciDuzeltilerYapildi','KapakBaskiyaHazir',
        'KapakYazisiVerildi','KapakYazisiYazilacak','KapakYazisiOnaylandi',
        'KapakGofreli','MatSelofon','Ozalit','OdemeKrediKarti4Taksit','OdemeKrediKartiPesin',
        'DizgiDosyasiDOC','KapakDosyasi','EkDosya1','KapakTasarimi','ToplamTutar','KimlikNo','OdemeToplam',
        'Kanca','OkumaNotu','Yasamoykusu','OdemeHavale','Film','EkMaliyet','HamMetinDOC','yaldizTxt',
        'OkumaDurum','DizgiDurum','KapakDurum','AyracDurum','PosterDurum','KartvizitDurum','WebDurum',
        'DizgiSorumlu','KapakSorumlu',
    ];
}

function production_unused_value_key(mixed $value): string
{
    if ($value === null) return '<NULL>';
    if ($value === '') return '<EMPTY>';
    return (string)$value;
}

function production_unused_field_profile(): array
{
    $columns = [];
    foreach (cup_db()->query('SHOW COLUMNS FROM `cinius_uretim`')->fetchAll() as $column) $columns[$column['Field']] = $column;
    $configured = production_unused_field_names();
    $existing = array_values(array_filter($configured, fn(string $field): bool => isset($columns[$field])));
    if ($existing === []) {
        $rowCount = (int)cup_db()->query('SELECT COUNT(*) FROM `cinius_uretim`')->fetchColumn();
        return ['rows'=>$rowCount,'recent_rows'=>min(500,$rowCount),'fields'=>[],'configured_fields'=>$configured,'existing_fields'=>[],'safe_fields'=>[]];
    }
    $select = implode(',',array_map(fn(string $field): string=>"`$field`",$existing));
    $rows = cup_db()->query("SELECT $select FROM `cinius_uretim` ORDER BY `ID`")->fetchAll();
    $recent = array_slice($rows,-500);
    $profiles = [];
    $safe = [];
    foreach ($existing as $field) {
        $allCounts = [];
        $recentCounts = [];
        foreach ($rows as $row) {
            $key = production_unused_value_key($row[$field]);
            $allCounts[$key] = ($allCounts[$key] ?? 0) + 1;
        }
        foreach ($recent as $row) {
            $key = production_unused_value_key($row[$field]);
            $recentCounts[$key] = ($recentCounts[$key] ?? 0) + 1;
        }
        arsort($allCounts);
        arsort($recentCounts);
        $dominant = (string)array_key_first($allCounts);
        $recentValue = (string)array_key_first($recentCounts);
        $dominantCount = (int)($allCounts[$dominant] ?? 0);
        $dominantPercent = $rows === [] ? 100.0 : 100*$dominantCount/count($rows);
        $qualifies = $dominantPercent >= 95.0 && count($recentCounts) === 1 && $dominant === $recentValue;
        if ($qualifies) $safe[] = $field;
        $profiles[] = [
            'field'=>$field,'type'=>(string)$columns[$field]['Type'],'dominant_value'=>$dominant,
            'dominant_count'=>$dominantCount,'dominant_percent'=>round($dominantPercent,2),
            'non_dominant_count'=>count($rows)-$dominantCount,'distinct_values'=>count($allCounts),
            'recent_value'=>$recentValue,'recent_distinct_values'=>count($recentCounts),'qualifies'=>$qualifies,
        ];
    }
    return ['rows'=>count($rows),'recent_rows'=>count($recent),'fields'=>$profiles,'configured_fields'=>$configured,'existing_fields'=>$existing,'safe_fields'=>$safe];
}

function production_drop_unused_fields(array $requestedFields): array
{
    $requested = array_values(array_unique(array_map('strval',$requestedFields)));
    if ($requested === []) throw new InvalidArgumentException('Kaldırılacak CUP alanı seçilmedi.');
    $profile = production_unused_field_profile();
    $allowed = array_flip($profile['safe_fields']);
    $selected = array_values(array_filter($requested,fn(string $field): bool=>isset($allowed[$field])));
    if ($selected === []) throw new RuntimeException('Seçilen alanlardan hiçbiri artık %95 / son 500 sabit değer kuralını karşılamıyor.');
    $drops = implode(', ',array_map(fn(string $field): string=>"DROP COLUMN `$field`",$selected));
    cup_db()->exec("ALTER TABLE `cinius_uretim` $drops");
    $result = ['selected_fields'=>count($requested),'removed_cup_fields'=>count($selected),'skipped_cup_fields'=>count($requested)-count($selected)];
    audit_log('cleanup_cup_unused_fields','cup_veri.cinius_uretim',null,['fields'=>$selected,'counts'=>$result]);
    return $result;
}
