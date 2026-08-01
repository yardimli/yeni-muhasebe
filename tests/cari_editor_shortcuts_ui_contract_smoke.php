<?php
declare(strict_types=1);

$api = file_get_contents(__DIR__ . '/../public/api.php');
$javascript = file_get_contents(__DIR__ . '/../public/assets/app.js');

foreach (['$editorActions = []', "'row_actions' => \$editorActions"] as $contract) {
    if (!str_contains($api, $contract)) throw new RuntimeException("Editor action metadata contract is missing: $contract");
}
foreach (["table === 'adresdefteri'", 'data-editor-shortcut', 'Bağlantılar', 'openReport(action.report', 'openTable(action.target'] as $contract) {
    if (!str_contains($javascript, $contract)) throw new RuntimeException("Cari editor shortcut contract is missing: $contract");
}

echo "Cari editor shortcut UI contract smoke test passed.\n";
