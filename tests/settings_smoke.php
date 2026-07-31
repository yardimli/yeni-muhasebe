<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$table = db()->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_settings'")->fetchColumn();
if ((int) $table !== 1) throw new RuntimeException('app_settings was not created.');

$currency = setting_get('default_currency', '');
if (!in_array($currency, array_column(currency_options(), 'value'), true)) {
    throw new RuntimeException('Default currency is missing or invalid.');
}

$columns = db()->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_settings' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
foreach (['setting_key', 'setting_value', 'updated_at'] as $column) {
    if (!in_array($column, $columns, true)) throw new RuntimeException("Settings column is missing: $column");
}

echo "Settings smoke test passed with currency $currency.\n";
