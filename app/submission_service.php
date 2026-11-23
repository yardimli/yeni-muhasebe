<?php
declare(strict_types=1);

function submission_db(): PDO
{
    static $pdo;
    return $pdo ??= new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env_value('DB_HOST', '127.0.0.1'), env_value('DB_PORT', '3306'),
        env_value('DB_DATABASE_SUBMISSIONS', 'cinius_laravel')),
        (string)env_value('DB_USERNAME', ''), (string)env_value('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
}

function submission_forms(PDO $pdo): array
{
    return $pdo->query('SELECT f.id,f.title,COUNT(s.id) AS total,COALESCE(SUM(CASE WHEN s.id IS NOT NULL AND s.deleted_at IS NULL AND s.read_at IS NULL THEN 1 ELSE 0 END),0) AS unread FROM request_forms f LEFT JOIN submissions s ON s.request_form_id=f.id GROUP BY f.id,f.title ORDER BY f.id')->fetchAll();
}

function submission_menu_forms(): array
{
    static $forms;
    if ($forms !== null) return $forms;
    try { return $forms = submission_forms(submission_db()); }
    catch (Throwable $e) {
        error_log('Submission menu unavailable: ' . $e->getMessage());
        return $forms = [];
    }
}

function submission_text(mixed $value): string
{
    return is_array($value) ? implode(', ', array_map('submission_text', $value)) : trim(strip_tags((string)$value));
}

function submission_list(PDO $pdo, int $form, int $page, string $filter): array
{
    $formQuery = $pdo->prepare('SELECT fields FROM request_forms WHERE id=?');
    $formQuery->execute([$form]);
    $fields = json_decode((string)$formQuery->fetchColumn(), true) ?: [];
    $where = 's.request_form_id=? AND s.deleted_at IS ' . ($filter === 'trash' ? 'NOT NULL' : 'NULL');
    if ($filter === 'read') $where .= ' AND s.read_at IS NOT NULL';
    if ($filter === 'unread') $where .= ' AND s.read_at IS NULL';
    $count = $pdo->prepare("SELECT COUNT(*) FROM submissions s WHERE $where");
    $count->execute([$form]);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / 50));
    $page = max(1, min($pages, $page));
    $offset = ($page - 1) * 50;
    $query = $pdo->prepare("SELECT s.id,s.data,s.created_at,s.read_at,(SELECT COUNT(*) FROM submission_files f WHERE f.submission_id=s.id) AS attachments FROM submissions s WHERE $where ORDER BY s.created_at DESC,s.id DESC LIMIT 50 OFFSET $offset");
    $query->execute([$form]);
    $rows = $query->fetchAll();
    foreach ($rows as &$row) {
        $data = json_decode($row['data'], true) ?: [];
        $parts = ['name'=>'', 'email'=>'', 'phone'=>''];
        foreach ($data as $label => $value) {
            $text = submission_text($value);
            $label = mb_strtolower(strtr((string)$label, ['İ'=>'i', 'ı'=>'i']), 'UTF-8');
            $kind = match (true) {
                (bool)preg_match('/e-?posta|e-?mail/u', $label) => 'email',
                (bool)preg_match('/telefon|phone|gsm/u', $label) => 'phone',
                (bool)preg_match('/adiniz|soyad|yazar adi|^ad$|^name$/u', $label) => 'name',
                default => null,
            };
            if ($kind !== null && $parts[$kind] === '' && $text !== '') $parts[$kind] = mb_substr($text, 0, 160);
        }
        $row['summary'] = implode(' · ', array_filter($parts, fn($value) => $value !== ''));
        $body = [];
        foreach ($fields as $field) {
            if (($field['type'] ?? '') !== 'textarea') continue;
            $value = $data[($field['label'] ?? '') ?: ($field['id'] ?? '')] ?? '';
            $body[] = html_entity_decode(preg_replace('/<[^>]*>/', ' ', is_array($value) ? submission_text($value) : (string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $words = preg_split('/[\s\x{00A0}]+/u', trim(implode(' ', $body)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $row['preview'] = implode(' ', array_slice($words, 0, 30)) . (count($words) > 30 ? '…' : '');
        unset($row['data']);
    }
    return compact('rows', 'total', 'pages', 'page');
}

function submission_find(PDO $pdo, int $id, bool $includeTrash = false): ?array
{
    $query = $pdo->prepare('SELECT s.*,f.title FROM submissions s JOIN request_forms f ON f.id=s.request_form_id WHERE s.id=?' . ($includeTrash ? '' : ' AND s.deleted_at IS NULL'));
    $query->execute([$id]);
    return $query->fetch() ?: null;
}

function submission_mark(PDO $pdo, int $id, bool $read): void
{
    $time = gmdate('Y-m-d H:i:s');
    $query = $pdo->prepare('UPDATE submissions SET read_at=' . ($read ? 'COALESCE(read_at,?)' : 'NULL') . ',updated_at=? WHERE id=? AND deleted_at IS NULL');
    $query->execute($read ? [$time, $time, $id] : [$time, $id]);
}

function submission_file_path(string $root, string $relative): string|false
{
    $root = realpath($root);
    if (!$root || str_contains($relative, "\0")) return false;
    $path = realpath($root . DIRECTORY_SEPARATOR . $relative);
    return $path && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path) && is_readable($path) ? $path : false;
}

function submission_trash_action(PDO $pdo, int $id, string $action, string $root): void
{
    if (!in_array($action, ['trash','restore','purge'], true)) throw new InvalidArgumentException('Geçersiz işlem.');
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT deleted_at FROM submissions WHERE id=?' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
        $query->execute([$id]);
        $row = $query->fetch();
        if (!$row || ($action === 'trash' ? $row['deleted_at'] !== null : $row['deleted_at'] === null)) {
            throw new InvalidArgumentException('Başvurunun durumu değişti. Listeyi yenileyin.');
        }
        if ($action === 'purge') {
            $query = $pdo->prepare('SELECT path,is_placeholder FROM submission_files WHERE submission_id=?');
            $query->execute([$id]);
            foreach ($query->fetchAll() as $file) {
                // Imported/shared files must never be removed. Only this submission's own uploads.
                if ($file['is_placeholder'] || !str_starts_with($file['path'], 'submissions/' . $id . '/')) continue;
                $path = submission_file_path($root, $file['path']);
                $ownedPath = submission_file_path($root . '/submissions/' . $id, substr($file['path'], strlen('submissions/' . $id . '/')));
                if (!$path || $path !== $ownedPath) continue;
                if ($path && !unlink($path)) throw new RuntimeException('Ek dosya silinemedi.');
            }
            $pdo->prepare('DELETE FROM submission_files WHERE submission_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM submissions WHERE id=? AND deleted_at IS NOT NULL')->execute([$id]);
        } else {
            $time = gmdate('Y-m-d H:i:s');
            $pdo->prepare('UPDATE submissions SET deleted_at=?,updated_at=? WHERE id=?')->execute([$action === 'trash' ? $time : null, $time, $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
