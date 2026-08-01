<?php
declare(strict_types=1);

const CUP_PLACEHOLDER_EMAIL = 'admin@ciniusyayinlari.com';

function cleanup_cup_normalize(string $value, bool $title = false): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    if ($title) {
        $value = preg_replace('/^\s*\d+\s*[-–—:]\s*/u', '', $value) ?? $value;
        $value = preg_replace('/\s*\([^()]*(?:paket|telifli|premius|poetikus|maksimus|mikro|trend|ekopress)[^()]*\)\s*$/u', '', $value) ?? $value;
    }
    $value = strtr($value, ['ç'=>'c','ğ'=>'g','ı'=>'i','i̇'=>'i','ö'=>'o','ş'=>'s','ü'=>'u','â'=>'a','î'=>'i','û'=>'u']);
    $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function cleanup_cup_tokens(string $normalized): array
{
    $ignored = ['ve'=>true,'ile'=>true,'bir'=>true,'the'=>true];
    return array_values(array_unique(array_filter(explode(' ', $normalized), fn (string $token): bool => strlen($token) >= 3 && !isset($ignored[$token]))));
}

function cleanup_cup_similarity(string $left, string $right): float
{
    if ($left === '' || $right === '') return 0.0;
    if ($left === $right) return 100.0;
    $maxLength = max(strlen($left), strlen($right));
    $levenshtein = $maxLength === 0 ? 100.0 : max(0.0, 100.0 * (1 - levenshtein($left, $right) / $maxLength));
    similar_text($left, $right, $similar);
    $leftTokens = cleanup_cup_tokens($left);
    $rightTokens = cleanup_cup_tokens($right);
    $union = array_values(array_unique([...$leftTokens, ...$rightTokens]));
    $jaccard = $union === [] ? 0.0 : 100.0 * count(array_intersect($leftTokens, $rightTokens)) / count($union);
    return round(max(($levenshtein + $similar) / 2, $jaccard * 0.92), 2);
}

