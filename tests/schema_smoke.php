<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
$schema = require APP_ROOT . '/app/schema.php';
$errors = [];

foreach ($schema as $table => $config) {
    try {
        $columns = db()->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $exception) {
        $errors[] = "$table cannot be read: {$exception->getMessage()}";
        continue;
    }
    $known = array_flip($columns);
    foreach (array_merge($config['list'] ?? [], $config['search'] ?? [], array_keys($config['relations'] ?? [])) as $column) {
        if (!isset($known[$column])) {
            $errors[] = "$table.$column is configured but does not exist.";
        }
    }
    foreach ($config['relations'] ?? [] as $column => [$target, $label]) {
        if (!isset($schema[$target])) {
            $errors[] = "$table.$column targets unavailable table $target.";
            continue;
        }
        $targetColumns = db()->query('SHOW COLUMNS FROM `' . $target . '`')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('ID', $targetColumns, true) || !in_array($label, $targetColumns, true)) {
            $errors[] = "$table.$column lookup $target.$label is invalid.";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

printf("Schema smoke test passed: %d tables configured.\n", count($schema));

