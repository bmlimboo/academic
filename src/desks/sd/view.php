<?php
namespace CAS\SignDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_login();
$isDaa = $u['role'] === 'daa';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$st = db()->prepare('SELECT r.*, u.name AS user_name, IF(CHAR_LENGTH(r.department) > 0, r.department, u.department) AS dept FROM sd_doc_requests r JOIN sd_users u ON u.id = r.user_id WHERE r.id = ?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r || (!$isDaa && (int)$r['user_id'] !== $u['id'])) { http_response_code(404); exit('Document not found.'); }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    $open = in_array($r['status'], ['submitted', 'under_review'], true);
    if ($action === 'delete' && !$isDaa) { http_response_code(403); exit('Only the DAA can delete documents.'); }
    try {
        if ($action === 'delete') {
            delete_document($id);
            flash('Document deleted.');
            redirect($isDaa ? 'queue.php' : 'my.php');
        } elseif ($isDaa && $open && $action === 'sign') {
            [$file, $orig] = save_document($_FILES['signed'] ?? [], 'signed', ACCEPT_SIGNED);
            $dno = normalize_dispatch($_POST['dispatch_no'] ?? '');
            try {
                if (mb_strlen($dno) > 80) { throw new RuntimeException('The dispatch number is too long (80 characters at most).'); }
                if ($dno !== '') { register_dispatch($id, $u['id'], $dno); }
            } catch (RuntimeException $ex) {
                @unlink(STORAGE_DIR . DIRECTORY_SEPARATOR . $file);
                throw $ex;
            }
            db()->prepare("UPDATE sd_doc_requests SET status='signed', signed_file=?, signed_name=?, daa_remarks=?, dispatch_no=?, signed_at=NOW() WHERE id=?")
                ->execute([$file, $orig, $reason ?: null, $dno ?: null, $id]);
            log_action($id, $u['id'], 'signed', trim(($dno ? 'Dispatch no. ' . $dno : '') . ($dno && $reason ? '. ' : '') . $reason) ?: null);
            flash('Signed copy uploaded. The submitter can now download it.');
            redirect('queue.php');
        } elseif ($isDaa && $open && in_array($action, ['return', 'reject'], true)) {
            if ($reason === '') { throw new RuntimeException('Give a reason so the submitter knows what to do next.'); }
            $new = $action === 'return' ? 'returned' : 'rejected';
            db()->prepare('UPDATE sd_doc_requests SET status=?, daa_remarks=? WHERE id=?')->execute([$new, $reason, $id]);
            log_action($id, $u['id'], $new, $reason);
            flash($action === 'return' ? 'Returned for correction.' : 'Document rejected.');
            redirect('queue.php');
        } elseif (!$isDaa && $r['status'] === 'returned' && $action === 'resubmit') {
            [$file, $orig] = save_document($_FILES['file'] ?? [], 'orig', ACCEPT_SUBMIT);
            db()->prepare("UPDATE sd_doc_requests SET status='submitted', original_file=?, original_name=? WHERE id=?")->execute([$file, $orig, $id]);
            @unlink(STORAGE_DIR . DIRECTORY_SEPARATOR . basename($r['original_file']));
            log_action($id, $u['id'], 'resubmitted', $reason ?: null);
            flash('Corrected copy sent to the DAA.');
            redirect('view.php?id=' . $id);
        }
    } catch (RuntimeException $ex) {
        $errors[] = $ex->getMessage();
    }
}

// First time the DAA opens a new document, mark it as under review.
if ($isDaa && $r['status'] === 'submitted' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    db()->prepare("UPDATE sd_doc_requests SET status='under_review' WHERE id=?")->execute([$id]);
    log_action($id, $u['id'], 'opened');
    $r['status'] = 'under_review';
}

$logs = db()->prepare('SELECT l.*, u.name FROM sd_doc_logs l JOIN sd_users u ON u.id = l.actor_id WHERE l.request_id = ? ORDER BY l.id');
$logs->execute([$id]);
$logs = $logs->fetchAll();
$open = in_array($r['status'], ['submitted', 'under_review'], true);

