<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$query = hex2bin('4179c59f65204b617961'); // Ayşe Kaya in UTF-8, kept shell-encoding independent.
$terms = database_search_terms($query);
if ($terms !== [hex2bin('4179c59f65'), 'Kaya']) {
    throw new RuntimeException('Name search was not split into independent terms.');
}

$columns = ['FirmaAd','Ad','Soyad','eposta','VergiNo'];
$where = [];
$params = [];
foreach ($terms as $term) {
    $where[] = '(' . implode(' OR ', array_map(fn (string $column): string => "`$column` LIKE ?", $columns)) . ')';
    foreach ($columns as $_) $params[] = '%' . $term . '%';
}
$statement = db()->prepare('SELECT `ID` FROM `adresdefteri` WHERE ' . implode(' AND ', $where));
$statement->execute($params);
$ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
if (!in_array(3178, $ids, true)) {
    throw new RuntimeException('Tokenized name search did not find cari record #3178.');
}

echo "Tokenized cari name search found record #3178.\n";
