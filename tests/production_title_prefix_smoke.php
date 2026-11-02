<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
require APP_ROOT . '/app/production_service.php';

$cases = [
    ['Sıla', 9393, '9393 - Sıla'],
    ['  Sıla  ', 9393, '9393 - Sıla'],
    ['9393 - Sıla', 9393, '9393 - Sıla'],
    ['9393-Sıla', 9393, '9393-Sıla'],
    ['9393 – Sıla', 9393, '9393 – Sıla'],
    ['9393 Sıla', 9393, '9393 Sıla'],
    [' 9393 - Sıla', 9393, ' 9393 - Sıla'],
    ['93930 - Başka kitap', 9393, '9393 - 93930 - Başka kitap'],
    ['1984', 9393, '9393 - 1984'],
    ['Kitap 9393', 9393, '9393 - Kitap 9393'],
];
foreach ($cases as [$title, $id, $expected]) {
    $actual = production_title_with_id($title, $id);
    if ($actual !== $expected) throw new RuntimeException('Unexpected prefix for: ' . $title);
    if (production_title_with_id($actual, $id) !== $actual) throw new RuntimeException('Repeated save duplicated the prefix.');
}
echo "Production title prefix and repeated-save checks passed.\n";
