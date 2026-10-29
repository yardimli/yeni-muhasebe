<?php
declare(strict_types=1);

$javascript = file_get_contents(__DIR__ . '/../public/assets/app.js');

foreach ([
    'const openPrimaryRow = (row, rowId) =>',
    "const actionsForRow = row =>",
    "return [primary, ...relations]",
    'tableRow.ondblclick = () => openPrimaryRow',
    'if (item.primary)',
    'data-production-row-action',
    "label:'WebCin Kaydını Aç'",
    "accounts.map(account =>",
    "openEditor('adresdefteri', 'Cari Kartlar', item.accountId, null)",
] as $contract) {
    if (!str_contains($javascript, $contract)) throw new RuntimeException("Row primary-action contract is missing: $contract");
}

echo "Row primary-action UI contract smoke test passed.\n";
