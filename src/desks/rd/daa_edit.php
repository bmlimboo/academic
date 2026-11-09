<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

// Opens in its own window: the DAA edits every column of one timeline row.
require __DIR__ . '/lib.php';
$u = require_role('daa');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$a = activity($id);
if (!$a) { http_response_code(404); exit('Activity not found.'); }
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) { throw new RuntimeException('Enter the activity name.'); }
        $d = fn(string $k) => ($v = trim((string)($_POST[$k] ?? ''))) !== '' && strtotime($v) ? date('Y-m-d', strtotime($v)) : null;
        $from = $d('deadline_from'); $to = $d('deadline_to');
        if ($to && $from && $to < $from) { throw new RuntimeException('The end date is before the start date.'); }
        $roles = implode(',', array_values(array_intersect(array_keys(ROLES), (array)($_POST['roles'] ?? []))));
        $roles = implode(',', array_filter(explode(',', $roles), fn($r) => $r !== 'daa'));
        $set = ['title' => $title, 'deadline_from' => $from, 'deadline_to' => $to, 't0_text' => mb_substr(trim((string)($_POST['t0_text'] ?? '')), 0, 80), 'roles' => $roles,
                'enabled' => !empty($_POST['enabled']) ? 1 : 0, 'eval_files' => max(0, min(2, (int)($_POST['eval_files'] ?? 0))), 'pl_download' => !empty($_POST['pl_download']) ? 1 : 0];
        if (in_array($a['kind'], ['student_file', 'student_text'], true)) { $set['ack_role'] = in_array($_POST['ack_role'] ?? '', ['', 'supervisor', 'pl'], true) ? $_POST['ack_role'] : ''; }
        foreach (['student', 'supervisor', 'daa', 'pl', 'evaluator'] as $r) { $set['txt_' . $r] = mb_substr(trim((string)($_POST['txt_' . $r] ?? '')), 0, 3000); }
        db()->prepare('UPDATE rd_activities SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?')->execute([...array_values($set), $id]);
        flash('Saved.'); redirect('daa_edit.php?id=' . $id . '&saved=1');
    } catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
}
popup_start('Edit: ' . $a['title'], 'Activity ' . (int)$a['seq']);
foreach ($errors as $er) { echo '<div class="flash err">' . e($er) . '</div>'; }
$rs = act_roles($a);
?>
<form method="post" class="card formcard"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <label>Activity<input name="title" value="<?= e($a['title']) ?>" maxlength="200" required></label>
  <div class="row3"><label>Deadline (from)<input type="date" name="deadline_from" value="<?= e($a['deadline_from']) ?>"></label>
    <label>Deadline (to), if a range<input type="date" name="deadline_to" value="<?= e($a['deadline_to']) ?>"></label>
    <label>Deadline (T0)<input name="t0_text" value="<?= e($a['t0_text']) ?>" maxlength="80" placeholder="e.g. August 7 2026 or Monday"></label></div>
  <p style="margin:10px 0 4px;font-weight:600">Assigned to</p>
  <?php foreach (['student', 'supervisor', 'pl', 'evaluator'] as $r): ?><label class="chkline"><input type="checkbox" name="roles[]" value="<?= $r ?>" <?= in_array($r, $rs, true) ? 'checked' : '' ?>> <?= e(ROLES[$r]) ?></label><?php endforeach; ?>
  <label class="chkline"><input type="checkbox" name="enabled" value="1" <?= $a['enabled'] ? 'checked' : '' ?>> Switched on</label>
  <div class="row3">
    <?php if (in_array($a['kind'], ['student_file', 'student_text'], true)): ?><label>Received by<select name="ack_role"><option value="">Nobody (just visible)</option><option value="supervisor" <?= $a['ack_role'] === 'supervisor' ? 'selected' : '' ?>>Supervisor</option><option value="pl" <?= $a['ack_role'] === 'pl' ? 'selected' : '' ?>>Programme Leader</option></select></label><?php endif; ?>
    <label>Evaluators can view the student's file<select name="eval_files"><?php foreach (EVAL_FILES as $k => $l): ?><option value="<?= $k ?>" <?= (int)$a['eval_files'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label class="chkline" style="align-self:end"><input type="checkbox" name="pl_download" value="1" <?= $a['pl_download'] ? 'checked' : '' ?>> Programme Leader may download</label></div>
  <h3>What each role sees and does</h3>
  <?php foreach (['student' => 'Student', 'supervisor' => 'Supervisor', 'daa' => 'DAA (Coordinator)', 'pl' => 'Programme Leader', 'evaluator' => 'Evaluator'] as $r => $l): ?>
    <label><?= e($l) ?><textarea name="txt_<?= $r ?>" rows="4"><?= e($a['txt_' . $r]) ?></textarea></label>
  <?php endforeach; ?>
  <p class="muted">The type of activity (<?= e(KIND_LABELS[$a['kind']] ?? $a['kind']) ?>) decides which buttons people get and cannot be changed here.</p>
  <button class="btn" type="submit">Save</button>
</form>
<?php popup_end();
