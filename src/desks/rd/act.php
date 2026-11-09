<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

// Opens in its own window: the actions, uploads and lists for one timeline activity, for the signed-in role.
require __DIR__ . '/lib.php';
require_once __DIR__ . '/lib_evalreturn.php';
$u = require_login();
$role = $u['role'];
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$a = activity($id);
if (!$a) { http_response_code(404); exit('Activity not found.'); }
$mode = activity_mode($u, $id);
if ($mode !== 'open' && !($role === 'daa')) {
    http_response_code(403);
    popup_start($a['title']);
    echo '<div class="flash err">' . ($mode === 'upcoming' ? 'This activity is upcoming and not open yet.' : 'This activity is not available to you.') . '</div>';
    popup_end(); exit;
}
$kind = $a['kind'];
$aid = $id;
$dept = current_dept($u);
$errors = [];

function back(int $id, string $msg): void { \deskResearchNotice($id,$msg);flash($msg); redirect('act.php?id=' . $id . '&saved=1'); }
function my_student(array $u, int $sid): bool     // is $sid one of this supervisor's accepted students?
{
    $st = db()->prepare("SELECT 1 FROM rd_supervision WHERE student_id = ? AND supervisor_id = ? AND status IN ('accepted','assigned')"); $st->execute([$sid, $u['id']]);
    return (bool)$st->fetch();
}
function need_text(string $v, int $max, string $what): string
{
    $v = trim($v);
    if ($v === '' || mb_strlen($v) > $max) { throw new RuntimeException($what . ' is required (up to ' . $max . ' characters).'); }
    return $v;
}

