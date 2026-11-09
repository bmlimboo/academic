<?php
namespace CAS\CertDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
require_role('examcell');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('requests.php'); }
csrf_check();

function back(string $msg): void { flash($msg, 'err'); redirect('requests.php'); }

try {
    if (!class_exists('ZipArchive')) { back('ZIP support is not enabled in PHP. In XAMPP, enable extension=zip in php.ini and restart Apache.'); }

    $kinds = array_values(array_intersect(array_keys(KINDS), (array)($_POST['kinds'] ?? [])));
    if (!$kinds) { back('Tick at least one kind of file to include.'); }
    $onlyType = (int)($_POST['type_dl'] ?? 0);

    if (($_POST['mode'] ?? '') === 'selected') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
        if (!$ids) { back('Tick at least one request first.'); }
        $st = db()->query('SELECT r.* FROM cd_requests r WHERE r.id IN (' . implode(',', $ids) . ') ORDER BY r.id');
        $rows = $st->fetchAll();
        attach_items($rows);
        if ((int)($_POST['type'] ?? 0) > 0 && !$onlyType) { $onlyType = (int)$_POST['type']; }
    } else {
        $src = $_POST;                      // same filters as the list on screen (type, status, year, search)
        if ($onlyType > 0) { $src['type'] = $onlyType; }
        [$rows] = requests_rows($src);
        if (!$onlyType && (int)($_POST['type'] ?? 0) > 0) { $onlyType = (int)$_POST['type']; }
    }
    if (!$rows) { back('There are no requests to download.'); }

    @set_time_limit(300);
    $tmpDir = is_writable(sys_get_temp_dir()) ? sys_get_temp_dir() : STORAGE_DIR;
    $tmp = tempnam($tmpDir, 'cd');
    if ($tmp === false) { back('Could not create a temporary file in ' . $tmpDir); }
    $zip = new ZipArchive();
    $opened = $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) { @unlink($tmp); back('Could not create the ZIP file (code ' . $opened . ').'); }

    $typeNames = [];
    foreach (cert_types(false) as $t) { $typeNames[(int)$t['id']] = $t['name']; }
    $used = []; $added = 0; $missing = 0;
    foreach ($rows as $r) {
        $files = array_values(array_filter(request_files((int)$r['id']), fn($f) => in_array($f['kind'], $kinds, true)));
        $reqDir = name_part($r['ref'] . ' - ' . $r['full_name'] . ' - ' . $r['student_id'], 90) ?: ('Request ' . $r['id']);
        foreach ($r['items'] as $it) {
            $tid = (int)$it['type_id'];
            if ($onlyType && $tid !== $onlyType) { continue; }
            $typeDir = name_part($typeNames[$tid] ?? 'Certificate', 70) ?: 'Certificate';
            foreach ($files as $f) {
                // Certificate files belong to one requested item; all other files are shared by the whole request.
                if ($f['kind'] === 'certificate' && (int)$f['item_id'] !== (int)$it['id']) { continue; }
                $path = paper_path($f['stored']);
                if (!is_file($path)) { $missing++; continue; }
                $ext = strtolower(pathinfo($f['stored'], PATHINFO_EXTENSION));
                $base = name_part(KINDS[$f['kind']], 40) . ' - ' . (name_part(pathinfo($f['original_name'], PATHINFO_FILENAME), 60) ?: 'file');
                $entry = $typeDir . '/' . $reqDir . '/' . $base . '.' . $ext;
                for ($n = 2; isset($used[strtolower($entry)]); $n++) { $entry = $typeDir . '/' . $reqDir . '/' . $base . ' (' . $n . ').' . $ext; }
                $used[strtolower($entry)] = true;
                $zip->addFile($path, $entry);
                $added++;
            }
        }
    }
    if ($added === 0) { $zip->close(); @unlink($tmp); back('No files matched your choices.' . ($missing ? ' ' . $missing . ' file(s) were missing from the storage folder.' : '')); }
    if (!$zip->close() || !is_file($tmp) || filesize($tmp) === 0) { @unlink($tmp); back('The ZIP file could not be written. Check that PHP can write to ' . $tmpDir); }

    $label = $onlyType ? name_part($typeNames[$onlyType] ?? 'Certificates', 50) : 'All certificate types';
    while (ob_get_level() > 0) { ob_end_clean(); }
    ini_set('zlib.output_compression', '0');
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($tmp));
    header('Content-Disposition: attachment; filename="Certificate requests - ' . $label . ' - ' . date('Y-m-d') . '.zip"');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
    unlink($tmp);
} catch (Throwable $ex) {
    back('Download failed: ' . $ex->getMessage());
}
