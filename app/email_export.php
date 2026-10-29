<?php
declare(strict_types=1);

function newsletter_rows(iterable $records): Generator
{
    $seen = [];
    foreach ($records as $record) {
        $name = trim(preg_replace('/[\r\n\t]+/u', ' ', (string)$record['name']) ?? '');
        // Neutralize spreadsheet formulas while keeping CSV readable by newsletter importers.
        if (preg_match('/^[=+@-]/', $name)) $name = "'" . $name;
        foreach (preg_split('/[;,\s]+/u', trim((string)$record['email']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $email) {
            $email = strtolower($email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $email === 'admin@ciniusyayinlari.com' || isset($seen[$email])) continue;
            $seen[$email] = true;
            yield [$name, $email];
        }
    }
}
