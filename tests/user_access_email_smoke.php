<?php
declare(strict_types=1);

// Isolated fixtures: never edits installed accounts or connects to a database.
const APP_ROOT = __DIR__ . '/..';
function environment_login_user(): array { return ['id'=>'environment','username'=>'mainadmin','role'=>'administrator']; }
function current_user(): ?array { return $GLOBALS['test_user'] ?? null; }
require APP_ROOT . '/app/access.php';
require APP_ROOT . '/app/email_export.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException($message);
}
$path = tempnam(sys_get_temp_dir(), 'user-access-');
try {
    file_put_contents($path, json_encode(['users'=>[['id'=>1,'username'=>'original','role'=>'administrator','password_hash'=>password_hash('Original-password-123', PASSWORD_DEFAULT)]]]));
    $input = ['username'=>'editor','password'=>'New-password-123','role'=>'user','permissions'=>['menu:Cari','table:adresdefteri','users','invalid']];
    $user = save_managed_user($input, $path);
    check(!isset($user['password_hash']), 'Password hashes leaked.');
    check(can_access('table:adresdefteri', $user), 'Selected menu denied.');
    check(!can_access('table:asp_urun', $user) && !can_access('users', $user), 'Unselected/admin menu granted.');
    check(!can_access('table:adresdefteri', array_replace($user, ['permissions'=>['table:adresdefteri']])), 'Parent menu permission bypassed.');
    $GLOBALS['test_user'] = $user;
    check(can_lookup('sehirler','sehir'), 'Required editor lookup denied.');
    check(!can_lookup('asp_urun','kitapadi'), 'Unrelated lookup granted.');
    foreach (menu_catalog() as $items) foreach ($items as $key => $_) check(can_access($key, environment_login_user()), 'Administrator missing menu.');
    check(array_key_first(menu_catalog()) === 'WebCin', 'WebCin is not first.');
    $stored = json_decode(file_get_contents($path), true)['users'][1];
    check(password_verify($input['password'], $stored['password_hash']), 'Password was not hashed correctly.');
    $updated = save_managed_user(['id'=>$user['id'],'username'=>'renamed','password'=>'','role'=>'administrator'], $path);
    check($updated['role'] === 'administrator', 'Role edit failed.');
    check(json_decode(file_get_contents($path), true)['users'][1]['password_hash'] === $stored['password_hash'], 'Blank password changed the hash.');
    rejects(fn()=>save_managed_user($input + ['id'=>'environment'], $path), 'Environment account changed.');
    rejects(fn()=>save_managed_user(['id'=>1,'username'=>'original','role'=>'user'], $path), 'Original admin demoted.');
    rejects(fn()=>save_managed_user(array_replace($input,['username'=>'MAINADMIN']), $path), 'Environment username duplicate allowed.');
    rejects(fn()=>save_managed_user(array_replace($input,['username'=>'RENAMED']), $path), 'Duplicate username allowed.');
    rejects(fn()=>save_managed_user(array_replace($input,['password'=>'short']), $path), 'Short password allowed.');
    rejects(fn()=>save_managed_user($input + ['id'=>'missing'], $path), 'Unknown user updated.');
    $rows = iterator_to_array(newsletter_rows([
        ['name'=>'Çağrı, "Yazar"','email'=>'AUTHOR@example.com; second@example.com'],
        ['name'=>'Duplicate','email'=>'author@example.com'],
        ['name'=>'Invalid','email'=>'bad-address; admin@ciniusyayinlari.com'],
        ['name'=>"=FORMULA\nTest",'email'=>'valid@example.com'],
    ]));
    check(count($rows) === 3, 'Deduplication/validation failed.');
    check($rows[0] === ['Çağrı, "Yazar"','author@example.com'], 'Unicode/name preservation failed.');
    check($rows[2][0] === "'=FORMULA Test", 'Formula/newline protection failed.');
    $csv = fopen('php://temp','w+');
    foreach ($rows as $row) fputcsv($csv,$row,',','"','',"\r\n");
    rewind($csv);
    check(fgetcsv($csv,0,',','"','') === $rows[0], 'CSV quoting roundtrip failed.');
    fclose($csv);
    echo "User permissions, protected admins, password edits, and newsletter CSV checks passed.\n";
} finally { unlink($path); }
