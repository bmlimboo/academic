<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

// DAA: switch activities on/off, edit every column, assign roles, share the timeline document.
require __DIR__ . '/lib.php';
$u = require_role('daa');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['action'] ?? ''); $aid = (int)($_POST['aid'] ?? 0);
    try {
        if ($act === 'toggle' && ($a = activity($aid))) {
            db()->prepare('UPDATE rd_activities SET enabled = ? WHERE id = ?')->execute([$a['enabled'] ? 0 : 1, $aid]);
            flash('"' . $a['title'] . '" is now ' . ($a['enabled'] ? 'OFF.' : 'ON.'));
        } elseif ($act === 'upto' && ($a = activity($aid))) {
            db()->prepare('UPDATE rd_activities SET enabled = IF(seq <= ?, 1, 0)')->execute([$a['seq']]);
            flash('Activities 1 to ' . $a['seq'] . ' are ON. The rest are OFF.');
        } elseif ($act === 'all') {
            db()->exec('UPDATE rd_activities SET enabled = ' . (($_POST['to'] ?? '') === 'on' ? 1 : 0));
            flash(($_POST['to'] ?? '') === 'on' ? 'All activities are ON.' : 'All activities are OFF.');
        } elseif ($act === 'delete' && ($a = activity($aid))) {
            if ($a['is_core']) { throw new RuntimeException('Activities from the RES402 timeline cannot be deleted. Switch them off instead.'); }
            foreach (files_of($aid) as $f) { delete_file_row($f); }
            db()->prepare('DELETE FROM rd_activities WHERE id = ?')->execute([$aid]);
            flash('Activity deleted.');
        } elseif ($act === 'add') {
            $title = trim((string)($_POST['title'] ?? '')); $kind = (string)($_POST['kind'] ?? '');
            if ($title === '' || mb_strlen($title) > 200) { throw new RuntimeException('Enter the activity name.'); }
            if (!in_array($kind, ['publish', 'student_file', 'supervisor_file', 'student_text'], true)) { throw new RuntimeException('Choose a type.'); }
            $seq = (int)db()->query('SELECT COALESCE(MAX(seq),0) + 1 FROM rd_activities')->fetchColumn();
            db()->prepare("INSERT INTO rd_activities (seq, title, kind, ack_role, roles, enabled, is_core) VALUES (?,?,?,?,?,0,0)")
                ->execute([$seq, $title, $kind, $kind === 'supervisor_file' ? 'student' : ($kind === 'student_text' ? 'supervisor' : ''), 'student,supervisor,pl']);
            flash('Added as activity ' . $seq . ' (switched off). Open Edit to set the dates and instructions.');
        } elseif ($act === 'timeline_doc') {
            [$s, $o] = save_doc($_FILES['file'] ?? []);
            foreach (db()->query("SELECT * FROM rd_files WHERE label = 'timeline'")->fetchAll() as $old) { delete_file_row($old); }
            add_file(null, null, $u, $s, $o, 'timeline', 'all');
            flash('Timeline document shared with everyone.');
        }
    } catch (RuntimeException $ex) { flash($ex->getMessage(), 'err'); }
    redirect('daa_timeline.php');
}

$acts = all_activities();
$tl = db()->query("SELECT * FROM rd_files WHERE label = 'timeline' ORDER BY id DESC LIMIT 1")->fetch();
layout_start('Manage timeline', 'tl', 'DAA');
?>
<p class="lead">Switch an activity <b>on</b> when it should open. People see the activities before the first one that is switched off, and only the <b>next two</b> after it, marked "Upcoming". Everything later stays hidden.</p>

<div class="cols2">
  <section class="card slim"><div class="cardhead"><h2>Timeline document (Word or PDF)</h2></div>
    <?php if ($tl): ?><div class="fileline"><a href="file.php?id=<?= (int)$tl['id'] ?>" target="_blank"><?= e($tl['original_name']) ?></a> <span class="muted"><?= e(fmt_day($tl['created_at'])) ?></span></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="formcard flat"><?= csrf_field() ?><input type="hidden" name="action" value="timeline_doc">
      <label>Upload a new one. Everyone can view it.<input type="file" name="file" required accept=".docx,.doc,.pdf" data-max="<?= MAX_UPLOAD ?>"></label><button class="btn small">Upload and share</button></form></section>
  <section class="card slim"><div class="cardhead"><h2>Quick actions</h2></div>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="all"><button class="btn small" name="to" value="on">Switch all on</button><button class="btn small ghost" name="to" value="off" data-confirm="Switch all activities off?">Switch all off</button></form>
    <p class="muted">Or use "On up to here" on a row to open everything up to that activity.</p></section>
</div>

<section class="card"><div class="tablewrap"><table class="tl"><thead><tr><th>#</th><th>Activity</th><th>Deadline</th><th>T0</th><th>Assigned to</th><th>State</th><th></th></tr></thead><tbody>
<?php foreach ($acts as $a): ?>
  <tr><td><?= (int)$a['seq'] ?></td><td><b><?= e($a['title']) ?></b><div class="muted"><?= e(KIND_LABELS[$a['kind']] ?? $a['kind']) ?><?= $a['form_no'] ? ' · Form ' . (int)$a['form_no'] : '' ?></div></td>
    <td class="nowrap"><?= e(act_deadline_text($a)) ?></td><td class="nowrap"><?= e($a['t0_text']) ?></td>
    <td class="small"><?= e(implode(', ', array_map(fn($r) => ROLES[$r] ?? $r, act_roles($a)))) ?: '<span class="muted">nobody</span>' ?></td>
    <td><?= $a['enabled'] ? badge('On', 'green') : badge('Off', 'red') ?></td>
    <td class="nowrap"><form method="post" class="inlineform"><?= csrf_field() ?><input type="hidden" name="aid" value="<?= (int)$a['id'] ?>">
        <button class="btn small <?= $a['enabled'] ? 'ghost' : '' ?>" name="action" value="toggle"><?= $a['enabled'] ? 'Switch off' : 'Switch on' ?></button>
        <button class="btn small ghost" name="action" value="upto" data-confirm="Switch ON activities 1 to <?= (int)$a['seq'] ?> and OFF everything after?">On up to here</button>
        <?= popup_link('daa_edit.php?id=' . (int)$a['id'], 'Edit', 'btn small ghost') ?>
        <?php if (!$a['is_core']): ?><button class="btn small danger" name="action" value="delete" data-confirm="Delete this activity and its files?">Delete</button><?php endif; ?></form></td></tr>
<?php endforeach; ?></tbody></table></div></section>

<section class="card"><h2>Add an activity</h2><p class="muted" style="margin-top:4px">For extra steps that are not in the RES402 document. Choose how it behaves.</p>
  <form method="post" class="formcard flat"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="row2"><label>Name<input name="title" maxlength="200" required></label>
    <label>Type<select name="kind"><option value="student_file">Student uploads a file (to supervisor)</option><option value="supervisor_file">Supervisor returns a file (to student)</option><option value="student_text">Student shares comments (to supervisor)</option><option value="publish">DAA shares a document</option></select></label></div>
    <button class="btn" type="submit">Add</button></form></section>
<?php layout_end();
