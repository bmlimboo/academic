<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

// DAA and Programme Leader: consolidated, dated marks from Forms 4, 5, 6, 7 and 8, by department.
require __DIR__ . '/lib.php';
require __DIR__ . '/report_marks_matrix.php';
$u = require_role('pl', 'daa');
$dept = current_dept($u);

// DAA can unlock a submitted mark sheet so the person can correct it
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $u['role'] === 'daa') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'unlock') {
        db()->prepare("UPDATE rd_marks SET status = 'draft', submitted_at = NULL WHERE id = ?")->execute([(int)($_POST['mid'] ?? 0)]);
        flash('Unlocked. The person can correct and submit again.');
    }
    redirect('marks.php');
}

$students = students_in_scope($u, $dept);
$ids = ids_of($students);
$M = []; foreach ([4, 5, 6, 7, 8] as $f) { $M[$f] = marks_map($f, $ids); }
function latest_date(array $rows): ?string { $d = null; foreach ($rows as $r) { if ($d === null || $r['submitted_at'] > $d) { $d = $r['submitted_at']; } } return $d; }

// One consolidated record per student
$table = [];
foreach ($students as $s) {
    $sid = (int)$s['id']; $rec = ['s' => $s, 'f' => [], 'total' => 0.0, 'n' => 0, 'date' => null];
    foreach ([4, 5, 6, 7, 8] as $f) {
        $rows = $M[$f][$sid] ?? [];
        $avg = avg_marks($rows);
        $rec['f'][$f] = ['avg' => $avg, 'date' => latest_date($rows), 'count' => count($rows)];
        if ($avg !== null) { $rec['total'] += weighted($f, $avg); $rec['n']++; if ($rec['date'] === null || $rec['f'][$f]['date'] > $rec['date']) { $rec['date'] = $rec['f'][$f]['date']; } }
    }
    $table[] = $rec;
}

// ---- CSV downloads: ?csv=all | 4..8
if (isset($_GET['csv'])) {
    $which = (string)$_GET['csv'];
    if ($u['role'] === 'pl' && $dept === '') { flash('Select a department before downloading.', 'err'); redirect('marks.php'); }
    $label = $dept !== '' ? name_part($dept, 40) : 'All departments';
    header('Content-Type: text/csv; charset=utf-8');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    if ($which === 'all') {
        header('Content-Disposition: attachment; filename="Consolidated marks - ' . $label . ' - ' . date('Y-m-d') . '.csv"');
        fputcsv($out, ['Student', 'Student no', 'Department', 'Batch', 'Title of work', 'Form 4 /15', 'Form 4 date', 'Form 5 /5', 'Form 5 date', 'Form 6 /100', 'Form 6 date', 'Form 6 as 30%', 'Form 7 /40', 'Form 7 date', 'Form 8 /10', 'Form 8 date', 'Total /100 (forms submitted so far)']);
        foreach ($table as $r) {
            $s = $r['s']; $f = $r['f'];
            fputcsv($out, [$s['name'], $s['student_no'], $s['department'], $s['batch'], work_title($s),
                fmt_mark($f[4]['avg']), $f[4]['date'] ? fmt_day($f[4]['date']) : '', fmt_mark($f[5]['avg']), $f[5]['date'] ? fmt_day($f[5]['date']) : '',
                fmt_mark($f[6]['avg']), $f[6]['date'] ? fmt_day($f[6]['date']) : '', fmt_mark(weighted(6, $f[6]['avg'])),
                fmt_mark($f[7]['avg']), $f[7]['date'] ? fmt_day($f[7]['date']) : '', fmt_mark($f[8]['avg']), $f[8]['date'] ? fmt_day($f[8]['date']) : '', $r['n'] ? fmt_mark($r['total']) : '']);
        }
    } elseif (isset(FORMS[(int)$which])) {
        $fn = (int)$which;
        header('Content-Disposition: attachment; filename="Form ' . $fn . ' marks - ' . $label . ' - ' . date('Y-m-d') . '.csv"');
        fputcsv($out, ['Student', 'Student no', 'Department', 'Title of work', 'Marked by', 'Marks', 'Full marks', 'Date']);
        foreach ($students as $s) { foreach ($M[$fn][$s['id']] ?? [] as $m) { fputcsv($out, [$s['name'], $s['student_no'], $s['department'], work_title($s), $m['evaluator_name'], fmt_mark((float)$m['total']), fmt_mark(form_max($fn)), fmt_day($m['submitted_at'])]); } }
    }
    fclose($out); exit;
}

layout_start('Consolidated marks', 'marks', $u['role'] === 'pl' ? 'Programme Leader' : 'DAA');
?>
<p class="lead">Marks from Forms 4, 5, 6, 7 and 8 in one table with the dates they were submitted. Where two examiners marked a form, the average is used. The total adds each form's share of the 100 marks: Form 4 (15%), Form 5 (5%), Form 6 (30%), Form 7 (40%), Form 8 (10%).</p>
<div class="cols2"><section class="card slim"><div class="cardhead"><h2>View by department</h2></div><?= dept_select($dept) ?></section>
  <section class="card slim"><div class="cardhead"><h2>Download</h2></div>
    <?php if ($u['role'] === 'pl' && $dept === ''): ?><span class="muted">Select a department to download.</span><?php else: ?>
    <div class="row"><a class="btn small" href="marks.php?csv=all">Consolidated CSV</a>
      <?php foreach ([4, 5, 6, 7, 8] as $f): ?><a class="btn small ghost" href="marks.php?csv=<?= $f ?>">Form <?= $f ?></a><?php endforeach; ?></div><?php endif; ?></section></div>

<?= rmx_css() . rmx_consolidated_html($table, $dept) ?>

<?php if ($u['role'] === 'daa'):
    $all = db()->query("SELECT m.*, s.name sname, u.name ename FROM rd_marks m JOIN rd_users s ON s.id = m.student_id JOIN rd_users u ON u.id = m.evaluator_id WHERE m.status = 'submitted' AND m.student_id IN (" . in_list($ids) . ') ORDER BY m.submitted_at DESC LIMIT 200')->fetchAll(); ?>
<section class="card"><div class="cardhead"><h2>Submitted mark sheets</h2></div><div class="tablewrap"><table><thead><tr><th>Student</th><th>Form</th><th>Marked by</th><th>Marks</th><th>Date</th><th></th></tr></thead><tbody>
  <?php foreach ($all as $m): ?><tr><td><?= e($m['sname']) ?></td><td>Form <?= (int)$m['form_no'] ?></td><td><?= e($m['ename']) ?></td><td><?= fmt_mark((float)$m['total']) ?></td><td class="nowrap"><?= e(fmt_date($m['submitted_at'])) ?></td>
    <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="unlock"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><button class="btn small ghost" data-confirm="Unlock this mark sheet so it can be corrected?">Unlock</button></form></td></tr><?php endforeach; ?>
  <?php if (!$all): ?><tr><td colspan="6" class="empty">Nothing submitted yet.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif;
layout_end();
