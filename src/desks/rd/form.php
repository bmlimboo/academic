<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

// Opens in its own window: mark entry for Forms 4 to 8.
require __DIR__ . '/lib.php';
$u = require_role('evaluator', 'pl', 'supervisor');
$form = (int)($_GET['form'] ?? $_POST['form'] ?? 0);
$sid = (int)($_GET['student'] ?? $_POST['student'] ?? 0);
$aid = (int)($_GET['a'] ?? $_POST['a'] ?? 0);
$F = FORMS[$form] ?? null;
$stu = user_by_id($sid);
$act = activity($aid);
if (!$F || !$stu || $stu['role'] !== 'student' || !$act) { http_response_code(404); exit('Form not found.'); }

// Who may enter which form
$ok = false;
$q = fn(string $sql, array $p) => (function () use ($sql, $p) { $st = db()->prepare($sql); $st->execute($p); return (bool)$st->fetch(); })();
if ($form === 4 && $u['role'] === 'evaluator') { $ok = $q("SELECT 1 FROM rd_allocations WHERE kind='proposal' AND student_id=? AND evaluator_id=?", [$sid, $u['id']]); }
if ($form === 6 && $u['role'] === 'evaluator') { $ok = $q("SELECT 1 FROM rd_allocations WHERE kind='project' AND student_id=? AND evaluator_id=?", [$sid, $u['id']]); }
if ($form === 7 && $u['role'] === 'pl') { $ok = $stu['department'] === $u['department']; }
if (in_array($form, [5, 8], true) && $u['role'] === 'supervisor') { $ok = $q("SELECT 1 FROM rd_supervision WHERE student_id=? AND supervisor_id=? AND status IN ('accepted','assigned')", [$sid, $u['id']]); }
if (!$ok || activity_mode($u, $aid) !== 'open' || (int)$act['form_no'] !== $form && !($act['kind'] === 'grades' && in_array($form, [5, 8], true))) {
    http_response_code(403); popup_start('Not available'); echo '<div class="flash err">You cannot enter these marks for this student, or the activity is not open.</div>'; popup_end(); exit;
}
$sv = db()->prepare('SELECT * FROM rd_supervision WHERE student_id = ?'); $sv->execute([$sid]); $sv = $sv->fetch() ?: [];
$row = marks_row($form, $sid, (int)$u['id']);
$locked = $row && $row['status'] === 'submitted';
$vals = $row ? (json_decode($row['data'], true) ?: []) : [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    csrf_check();
    $in = (array)($_POST['m'] ?? []); $vals = []; $sum = 0.0; $missing = false;
    foreach ($F['items'] as $i => [, , $max]) {
        $v = trim((string)($in[$i] ?? ''));
        if ($v === '') { $vals[$i] = null; $missing = true; continue; }
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > $max) { $errors[] = 'Marks for row ' . ($i + 1) . ' must be between 0 and ' . $max . '.'; continue; }
        $vals[$i] = round((float)$v, 2); $sum += $vals[$i];
    }
    $submit = ($_POST['do'] ?? '') === 'submit';
    if ($submit && $missing && !$errors) { $errors[] = 'Enter marks for every row before submitting. Use 0 where nothing is awarded.'; }
    if (!$errors) {
        db()->prepare("INSERT INTO rd_marks (form_no, student_id, evaluator_id, data, total, status, updated_at, submitted_at) VALUES (?,?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE data = VALUES(data), total = VALUES(total), status = VALUES(status), updated_at = VALUES(updated_at), submitted_at = VALUES(submitted_at)")
            ->execute([$form, $sid, $u['id'], json_encode($vals), $sum, $submit ? 'submitted' : 'draft', now_sql(), $submit ? now_sql() : null]);
        flash($submit ? 'Marks submitted.' : 'Draft saved.');
        redirect('form.php?form=' . $form . '&student=' . $sid . '&a=' . $aid . ($submit ? '&saved=1' : ''));
    }
}

popup_start($F['title'], $stu['name']);
foreach ($errors as $er) { echo '<div class="flash err">' . e($er) . '</div>'; }
?>
<section class="card">
  <dl class="kv">
    <dt><?= e($F['topic']) ?></dt><dd><?= e(($sv['final_topic'] ?? '') ?: ($sv['topic'] ?? '') ?: '—') ?></dd>
    <dt>Name of student</dt><dd><?= e($stu['name']) ?> <?= $stu['student_no'] ? '(' . e($stu['student_no']) . ')' : '' ?></dd>
    <dt>Department</dt><dd><?= e($stu['department']) ?></dd>
    <dt>Batch · Year</dt><dd><?= e($stu['batch'] ?: '—') ?> · <?= e(ACADEMIC_YEAR) ?></dd>
  </dl>
  <?php if ($locked): ?><div class="flash ok">Submitted on <?= e(fmt_date($row['submitted_at'])) ?>. Marks cannot be changed. Ask the DAA to unlock if a correction is needed.</div><?php endif; ?>
  <form method="post"><?= csrf_field() ?>
    <input type="hidden" name="form" value="<?= $form ?>"><input type="hidden" name="student" value="<?= $sid ?>"><input type="hidden" name="a" value="<?= $aid ?>">
    <div class="tablewrap"><table class="marktable"><thead><tr><th>Component</th><th>Criteria</th><th>Full mark</th><th>Mark obtained</th></tr></thead><tbody>
    <?php foreach ($F['items'] as $i => [$comp, $crit, $max]): ?>
      <tr><td><b><?= e($comp) ?></b></td><td><?= e($crit) ?></td><td><?= fmt_mark((float)$max) ?></td>
        <td><input class="mk" type="number" name="m[<?= $i ?>]" min="0" max="<?= $max ?>" step="0.25" value="<?= e($vals[$i] ?? '') ?>" <?= $locked ? 'readonly' : '' ?>></td></tr>
    <?php endforeach; ?>
      <tr class="total"><td colspan="2"><b>Overall Grade</b></td><td><?= fmt_mark((float)$F['max']) ?></td><td><b id="total">0</b></td></tr>
    </tbody></table></div>
    <p class="muted"><?= e($F['who']) ?>: <b><?= e($u['name']) ?></b> · Date: <?= e(date('d M Y')) ?></p>
    <?php if (!$locked): ?><div class="row"><button class="btn ghost" name="do" value="draft">Save draft</button><button class="btn" name="do" value="submit" data-confirm="Submit these marks? You cannot change them afterwards.">Submit</button></div><?php endif; ?>
  </form>
</section>
<script>(function(){var t=document.getElementById('total');function f(){var s=0;document.querySelectorAll('.mk').forEach(function(i){var v=parseFloat(i.value);if(!isNaN(v))s+=v;});t.textContent=(Math.round(s*100)/100);}document.querySelectorAll('.mk').forEach(function(i){i.addEventListener('input',f);});f();})();</script>
<?php popup_end();
