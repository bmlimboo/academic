<?php
namespace CAS\SignDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_login();
$st = db()->prepare('SELECT r.*, u.name AS user_name, IF(CHAR_LENGTH(r.department) > 0, r.department, u.department) AS dept FROM sd_doc_requests r JOIN sd_users u ON u.id = r.user_id WHERE r.id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$r = $st->fetch();
if (!$r || ($u['role'] !== 'daa' && (int)$r['user_id'] !== $u['id'])) { http_response_code(404); exit('File not found.'); }

$signed = ($_GET['type'] ?? '') === 'signed';
$file = $signed ? $r['signed_file'] : $r['original_file'];
$path = $file ? STORAGE_DIR . DIRECTORY_SEPARATOR . basename($file) : '';
if (!$path || !is_file($path)) { http_response_code(404); exit('File not found.'); }

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = ['pdf' => 'application/pdf', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
$name = doc_filename($r, $signed, $ext);
$ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
header('Content-Disposition: ' . (isset($_GET['dl']) || $ext !== 'pdf' ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
readfile($path);
