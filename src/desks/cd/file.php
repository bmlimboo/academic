<?php
namespace CAS\CertDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_login();
$st = db()->prepare('SELECT * FROM cd_request_files WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$f = $st->fetch();
$r = $f ? load_request((int)$f['request_id']) : null;
$path = $f ? paper_path($f['stored']) : '';
if (!$f || !$r || !can_see_file($u, $r, $f) || !is_file($path)) { http_response_code(404); exit('File not found.'); }
$ext = strtolower(pathinfo($f['stored'], PATHINFO_EXTENSION));
$base = name_part(KINDS[$f['kind']] ?? 'File', 40) . ' - ' . name_part((string)$r['ref'], 30);
$orig = pathinfo($f['original_name'], PATHINFO_FILENAME);
$name = $base . ' - ' . (name_part($orig, 60) ?: 'file') . '.' . $ext;
$ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
header('Content-Type: ' . file_mime($f['stored']));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
