<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

// Marks report for the DAA and Programme Leaders: all marks from Forms 4, 5, 6, 7 and 8. View on screen, download as Word, PDF or Excel.
require __DIR__ . '/lib.php';
require __DIR__ . '/lib_export.php';
require __DIR__ . '/report_marks_matrix.php';
$u = require_role('pl', 'daa');
$dept = current_dept($u);

function marks_report(array $u, string $dept): array
{
    $students = students_in_scope($u, $dept);
    $ids = ids_of($students);
    $M = []; foreach ([4, 5, 6, 7, 8] as $f) { $M[$f] = marks_map($f, $ids); }
    $label = $dept !== '' ? $dept : 'All departments';

    // ---- summary: one line per student
    $cols = ['Student', 'Student no', 'Department', 'Supervisor', 'Title of work'];
    foreach ([4, 5, 6, 7, 8] as $f) { $cols[] = 'Form ' . $f . ' /' . fmt_mark(form_max($f)); }
    array_splice($cols, 8, 0, ['Form 6 as ' . FORMS[6]['weight'] . '%']);
    $cols[] = 'Total /100'; $cols[] = 'Forms marked';
    $rows = [];
    foreach ($students as $s) {
        $sid = (int)$s['id']; $avg = []; $total = 0.0; $n = 0;
        foreach ([4, 5, 6, 7, 8] as $f) {
            $a = avg_marks($M[$f][$sid] ?? []); $avg[$f] = $a === null ? null : round($a, 2);
            if ($a !== null) { $total += (float)weighted($f, $a); $n++; }
        }
        $w6 = $avg[6] === null ? null : round((float)weighted(6, $avg[6]), 2);
        $rows[] = [$s['name'], $s['student_no'] ?: '', $s['department'] ?: '', $s['sup_name'] ?: '', work_title($s), $avg[4], $avg[5], $avg[6], $w6, $avg[7], $avg[8],
            $n ? round($total, 2) : null, ['v' => $n . ' of 5', 'c' => $n >= 5 ? 'green' : ($n ? 'amber' : 'gray')]];
    }
    $sections = [['key' => 'summary', 'tab' => 'Summary', 'heading' => 'Summary of all forms', 'cols' => $cols, 'rows' => $rows,
        'note' => 'Where two examiners marked a form, the average is shown. Total = each form\'s share of 100 (Form 4 ' . FORMS[4]['weight'] . '%, Form 5 ' . FORMS[5]['weight'] . '%, Form 6 ' . FORMS[6]['weight'] . '%, Form 7 ' . FORMS[7]['weight'] . '%, Form 8 ' . FORMS[8]['weight'] . '%) for the forms submitted so far.']];

    // ---- one section per form: every submitted mark sheet with the marks for each row of the form
    $byId = []; foreach ($students as $s) { $byId[(int)$s['id']] = $s; }
    foreach ([4, 5, 6, 7, 8] as $f) {
        $F = FORMS[$f]; $names = array_column($F['items'], 0); $count = array_count_values($names); $seen = [];
        $cols = ['Student', 'Student no', 'Department', 'Marked by', 'Date'];
        foreach ($F['items'] as $it) {
            $nm = $it[0]; $seen[$nm] = ($seen[$nm] ?? 0) + 1;
            $cols[] = $nm . ($count[$nm] > 1 ? ' ' . $seen[$nm] : '') . ' (' . fmt_mark((float)$it[2]) . ')';
        }
        $cols[] = 'Total /' . fmt_mark(form_max($f));
        $rows = [];
        foreach ($students as $s) {
            foreach ($M[$f][(int)$s['id']] ?? [] as $m) {
                $vals = json_decode((string)$m['data'], true) ?: [];
                $row = [$s['name'], $s['student_no'] ?: '', $s['department'] ?: '', $m['evaluator_name'], fmt_day($m['submitted_at'])];
                foreach ($F['items'] as $i => $it) { $row[] = isset($vals[$i]) && $vals[$i] !== null ? round((float)$vals[$i], 2) : null; }
                $row[] = round((float)$m['total'], 2);
                $rows[] = $row;
            }
        }
        $sections[] = ['key' => 'f' . $f, 'tab' => 'Form ' . $f, 'heading' => $F['title'], 'cols' => $cols, 'rows' => $rows,
            'note' => 'Marked by: ' . $F['who'] . '. Full mark for each row is shown in brackets. One line per submitted mark sheet.'];
    }
    return ['title' => 'Marks report: Forms 4 to 8', 'subtitle' => $label . ' · ' . ACADEMIC_YEAR . ' · Generated ' . date('d M Y, H:i') . ' · Only submitted mark sheets are included.', 'sections' => $sections];
}

// ---- download
if (isset($_GET['download'])) {
    if ($u['role'] === 'pl' && $dept === '') { flash('Select a department before downloading.', 'err'); redirect('report_marks.php'); }
    $rep = marks_report($u, $dept);
    $part = (string)($_GET['part'] ?? 'all');
    if ($part !== 'all') { $rep['sections'] = array_values(array_filter($rep['sections'], fn($s) => $s['key'] === $part)); }
    if (!$rep['sections']) { redirect('report_marks.php'); }
    rx_send($rep, (string)$_GET['download'], 'Marks report - ' . ($dept !== '' ? $dept : 'All departments') . ' - ' . date('Y-m-d'));
}

// ---- screen
$rep = marks_report($u, $dept);
$tabs = []; foreach ($rep['sections'] as $s) { $tabs[$s['key']] = $s['tab']; }
$tab = (string)($_GET['tab'] ?? 'summary'); if (!isset($tabs[$tab])) { $tab = 'summary'; }
$cur = array_values(array_filter($rep['sections'], fn($s) => $s['key'] === $tab))[0];

layout_start('Marks report', 'rmarks', $u['role'] === 'pl' ? 'Programme Leader' : 'DAA');
echo rx_css() . rmx_css();
?>
<p class="lead">All marks from Forms 4, 5, 6, 7 and 8. Open the Summary for one line per student, or a form tab to see every mark sheet and the marks given for each row. Download as Word, PDF or Excel.</p>
<div class="cols2">
  <section class="card slim"><div class="cardhead"><h2>View by department</h2></div><?= dept_select($dept, ['tab' => $tab]) ?></section>
  <section class="card slim"><div class="cardhead"><h2>Download</h2></div>
    <?php if ($u['role'] === 'pl' && $dept === ''): ?><span class="muted">Select a department to download.</span>
    <?php else: echo rx_download_bar('report_marks.php', $tab, $tabs[$tab], ['tab' => $tab]); endif; ?></section>
</div>
<?= rx_tabs('report_marks.php', $tabs, $tab, []) ?>
<section class="card"><div class="cardhead"><h2><?= e($cur['heading']) ?></h2><span class="muted"><?= count($cur['rows']) ?> row<?= count($cur['rows']) === 1 ? '' : 's' ?></span></div>
  <?php if (!empty($cur['note'])): ?><p class="muted"><?= e($cur['note']) ?></p><?php endif; ?>
  <?= $tab === 'summary' ? rmx_matrix_html($cur) : rx_table_html($cur) ?></section>
<?php layout_end();
