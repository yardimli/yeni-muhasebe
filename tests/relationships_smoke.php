<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
$schema = require APP_ROOT . '/app/schema.php';
$relationships = require APP_ROOT . '/app/relationships.php';
$errors = [];
$actionCount = 0;

foreach ($relationships as $source => $actions) {
    if (!isset($schema[$source])) {
        $errors[] = "Unknown source table: $source";
        continue;
    }
    $sourceColumns = array_flip(db()->query("SHOW COLUMNS FROM `$source`")->fetchAll(PDO::FETCH_COLUMN));
    foreach ($actions as $action) {
        $actionCount++;
        $value = $action['value'] ?? 'ID';
        if (!isset($sourceColumns[$value])) {
            $errors[] = "$source action {$action['label']} uses missing value field $value";
        }
        if (isset($action['target'])) {
            $target = $action['target'];
            $filter = $action['filter'] ?? 'ID';
            if (!isset($schema[$target])) {
                $errors[] = "$source action {$action['label']} targets unknown table $target";
                continue;
            }
            $targetColumns = db()->query("SHOW COLUMNS FROM `$target`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array($filter, $targetColumns, true)) {
                $errors[] = "$source action {$action['label']} filters missing field $target.$filter";
            }
        } elseif (!in_array($action['report'] ?? '', ['product_sales', 'author_sales'], true)) {
            $errors[] = "$source action {$action['label']} has an unknown report";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

printf("Relationship smoke test passed: %d row actions across %d tables.\n", $actionCount, count($relationships));

