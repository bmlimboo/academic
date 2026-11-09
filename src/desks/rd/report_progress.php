<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

// Completion report for the DAA and Programme Leaders: who has completed their tasks and who has not, for every student, supervisor and evaluator.
// View on screen, download as Word, PDF or Excel.
require __DIR__ . '/lib.php';
require __DIR__ . '/lib_export.php';
$u = require_role('pl', 'daa');
$dept = current_dept($u);
$only = !empty($_GET['only']);

// A status cell. $done: true = finished, false = not finished, null = not counted (activity not open, or nothing to do)
function pr_cell(string $text, string $color, ?bool $done): array { return ['v' => $text, 'c' => $color, 'done' => $done]; }
// "3/5" cell for supervisors and evaluators
function pr_count(array $a, int $done, int $total): array
{
    if (!$a['enabled']) { return pr_cell('—', 'gray', null); }
    if ($total === 0) { return pr_cell('–', 'gray', null); }
    if ($done >= $total) { return pr_cell($done . '/' . $total, 'green', true); }
    return pr_cell($done . '/' . $total, act_overdue($a) ? 'red' : 'amber', false);
}
// Last column: how many of the person's open activities are finished
function pr_total(int $done, int $of): array
{
    if ($of === 0) { return pr_cell('–', 'gray', null); }
    return pr_cell($done . ' of ' . $of, $done >= $of ? 'green' : ($done === 0 ? 'red' : 'amber'), $done >= $of);
}
function pr_sum(array $cells): array { $of = 0; $ok = 0; foreach ($cells as $c) { if ($c['done'] !== null) { $of++; $ok += $c['done'] ? 1 : 0; } } return [$ok, $of]; }

function pr_student_cell(array $a, array $s, ?array $sub): array
{
    if (!$a['enabled']) { return pr_cell('—', 'gray', null); }
    $late = act_overdue($a);
    $pend = $late ? pr_cell('Overdue', 'red', false) : pr_cell('Pending', 'amber', false);
    switch ($a['kind']) {
        case 'supervisor_select':
            $st = (string)($s['sv_status'] ?? '');
            if (in_array($st, ['accepted', 'assigned'], true)) { return pr_cell('Done', 'green', true); }
            if ($st === 'requested') { return pr_cell('Waiting', 'amber', false); }
            return $st === 'rejected' ? pr_cell('Rejected', 'red', false) : $pend;
        case 'topic_final':
            $f = (string)($s['final_status'] ?? 'none');
            return $f === 'approved' ? pr_cell('Done', 'green', true) : ($f === 'submitted' ? pr_cell('Waiting', 'amber', false) : $pend);
        case 'student_file': case 'student_text':
            return $sub ? pr_cell('Done', 'green', true) : $pend;
        case 'supervisor_file':   // the student's part is to mark the file as received
            if (!$sub) { return pr_cell('Awaiting', 'gray', null); }
            return $sub['ack_status'] === 'received' ? pr_cell('Done', 'green', true) : $pend;
        case 'defense':
            if (!$sub) { return $pend; }
            return $sub['status'] === 'presented' ? pr_cell('Done', 'green', true) : pr_cell('Absent', 'red', false);
    }
    return pr_cell('–', 'gray', null);
}