function cleanup_cup_product_date(array $product): ?string
{
    $added = trim((string) ($product['eklemeTarihi'] ?? ''));
    if ($added !== '' && !str_starts_with($added, '0000-00-00')) return substr($added, 0, 19);
    $published = trim((string) ($product['baskitarihi'] ?? ''));
    if ($published === '') return null;
    foreach (['Y-m-d','d.m.Y','d/m/Y','Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $published);
        if ($date !== false) return $date->format('Y-m-d 00:00:00');
    }
    return null;
}

function cleanup_cup_match_rows(): array
{
    static $matches;
    if ($matches !== null) return $matches;

    $cupStatement = cup_db()->prepare("SELECT `ID`,`KitabinAdi`,`YazarinAdi`,`YazarinEpostasi`,`ISBN`,`Paket`,`SozlesmeTarihi`
        FROM `cinius_uretim` WHERE LOWER(TRIM(`YazarinEpostasi`)) = ? ORDER BY `ID` DESC");
    $cupStatement->execute([CUP_PLACEHOLDER_EMAIL]);
    $cupRows = $cupStatement->fetchAll();

    $products = db()->query("SELECT u.`ID`,u.`kitapadi`,u.`isbn`,u.`eklemeTarihi`,u.`baskitarihi`,u.`CinYazarID`,
            a.`FirmaAd`,a.`Ad`,a.`Soyad`,TRIM(a.`eposta`) `eposta`
        FROM `asp_urun` u JOIN `adresdefteri` a ON a.`ID`=u.`CinYazarID`
        WHERE COALESCE(u.`CinYazarID`,0)>0 AND NULLIF(TRIM(a.`eposta`),'') IS NOT NULL
          AND LOWER(TRIM(a.`eposta`))<>" . db()->quote(CUP_PLACEHOLDER_EMAIL))->fetchAll();

    $products = array_values(array_filter($products, fn (array $product): bool => filter_var(trim((string)$product['eposta']), FILTER_VALIDATE_EMAIL) !== false));
    $authorIndex = [];
    $tokenIndex = [];
    $isbnIndex = [];
    foreach ($products as $index => &$product) {
        $names = array_values(array_unique(array_filter([
            cleanup_cup_normalize((string) ($product['FirmaAd'] ?? '')),
            cleanup_cup_normalize(trim((string) ($product['Ad'] ?? '') . ' ' . (string) ($product['Soyad'] ?? ''))),
        ])));
        $product['_authors'] = $names;
        $product['_title'] = cleanup_cup_normalize((string) $product['kitapadi'], true);
        $product['_isbn'] = preg_replace('/\D+/', '', (string) ($product['isbn'] ?? '')) ?? '';
        foreach ($names as $name) {
            $authorIndex[$name][$index] = true;
            foreach (cleanup_cup_tokens($name) as $token) $tokenIndex[$token][$index] = true;
        }
        if ($product['_isbn'] !== '') $isbnIndex[$product['_isbn']][$index] = true;
    }
    unset($product);

    $matches = [];
    foreach ($cupRows as $cup) {
        $cupAuthor = cleanup_cup_normalize((string) $cup['YazarinAdi']);
        $cupTitle = cleanup_cup_normalize((string) $cup['KitabinAdi'], true);
        $cupIsbn = preg_replace('/\D+/', '', (string) ($cup['ISBN'] ?? '')) ?? '';
        if ($cupAuthor === '' || $cupTitle === '') continue;
        $candidateIndexes = $authorIndex[$cupAuthor] ?? [];
        if ($cupIsbn !== '' && isset($isbnIndex[$cupIsbn])) $candidateIndexes += $isbnIndex[$cupIsbn];
        if ($candidateIndexes === []) {
            foreach (cleanup_cup_tokens($cupAuthor) as $token) $candidateIndexes += $tokenIndex[$token] ?? [];
        }
        $ranked = [];
        foreach (array_keys($candidateIndexes) as $index) {
            $product = $products[$index];
            $authorScore = 0.0;
            foreach ($product['_authors'] as $author) $authorScore = max($authorScore, cleanup_cup_similarity($cupAuthor, $author));
            $titleScore = cleanup_cup_similarity($cupTitle, $product['_title']);
            $isbnExact = $cupIsbn !== '' && $cupIsbn === $product['_isbn'];
            $overall = round($authorScore * 0.45 + $titleScore * 0.55, 2);
            if ($isbnExact && $authorScore >= 75 && $titleScore >= 60) $overall = max($overall, 98.0);
            $qualified = ($authorScore >= 84 && $titleScore >= 72 && $overall >= 82)
                || ($isbnExact && $authorScore >= 75 && $titleScore >= 60);
            if (!$qualified) continue;
            $ranked[] = ['product'=>$product,'author_score'=>$authorScore,'title_score'=>$titleScore,'score'=>$overall,'isbn_exact'=>$isbnExact];
        }
        if ($ranked === []) continue;
        usort($ranked, fn (array $a, array $b): int => [$b['score'],$b['title_score'],(int)$b['isbn_exact']] <=> [$a['score'],$a['title_score'],(int)$a['isbn_exact']]);
        $best = $ranked[0];
        $product = $best['product'];
        $nextScore = isset($ranked[1]) ? (float) $ranked[1]['score'] : null;
        $matches[] = [
            'cup_id'=>(int)$cup['ID'], 'cup_title'=>(string)$cup['KitabinAdi'], 'cup_author'=>(string)$cup['YazarinAdi'],
            'cup_isbn'=>(string)$cup['ISBN'], 'cup_package'=>(string)$cup['Paket'], 'cup_contract_date'=>$cup['SozlesmeTarihi'],
            'product_id'=>(int)$product['ID'], 'product_title'=>(string)$product['kitapadi'], 'product_author'=>(string)($product['FirmaAd'] ?: trim($product['Ad'].' '.$product['Soyad'])),
            'product_isbn'=>(string)$product['isbn'], 'author_id'=>(int)$product['CinYazarID'], 'email'=>(string)$product['eposta'],
            'source_date'=>cleanup_cup_product_date($product), 'author_score'=>$best['author_score'], 'title_score'=>$best['title_score'],
            'score'=>$best['score'], 'isbn_exact'=>$best['isbn_exact'], 'ambiguous'=>$nextScore !== null && $best['score'] - $nextScore < 2.0,
        ];
    }
    return $matches;
}

function cleanup_cup_match_page(int $page, int $perPage, string $query = ''): array
{
    $rows = cleanup_cup_match_rows();
    if ($query !== '') {
        $needle = cleanup_cup_normalize($query);
        $rows = array_values(array_filter($rows, function (array $row) use ($needle): bool {
            return str_contains(cleanup_cup_normalize(implode(' ', [$row['cup_id'],$row['cup_title'],$row['cup_author'],$row['product_id'],$row['product_title'],$row['product_author'],$row['email']])), $needle);
        }));
    }
    $total = count($rows);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1,$page),$pages);
    return ['rows'=>array_slice($rows,($page-1)*$perPage,$perPage),'total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>$pages];
}

function cleanup_cup_match_pairs(string $serialized): array
{
    $pairs = [];
    foreach (array_filter(explode(',', $serialized)) as $value) {
        if (!preg_match('/^(\d+):(\d+)$/', trim($value), $match)) continue;
        $pairs[(int)$match[1]] = (int)$match[2];
    }
    if ($pairs === []) throw new InvalidArgumentException('Güncellenecek üretim eşleşmesi seçilmedi.');
    if (count($pairs) > 5000) throw new InvalidArgumentException('Bir işlemde en fazla 5.000 eşleşme seçilebilir.');
    return $pairs;
}

function cleanup_apply_cup_email_matches(string $serialized): array
{
    $requested = cleanup_cup_match_pairs($serialized);
    $valid = [];
    foreach (cleanup_cup_match_rows() as $row) {
        if (($requested[$row['cup_id']] ?? 0) === $row['product_id']) $valid[$row['cup_id']] = $row;
    }
    $result = ['selected_matches'=>count($requested),'updated_emails'=>0,'updated_contract_dates'=>0,'skipped_matches'=>count($requested)-count($valid)];
    if ($valid === []) return $result;
    $connection = cup_db();
    $connection->beginTransaction();
    try {
        $current = $connection->prepare('SELECT `YazarinEpostasi`,`SozlesmeTarihi` FROM `cinius_uretim` WHERE `ID`=? FOR UPDATE');
        $update = $connection->prepare("UPDATE `cinius_uretim` SET `YazarinEpostasi`=?, `SozlesmeTarihi`=CASE WHEN `SozlesmeTarihi` IS NULL THEN ? ELSE `SozlesmeTarihi` END WHERE `ID`=? AND LOWER(TRIM(`YazarinEpostasi`))=?");
        foreach ($valid as $cupId => $row) {
            $current->execute([$cupId]);
            $before = $current->fetch();
            if (!$before || mb_strtolower(trim((string)$before['YazarinEpostasi']),'UTF-8') !== CUP_PLACEHOLDER_EMAIL) { $result['skipped_matches']++; continue; }
            $update->execute([$row['email'],$row['source_date'],$cupId,CUP_PLACEHOLDER_EMAIL]);
            if ($update->rowCount() > 0) {
                $result['updated_emails']++;
                if ($before['SozlesmeTarihi'] === null && $row['source_date'] !== null) $result['updated_contract_dates']++;
            } else $result['skipped_matches']++;
        }
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }
    audit_log('cleanup_cup_email_matches','cup_veri.cinius_uretim',null,$result);
    return $result;
}
