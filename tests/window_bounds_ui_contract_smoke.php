<?php
declare(strict_types=1);

$javascript = file_get_contents(__DIR__ . '/../public/assets/app.js');
foreach (['fitRectInsideDesktop', 'desktop.clientHeight', 'desktopHeight - height', 'applyStoredWindowLayout', 'fitWindowInsideDesktop'] as $contract) {
    if (!str_contains($javascript, $contract)) throw new RuntimeException("Window bounds contract is missing: $contract");
}

echo "Window bounds UI contract smoke test passed.\n";