function progress_report(array $u, string $dept, bool $only): array
{
    $acts = all_activities();
    $label = $dept !== '' ? $dept : 'All departments';
    $stats = [];

    // ================= by student
    $sActs = array_values(array_filter($acts, fn($a) => in_array($a['kind'], ['supervisor_select', 'topic_final', 'student_file', 'student_text', 'supervisor_file', 'defense'], true) && act_assigned($a, 'student')));
    $students = students_in_scope($u, $dept); $ids = ids_of($students);
    $subs = []; foreach ($sActs as $a) { $subs[(int)$a['id']] = subs_for((int)$a['id'], $ids); }
    $cols = ['Student', 'Student no', 'Department', 'Supervisor']; $hints = [];
    foreach ($sActs as $a) { $cols[] = '#' . $a['seq']; $hints[count($cols) - 1] = $a['title']; }
    $cols[] = 'Completed';
    $rows = []; $okAll = 0; $withTasks = 0;
    foreach ($students as $s) {
        $cells = []; foreach ($sActs as $a) { $cells[] = pr_student_cell($a, $s, $subs[(int)$a['id']][(int)$s['id']] ?? null); }
        [$ok, $of] = pr_sum($cells);
        if ($of > 0) { $withTasks++; if ($ok >= $of) { $okAll++; } }
        if ($only && ($of === 0 || $ok >= $of)) { continue; }
        $rows[] = array_merge([$s['name'], $s['student_no'] ?: '', $s['department'] ?: '', $s['sup_name'] ?: ''], $cells, [pr_total($ok, $of)]);
    }
    $stats[] = ['Students up to date', $okAll . ' of ' . $withTasks];
    $sections = [['key' => 'student', 'tab' => 'By student', 'heading' => 'Completion by student', 'cols' => $cols, 'hints' => $hints, 'rows' => $rows,
        'note' => 'Done = finished · Waiting = submitted, waiting for the supervisor\'s decision · Pending = open, deadline not passed · Overdue = open, deadline passed · Awaiting = supervisor has not returned the file yet (not counted) · — = activity not switched on yet. # numbers are listed under Activities.']];

    // ================= by supervisor
    $sups = users_by_role('supervisor', $dept);
    $sv = db()->query("SELECT sv.* FROM rd_supervision sv JOIN rd_users s ON s.id = sv.student_id WHERE s.role = 'student' AND s.active = 1")->fetchAll();
    $bySup = []; $matchedIds = [];
    foreach ($sv as $r) { $bySup[(int)$r['supervisor_id']][] = $r; if (in_array($r['status'], ['accepted', 'assigned'], true)) { $matchedIds[] = (int)$r['student_id']; } }
    $vActs = array_values(array_filter($acts, fn($a) => act_assigned($a, 'supervisor') && (in_array($a['kind'], ['supervisor_select', 'topic_final', 'supervisor_file', 'grades'], true)
        || (in_array($a['kind'], ['student_file', 'student_text'], true) && $a['ack_role'] === 'supervisor'))));
    $vSubs = []; foreach ($vActs as $a) { if (!in_array($a['kind'], ['supervisor_select', 'topic_final', 'grades'], true)) { $vSubs[(int)$a['id']] = subs_for((int)$a['id'], $matchedIds); } }
    $m58 = []; foreach (db()->query("SELECT student_id, form_no, evaluator_id FROM rd_marks WHERE status = 'submitted' AND form_no IN (5, 8)")->fetchAll() as $m) { $m58[(int)$m['evaluator_id']][(int)$m['student_id']][(int)$m['form_no']] = true; }
    $cols = ['Supervisor', 'Department', 'Students']; $hints = [];
    foreach ($vActs as $a) { $cols[] = '#' . $a['seq']; $hints[count($cols) - 1] = $a['title']; }
    $cols[] = 'Completed';
    $rows = []; $okAll = 0; $withTasks = 0;
    foreach ($sups as $p) {
        $pid = (int)$p['id']; $mine = $bySup[$pid] ?? [];
        $matched = array_values(array_filter($mine, fn($r) => in_array($r['status'], ['accepted', 'assigned'], true))); $n = count($matched);
        $cells = [];
        foreach ($vActs as $a) {
            $aid = (int)$a['id']; $done = 0; $total = 0;
            switch ($a['kind']) {
                case 'supervisor_select': $total = count($mine); $done = count(array_filter($mine, fn($r) => $r['status'] !== 'requested')); break;
                case 'topic_final':
                    $total = count(array_filter($matched, fn($r) => in_array($r['final_status'], ['submitted', 'approved'], true)));
                    $done = count(array_filter($matched, fn($r) => $r['final_status'] === 'approved')); break;
                case 'supervisor_file':
                    $total = $n; foreach ($matched as $r) { if (isset($vSubs[$aid][(int)$r['student_id']])) { $done++; } } break;
                case 'grades':
                    $total = $n; foreach ($matched as $r) { if (!empty($m58[$pid][(int)$r['student_id']][5]) && !empty($m58[$pid][(int)$r['student_id']][8])) { $done++; } } break;
                default:   // student_file / student_text that the supervisor must mark as received
                    foreach ($matched as $r) { $x = $vSubs[$aid][(int)$r['student_id']] ?? null; if ($x) { $total++; if ($x['ack_status'] === 'received') { $done++; } } }
            }
            $cells[] = pr_count($a, $done, $total);
        }
        [$ok, $of] = pr_sum($cells);
        if ($of > 0) { $withTasks++; if ($ok >= $of) { $okAll++; } }
        if ($only && ($of === 0 || $ok >= $of)) { continue; }
        $rows[] = array_merge([$p['name'], $p['department'] ?: '', $n], $cells, [pr_total($ok, $of)]);
    }
    $stats[] = ['Supervisors up to date', $okAll . ' of ' . $withTasks];
    $sections[] = ['key' => 'supervisor', 'tab' => 'By supervisor', 'heading' => 'Completion by supervisor', 'cols' => $cols, 'hints' => $hints, 'rows' => $rows,
        'note' => 'Each cell is tasks done / tasks to do: decisions on supervision requests, topics approved, files received or returned, and Form 5 and 8 marks. Green = all done · Amber = some left · Red = some left and the deadline has passed · – = nothing to do yet · — = activity not switched on yet.'];

    // ================= by evaluator
    $evs = users_by_role('evaluator', $dept);
    $al = []; foreach (db()->query('SELECT * FROM rd_allocations')->fetchAll() as $r) { $al[(int)$r['evaluator_id']][$r['kind']][] = $r; }
    $m46 = []; foreach (db()->query("SELECT student_id, form_no, evaluator_id FROM rd_marks WHERE status = 'submitted' AND form_no IN (4, 6)")->fetchAll() as $m) { $m46[(int)$m['evaluator_id']][(int)$m['form_no']][(int)$m['student_id']] = true; }
    $eActs = array_values(array_filter($acts, fn($a) => act_assigned($a, 'evaluator') && (($a['kind'] === 'defense' && (int)$a['form_no'] === 4) || in_array($a['kind'], ['assign_evaluators', 'evaluate'], true))));
    $cols = ['Evaluator', 'Department', 'Proposals allocated', 'Projects allocated']; $hints = [];
    foreach ($eActs as $a) { $cols[] = '#' . $a['seq']; $hints[count($cols) - 1] = $a['title']; }
    $cols[] = 'Completed';
    $rows = []; $okAll = 0; $withTasks = 0;
    foreach ($evs as $p) {
        $pid = (int)$p['id']; $prop = $al[$pid]['proposal'] ?? []; $proj = $al[$pid]['project'] ?? [];
        $cells = [];
        foreach ($eActs as $a) {
            if ($a['kind'] === 'defense') { $total = count($prop); $done = count(array_filter($prop, fn($r) => !empty($m46[$pid][4][(int)$r['student_id']]))); }
            elseif ($a['kind'] === 'assign_evaluators') { $total = count($proj); $done = count(array_filter($proj, fn($r) => $r['received_at'] !== null)); }
            else { $total = count($proj); $done = count(array_filter($proj, fn($r) => !empty($m46[$pid][6][(int)$r['student_id']]))); }
            $cells[] = pr_count($a, $done, $total);
        }
        [$ok, $of] = pr_sum($cells);
        if ($of > 0) { $withTasks++; if ($ok >= $of) { $okAll++; } }
        if ($only && ($of === 0 || $ok >= $of)) { continue; }
        $rows[] = array_merge([$p['name'], $p['department'] ?: '', count($prop), count($proj)], $cells, [pr_total($ok, $of)]);
    }
    $stats[] = ['Evaluators up to date', $okAll . ' of ' . $withTasks];
    $sections[] = ['key' => 'evaluator', 'tab' => 'By evaluator', 'heading' => 'Completion by evaluator', 'cols' => $cols, 'hints' => $hints, 'rows' => $rows,
        'note' => 'Each cell is done / allocated: Form 4 proposal marks, projects marked as received, and Form 6 project marks. Green = all done · Amber = some left · Red = some left and the deadline has passed · – = nothing allocated · — = activity not switched on yet.'];

    // ================= activity key
    $rows = [];
    foreach ($acts as $a) { $rows[] = [(int)$a['seq'], $a['title'], KIND_LABELS[$a['kind']] ?? $a['kind'], act_deadline_text($a) ?: '', $a['enabled'] ? ['v' => 'Open', 'c' => 'green'] : ['v' => 'Not open yet', 'c' => 'gray']]; }
    $sections[] = ['key' => 'activities', 'tab' => 'Activities', 'heading' => 'Activities (key to the # numbers)', 'cols' => ['#', 'Activity', 'Type', 'Deadline', 'Status'], 'rows' => $rows];

    return ['title' => 'Completion report', 'subtitle' => $label . ' · ' . ACADEMIC_YEAR . ' · Generated ' . date('d M Y, H:i') . ($only ? ' · Only people with unfinished work' : ''), 'sections' => $sections, 'stats' => $stats];
}

