<?php
namespace CAS\SignDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
require_daa();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('report.php'); }
csrf_check();

$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
if (!$ids) { flash('Tick at least one document first.', 'err'); redirect('report.php'); }

$n = 0;
foreach ($ids as $id) {
    if (delete_document($id)) { $n++; }
}
flash($n . ' document' . ($n === 1 ? '' : 's') . ' deleted.');
redirect('report.php');
