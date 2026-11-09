<?php
namespace CAS\SignDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = current_user();
if (!$u || $u['role'] !== 'daa') { json_out(['ok' => false, 'error' => 'Please sign in again as the DAA.'], 403); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['ok' => false, 'error' => 'Not allowed.'], 405); }
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { json_out(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 400); }

$id = (int)($_POST['id'] ?? 0);
$st = db()->prepare('SELECT * FROM sd_doc_requests WHERE id = ?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r) { json_out(['ok' => false, 'error' => 'Document not found.'], 404); }
if (!in_array($r['status'], ['submitted', 'under_review'], true)) { json_out(['ok' => false, 'error' => 'This document has already been handled.'], 409); }

// Password re-entry: the person at the keyboard must be the DAA.
$hash = db()->prepare('SELECT password_hash FROM sd_users WHERE id = ?');
$hash->execute([$u['id']]);
if (!password_verify((string)($_POST['password'] ?? ''), (string)$hash->fetchColumn())) {
    sleep(1);
    json_out(['ok' => false, 'error' => 'The password is incorrect.'], 403);
}

try {
    [$file, $orig] = save_document($_FILES['signed'] ?? [], 'signed', ['pdf']);
    $dno = normalize_dispatch($_POST['dispatch_no'] ?? '');
    try {
        if (mb_strlen($dno) > 80) { throw new RuntimeException('The dispatch number is too long (80 characters at most).'); }
        if ($dno !== '') { register_dispatch($id, $u['id'], $dno); }
    } catch (RuntimeException $ex) {
        @unlink(STORAGE_DIR . DIRECTORY_SEPARATOR . $file);
        throw $ex;
    }
    db()->prepare("UPDATE sd_doc_requests SET status='signed', signed_file=?, signed_name=?, dispatch_no=?, signed_at=NOW() WHERE id=?")
        ->execute([$file, 'signed.pdf', $dno ?: null, $id]);
    $summary = trim(preg_replace('/[^\w\s,.\-]/u', '', (string)($_POST['summary'] ?? '')));
    log_action($id, $u['id'], 'stamped', trim(($dno ? 'Dispatch no. ' . $dno . '. ' : '') . ($summary ? 'Placed: ' . mb_substr($summary, 0, 250) : '')) ?: null);
    flash('Signed and stamped. The submitter can now download the signed PDF.');
    json_out(['ok' => true, 'redirect' => 'view.php?id=' . $id]);
} catch (RuntimeException $ex) {
    json_out(['ok' => false, 'error' => $ex->getMessage()], 422);
}
