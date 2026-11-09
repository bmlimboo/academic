<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

// Serves a file to people who are allowed to open it (never directly from storage/).
require __DIR__ . '/lib.php';
$u = require_login();
$st = db()->prepare('SELECT * FROM rd_files WHERE id = ?'); $st->execute([(int)($_GET['id'] ?? 0)]);
$f = $st->fetch();
$dl = isset($_GET['dl']);
if (!$f || !can_open_file($u, $f, $dl) || !is_file(file_path($f['file']))) { http_response_code(404); exit('File not found.'); }
$ext = strtolower(pathinfo($f['file'], PATHINFO_EXTENSION));
$types = ['pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc' => 'application/msword'];
$ascii = preg_replace('/[^\x20-\x7E]/', '_', $f['original_name']);
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize(file_path($f['file'])));
header('Content-Disposition: ' . (($dl || $ext !== 'pdf') ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($f['original_name']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile(file_path($f['file']));