// =========================== actions ===========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['action'] ?? '');
    if($role==='pl' && !empty($_POST['student_id'])){$scopeStudent=user_by_id((int)$_POST['student_id']);if(!$scopeStudent || $scopeStudent['department']!==$u['department']){http_response_code(403);exit('Student is outside your department.');}}
    try {
        if ($act === 'delete_file' && $role === 'daa') {
            $st = db()->prepare('SELECT * FROM rd_files WHERE id = ?'); $st->execute([(int)($_POST['fid'] ?? 0)]);
            if ($f = $st->fetch()) { delete_file_row($f); }
            back($id, 'File deleted.');
        }
        eval_return_handle($u, $a, $act, $id);
        switch ($kind) {
            case 'publish':
                if ($act === 'publish_upload' && $role === 'daa') {
                    $rc = array_values(array_intersect(['student', 'supervisor', 'pl', 'evaluator'], (array)($_POST['receivers'] ?? [])));
                    if (!$rc) { throw new RuntimeException('Select at least one receiver.'); }
                    [$s, $o] = save_doc($_FILES['file'] ?? []);
                    add_file($aid, null, $u, $s, $o, trim((string)($_POST['label'] ?? '')), implode(',', $rc));
                    back($id, 'Document shared with: ' . implode(', ', array_map(fn($r) => ROLES[$r], $rc)) . '.');
                }
                break;

            case 'supervisor_select':
                if ($act === 'choose_sup' && $role === 'student') {
                    $sv = db()->prepare('SELECT status FROM rd_supervision WHERE student_id = ?'); $sv->execute([$u['id']]); $cur = $sv->fetch();
                    if ($cur && in_array($cur['status'], ['accepted', 'assigned'], true)) { throw new RuntimeException('You already have a supervisor.'); }
                    $sup = user_by_id((int)($_POST['supervisor_id'] ?? 0));
                    if (!$sup || !in_array($sup['role'],['supervisor','pl','daa'],true) || !$sup['active']) { throw new RuntimeException('Choose a supervisor from the list.'); }
                    $topic = need_text((string)($_POST['topic'] ?? ''), 300, 'The topic');
                    db()->prepare("INSERT INTO rd_supervision (student_id, supervisor_id, topic, status, sup_comment, updated_at) VALUES (?,?,?,'requested',NULL,?)
                                   ON DUPLICATE KEY UPDATE supervisor_id = VALUES(supervisor_id), topic = VALUES(topic), status = 'requested', sup_comment = NULL, updated_at = VALUES(updated_at)")
                        ->execute([$u['id'], $sup['id'], $topic, now_sql()]);
                    back($id, 'Submitted to ' . $sup['name'] . '.');
                }
                if ($act === 'sup_decide' && $role === 'supervisor') {
                    $sid = (int)($_POST['student_id'] ?? 0); $dec = ($_POST['decision'] ?? '') === 'accept' ? 'accepted' : 'rejected';
                    $note = mb_substr(trim((string)($_POST['comment'] ?? '')), 0, 400);
                    $st = db()->prepare("UPDATE rd_supervision SET status = ?, sup_comment = ?, updated_at = ? WHERE student_id = ? AND supervisor_id = ? AND status = 'requested'");
                    $st->execute([$dec, $note ?: null, now_sql(), $sid, $u['id']]);
                    if (!$st->rowCount()) { throw new RuntimeException('That request is no longer waiting for your decision.'); }
                    back($id, $dec === 'accepted' ? 'Accepted.' : 'Marked as not accepted.');
                }
                if ($act === 'assign_sup' && $role === 'daa') {
                    $sid = (int)($_POST['student_id'] ?? 0); $stu = user_by_id($sid); $sup = user_by_id((int)($_POST['supervisor_id'] ?? 0));
                    if (!$stu || $stu['role'] !== 'student' || !$sup || !in_array($sup['role'],['supervisor','pl','daa'],true)) { throw new RuntimeException('Choose a student and a supervisor.'); }
                    $sv = db()->prepare('SELECT status FROM rd_supervision WHERE student_id = ?'); $sv->execute([$sid]); $cur = $sv->fetch();
                    if ($cur && in_array($cur['status'], ['accepted', 'assigned'], true)) { throw new RuntimeException('This student already has a supervisor.'); }
                    db()->prepare("INSERT INTO rd_supervision (student_id, supervisor_id, topic, status, sup_comment, updated_at) VALUES (?,?,?,'assigned',NULL,?)
                                   ON DUPLICATE KEY UPDATE supervisor_id = VALUES(supervisor_id), status = 'assigned', sup_comment = NULL, updated_at = VALUES(updated_at)")
                        ->execute([$sid, $sup['id'], mb_substr(trim((string)($_POST['topic'] ?? '')), 0, 300), now_sql()]);
                    back($id, $sup['name'] . ' assigned to ' . $stu['name'] . '.');
                }
                break;

            case 'topic_final':
                if ($act === 'final_topic' && $role === 'student') {
                    $sv = db()->prepare("SELECT * FROM rd_supervision WHERE student_id = ? AND status IN ('accepted','assigned')"); $sv->execute([$u['id']]); $row = $sv->fetch();
                    if (!$row) { throw new RuntimeException('Your supervisor must accept you before you can finalize the topic.'); }
                    if ($row['final_status'] === 'approved') { throw new RuntimeException('The topic is already approved.'); }
                    $t = need_text((string)($_POST['final_topic'] ?? ''), 400, 'The finalized topic');
                    db()->prepare("UPDATE rd_supervision SET final_topic = ?, final_status = 'submitted', final_comment = NULL, updated_at = ? WHERE student_id = ?")->execute([$t, now_sql(), $u['id']]);
                    back($id, 'Topic submitted to your supervisor.');
                }
                if ($act === 'approve_topic' && $role === 'supervisor') {
                    $sid = (int)($_POST['student_id'] ?? 0);
                    $st = db()->prepare("UPDATE rd_supervision SET final_status = 'approved', final_comment = ?, updated_at = ? WHERE student_id = ? AND supervisor_id = ? AND final_status = 'submitted'");
                    $st->execute([mb_substr(trim((string)($_POST['comment'] ?? '')), 0, 400) ?: null, now_sql(), $sid, $u['id']]);
                    if (!$st->rowCount()) { throw new RuntimeException('There is no submitted topic to approve.'); }
                    back($id, 'Topic approved and completed.');
                }
                break;

            case 'student_file': case 'supervisor_file': case 'student_text':
                $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500) ?: null;
                if ($act === 'upload' && $role === 'student' && $kind === 'student_file') {
                    $cur = submission($aid, (int)$u['id']);
                    if ($cur && $a['ack_role'] && $cur['ack_status'] === 'received') { throw new RuntimeException('Your submission was already received. It can no longer be replaced.'); }
                    [$s, $o] = save_doc($_FILES['file'] ?? []);
                    add_file($aid, (int)$u['id'], $u, $s, $o, '');
                    save_submission($aid, (int)$u['id'], 'submitted', null, $note);
                    back($id, 'Submitted and completed.');
                }
                if ($act === 'share' && $role === 'student' && $kind === 'student_text') {
                    $cur = submission($aid, (int)$u['id']);
                    if ($cur && $cur['ack_status'] === 'received') { throw new RuntimeException('Your comments were already received.'); }
                    save_submission($aid, (int)$u['id'], 'submitted', need_text((string)($_POST['body'] ?? ''), 5000, 'The comments'), null);
                    back($id, 'Shared with your supervisor.');
                }
                if ($act === 'upload_for' && $role === 'supervisor' && $kind === 'supervisor_file') {
                    $sid = (int)($_POST['student_id'] ?? 0);
                    if (!my_student($u, $sid)) { throw new RuntimeException('That is not one of your students.'); }
                    [$s, $o] = save_doc($_FILES['file'] ?? []);
                    add_file($aid, $sid, $u, $s, $o, '');
                    save_submission($aid, $sid, 'submitted', null, $note);
                    back($id, 'Returned to the student.');
                }
                if ($act === 'ack') {
                    $sid = (int)($_POST['student_id'] ?? 0); $sub = submission($aid, $sid);
                    if (!$sub) { throw new RuntimeException('Nothing has been submitted yet.'); }
                    $okRole = $kind === 'supervisor_file' ? ($role === 'student' && $sid === (int)$u['id']) : ($a['ack_role'] === 'supervisor' && $role === 'supervisor' && my_student($u, $sid)) || ($a['ack_role'] === 'pl' && $role === 'pl');
                    if (!$okRole) { throw new RuntimeException('You cannot mark this as received.'); }
                    ack_submission($aid, $sid, (int)$u['id'], $note);
                    back($id, 'Received and completed.');
                }
                break;

            case 'defense':
                if ($act === 'presented' && $role === 'student') {
                    $v = ($_POST['status'] ?? '') === 'presented' ? 'presented' : 'not_presented';
                    save_submission($aid, (int)$u['id'], $v, null, null);
                    back($id, $v === 'presented' ? 'Marked as presented and completed.' : 'Marked as did not present.');
                }
                break;

            case 'assign_evaluators':
                if ($act === 'allocate' && $role === 'pl') {
                    $sid = (int)($_POST['student_id'] ?? 0); $e1 = (int)($_POST['ev1'] ?? 0); $e2 = (int)($_POST['ev2'] ?? 0);
                    $stu = user_by_id($sid);
                    if (!$stu || $stu['role'] !== 'student') { throw new RuntimeException('Unknown student.'); }
                    if (!$e1 || !$e2 || $e1 === $e2) { throw new RuntimeException('Select two different evaluators.'); }
                    foreach ([$e1, $e2] as $ev) { $x = user_by_id($ev); if (!$x || !in_array($x['role'],['supervisor','pl','daa'],true) || !$x['active']) { throw new RuntimeException('Choose evaluators from the list.'); } }
                    $pdo = db(); $pdo->beginTransaction();
                    $pdo->prepare("DELETE FROM rd_allocations WHERE kind = 'project' AND student_id = ? AND evaluator_id NOT IN (?, ?)")->execute([$sid, $e1, $e2]);
                    $ins = $pdo->prepare("INSERT IGNORE INTO rd_allocations (kind, student_id, evaluator_id) VALUES ('project', ?, ?)");
                    $ins->execute([$sid, $e1]); $ins->execute([$sid, $e2]);
                    $pdo->commit();
                    back($id, 'Sent to the two evaluators.');
                }
                if ($act === 'received' && $role === 'evaluator') {
                    $st = db()->prepare("UPDATE rd_allocations SET received_at = ? WHERE kind = 'project' AND student_id = ? AND evaluator_id = ? AND received_at IS NULL");
                    $st->execute([now_sql(), (int)($_POST['student_id'] ?? 0), $u['id']]);
                    back($id, 'Received.');
                }
                break;
        }
        throw new RuntimeException('That action is not available.');
    } catch (RuntimeException $ex) {
        $errors[] = $ex->getMessage();
        if (db()->inTransaction()) { db()->rollBack(); }
    }
}

