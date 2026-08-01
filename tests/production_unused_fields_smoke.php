<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/app/production_schema_cleanup.php';
require APP_ROOT . '/app/production_service.php';

$configured = production_unused_field_names();
if (count($configured) !== 49 || count(array_unique($configured)) !== 49) throw new RuntimeException('Expected 49 unique CUP cleanup fields.');
$profile = production_unused_field_profile();
if (array_diff($profile['existing_fields'],$profile['safe_fields']) !== []) throw new RuntimeException('An existing configured CUP field no longer satisfies the cleanup rule.');
foreach ($profile['fields'] as $field) {
    if ((float)$field['dominant_percent'] < 95 || (int)$field['recent_distinct_values'] !== 1 || !$field['qualifies']) throw new RuntimeException("CUP field profile is unsafe: {$field['field']}");
}

$groups = production_available_field_groups();
$visibleFields = [];
foreach ($groups as $fields) array_push($visibleFields,...array_keys($fields));
$exposed = array_values(array_intersect($configured,$visibleFields));
if ($exposed !== []) throw new RuntimeException('Unused CUP fields remain editable: ' . implode(', ',$exposed));

$list = production_list(1,50,'','all','ID','desc');
$listed = array_values(array_intersect($configured,$list['columns']));
if ($listed !== []) throw new RuntimeException('Unused CUP fields remain listed: ' . implode(', ',$listed));

$cleanupUi = file_get_contents(APP_ROOT . '/public/cleanup.php');
foreach (['cup_fields','cleanup_cup_unused_fields','Uygulama moduna geç ve seçimi aç','Seçili CUP alanlarını kaldır'] as $contract) {
    if (!str_contains($cleanupUi,$contract)) throw new RuntimeException("CUP field cleanup UI contract is missing: $contract");
}

echo json_encode(['ok'=>true,'configured_fields'=>count($configured),'existing_fields'=>count($profile['existing_fields']),'safe_fields'=>count($profile['safe_fields']),'visible_editor_fields'=>count($visibleFields)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
