<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
$schema = require APP_ROOT . '/app/schema.php';
$businessReports = require APP_ROOT . '/app/business_reports.php';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf($_POST['_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'setup') {
            if (($_POST['password'] ?? '') !== ($_POST['password_confirmation'] ?? '')) {
                throw new InvalidArgumentException('Passwords do not match.');
            }
            create_initial_user((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
            attempt_login((string) $_POST['username'], (string) $_POST['password']);
            header('Location: ./');
            exit;
        }
        if ($action === 'login') {
            if (attempt_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                header('Location: ./');
                exit;
            }
            $error = 'Username or password is incorrect.';
        }
        if ($action === 'logout') {
            audit_log('logout', null, null, []);
            $_SESSION = [];
            session_destroy();
            header('Location: ./');
            exit;
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$groups = [];
foreach ($schema as $table => $config) {
    $groups[$config['group']][$table] = $config;
}
$user = current_user();
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title><?= h(env_value('APP_NAME', 'Cinius Muhasebe')) ?></title>
    <link rel="stylesheet" href="assets/app.css">
    <style>.report-submenu{display:none;position:absolute;left:100%;top:-.25rem;min-width:18rem;border:1px solid #475569;border-radius:.25rem;background:#1e293b;padding:.25rem 0;box-shadow:0 20px 25px -5px rgba(0,0,0,.25)}.report-group:hover>.report-submenu{display:block}</style>
</head>
<body class="h-screen overflow-hidden bg-slate-900 font-sans text-slate-900">
<?php if ($user === null): ?>
    <main class="flex min-h-screen items-center justify-center bg-gradient-to-br from-slate-950 via-slate-900 to-blue-950 p-6">
        <section class="w-full max-w-md rounded-2xl border border-white/10 bg-white p-8 shadow-2xl">
            <div class="mb-7">
                <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-blue-600 text-xl font-bold text-white">CM</div>
                <h1 class="text-2xl font-bold text-slate-900"><?= app_is_configured() ? 'Giriş Yap' : 'İlk Yönetici Hesabı' ?></h1>
                <p class="mt-2 text-sm text-slate-500"><?= app_is_configured() ? 'Muhasebe masaüstüne erişmek için oturum açın.' : 'Uygulama için ilk yönetici hesabını güvenli biçimde oluşturun.' ?></p>
            </div>
            <?php if ($error !== ''): ?><div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"><?= h($error) ?></div><?php endif; ?>
            <form method="post" class="space-y-4">
                <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= app_is_configured() ? 'login' : 'setup' ?>">
                <label class="block"><span class="mb-1 block text-sm font-medium">Kullanıcı adı</span><input class="form-input" name="username" required autofocus autocomplete="username"></label>
                <label class="block"><span class="mb-1 block text-sm font-medium">Şifre</span><input class="form-input" type="password" name="password" required minlength="<?= app_is_configured() ? 1 : 12 ?>" autocomplete="<?= app_is_configured() ? 'current-password' : 'new-password' ?>"></label>
                <?php if (!app_is_configured()): ?><label class="block"><span class="mb-1 block text-sm font-medium">Şifreyi doğrula</span><input class="form-input" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label><?php endif; ?>
                <button class="w-full rounded-lg bg-blue-600 px-4 py-2.5 font-semibold text-white hover:bg-blue-700"><?= app_is_configured() ? 'Giriş Yap' : 'Hesabı Oluştur' ?></button>
            </form>
        </section>
    </main>
<?php else: ?>
    <div class="flex h-screen flex-col">
        <header class="relative z-[9000] flex h-10 shrink-0 items-stretch border-b border-slate-700 bg-slate-900 text-slate-100 shadow-lg">
            <div class="flex items-center bg-blue-700 px-4 text-sm font-bold">Cinius Muhasebe</div>
            <nav class="flex items-stretch">
                <?php foreach (menu_catalog() as $group => $items):
                    $visible = array_filter($items, fn($item, $key) => can_access($key), ARRAY_FILTER_USE_BOTH);
                    if (!$visible) continue;
                    $sections = [];
                    foreach ($visible as $key => $item) $sections[$item['subgroup'] ?? ''][$key] = $item;
                ?>
                    <div class="group relative flex items-center px-3 text-sm hover:bg-slate-700">
                        <button type="button"><?= h($group) ?></button>
                        <div class="menu-panel">
                            <?php foreach ($sections as $section => $entries): ?>
                                <?php if ($section !== ''): ?>
                                    <div class="report-group relative">
                                        <button type="button" class="flex w-full items-center justify-between whitespace-nowrap px-4 py-2 text-left text-sm hover:bg-blue-600"><span><?= h($section) ?></span><span class="ml-4">›</span></button>
                                        <div class="report-submenu">
                                <?php endif; ?>
                                <?php foreach ($entries as $key => $item): $tag = $item['attribute'] === 'href' ? 'a' : 'button'; ?>
                                    <<?= $tag ?> <?= $tag === 'button' ? 'type="button"' : '' ?> <?= h($item['attribute']) ?>="<?= h($item['value']) ?>" data-label="<?= h($item['label']) ?>" class="block w-full whitespace-nowrap px-4 py-2 text-left text-sm hover:bg-blue-600"><?= h($item['label']) ?></<?= $tag ?>>
                                <?php endforeach; ?>
                                <?php if ($section !== ''): ?></div></div><?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </nav>
            <div class="ml-auto flex items-center gap-4 px-3 text-xs">
                <span><?= h($user['username']) ?></span>
                <span id="desktop-clock" class="tabular-nums text-slate-300"></span>
                <form method="post"><input type="hidden" name="_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button class="rounded bg-red-700 px-2 py-1 hover:bg-red-600">Çıkış</button></form>
            </div>
        </header>
        <main id="desktop" data-user-id="<?= h((string) $user['id']) ?>" class="relative flex-1 overflow-hidden bg-gradient-to-br from-slate-700 via-slate-800 to-slate-950">
            <div class="pointer-events-none absolute inset-0 flex items-center justify-center opacity-[0.035]"><span class="text-[10rem] font-black text-white">CM</span></div>
            <div id="empty-desktop" class="pointer-events-none absolute inset-0 flex items-center justify-center text-center text-slate-400">
                <div><div class="text-lg font-medium">Muhasebe Masaüstü</div><div class="mt-1 text-sm">Bir bölüm açmak için üst menüyü kullanın.</div></div>
            </div>
        </main>
        <footer id="taskbar" class="z-[9000] flex h-10 shrink-0 items-center gap-1 overflow-x-auto border-t border-slate-600 bg-slate-900 px-2 text-white"></footer>
    </div>
    <div id="toast-region" class="fixed bottom-14 right-4 z-[20000] flex w-80 flex-col gap-2"></div>
    <script id="access-permissions" type="application/json"><?= json_encode(array_values(array_filter(array_merge(...array_values(array_map('array_keys', menu_catalog()))), 'can_access')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script src="assets/app.js?v=<?= (int) (@filemtime(__DIR__ . '/assets/app.js') ?: time()) ?>" defer></script>
<?php endif; ?>
</body>
</html>