layout_start($r['title'], $isDaa ? 'queue' : 'mine', $r['ref_no']);
foreach ($errors as $er) { echo '<div class="flash err">' . e($er) . '</div>'; }
?>
<div class="cols">
  <section class="card">
    <div class="cardhead"><h2>Details</h2><?= badge($r['status']) ?></div>
    <dl>
      <dt>Submitted by</dt><dd><?= e($r['user_name']) ?><?= $r['submitter_type'] ? ' (' . e($r['submitter_type']) . ')' : '' ?>, <?= e($r['dept']) ?></dd>
      <dt>Type</dt><dd><?= e($r['doc_type']) ?><?= $r['urgency'] === 'urgent' ? ' <span class="badge red">Urgent</span>' : '' ?></dd>
      <dt>Submitted</dt><dd><?= e(fmt_date($r['created_at'])) ?></dd>
      <?php if (!empty($r['dispatch_no'])): ?><dt>Dispatch no.</dt><dd><strong><?= e($r['dispatch_no']) ?></strong></dd><?php endif; ?>
      <?php if ($r['remarks']): ?><dt>Remarks</dt><dd><?= nl2br(e($r['remarks'])) ?></dd><?php endif; ?>
      <?php if ($r['daa_remarks']): ?><dt>DAA note</dt><dd><?= nl2br(e($r['daa_remarks'])) ?></dd><?php endif; ?>
    </dl>
    <div class="files">
      <a class="btn small ghost" href="download.php?id=<?= $id ?>&type=original" target="_blank">View submitted PDF</a>
      <?php if ($r['signed_file']): ?>
        <a class="btn small" href="download.php?id=<?= $id ?>&type=signed&dl=1">Download signed copy</a>
      <?php endif; ?>
    </div>

    <?php if ($isDaa && $open): ?>
      <h3>Sign &amp; stamp online</h3>
      <p class="muted">Preview the document, drag your signature, seal, date and dispatch number onto it, and save it as the signed PDF.</p>
      <div class="files"><a class="btn" href="stamp.php?id=<?= $id ?>">Sign &amp; stamp</a></div>
      <h3>Or upload a signed copy</h3>
      <form method="post" enctype="multipart/form-data" class="formcard flat">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="sign">
        <label>Signed PDF<input type="file" name="signed" accept="application/pdf,.pdf" required data-filename></label>
        <label>Dispatch number<input name="dispatch_no" maxlength="80" autocomplete="off" placeholder="e.g. <?= e(DISPATCH_PREFIX . '/' . academic_year() . '/627') ?>">
          <span class="muted">Type it from your register, or leave empty to sign without one.<?= last_dispatch_number() !== '' ? ' Last issued here: ' . e(last_dispatch_number()) : '' ?></span></label>
        <label>Note to submitter (optional)<input name="reason" maxlength="300"></label>
        <button class="btn" type="submit">Upload signed copy</button>
      </form>
      <h3>Cannot sign this one?</h3>
      <form method="post" class="formcard flat">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
        <label>Reason<textarea name="reason" rows="2" maxlength="500" required></textarea></label>
        <div class="files">
          <button class="btn ghost" name="action" value="return" type="submit">Return for correction</button>
          <button class="btn danger" name="action" value="reject" type="submit" data-confirm="Reject this document?">Reject</button>
        </div>
      </form>
    <?php elseif (!$isDaa && $r['status'] === 'returned'): ?>
      <h3>Upload corrected copy</h3>
      <form method="post" enctype="multipart/form-data" class="formcard flat">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="resubmit">
        <label>Corrected PDF<input type="file" name="file" accept="application/pdf,.pdf" required data-filename></label>
        <label>What changed (optional)<input name="reason" maxlength="300"></label>
        <button class="btn" type="submit">Send again to DAA</button>
      </form>
    <?php endif; ?>

    <?php if ($isDaa): ?>
    <h3>Delete this document</h3>
    <form method="post" class="formcard flat">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete">
      <p class="muted">Permanently removes the document, any signed copy, and its history.</p>
      <button class="btn danger" type="submit" data-confirm="Delete this document and its files permanently? This cannot be undone.">Delete document</button>
    </form>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>History</h2>
    <ol class="timeline">
      <?php foreach ($logs as $l): ?>
        <li><b><?= e(ACTION_LABELS[$l['action']] ?? $l['action']) ?></b>
          <span class="muted"><?= e($l['name']) ?> · <?= e(fmt_date($l['created_at'])) ?></span>
          <?php if ($l['note']): ?><div><?= nl2br(e($l['note'])) ?></div><?php endif; ?></li>
      <?php endforeach; ?>
    </ol>
  </section>
</div>
<?php layout_end();
