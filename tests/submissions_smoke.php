<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
function submission_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE request_forms (id INTEGER PRIMARY KEY,title TEXT,fields TEXT); CREATE TABLE submissions (id INTEGER PRIMARY KEY,request_form_id INTEGER,data TEXT,created_at TEXT,updated_at TEXT,read_at TEXT,deleted_at TEXT); CREATE TABLE submission_files (id INTEGER PRIMARY KEY,submission_id INTEGER);');
$pdo->exec("INSERT INTO request_forms (id,title) VALUES (1,'İletişim'),(2,'Dosya Gönder'),(3,'Boş Form')");
$pdo->prepare('UPDATE request_forms SET fields=? WHERE id=1')->execute([json_encode([['type'=>'textarea','label'=>'Mesaj']])]);
$insert = $pdo->prepare('INSERT INTO submissions VALUES (?,?,?,?,?,NULL,NULL)');
for ($i=1; $i<=55; $i++) $insert->execute([$i,1,json_encode(['Ad'=>'Çağrı','Mesaj'=>['Merhaba','Dünya']]),'2026-11-23 12:00:00','2026-11-23 12:00:00']);
$insert->execute([56,2,'{}','2026-11-23 12:00:00','2026-11-23 12:00:00']);
$pdo->exec("UPDATE submissions SET deleted_at='2026-11-23' WHERE id=55; INSERT INTO submission_files VALUES (1,54),(2,54)");
$forms = submission_forms($pdo);
submission_check(array_column($forms,'unread') === [54,1,0], 'Unread counts include trash or lose empty forms.');
$first = submission_list($pdo,1,1,'');
$second = submission_list($pdo,1,2,'');
submission_check(count($first['rows']) === 50 && count($second['rows']) === 4 && $first['total'] === 54, 'Pagination failed.');
submission_check($first['rows'][0]['id'] === 54 && $first['rows'][0]['attachments'] === 2, 'Ordering or attachment count failed.');
submission_check($first['rows'][0]['summary'] === 'Çağrı' && !isset($first['rows'][0]['data']), 'Summary must contain contact values only, without labels or message text.');
submission_check($first['rows'][0]['preview'] === 'Merhaba, Dünya', 'Body preview missing.');
$previewWords = array_map(fn($i) => 'sözcük' . $i, range(1,31));
$pdo->prepare('UPDATE submissions SET data=? WHERE id=53')->execute([json_encode(['Ad'=>'Yazar','Mesaj'=>'<p>'.implode('&nbsp;', $previewWords).'</p>'])]);
$previewRows = array_column(submission_list($pdo,1,1,'')['rows'], null, 'id');
submission_check($previewRows[53]['preview'] === implode(' ',array_slice($previewWords,0,30)).'…', 'Preview must normalize markup/spacing and stop at 30 words.');
submission_check(submission_list($pdo,2,1,'')['rows'][0]['preview'] === '', 'Forms without body text should not invent a preview.');
submission_check(submission_find($pdo,55) === null, 'Trashed submission exposed.');
submission_mark($pdo,54,true);
$readAt=submission_find($pdo,54)['read_at'];
submission_mark($pdo,54,true);
submission_check(submission_find($pdo,54)['read_at'] === $readAt, 'Repeated opening changed read timestamp.');
submission_check(submission_list($pdo,1,1,'read')['total'] === 1 && submission_list($pdo,1,1,'unread')['total'] === 53, 'Read filter failed.');
submission_mark($pdo,54,false);
submission_check(submission_forms($pdo)[0]['unread'] === 54, 'Mark unread did not restore count.');
submission_mark($pdo,55,true);
submission_check($pdo->query('SELECT read_at FROM submissions WHERE id=55')->fetchColumn() === null, 'Trash was updated.');
$root=sys_get_temp_dir().'/submission-test-'.bin2hex(random_bytes(5));
mkdir($root); mkdir($root.'/storage'); file_put_contents($root.'/storage/file.txt','attachment'); file_put_contents($root.'/private.txt','private');
try {
    submission_check(submission_file_path($root.'/storage','file.txt') === realpath($root.'/storage/file.txt'), 'Attachment not resolved.');
    foreach (['../private.txt','missing.txt','.',"file.txt\0"] as $path) submission_check(submission_file_path($root.'/storage',$path) === false,'Unsafe attachment accepted.');
    $pdo->exec("ALTER TABLE submission_files ADD COLUMN path TEXT DEFAULT ''; ALTER TABLE submission_files ADD COLUMN is_placeholder INTEGER DEFAULT 0");
    submission_trash_action($pdo,54,'trash',$root.'/storage');
    submission_check(submission_find($pdo,54) === null && submission_find($pdo,54,true)['deleted_at'] !== null,'Trash did not hide entry.');
    submission_check(submission_list($pdo,1,1,'trash')['total'] === 2 && submission_forms($pdo)[0]['unread'] === 53,'Trash listing/count failed.');
    submission_trash_action($pdo,54,'restore',$root.'/storage');
    submission_check(submission_find($pdo,54)['read_at'] === null && submission_forms($pdo)[0]['unread'] === 54,'Restore failed to preserve read state/count.');
    try { submission_trash_action($pdo,54,'purge',$root.'/storage'); throw new RuntimeException('Active entry could be purged.'); }
    catch (InvalidArgumentException $expected) {}
    mkdir($root.'/storage/submissions'); mkdir($root.'/storage/submissions/54');
    file_put_contents($root.'/storage/submissions/54/upload.txt','upload');
    $pdo->exec("UPDATE submission_files SET path='submissions/54/upload.txt' WHERE id=1; UPDATE submission_files SET path='file.txt',is_placeholder=1 WHERE id=2");
    $pdo->exec("INSERT INTO submission_files (id,submission_id,path) VALUES (3,54,'submissions/54/../../file.txt')");
    submission_trash_action($pdo,54,'trash',$root.'/storage');
    submission_trash_action($pdo,54,'purge',$root.'/storage');
    submission_check(submission_find($pdo,54,true) === null && (int)$pdo->query('SELECT COUNT(*) FROM submission_files WHERE submission_id=54')->fetchColumn() === 0,'Purge left records.');
    submission_check(!is_file($root.'/storage/submissions/54/upload.txt') && is_file($root.'/storage/file.txt'),'Purge did not restrict deletion to owned attachments.');
    submission_check(submission_find($pdo,53) !== null,'Purge deleted unrelated entry.');
    rmdir($root.'/storage/submissions/54'); rmdir($root.'/storage/submissions');
} finally { unlink($root.'/storage/file.txt'); unlink($root.'/private.txt'); rmdir($root.'/storage'); rmdir($root); }
// Read-only check against the configured database and real menu catalog.
$liveForms=submission_forms(submission_db());
foreach($liveForms as $form) {
    $permission='submissions:'.$form['id'];
    submission_check(isset(menu_catalog()['WebCin'][$permission]),'Form missing from WebCin menu.');
    $user=['role'=>'user','permissions'=>['menu:WebCin',$permission]];
    submission_check(can_access($permission,$user),'Granted form denied.');
    submission_check(!can_access($permission,['role'=>'user','permissions'=>[$permission]]),'Parent menu bypass.');
    submission_check(!can_access($permission,['role'=>'user','permissions'=>['menu:WebCin']]),'Form permission bypass.');
    $list=submission_list(submission_db(),(int)$form['id'],1,'unread');
    submission_check($list['total'] === (int)$form['unread'],'Live unread counts disagree.');
}
echo "Submission fixtures, read state, pagination, file containment, permissions, and ".count($liveForms)." live form menus passed.\n";