// =========================== page ===========================
popup_start($a['title'], 'Activity ' . (int)$a['seq']);
foreach ($errors as $er) { echo '<div class="flash err">' . e($er) . '</div>'; }
echo '<div class="meta"><span><b>Deadline</b> ' . e(act_deadline_text($a)) . '</span>' . ($a['t0_text'] ? '<span><b>T0</b> ' . e($a['t0_text']) . '</span>' : '')
   . (act_overdue($a) ? '<span>' . badge('Date has passed', 'amber') . '</span>' : '') . ($role === 'daa' ? '<span>' . ($a['enabled'] ? badge('Switched on', 'green') : badge('Switched off', 'red')) . '</span>' : '') . '</div>';
if (role_text($a, $role) !== '') { echo '<div class="notice"><b>Your part</b><br>' . nl2br(e(role_text($a, $role))) . '</div>'; }
if ($role !== 'student' && !($role === 'supervisor' && $kind === 'supervisor_select') && $kind !== 'publish') { echo dept_select($dept, ['id' => $id]); }

$scope = students_in_scope($u, $dept, eval_kind($a));
$ids = ids_of($scope);

// ---- small shared pieces
$studentCell = fn(array $s) => '<b>' . e($s['name']) . '</b><div class="muted">' . e($s['student_no'] ?: '') . ' ' . e($s['department'] ?: '') . '</div>';
$noStudents = '<p class="empty">No students to show' . ($dept ? ' in ' . e($dept) : '') . '.</p>';

