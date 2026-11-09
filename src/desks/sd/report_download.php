<?php
namespace CAS\SignDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
require_daa();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('report.php'); }
csrf_check();

function back(string $msg): void { flash($msg, 'err'); redirect('report.php'); }

try {
    if (!class_exists('ZipArchive')) {
        back('ZIP support is not enabled in PHP. In XAMPP, enable extension=zip in php.ini and restart Apache.');
    }
    if (($_POST['mode'] ?? '') === 'selected') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
        if (!$ids) { back('Tick at least one document first.'); }
        $rows = db()->query('SELECT r.*, u.name AS user_name, IF(CHAR_LENGTH(r.department) > 0, r.department, u.department) AS dept FROM sd_doc_requests r JOIN sd_users u ON u.id = r.user_id WHERE r.id IN (' . implode(',', $ids) . ') ORDER BY r.id')->fetchAll();
    } else {
        [$rows] = report_rows($_POST);
    }
    if (!$rows) { back('There are no documents to download.'); }

    @set_time_limit(300);
    $tmpDir = is_writable(sys_get_temp_dir()) ? sys_get_temp_dir() : STORAGE_DIR;
    $tmp = tempnam($tmpDir, 'sd');
    if ($tmp === false) { back('Could not create a temporary file in ' . $tmpDir); }
    $zip = new ZipArchive();
    $opened = $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) { @unlink($tmp); back('Could not create the ZIP file (code ' . $opened . ').'); }

    $used = [];
    $added = 0;
    $missing = [];
    foreach ($rows as $r) {
        foreach ([['original_file', false], ['signed_file', true]] as [$col, $isSigned]) {
            if (!$r[$col]) { continue; }
            $path = STORAGE_DIR . DIRECTORY_SEPARATOR . basename($r[$col]);
            if (is_file($path)) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $name = doc_filename($r, $isSigned, $ext);
                for ($n = 2; isset($used[strtolower($name)]); $n++) {
                    $name = preg_replace('/\.' . $ext . '$/', ' (' . $n . ').' . $ext, doc_filename($r, $isSigned, $ext));
                }
                $used[strtolower($name)] = true;
                $zip->addFile($path, $name);
                $added++;
            } else {
                $missing[] = $path;
            }
        }
    }
    if ($added === 0) { $zip->close(); @unlink($tmp); back('Files not found. Looked for: ' . implode(' | ', array_slice($missing, 0, 3))
        . ' (storage folder exists: ' . (is_dir(STORAGE_DIR) ? 'yes' : 'no')
        . ', readable: ' . (is_readable(STORAGE_DIR) ? 'yes' : 'no') . ').'); }
    if (!$zip->close() || !is_file($tmp) || filesize($tmp) === 0) { @unlink($tmp); back('The ZIP file could not be written. Check that PHP can write to ' . $tmpDir); }

    while (ob_get_level() > 0) { ob_end_clean(); }
    ini_set('zlib.output_compression', '0');
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($tmp));
    header('Content-Disposition: attachment; filename="SignDesk-documents-' . date('Y-m-d') . '.zip"');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
    unlink($tmp);
} catch (Throwable $ex) {
    back('Download failed: ' . $ex->getMessage());
}