// ---- download
if (isset($_GET['download'])) {
    if ($u['role'] === 'pl' && $dept === '') { flash('Select a department before downloading.', 'err'); redirect('report_progress.php'); }
    $rep = progress_report($u, $dept, $only);
    $part = (string)($_GET['part'] ?? 'all');
    if ($part !== 'all') { $rep['sections'] = array_values(array_filter($rep['sections'], fn($s) => $s['key'] === $part || ($part !== 'activities' && $s['key'] === 'activities'))); }
    rx_send($rep, (string)$_GET['download'], 'Completion report - ' . ($dept !== '' ? $dept : 'All departments') . ' - ' . date('Y-m-d'));
}

// ---- screen
$rep = progress_report($u, $dept, $only);
$tabs = []; foreach ($rep['sections'] as $s) { $tabs[$s['key']] = $s['tab']; }
$tab = (string)($_GET['tab'] ?? 'student'); if (!isset($tabs[$tab])) { $tab = 'student'; }
$cur = array_values(array_filter($rep['sections'], fn($s) => $s['key'] === $tab))[0];
$keep = $only ? ['only' => 1] : [];

layout_start('Completion report', 'rprog', $u['role'] === 'pl' ? 'Programme Leader' : 'DAA');
echo rx_css();
?>
<p class="lead">Who has completed their tasks and who has not, activity by activity. Switch between students, supervisors and evaluators, and download the table as Word, PDF or Excel.</p>
<div class="stats" style="grid-template-columns:repeat(3,1fr)"><?php foreach ($rep['stats'] as [$l, $v]): ?><div class="stat"><span><?= e($l) ?></span><b><?= e($v) ?></b></div><?php endforeach; ?></div>
<div class="cols2">
  <section class="card slim"><div class="cardhead"><h2>View</h2></div>
    <?= dept_select($dept, ['tab' => $tab] + $keep) ?>
    <form method="get" style="margin-top:10px"><input type="hidden" name="tab" value="<?= e($tab) ?>"><label class="rxcheck"><input type="checkbox" name="only" value="1" <?= $only ? 'checked' : '' ?> onchange="this.form.submit()"> Show only people with unfinished work</label></form></section>
  <section class="card slim"><div class="cardhead"><h2>Download</h2></div>
    <?php if ($u['role'] === 'pl' && $dept === ''): ?><span class="muted">Select a department to download.</span>
    <?php else: echo rx_download_bar('report_progress.php', $tab, $tabs[$tab], ['tab' => $tab] + $keep); endif; ?></section>
</div>
<?= rx_tabs('report_progress.php', $tabs, $tab, $keep) ?>
<section class="card"><div class="cardhead"><h2><?= e($cur['heading']) ?></h2><span class="muted"><?= count($cur['rows']) ?> row<?= count($cur['rows']) === 1 ? '' : 's' ?></span></div>
  <?php if (!empty($cur['note'])): ?><p class="muted"><?= e($cur['note']) ?></p><?php endif; ?>
  <?= rx_table_html($cur) ?></section>
<?php layout_end();
