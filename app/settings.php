<?php
declare(strict_types=1);

function ensure_settings_table(): void
{
    static $ensured = false;
    if ($ensured) return;

    db()->exec("CREATE TABLE IF NOT EXISTS `app_settings` (
        `setting_key` varchar(100) NOT NULL,
        `setting_value` varchar(255) NOT NULL,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $statement = db()->prepare('INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_value`) VALUES (?, ?)');
    $statement->execute(['default_currency', 'TL']);
    $ensured = true;
}

function setting_get(string $key, string $default = ''): string
{
    ensure_settings_table();
    $statement = db()->prepare('SELECT `setting_value` FROM `app_settings` WHERE `setting_key` = ? LIMIT 1');
    $statement->execute([$key]);
    $value = $statement->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function setting_set(string $key, string $value): void
{
    ensure_settings_table();
    $statement = db()->prepare('INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)');
    $statement->execute([$key, $value]);
}

function currency_options(): array
{
    return [
        ['value' => 'TL', 'label' => 'TL — Türk Lirası'],
        ['value' => 'USD', 'label' => 'USD — Amerikan Doları'],
        ['value' => 'EUR', 'label' => 'EUR — Euro'],
        ['value' => 'GBP', 'label' => 'GBP — İngiliz Sterlini'],
    ];
}