switch ($kind) {
// ---------------------------------------------------------------- 1. documents from the DAA
case 'publish':
    $files = array_values(array_filter(files_of($aid), fn($f) => $role === 'daa' || in_array($role, explode(',', $f['receivers']), true)));
    if ($role === 'daa'): ?>
    <section class="card"><h2>Upload a document</h2>
      <form method="post" enctype="multipart/form-data" class="formcard flat"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="publish_upload">
        <label>Document (PDF or Word, up to <?= MAX_UPLOAD / 1048576 ?> MB)<input type="file" name="file" required accept=".pdf,.docx,.doc" data-max="<?= MAX_UPLOAD ?>"></label>
        <label>Title / note (optional)<input name="label" maxlength="200"></label>
        <p style="margin:6px 0 4px;font-weight:600">Send to (select one or more)</p>
        <?php foreach (['supervisor' => 'Supervisors', 'pl' => 'Programme Leaders', 'student' => 'Students', 'evaluator' => 'Evaluators'] as $k => $l): ?>
          <label class="chkline"><input type="checkbox" name="receivers[]" value="<?= $k ?>" <?= $k !== 'evaluator' ? 'checked' : '' ?>> <?= $l ?></label>
        <?php endforeach; ?>
        <button class="btn" type="submit">Upload and share</button>
      </form></section>
    <?php endif; ?>
    <section class="card"><h2>Documents</h2>
    <?php if (!$files): ?><p class="empty">No document has been shared yet.</p><?php else: ?>
      <div class="tablewrap"><table><thead><tr><th>Document</th><th>Shared with</th><th>Date</th><?= $role === 'daa' ? '<th></th>' : '' ?></tr></thead><tbody>
      <?php foreach ($files as $f): ?><tr><td><?= e($f['label'] ?: '') ?><?= $f['label'] ? '<br>' : '' ?><?= file_links($u, [$f]) ?></td>
        <td><?= e(implode(', ', array_map(fn($r) => ROLES[$r] ?? $r, array_filter(explode(',', $f['receivers']))))) ?></td><td class="nowrap"><?= e(fmt_day($f['created_at'])) ?></td>
        <?php if ($role === 'daa'): ?><td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete_file"><input type="hidden" name="fid" value="<?= (int)$f['id'] ?>"><button class="btn small danger" data-confirm="Delete this document for everyone?">Delete</button></form></td><?php endif; ?></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?></section>
<?php break;

// ---------------------------------------------------------------- 2. choose supervisor
case 'supervisor_select':
    if ($role === 'student'):
        $sv = db()->prepare('SELECT sv.*, s.name sup_name FROM rd_supervision sv LEFT JOIN rd_users s ON s.id = sv.supervisor_id WHERE sv.student_id = ?'); $sv->execute([$u['id']]); $cur = $sv->fetch();
        $locked = $cur && in_array($cur['status'], ['accepted', 'assigned'], true);
        $sups = users_by_role('supervisor');
        if ($cur): ?>
        <section class="card"><h2>Your request</h2>
          <p><b><?= e($cur['sup_name']) ?></b> · <?= e($cur['topic'] ?: 'no topic written') ?></p>
          <p><?= ['requested' => badge('Waiting for the supervisor', 'amber'), 'accepted' => badge('Accepted', 'green'), 'assigned' => badge('Assigned by the DAA', 'green'), 'rejected' => badge('Not accepted', 'red')][$cur['status']] ?></p>
          <?php if ($cur['sup_comment']): ?><p class="muted">Supervisor's comment: <?= e($cur['sup_comment']) ?></p><?php endif; ?>
        </section>
        <?php endif;
        if (!$locked): ?>
        <section class="card"><h2><?= $cur ? 'Choose again' : 'Choose a department and supervisor' ?></h2>
          <form method="post" class="formcard flat" id="supform"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="choose_sup">
            <label>Department<select id="dsel"><option value="">All departments</option><?php foreach (DEPARTMENTS as $d): ?><option value="<?= e($d) ?>" <?= $d === $u['department'] ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select></label>
            <label>Supervisor<select name="supervisor_id" id="ssel" required><option value="">Choose a supervisor</option>
              <?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>" data-dept="<?= e($s['department']) ?>"><?= e($s['name']) ?> (<?= e($s['department']) ?>)</option><?php endforeach; ?></select></label>
            <label>Proposed topic<input name="topic" maxlength="300" required></label>
            <button class="btn" type="submit">Submit</button>
          </form></section>
          <script>(function(){var d=document.getElementById('dsel'),s=document.getElementById('ssel');function f(){[].forEach.call(s.options,function(o){if(!o.value)return;o.hidden=d.value&&o.dataset.dept!==d.value;});if(s.selectedOptions[0]&&s.selectedOptions[0].hidden)s.value='';}d.addEventListener('change',f);f();})();</script>
        <?php endif;
    elseif ($role === 'supervisor'):
        $st = db()->prepare("SELECT sv.*, s.name, s.student_no, s.department FROM rd_supervision sv JOIN rd_users s ON s.id = sv.student_id WHERE sv.supervisor_id = ? ORDER BY FIELD(sv.status,'requested','accepted','assigned','rejected'), s.name"); $st->execute([$u['id']]); $rows = $st->fetchAll(); ?>
        <section class="card"><h2>Students and topics</h2>
        <?php if (!$rows): ?><p class="empty">No student has chosen you yet.</p><?php else: ?><div class="tablewrap"><table><thead><tr><th>Student</th><th>Topic</th><th>Status</th><th>Your action</th></tr></thead><tbody>
          <?php foreach ($rows as $r): ?><tr><td><?= $studentCell($r) ?></td><td><?= e($r['topic']) ?></td>
            <td><?= ['requested' => badge('Waiting', 'amber'), 'accepted' => badge('Accepted', 'green'), 'assigned' => badge('Assigned', 'green'), 'rejected' => badge('Not accepted', 'red')][$r['status']] ?><?= $r['sup_comment'] ? '<div class="muted">' . e($r['sup_comment']) . '</div>' : '' ?></td>
            <td><?php if ($r['status'] === 'requested'): ?>
              <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="sup_decide"><input type="hidden" name="student_id" value="<?= (int)$r['student_id'] ?>">
                <textarea name="comment" class="autogrow" rows="4" placeholder="Comment (optional)" maxlength="400"></textarea><div class="row"><button class="btn small" name="decision" value="accept">Accept</button><button class="btn small danger" name="decision" value="reject" data-confirm="Mark as not accepted?">Not accepted</button></div></form>
            <?php else: ?><span class="muted">Decided</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <?php else: // DAA and PL
        $sups = users_by_role('supervisor'); $all = students_in_scope($u, $dept); ?>
        <section class="card"><h2>Students, supervisors and agreed topics</h2>
        <?php if (!$all): echo $noStudents; else: ?><div class="tablewrap"><table><thead><tr><th>Student</th><th>Supervisor</th><th>Topic</th><th>Status</th><?= $role === 'daa' ? '<th>Assign a supervisor</th>' : '' ?></tr></thead><tbody>
          <?php foreach ($all as $s): $stt = $s['sv_status'] ?? ''; ?><tr><td><?= $studentCell($s) ?></td><td><?= e($s['sup_name'] ?: '—') ?></td><td><?= e($s['topic'] ?: '—') ?></td>
            <td><?= ['requested' => badge('Waiting', 'amber'), 'accepted' => badge('Accepted', 'green'), 'assigned' => badge('Assigned', 'green'), 'rejected' => badge('Not accepted', 'red'), '' => badge('Not submitted', 'gray')][$stt] ?></td>
            <?php if ($role === 'daa'): ?><td><?php if (!in_array($stt, ['accepted', 'assigned'], true)): ?>
              <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="assign_sup"><input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                <select name="supervisor_id" required><option value="">Choose a supervisor</option><?php foreach ($sups as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['name']) ?> (<?= e($x['department']) ?>)</option><?php endforeach; ?></select>
                <button class="btn small">Assign</button></form><?php else: ?><span class="muted">Matched</span><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <?php endif;
    break;

// ---------------------------------------------------------------- 3. finalize topic
case 'topic_final':
    if ($role === 'student'):
        $s = $scope[0] ?? null; $okSup = $s && in_array($s['sv_status'], ['accepted', 'assigned'], true); ?>
        <section class="card"><h2>Finalized research project topic</h2>
        <?php if (!$okSup): ?><p class="empty">You can finalize the topic after a supervisor has accepted you.</p>
        <?php else: ?>
          <p class="muted">Supervisor: <b><?= e($s['sup_name']) ?></b> · <?= $s['final_status'] === 'approved' ? badge('Approved and completed', 'green') : ($s['final_status'] === 'submitted' ? badge('Submitted. Waiting for approval', 'blue') : badge('Not submitted', 'amber')) ?></p>
          <?php if ($s['final_comment']): ?><p class="muted">Supervisor's comment: <?= e($s['final_comment']) ?></p><?php endif; ?>
          <?php if ($s['final_status'] === 'approved'): ?><div class="notice"><b>Topic</b><br><?= e($s['final_topic']) ?></div>
          <?php else: ?><form method="post" class="formcard flat"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="final_topic">
            <label>Finalized topic<textarea name="final_topic" rows="3" maxlength="400" required><?= e($s['final_topic'] ?: $s['topic']) ?></textarea></label>
            <button class="btn" type="submit">Submit</button></form><?php endif; ?>
        <?php endif; ?></section>
    <?php else: ?>
        <section class="card"><h2>Submitted topics</h2><?php if (!$scope): echo $noStudents; else: ?><div class="tablewrap"><table><thead><tr><th>Student</th><th>Agreed topic</th><th>Finalized topic</th><th>Status</th><?= $role === 'supervisor' ? '<th>Your action</th>' : '' ?></tr></thead><tbody>
          <?php foreach ($scope as $s): ?><tr><td><?= $studentCell($s) ?></td><td><?= e($s['topic'] ?: '—') ?></td><td><?= e($s['final_topic'] ?: '—') ?></td>
            <td><?= ['approved' => badge('Approved', 'green'), 'submitted' => badge('Waiting for approval', 'blue'), 'none' => badge('Not submitted', 'gray')][$s['final_status'] ?? 'none'] ?></td>
            <?php if ($role === 'supervisor'): ?><td><?php if ($s['final_status'] === 'submitted'): ?>
              <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="approve_topic"><input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                <textarea name="comment" class="autogrow" rows="4" placeholder="Comment (optional)" maxlength="400"></textarea><button class="btn small">Approve and complete</button></form><?php else: ?><span class="muted">—</span><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <?php endif;
    break;

// ---------------------------------------------------------------- file / text hand-overs
case 'student_file': case 'supervisor_file': case 'student_text':
    $subs = subs_for($aid, $ids); $fm = files_map($aid, $ids);
    $ackWho = $kind === 'supervisor_file' ? 'student' : $a['ack_role'];
    if ($role === 'student'):
        $sub = $subs[$u['id']] ?? null; $mine = $fm[$u['id']] ?? [];
        if ($kind === 'supervisor_file'): ?>
          <section class="card"><h2>From your supervisor</h2>
          <?php if (!$sub): ?><p class="empty">Your supervisor has not returned anything yet.</p><?php else: ?>
            <p><?= file_links($u, $mine) ?></p><?php if ($sub['note']): ?><div class="notice"><b>Supervisor's comment</b><br><?= nl2br(e($sub['note'])) ?></div><?php endif; ?>
            <?php if ($sub['ack_status'] === 'received'): ?><p><?= badge('Received and completed', 'green') ?></p><?php if ($sub['ack_note']): ?><p class="muted">Your comment: <?= e($sub['ack_note']) ?></p><?php endif; ?>
            <?php else: ?><form method="post" class="formcard flat"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="ack"><input type="hidden" name="student_id" value="<?= (int)$u['id'] ?>">
              <label>Your comments (optional)<textarea name="note" rows="3" maxlength="500"></textarea></label><button class="btn" type="submit">Received and completed</button></form><?php endif; ?>
          <?php endif; ?></section>
        <?php elseif ($kind === 'student_text'):
            $locked = $sub && $sub['ack_status'] === 'received'; ?>
          <section class="card"><h2>Comments received on the proposal</h2>
          <?php if ($sub): ?><p><?= $locked ? badge('Received by your supervisor', 'green') : badge('Shared. Waiting to be received', 'blue') ?></p><?php endif; ?>
          <form method="post" class="formcard flat"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="share">
            <label>Comments<textarea name="body" rows="9" required maxlength="5000" <?= $locked ? 'readonly' : '' ?>><?= e($sub['body'] ?? '') ?></textarea></label>
            <?php if (!$locked): ?><button class="btn" type="submit">Share with my supervisor</button><?php endif; ?></form></section>
        <?php else:
            $locked = $sub && $a['ack_role'] && $sub['ack_status'] === 'received'; ?>
          <section class="card"><h2>Your submission</h2>
          <?php if ($sub): ?><p><?= $locked ? badge('Submitted and received', 'green') : badge('Submitted', 'green') ?> <span class="muted"><?= e(fmt_date($sub['submitted_at'])) ?></span></p><?php endif; ?>
          <p><?= file_links($u, $mine) ?></p>
          <?php if (!$locked): ?><form method="post" enctype="multipart/form-data" class="formcard flat"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="upload">
            <label><?= $sub ? 'Upload a new version' : 'File to submit' ?> (PDF or Word, up to <?= MAX_UPLOAD / 1048576 ?> MB)<input type="file" name="file" required accept=".pdf,.docx,.doc" data-max="<?= MAX_UPLOAD ?>"></label>
            <label>Note (optional)<input name="note" maxlength="500"></label><button class="btn" type="submit">Submit</button></form>
          <?php else: ?><p class="muted">It was received, so it can no longer be replaced.</p><?php endif; ?></section>
        <?php endif;
    else: // supervisor, evaluator, PL, DAA : table
        if ($role === 'evaluator' && !$ids): echo '<p class="empty">Nothing is assigned to you for this activity.</p>'; break; endif;
        $canDel = $role === 'daa'; ?>
        <section class="card"><h2><?= $kind === 'supervisor_file' ? 'Return to students' : ($kind === 'student_text' ? 'Comments from students' : 'Submissions') ?></h2>
        <?php if (!$scope): echo $noStudents; else: ?><div class="tablewrap"><table><thead><tr><th>Student</th><th><?= $kind === 'student_text' ? 'Comments' : 'File' ?></th><th>Status</th><?= ($role === 'supervisor' && $kind === 'supervisor_file') || (($role === $ackWho) && $ackWho) ? '<th>Your action</th>' : '' ?></tr></thead><tbody>
          <?php foreach ($scope as $s): $sid = (int)$s['id']; $sub = $subs[$sid] ?? null; ?>
          <tr><td><?= $studentCell($s) ?><div class="muted"><?= e(work_title($s)) ?></div></td>
            <td><?php if ($kind === 'student_text'): echo $sub ? nl2br(e($sub['body'])) : '<span class="muted">—</span>'; else: echo file_links($u, $fm[$sid] ?? [], $canDel); if ($sub && $sub['note']): ?><div class="muted">Note: <?= e($sub['note']) ?></div><?php endif; endif; ?></td>
            <td><?php if (!$sub): ?><?= badge('Not yet', 'amber') ?><?php else: ?><?= badge($kind === 'supervisor_file' ? 'Returned' : 'Submitted', 'green') ?> <span class="muted"><?= e(fmt_day($sub['submitted_at'])) ?></span><?php if ($a['ack_role'] || $kind === 'supervisor_file'): ?><div><?= $sub['ack_status'] === 'received' ? badge('Received', 'green') : badge('Not yet received', 'blue') ?></div><?php if ($sub['ack_note']): ?><div class="muted">Student: <?= e($sub['ack_note']) ?></div><?php endif; endif; endif; ?></td>
            <?php if ($role === 'supervisor' && $kind === 'supervisor_file'): ?><td>
              <form method="post" enctype="multipart/form-data" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="upload_for"><input type="hidden" name="student_id" value="<?= $sid ?>">
                <input type="file" name="file" required accept=".pdf,.docx,.doc" data-max="<?= MAX_UPLOAD ?>"><textarea name="note" class="autogrow" rows="4" placeholder="Comment (optional)" maxlength="500"></textarea><button class="btn small"><?= $sub ? 'Upload again' : 'Submit and complete' ?></button></form></td>
            <?php elseif ($ackWho && $role === $ackWho): ?><td><?php if ($sub && $sub['ack_status'] !== 'received'): ?>
              <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="ack"><input type="hidden" name="student_id" value="<?= $sid ?>"><button class="btn small">Received and completed</button></form>
              <?php else: ?><span class="muted">—</span><?php endif; ?></td><?php endif; ?></tr>
          <?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <?php endif;
    break;

// ---------------------------------------------------------------- presentation / defense with marks
case 'defense':
    $form = (int)$a['form_no'];
    $subs = subs_for($aid, $ids);
    if ($role === 'student'):
        $sub = $subs[$u['id']] ?? null; ?>
        <section class="card"><h2>Your presentation</h2>
          <p><?= !$sub ? badge('Pending', 'amber') : badge($sub['status'] === 'presented' ? 'Presented and completed' : 'Did not present', $sub['status'] === 'presented' ? 'green' : 'red') ?></p>
          <form method="post" class="formcard flat row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="presented">
            <button class="btn" name="status" value="presented" data-confirm="Mark as presented and completed?">Presented and completed</button>
            <button class="btn ghost" name="status" value="not_presented" data-confirm="Mark as did not present?">Did not present</button></form></section>
    <?php else:
        $mm = marks_map($form, ids_of($scope)); $mine = [];
        if ($role === 'evaluator' || ($role === 'pl' && $form === 7) || $role === 'supervisor') { foreach ($scope as $s) { $mine[$s['id']] = marks_row($form, (int)$s['id'], (int)$u['id']); } }
        $canEnter = ($form === 4 && $role === 'evaluator') || ($form === 7 && $role === 'pl');
        $evFiles = [];
        if ($role === 'evaluator') { foreach ($scope as $s) { $evFiles[$s['id']] = db()->query("SELECT f.* FROM rd_files f JOIN rd_activities x ON x.id = f.activity_id WHERE x.eval_files = 1 AND x.enabled = 1 AND f.student_id = " . (int)$s['id'] . " AND f.uploader_role = 'student' ORDER BY f.id DESC LIMIT 1")->fetchAll(); } }
        $showMarks = in_array($role, ['pl', 'daa'], true) || $canEnter; ?>
        <section class="card"><div class="cardhead"><h2><?= e(FORMS[$form]['title'] ?? 'Marks') ?></h2>
          <?php if ($role === 'pl' || $role === 'daa'): ?><a class="btn small ghost" href="marks.php?csv=<?= $form ?>&amp;dept=<?= urlencode($dept) ?>" <?= ($role === 'pl' && $dept === '') ? 'data-alert="Select a department first, then download."' : '' ?>>Download CSV (<?= $dept ? e($dept) : ($role === 'pl' ? 'select a department' : 'all departments') ?>)</a><?php endif; ?></div>
        <?php if (!$scope): echo ($role === 'evaluator') ? '<p class="empty">No students are allocated to you for this evaluation.</p>' : $noStudents; else: ?>
        <div class="tablewrap"><table><thead><tr><th>Student</th><th>Title of work</th><th>Presentation</th><?= $role === 'evaluator' ? '<th>Proposal</th>' : '' ?><?= $showMarks ? '<th>Marks / ' . (int)form_max($form) . '</th>' : '' ?><?= $canEnter ? '<th>Your action</th>' : '' ?></tr></thead><tbody>
        <?php foreach ($scope as $s): $sid = (int)$s['id']; $sub = $subs[$sid] ?? null; $rows = $mm[$sid] ?? []; ?>
          <tr><td><?= $studentCell($s) ?></td><td><?= e(work_title($s) ?: '—') ?></td>
            <td><?= !$sub ? badge('Pending', 'amber') : badge($sub['status'] === 'presented' ? 'Presented' : 'Did not present', $sub['status'] === 'presented' ? 'green' : 'red') ?></td>
            <?php if ($role === 'evaluator'): ?><td><?= file_links($u, $evFiles[$sid] ?? []) ?></td><?php endif; ?>
            <?php if ($showMarks): ?><td><?php if ($role === 'evaluator'): echo ($mine[$sid] && $mine[$sid]['status'] === 'submitted') ? '<b>' . fmt_mark((float)$mine[$sid]['total']) . '</b>' : '–';
                else: if (!$rows): echo '–'; else: foreach ($rows as $r) { echo '<div>' . fmt_mark((float)$r['total']) . ' <span class="muted">' . e($r['evaluator_name']) . ' · ' . e(fmt_day($r['submitted_at'])) . '</span></div>'; } if (count($rows) > 1) { echo '<div><b>Average ' . fmt_mark(avg_marks($rows)) . '</b></div>'; } endif; endif; ?></td><?php endif; ?>
            <?php if ($canEnter): ?><td><?php $mr = $mine[$sid] ?? null; if ($mr && $mr['status'] === 'submitted'): ?><span class="muted">Submitted</span> <?= popup_link('form.php?form=' . $form . '&student=' . $sid . '&a=' . $aid, 'View', 'btn small ghost') ?>
              <?php else: echo popup_link('form.php?form=' . $form . '&student=' . $sid . '&a=' . $aid, $mr ? 'Continue marks' : 'Enter marks'); endif; ?></td><?php endif; ?></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <?php endif;
    eval_return_panel($u, $a, $scope, $aid);
    break;

// ---------------------------------------------------------------- 16. PL picks two evaluators per project
case 'assign_evaluators':
    $al = db()->query("SELECT al.*, u.name evaluator_name FROM rd_allocations al JOIN rd_users u ON u.id = al.evaluator_id WHERE al.kind = 'project' AND al.student_id IN (" . in_list($ids) . ') ORDER BY al.id')->fetchAll();
    $by = []; foreach ($al as $r) { $by[$r['student_id']][] = $r; }
    if ($role === 'pl'): $evs = users_by_role('evaluator'); ?>
      <section class="card"><h2>Select two evaluators for each project</h2><?php if (!$scope): echo $noStudents; else: ?><div class="tablewrap"><table><thead><tr><th>Student</th><th>Title of work</th><th>Evaluators</th></tr></thead><tbody>
        <?php foreach ($scope as $s): $sid = (int)$s['id']; $cur = $by[$sid] ?? []; ?><tr><td><?= $studentCell($s) ?></td><td><?= e(work_title($s) ?: '—') ?></td><td>
          <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="allocate"><input type="hidden" name="student_id" value="<?= $sid ?>">
            <?php foreach ([1, 2] as $n): ?><select name="ev<?= $n ?>" required><option value="">Evaluator <?= $n ?></option><?php foreach ($evs as $x): ?><option value="<?= (int)$x['id'] ?>" <?= (int)($cur[$n - 1]['evaluator_id'] ?? 0) === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?> (<?= e($x['department']) ?>)</option><?php endforeach; ?></select><?php endforeach; ?>
            <button class="btn small"><?= $cur ? 'Update' : 'Select and submit' ?></button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <?php elseif ($role === 'evaluator'): $fm = []; foreach ($scope as $s) { foreach (db()->query("SELECT f.* FROM rd_files f JOIN rd_activities x ON x.id = f.activity_id WHERE x.eval_files = 2 AND f.student_id = " . (int)$s['id'] . " AND f.uploader_role = 'student' ORDER BY f.id DESC")->fetchAll() as $f) { $fm[$s['id']][] = $f; } } ?>
      <section class="card"><h2>Work assigned to you</h2><?php if (!$scope): ?><p class="empty">Nothing has been assigned to you yet.</p><?php else: ?><div class="tablewrap"><table><thead><tr><th>Student</th><th>Title of work</th><th>Files</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($scope as $s): $sid = (int)$s['id']; $mine = array_values(array_filter($by[$sid] ?? [], fn($r) => (int)$r['evaluator_id'] === (int)$u['id']))[0] ?? null; ?><tr><td><?= $studentCell($s) ?></td><td><?= e(work_title($s) ?: '—') ?></td><td><?= file_links($u, $fm[$sid] ?? []) ?></td>
          <td><?php if ($mine && $mine['received_at']): echo badge('Received', 'green'); else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="received"><input type="hidden" name="student_id" value="<?= $sid ?>"><button class="btn small">Received</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <?php else: ?>
      <section class="card"><h2>Status</h2><?php if (!$scope): echo $noStudents; else: ?><div class="tablewrap"><table><thead><tr><th>Student</th><th>Title of work</th><th>Status</th><th>Evaluators</th></tr></thead><tbody>
        <?php foreach ($scope as $s): $cur = $by[$s['id']] ?? []; ?><tr><td><?= $studentCell($s) ?></td><td><?= e(work_title($s) ?: '—') ?></td><td><?= count($cur) >= 2 ? badge('Send to evaluators', 'green') : badge('Pending', 'amber') ?></td>
          <td><?= $cur ? e(implode(', ', array_column($cur, 'evaluator_name'))) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <?php endif;
    break;

// ---------------------------------------------------------------- 17. evaluators return Form 6 marks
case 'evaluate':
    $mm = marks_map(6, $ids);
    if ($role === 'student'): ?>
      <section class="card"><h2>Status</h2><p><?= isset($mm[$u['id']]) ? badge('Returned to Programme Leader', 'green') : badge('With evaluators', 'amber') ?></p></section>
    <?php elseif ($role === 'evaluator'): ?>
      <section class="card"><h2><?= e(FORMS[6]['title']) ?></h2><?php if (!$scope): ?><p class="empty">No project is allocated to you.</p><?php else: ?><div class="tablewrap"><table><thead><tr><th>Student</th><th>Title of work</th><th>Files</th><th>Your marks</th><th></th></tr></thead><tbody>
        <?php foreach ($scope as $s): $sid = (int)$s['id']; $mr = marks_row(6, $sid, (int)$u['id']);
            $pf = db()->query("SELECT f.* FROM rd_files f JOIN rd_activities x ON x.id = f.activity_id WHERE x.eval_files = 2 AND f.student_id = $sid AND f.uploader_role = 'student' ORDER BY f.id DESC LIMIT 1")->fetchAll(); ?>
        <tr><td><?= $studentCell($s) ?></td><td><?= e(work_title($s) ?: '—') ?></td><td><?= file_links($u, $pf) ?></td><td><?= ($mr && $mr['status'] === 'submitted') ? '<b>' . fmt_mark((float)$mr['total']) . '</b> / 100' : '–' ?></td>
          <td><?= popup_link('form.php?form=6&student=' . $sid . '&a=' . $aid, ($mr && $mr['status'] === 'submitted') ? 'View' : ($mr ? 'Continue marks' : 'Enter marks'), 'btn small') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <?php else: ?>
      <section class="card"><div class="cardhead"><h2><?= e(FORMS[6]['title']) ?></h2><?php if ($role === 'pl' || $role === 'daa'): ?><a class="btn small ghost" href="marks.php?csv=6&amp;dept=<?= urlencode($dept) ?>" <?= ($role === 'pl' && $dept === '') ? 'data-alert="Select a department first, then download."' : '' ?>>Download CSV (<?= $dept ? e($dept) : ($role === 'pl' ? 'select a department' : 'all departments') ?>)</a><?php endif; ?></div>
      <?php if (!$scope): echo $noStudents; else: ?><div class="tablewrap"><table><thead><tr><th>Student</th><th>Title of work</th><th>Status</th><?= in_array($role, ['pl', 'daa'], true) ? '<th>Marks / 100</th>' : '' ?></tr></thead><tbody>
        <?php foreach ($scope as $s): $rows = $mm[$s['id']] ?? []; ?><tr><td><?= $studentCell($s) ?></td><td><?= e(work_title($s) ?: '—') ?></td><td><?= $rows ? badge('Returned to Programme Leader', 'green') : badge('With evaluators', 'amber') ?></td>
          <?php if (in_array($role, ['pl', 'daa'], true)): ?><td><?php foreach ($rows as $r) { echo '<div>' . fmt_mark((float)$r['total']) . ' <span class="muted">' . e($r['evaluator_name']) . ' · ' . e(fmt_day($r['submitted_at'])) . '</span></div>'; } echo count($rows) > 1 ? '<div><b>Average ' . fmt_mark(avg_marks($rows)) . '</b></div>' : ($rows ? '' : '–'); ?></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <?php endif;
    eval_return_panel($u, $a, $scope, $aid);
    break;

// ---------------------------------------------------------------- 23. supervisor submits Forms 5 and 8
case 'grades':
    $m5 = marks_map(5, $ids); $m8 = marks_map(8, $ids);
    if ($role === 'student'): $done = isset($m5[$u['id']]) && isset($m8[$u['id']]); ?>
      <section class="card"><h2>Status</h2><p><?= $done ? badge('Procedure completed', 'green') : badge('In progress', 'amber') ?></p></section>
    <?php else: ?>
      <section class="card"><div class="cardhead"><h2>Research Process (Form 5) and Article Evaluation (Form 8)</h2>
        <?php if ($role === 'pl'): ?><a class="btn small ghost" href="marks.php?csv=all&amp;dept=<?= urlencode($dept) ?>" <?= ($role === 'pl' && $dept === '') ? 'data-alert="Select a department first, then download."' : '' ?>>Download CSV (<?= $dept ? e($dept) : ($role === 'pl' ? 'select a department' : 'all departments') ?>)</a><?php endif; ?></div>
      <?php if (!$scope): echo $noStudents; else: ?><div class="tablewrap"><table><thead><tr><th>Student</th><th>Title of work</th><th>Research Process /5</th><th>Article Evaluation /10</th></tr></thead><tbody>
        <?php foreach ($scope as $s): $sid = (int)$s['id']; ?><tr><td><?= $studentCell($s) ?></td><td><?= e(work_title($s) ?: '—') ?></td>
          <?php foreach ([5 => $m5, 8 => $m8] as $fn => $map): $r = $map[$sid][0] ?? null; ?><td><?php
              if ($r) { echo '<b>' . fmt_mark((float)$r['total']) . '</b> <span class="muted">' . e(fmt_day($r['submitted_at'])) . '</span>'; if ($role === 'supervisor') { echo ' ' . popup_link('form.php?form=' . $fn . '&student=' . $sid . '&a=' . $aid, 'View', 'btn small ghost'); } }
              elseif ($role === 'supervisor') { $dr = marks_row($fn, $sid, (int)$u['id']); echo popup_link('form.php?form=' . $fn . '&student=' . $sid . '&a=' . $aid, $dr ? 'Continue marks' : 'Enter marks'); }
              else { echo '–'; } ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <?php endif;
    break;
}
popup_end();
