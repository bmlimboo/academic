<?php
namespace CAS\SignDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_daa();
$kind = $_GET['kind'] ?? '';
$p = in_array($kind, ['signature', 'seal'], true) ? stamp_path($u['id'], $kind) : null;
if (!$p) { http_response_code(404); exit('Not found.'); }
header('Content-Type: ' . (substr($p, -4) === '.png' ? 'image/png' : 'image/jpeg'));
header('Content-Length: ' . filesize($p));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($p);
