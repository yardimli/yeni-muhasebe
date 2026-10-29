<?php
declare(strict_types=1);

function menu_catalog(): array
{
    static $menus;
    if ($menus !== null) return $menus;
    $item = fn($label, $attribute, $value = '') => compact('label', 'attribute', 'value');
    $menus = ['WebCin' => [
        'production:all' => $item('WebCin Projeleri', 'data-open-production-list', 'all'),
        'production:new' => $item('Yeni WebCin Kaydı', 'data-open-production-new'),
        'production:balances' => $item('WebCin Bakiyeleri', 'data-open-production-balances'),
        'production:report:returning_authors' => $item('Geri Dönen Yazarlar', 'data-open-production-report', 'returning_authors'),
        'production:report:package_distribution' => $item('Paket Dağılımı', 'data-open-production-report', 'package_distribution'),
    ]];
    foreach (require APP_ROOT . '/app/schema.php' as $table => $config) {
        $menus[$config['group']]['table:' . $table] = $item($config['label'], 'data-open-table', $table);
    }
    $menus['Tanımlar']['settings:currency'] = $item('Para Birimi', 'data-open-settings', 'currency');
    foreach (require APP_ROOT . '/app/business_reports.php' as $group => $reports) {
        foreach ($reports as $id => $label) $menus['Raporlar']['report:' . $id] = $item($label, 'data-open-business-report', $id) + ['subgroup' => $group];
    }
    $menus['Raporlar']['emails:cari'] = $item('Cari — Tüm E-postalar', 'href', 'email_export.php?source=cari') + ['subgroup' => 'E-postaları Dışa Aktar'];
    $menus['Raporlar']['emails:webcup'] = $item('WebCup Projeleri — Tüm E-postalar', 'href', 'email_export.php?source=webcup') + ['subgroup' => 'E-postaları Dışa Aktar'];
    foreach (['cascade'=>'Basamakla', 'tile'=>'Yan Yana Diz', 'minimize-all'=>'Tümünü Küçült', 'close-all'=>'Tümünü Kapat'] as $id => $label) {
        $menus['Pencere']['window:' . $id] = $item($label, 'data-window-command', $id);
    }
    $menus['Pencere']['cleanup'] = $item('Veri Temizleme…', 'href', 'cleanup.php') + ['admin' => true];
    $menus['Kullanıcılar']['users'] = $item('Kullanıcı Yönetimi', 'data-open-users') + ['admin' => true];
    return $menus;
}

function is_admin(?array $user = null): bool
{
    return (($user ?? current_user())['role'] ?? '') === 'administrator';
}

function can_access(string $permission, ?array $user = null): bool
{
    $user ??= current_user();
    if ($user === null) return false;
    if (is_admin($user)) return true;
    foreach (menu_catalog() as $group => $items) {
        if (!isset($items[$permission])) continue;
        return empty($items[$permission]['admin'])
            && in_array('menu:' . $group, $user['permissions'] ?? [], true)
            && in_array($permission, $user['permissions'] ?? [], true);
    }
    return false;
}

function require_permission(string $permission): void
{
    require_auth();
    if (!can_access($permission)) json_response(['error'=>'Bu bölüme erişim yetkiniz yok.'], 403);
}

function can_lookup(string $table, string $label): bool
{
    if (can_access('table:' . $table)) return true;
    foreach (require APP_ROOT . '/app/schema.php' as $source => $config) {
        if (!can_access('table:' . $source)) continue;
        foreach ($config['relations'] ?? [] as $relation) {
            if ($relation === [$table, $label]) return true;
        }
    }
    return false;
}

function protected_user(array $user): bool
{
    // The environment account and original locally bootstrapped account are permanent administrators.
    return in_array((string)($user['id'] ?? ''), ['environment', '1'], true);
}

function public_user(array $user): array
{
    return array_intersect_key($user, array_flip(['id', 'username', 'role', 'permissions'])) + ['permissions'=>[], 'protected'=>protected_user($user)];
}

function save_managed_user(array $input, string $path): array
{
    $file = fopen($path, 'c+');
    if (!$file || !flock($file, LOCK_EX)) throw new RuntimeException('Kullanıcı dosyası kilitlenemedi.');
    try {
        $raw = stream_get_contents($file);
        $document = $raw === '' ? ['users'=>[]] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($document['users'] ?? null)) throw new RuntimeException('Geçersiz kullanıcı dosyası.');
        $id = (string)($input['id'] ?? '');
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $role = (string)($input['role'] ?? 'user');
        if (!preg_match('/^[\pL\pN_.@-]{3,80}$/u', $username)) throw new InvalidArgumentException('Kullanıcı adı 3–80 karakter olmalı.');
        if (!in_array($role, ['administrator', 'user'], true)) throw new InvalidArgumentException('Geçersiz rol.');
        if ($id === 'environment') throw new InvalidArgumentException('Ana yönetici hesabı .env dosyasından yönetilir.');
        $environment = environment_login_user();
        if ($environment && strcasecmp($username, $environment['username']) === 0) throw new InvalidArgumentException('Bu kullanıcı adı zaten kullanılıyor.');
        $index = null;
        foreach ($document['users'] as $i => $user) {
            if ((string)$user['id'] === $id) $index = $i;
            elseif (mb_strtolower($user['username']) === mb_strtolower($username)) throw new InvalidArgumentException('Bu kullanıcı adı zaten kullanılıyor.');
        }
        if ($id !== '' && $index === null) throw new InvalidArgumentException('Kullanıcı bulunamadı.');
        $existing = $index === null ? null : $document['users'][$index];
        if ($existing && protected_user($existing) && $role !== 'administrator') throw new InvalidArgumentException('Ana yönetici normal kullanıcı yapılamaz.');
        if ((!$existing || $password !== '') && mb_strlen($password) < 12) throw new InvalidArgumentException('Parola en az 12 karakter olmalı.');
        $allowed = [];
        foreach (menu_catalog() as $group => $items) {
            $allowed[] = 'menu:' . $group;
            foreach ($items as $key => $item) if (empty($item['admin'])) $allowed[] = $key;
        }
        $permissions = $input['permissions'] ?? [];
        if (!is_array($permissions)) throw new InvalidArgumentException('Geçersiz menü izinleri.');
        $user = $existing ?? ['id'=>bin2hex(random_bytes(16)), 'created_at'=>date(DATE_ATOM)];
        $user['username'] = $username;
        $user['role'] = $role;
        $user['permissions'] = array_values(array_intersect($allowed, $permissions));
        if ($password !== '') $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        if ($index === null) $document['users'][] = $user;
        else $document['users'][$index] = $user;
        $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        rewind($file);
        if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) throw new RuntimeException('Kullanıcılar kaydedilemedi.');
        return public_user($user);
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}
