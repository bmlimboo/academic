<?php
namespace CAS\CertDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
require_role('examcell');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('requests.php'); }
csrf_check();

$ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
if (!$ids) { flash('Tick at least one request first.', 'err'); redirect('requests.php'); }

$n = 0;
try {
    foreach ($ids as $rid) {
        if (!load_request($rid)) { continue; }
        $files = request_files($rid);
        // The database rows (items, files, history, emails) are removed together with the request.
        db()->prepare('DELETE FROM cd_requests WHERE id = ?')->execute([$rid]);
        foreach ($files as $f) { @unlink(paper_path($f['stored'])); }
        $n++;
    }
    flash($n . ' request(s) deleted with all their files.');
} catch (Throwable $ex) {
    flash('Delete failed: ' . $ex->getMessage(), 'err');
}
redirect('requests.php');
