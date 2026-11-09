<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

// DAA: every file in one place. View, download, delete. ZIP by department.
require __DIR__ . '/lib.php';
$u = require_role('daa');
$dept = current_dept($u);
$actFilter = (int)($_GET['act'] ?? 0);

function file_rows(string $dept, int $act): array
{
    $w = ["f.label <> 'timeline'"]; $p = [];
    if ($dept !== '') { $w[] = 's.department = ?'; $p[] = $dept; }
    if ($act) { $w[] = 'f.activity_id = ?'; $p[] = $act; }
    $st = db()->prepare('SELECT f.*, s.name student_name, s.department, s.student_no, a.title atitle, a.seq FROM rd_files f LEFT JOIN rd_users s ON s.id = f.student_id LEFT JOIN rd_activities a ON a.id = f.activity_id WHERE ' . implode(' AND ', $w) . ' ORDER BY s.department, a.seq, s.name, f.id');
    $st->execute($p);
    return $st->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'delete') {
        $st = db()->prepare('SELECT * FROM rd_files WHERE id = ?'); $st->execute([(int)($_POST['fid'] ?? 0)]);
        if ($f = $st->fetch()) { delete_file_row($f); flash('File deleted.'); }
        redirect('daa_files.php');
    }
    if ($act === 'zip') {
        if (!class_exists('ZipArchive')) { flash('ZIP support is not enabled in PHP. In XAMPP, enable extension=zip in php.ini and restart Apache.', 'err'); redirect('daa_files.php'); }
        $zd = in_array($_POST['dept'] ?? '', DEPARTMENTS, true) ? $_POST['dept'] : '';
        $rows = file_rows($zd, (int)($_POST['act'] ?? 0));
        $tmp = tempnam(sys_get_temp_dir(), 'rd'); $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { flash('Could not create the ZIP file.', 'err'); redirect('daa_files.php'); }
        $used = []; $n = 0;
        foreach ($rows as $f) {
            $p = file_path($f['file']); if (!is_file($p)) { continue; }
            $dir = ($f['department'] ?: 'Documents from DAA') . '/' . sprintf('%02d ', (int)$f['seq']) . (name_part((string)$f['atitle'], 60) ?: 'Activity');
            $name = ($f['student_name'] ? name_part($f['student_name'], 40) . ' - ' : '') . $f['original_name'];
            $entry = $dir . '/' . $name;
            for ($i = 2; isset($used[strtolower($entry)]); $i++) { $entry = $dir . '/' . preg_replace('/(\.[^.]+)?$/', " ($i)$1", $name, 1); }
            $used[strtolower($entry)] = true; $zip->addFile($p, $entry); $n++;
        }
        if (!$n) { $zip->close(); @unlink($tmp); flash('There are no files to download for this choice.', 'err'); redirect('daa_files.php'); }
        $zip->close();
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/zip'); header('Content-Length: ' . filesize($tmp));
        header('Content-Disposition: attachment; filename="Research files - ' . ($zd ?: 'All departments') . ' - ' . date('Y-m-d') . '.zip"');
        readfile($tmp); unlink($tmp); exit;
    }
}

$rows = file_rows($dept, $actFilter);
$acts = all_activities();
layout_start('Files', 'files', 'DAA');
?>
<p class="lead">All uploaded files. Download a ZIP for one department (folders: department, activity, student) or for all.</p>
<form method="get" class="card filters"><label>Department<select name="dept" onchange="this.form.submit()"><option value="">All departments</option><?php foreach (DEPARTMENTS as $d): ?><option value="<?= e($d) ?>" <?= $d === $dept ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select></label>
  <label>Activity<select name="act" onchange="this.form.submit()"><option value="0">All activities</option><?php foreach ($acts as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $actFilter === (int)$x['id'] ? 'selected' : '' ?>><?= (int)$x['seq'] ?>. <?= e(mb_substr($x['title'], 0, 60)) ?></option><?php endforeach; ?></select></label></form>
<section class="card"><div class="cardhead"><h2><?= count($rows) ?> file<?= count($rows) === 1 ? '' : 's' ?></h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="zip"><input type="hidden" name="dept" value="<?= e($dept) ?>"><input type="hidden" name="act" value="<?= $actFilter ?>"><button class="btn small" <?= $rows ? '' : 'disabled' ?>>Download ZIP (<?= e($dept ?: 'all departments') ?>)</button></form></div>
<?php if (!$rows): ?><p class="empty">No files yet.</p><?php else: ?><div class="tablewrap"><table><thead><tr><th>File</th><th>Student</th><th>Activity</th><th>From</th><th>Date</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $f): ?><tr><td><?= file_links($u, [$f]) ?></td><td><?= $f['student_name'] ? e($f['student_name']) . '<div class="muted">' . e($f['department']) . '</div>' : '<span class="muted">—</span>' ?></td>
  <td><?= (int)$f['seq'] ?>. <?= e($f['atitle']) ?></td><td><?= e(ROLES[$f['uploader_role']] ?? $f['uploader_role']) ?></td><td class="nowrap"><?= e(fmt_date($f['created_at'])) ?></td>
  <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="fid" value="<?= (int)$f['id'] ?>"><button class="btn small danger" data-confirm="Delete this file permanently?">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php layout_end();
