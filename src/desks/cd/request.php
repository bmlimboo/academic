<?php
namespace CAS\CertDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_login();
$isAlum = $u['role'] === 'alumnus';
$isCell = $u['role'] === 'examcell';
$isDaa = $u['role'] === 'daa';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$r = load_request($id);
if (!$r || !can_see_request($u, $r)) { http_response_code(404); exit('Request not found.'); }
$errors = [];

function need(bool $ok, string $msg = 'This action is not available for this request right now.'): void
{
    if (!$ok) { throw new RuntimeException($msg); }
}
function reason_text(string $label): string
{
    $t = trim((string)($_POST['reason'] ?? ''));
    if ($t === '') { throw new RuntimeException('Write the ' . $label . '.'); }
    return mb_substr($t, 0, 450);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $st = $r['status'];
    try {
        if ($isAlum && $action === 'resubmit') {
            need($st === 'returned');
            $note = mb_substr(trim($_POST['note'] ?? ''), 0, 400);
            $list = files_list('supporting');
            need($list || $note !== '', 'Add at least one file or write a short note about what you changed.');
            foreach ($list as $f) { [$stored, $orig] = save_upload($f, ['pdf', 'jpg', 'png'], 'rq'); add_file($id, null, 'supporting', $stored, $orig, $u['name']); }
            set_status($id, 'submitted', ['cell_note' => null]);
            log_request($id, $u['name'], 'resubmitted', $note ?: null);
            flash('Resubmitted to the Exam Cell.');
        } elseif ($isAlum && $action === 'upload_proof') {
            need($st === 'awaiting_payment');
            $list = files_list('proof');
            need((bool)$list, 'Choose the scan of your deposit slip or receipt.');
            [$stored, $orig] = save_upload($list[0], ['pdf', 'jpg', 'png'], 'dp');
            add_file($id, null, 'deposit_proof', $stored, $orig, $u['name']);
            $ref = mb_substr(trim($_POST['deposit_ref'] ?? ''), 0, 120);
            set_status($id, 'payment_submitted', ['deposit_ref' => $ref ?: null, 'cell_note' => null]);
            log_request($id, $u['name'], 'proof_uploaded', $ref ?: null);
            flash('Deposit proof submitted. The Exam Cell will check it and send your certificates.');
        } elseif ($isCell && $action === 'send_daa') {
            need($st === 'submitted');
            $raw = str_replace(',', '', trim($_POST['amount'] ?? ''));
            need($raw !== '' && is_numeric($raw) && (float)$raw >= 0 && (float)$raw <= 10000000, 'Enter the amount payable (0 if there is no fee).');
            $amount = round((float)$raw, 2);
            $list = files_list('letter');
            $existing = array_values(array_filter(request_files($id), fn($f) => $f['kind'] === 'release_letter'));
            need($list || $existing, 'Upload the release letter (PDF) for the DAA to sign.');
            if ($list) {
                [$stored, $orig] = save_upload($list[0], ['pdf'], 'rl');
                foreach ($existing as $o) { delete_file_row($o); }
                add_file($id, null, 'release_letter', $stored, $orig, $u['name']);
            }
            set_status($id, 'with_daa', ['amount' => $amount, 'daa_note' => null, 'cell_note' => null]);
            $n = mb_substr(trim($_POST['note'] ?? ''), 0, 300);
            log_request($id, $u['name'], 'sent_daa', 'Amount payable: ' . money($amount) . ($n !== '' ? '. Note: ' . $n : ''));
            flash('Sent to the DAA for approval.');
        } elseif ($isCell && $action === 'return') {
            need($st === 'submitted');
            $reason = reason_text('reason, so the requester knows what to fix');
            set_status($id, 'returned', ['cell_note' => $reason]);
            log_request($id, $u['name'], 'returned', $reason);
            flash('Returned to the requester.');
        } elseif ($isCell && $action === 'reject') {
            need($st === 'submitted');
            $reason = reason_text('reason for rejecting the request');
            set_status($id, 'rejected', ['cell_note' => $reason]);
            log_request($id, $u['name'], 'rejected', $reason);
            flash('Request rejected.');
        } elseif ($isCell && $action === 'resend_payment') {
            need($st === 'awaiting_payment');
            [$s, $err] = send_payment_email($r, $u['name']);
            flash($s === 'failed' ? 'The email could not be sent: ' . $err : ($s === 'logged' ? 'Saved in the email log only: mail is not configured, so nothing was delivered.' : 'Payment email queued.'), $s === 'queued' ? 'ok' : 'err');
        } elseif ($isCell && $action === 'refuse_proof') {
            need($st === 'payment_submitted');
            $reason = reason_text('reason the proof was not accepted');
            set_status($id, 'awaiting_payment', ['cell_note' => $reason]);
            log_request($id, $u['name'], 'proof_refused', $reason);
            $r = load_request($id);
            [$s, $err] = send_payment_email($r, $u['name'], 'Your deposit proof for request ' . $r['ref'] . ' could not be accepted: ' . $reason . "\nPlease upload a correct proof.");
            flash('Sent back to the requester.' . ($s === 'queued' ? '' : ' Note: the email was not delivered (' . ($s === 'failed' ? $err : 'mail not configured') . ').'), $s === 'queued' ? 'ok' : 'err');
        } elseif ($isCell && $action === 'upload_certs') {
            need(in_array($st, ['payment_submitted', 'ready'], true));
            $files = request_files($id); $n = 0;
            foreach (request_items($id) as $it) {
                $list = files_list('cert_' . (int)$it['id']);
                if (!$list) { continue; }
                [$stored, $orig] = save_upload($list[0], ['pdf', 'jpg', 'png'], 'ct');
                foreach ($files as $f) { if ($f['kind'] === 'certificate' && (int)$f['item_id'] === (int)$it['id']) { delete_file_row($f); } }
                add_file($id, (int)$it['id'], 'certificate', $stored, $orig, $u['name']);
                $n++;
            }
            need($n > 0, 'Choose at least one certificate file.');
            log_request($id, $u['name'], 'certs_uploaded', $n . ' file(s)');
            flash($n . ' certificate file(s) saved.');
        } elseif ($isDaa && $action === 'daa_return') {
            need($st === 'with_daa');
            $reason = reason_text('remarks for the Exam Cell');
            set_status($id, 'submitted', ['daa_note' => $reason]);
            log_request($id, $u['name'], 'daa_returned', $reason);
            flash('Returned to the Exam Cell.');
            redirect('index.php');
        } elseif ($isCell && $action === 'delete_file') {
            $q = db()->prepare('SELECT * FROM cd_request_files WHERE id = ? AND request_id = ?');
            $q->execute([(int)($_POST['file_id'] ?? 0), $id]);
            $f = $q->fetch();
            need((bool)$f, 'File not found.');
            delete_file_row($f);
            log_request($id, $u['name'], 'file_deleted', (KINDS[$f['kind']] ?? $f['kind']) . ': ' . $f['original_name']);
            flash('File deleted.');
        } else {
            need(false, 'Unknown action.');
        }
        redirect('request.php?id=' . $id);
    } catch (RuntimeException $ex) {
        $errors[] = $ex->getMessage();
        $r = load_request($id);
    }
}

$st = $r['status'];
$items = request_items($id);
$allFiles = array_values(array_filter(request_files($id), fn($f) => can_see_file($u, $r, $f)));
$certByItem = [];
foreach ($allFiles as $f) { if ($f['kind'] === 'certificate') { $certByItem[(int)$f['item_id']] = $f; } }
$letter = null; $signed = null; $proof = null;
foreach (request_files($id) as $f) {
    if ($f['kind'] === 'release_letter') { $letter = $f; }
    if ($f['kind'] === 'signed_letter') { $signed = $f; }
    if ($f['kind'] === 'deposit_proof') { $proof = $f; }
}
$logs = request_logs($id);
$emails = $isCell ? request_emails($id) : [];
$allCerts = $items && count($certByItem) === count($items);
$showCerts = false;
foreach ($items as $it) { if (isset($certByItem[(int)$it['id']])) { $showCerts = true; } }

layout_start($r['ref'] . ' · ' . $r['full_name'], $isAlum ? 'mine' : 'requests', 'Certificate request');
foreach ($errors as $er) { echo '<div class="flash err">' . e($er) . '</div>'; }
?>
<div class="cols">
<div>
  <section class="card">
    <div class="cardhead"><h2>Request</h2><?= badge($st) ?></div>
    <dl>
      <dt>Reference</dt><dd><strong><?= e($r['ref']) ?></strong></dd>
      <dt>Name</dt><dd><?= e($r['full_name']) ?></dd>
      <dt>Student ID</dt><dd><?= e($r['student_id']) ?></dd>
      <dt>Programme</dt><dd><?= e($r['programme']) ?> · graduated <?= (int)$r['grad_year'] ?></dd>
      <dt>Contact</dt><dd><?= e($r['email']) ?><?= $r['phone'] !== '' ? ' · ' . e($r['phone']) : '' ?></dd>
      <?php if ($r['purpose'] !== ''): ?><dt>Purpose</dt><dd><?= e($r['purpose']) ?></dd><?php endif; ?>
      <dt>Submitted</dt><dd><?= e(fmt_date($r['created_at'])) ?></dd>
      <?php if ($r['amount'] !== null): ?><dt>Amount</dt><dd><strong><?= e(money($r['amount'])) ?></strong></dd><?php endif; ?>
      <?php if ($r['deposit_ref'] && !$isDaa): ?><dt>Deposit ref.</dt><dd><?= e($r['deposit_ref']) ?></dd><?php endif; ?>
    </dl>

    <h3>Certificates requested</h3>
    <div class="tablewrap"><table><thead><tr><th>Certificate</th><th>Fee</th><?php if ($showCerts): ?><th>File</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($items as $it): $cf = $certByItem[(int)$it['id']] ?? null; ?>
      <tr><td><?= e(item_label($it)) ?></td>
        <td class="nowrap"><?= $it['is_other'] ? 'set by Exam Cell' : ((float)$it['fee'] > 0 ? e(money($it['fee'])) : 'no fee') ?></td>
        <?php if ($showCerts): ?><td class="nowrap"><?php if ($cf): ?><a href="file.php?id=<?= (int)$cf['id'] ?>" target="_blank">View</a> · <a href="file.php?id=<?= (int)$cf['id'] ?>&amp;dl=1">Download</a><?php endif; ?></td><?php endif; ?></tr>
    <?php endforeach; ?></tbody></table></div>

    <?php if ($isAlum && $st === 'returned' && $r['cell_note']): ?><div class="flash err" style="margin-top:14px"><b>The Exam Cell returned your request:</b> <?= e($r['cell_note']) ?></div><?php endif; ?>
    <?php if ($isAlum && $st === 'rejected'): ?><div class="flash err" style="margin-top:14px"><b>Request rejected:</b> <?= e($r['cell_note'] ?? '') ?></div><?php endif; ?>
    <?php if ($isCell && $st === 'submitted' && $r['daa_note']): ?><div class="flash err" style="margin-top:14px"><b>The DAA returned this request:</b> <?= e($r['daa_note']) ?></div><?php endif; ?>
  </section>

  <section class="card">
    <h2>Documents</h2>
    <?php if (!$allFiles): ?><p class="empty">No files.</p><?php else: ?>
    <div class="tablewrap"><table><thead><tr><th>Type</th><th>File</th><th>Added</th><th></th></tr></thead><tbody>
    <?php foreach ($allFiles as $f): ?>
      <tr><td><?= e(KINDS[$f['kind']] ?? $f['kind']) ?><?php if ($f['kind'] === 'certificate'): foreach ($items as $it) { if ((int)$it['id'] === (int)$f['item_id']) { echo '<div class="muted">' . e($it['name']) . '</div>'; } } endif; ?></td>
        <td><?= e($f['original_name']) ?></td>
        <td class="nowrap"><?= e(fmt_date($f['uploaded_at'])) ?><div class="muted"><?= e($f['uploaded_by']) ?></div></td>
        <td class="nowrap"><a class="btn small ghost" href="file.php?id=<?= (int)$f['id'] ?>" target="_blank">View</a> <a class="btn small ghost" href="file.php?id=<?= (int)$f['id'] ?>&amp;dl=1">Download</a>
  <?php if ($isCell): ?>
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete_file"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
    <button class="btn small danger" type="submit" data-confirm="Delete this file permanently? This cannot be undone.">Delete</button></form>
  <?php endif; ?>
</td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </section>

  <?php /* ---------------- Alumnus actions ---------------- */ ?>
  <?php if ($isAlum && $st === 'returned'): ?>
  <section class="card">
    <h2>Add what is missing and resubmit</h2>
    <form method="post" enctype="multipart/form-data" class="formcard flat" style="margin-top:12px">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="resubmit">
      <label>More documents (PDF, JPG or PNG)<input type="file" name="supporting[]" multiple accept=".pdf,.jpg,.jpeg,.png" data-max="<?= MAX_UPLOAD ?>"></label>
      <label>Note to the Exam Cell (optional)<textarea name="note" rows="2" maxlength="400"></textarea></label>
      <button class="btn" type="submit">Resubmit</button>
    </form>
  </section>
  <?php elseif ($isAlum && $st === 'awaiting_payment'): ?>
  <section class="card">
    <h2>Pay and upload your deposit proof</h2>
    <p>Your request was approved. Please deposit <strong><?= e(money($r['amount'])) ?></strong> with these details:</p>
    <div class="note big"><?= nl2br(e(setting('payment_details'))) ?></div>
    <?php if ($r['cell_note']): ?><div class="flash err"><b>Your last proof was not accepted:</b> <?= e($r['cell_note']) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="formcard flat" style="margin-top:12px">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="upload_proof">
      <label>Scan or photo of the deposit slip or transfer receipt (PDF, JPG or PNG)<input type="file" name="proof" required accept=".pdf,.jpg,.jpeg,.png" data-max="<?= MAX_UPLOAD ?>"></label>
      <label>Deposit or transaction number (optional)<input name="deposit_ref" maxlength="120"></label>
      <button class="btn" type="submit" data-confirm="Submit this deposit proof to the Exam Cell? You cannot change it afterwards.">Upload and submit</button>
    </form>
  </section>
  <?php elseif ($isAlum && in_array($st, ['payment_submitted', 'ready'], true)): ?>
  <section class="card"><h2>Next</h2><p>The Exam Cell has your deposit proof. Your certificates will be emailed to <?= e($r['email']) ?>, and you can also collect them in person from the Exam Cell.</p></section>
  <?php elseif ($isAlum && $st === 'released'): ?>
  <section class="card"><h2>Released</h2><p>Your certificates were emailed to <?= e($r['email']) ?> and are listed above. You can also collect them in person from the Exam Cell.</p></section>
  <?php elseif ($isAlum && in_array($st, ['submitted', 'with_daa'], true)): ?>
  <section class="card"><h2>Next</h2><p>Your request is being processed. You will get an email when payment is needed.</p></section>
  <?php endif; ?>

  <?php /* ---------------- Exam Cell actions ---------------- */ ?>
  <?php if ($isCell && $st === 'submitted'): ?>
  <section class="card">
    <h2>Review and send to the DAA</h2>
    <p class="muted">Check the documents above. Then upload the letter that asks for the release of the certificates. The DAA will put the seal and signature on it.</p>
    <form method="post" enctype="multipart/form-data" class="formcard flat" style="margin-top:12px">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="send_daa">
      <label>Release letter (PDF)<?= $letter ? ' <span class="muted">· ' . e($letter['original_name']) . ' is already attached. Choose a file only to replace it.</span>' : '' ?>
        <input type="file" name="letter" accept=".pdf,application/pdf" <?= $letter ? '' : 'required' ?> data-max="<?= MAX_UPLOAD ?>"></label>
      <div class="row2">
        <label>Amount payable (<?= e(CURRENCY) ?>)<input name="amount" required inputmode="decimal" value="<?= e(number_format((float)($r['amount'] ?? default_amount($items)), 2, '.', '')) ?>"></label>
        <label>Note for the DAA (optional)<input name="note" maxlength="300"></label>
      </div>
      <p class="muted">The amount is the total of the standard fees. Change it if the fee differs, for example for "Others". Use 0 if there is no fee.</p>
      <button class="btn" type="submit" data-confirm="Send this request and letter to the DAA for approval?">Send to DAA for approval</button>
    </form>
  </section>
  <section class="card">
    <h2>Cannot proceed?</h2>
    <div class="cols2">
      <form method="post" class="formcard flat">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="return">
        <label>Return to requester: what is missing or wrong?<textarea name="reason" rows="2" required maxlength="450"></textarea></label>
        <button class="btn ghost" type="submit" data-confirm="Return this request to the requester?">Return to requester</button>
      </form>
      <form method="post" class="formcard flat">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reject">
        <label>Reject: reason<textarea name="reason" rows="2" required maxlength="450"></textarea></label>
        <button class="btn danger" type="submit" data-confirm="Reject this request? The requester will see the reason.">Reject</button>
      </form>
    </div>
  </section>
  <?php elseif ($isCell && $st === 'with_daa'): ?>
  <section class="card"><h2>With the DAA</h2><p>Waiting for the DAA to put the seal and signature on the letter. You will see the signed letter in Documents, and the payment email goes to the requester automatically.</p></section>
  <?php elseif ($isCell && $st === 'awaiting_payment'): ?>
  <section class="card">
    <h2>Waiting for the deposit</h2>
    <p>The payment email was sent to <?= e($r['email']) ?> when the DAA approved. The requester will upload the deposit proof.</p>
    <form method="post" class="formcard flat"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="resend_payment">
      <button class="btn ghost" type="submit" data-confirm="Send the payment email again?">Resend payment email</button></form>
  </section>
  <?php elseif ($isCell && in_array($st, ['payment_submitted', 'ready'], true)): ?>
  <section class="card">
    <h2><?= $st === 'ready' ? 'Release the certificates' : 'Deposit received: release the certificates' ?></h2>
    <?php if ($proof): ?><p>Deposit proof: <a href="file.php?id=<?= (int)$proof['id'] ?>" target="_blank"><?= e($proof['original_name']) ?></a><?= $r['deposit_ref'] ? ' · ref. ' . e($r['deposit_ref']) : '' ?>. Amount due: <strong><?= e(money($r['amount'])) ?></strong>.</p>
    <?php else: ?><p>No deposit proof was needed (amount payable: <?= e(money($r['amount'])) ?>).</p><?php endif; ?>
    <h3>1. Upload the finished certificates (already sealed and signed)</h3>
    <form method="post" enctype="multipart/form-data" class="formcard flat">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="upload_certs">
      <?php foreach ($items as $it): $cf = $certByItem[(int)$it['id']] ?? null; ?>
        <label><?= e(item_label($it)) ?><?= $cf ? ' <span class="badge green">uploaded</span> <span class="muted">' . e($cf['original_name']) . '. Choose a file only to replace it.</span>' : '' ?>
          <input type="file" name="cert_<?= (int)$it['id'] ?>" accept=".pdf,.jpg,.jpeg,.png" data-max="<?= MAX_UPLOAD ?>"></label>
      <?php endforeach; ?>
      <button class="btn ghost" type="submit">Save certificate files</button>
    </form>
    <h3>2. Email the requester</h3>
    <?php if ($allCerts): ?>
      <p class="muted">The email is prepared from the template. You can edit it before sending.</p>
      <a class="btn" href="email.php?id=<?= $id ?>">Prepare email</a>
    <?php else: ?><p class="muted">Upload a file for every certificate above to continue.</p><?php endif; ?>
    <h3>Proof not valid?</h3>
    <form method="post" class="formcard flat"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="refuse_proof">
      <?php if ($proof): ?><label>Reason<input name="reason" required maxlength="450"></label>
      <button class="btn danger" type="submit" data-confirm="Send this back to the requester to upload a correct proof?">Ask for a new proof</button><?php endif; ?>
    </form>
  </section>
  <?php elseif ($isCell && $st === 'released'): ?>
  <section class="card"><h2>Released</h2><p>The certificates were emailed to the requester.</p><a class="btn ghost" href="email.php?id=<?= $id ?>">Send another email</a></section>
  <?php endif; ?>

  <?php /* ---------------- DAA actions ---------------- */ ?>
  <?php if ($isDaa && $st === 'with_daa'): ?>
  <section class="card">
    <h2>Your approval</h2>
    <p class="muted">Review the application and documents above. Then open the release letter and put your seal and signature on it.</p>
    <div class="files">
      <?php if ($letter): ?><a class="btn ghost" href="file.php?id=<?= (int)$letter['id'] ?>" target="_blank">View letter</a><?php endif; ?>
      <a class="btn" href="sign.php?id=<?= $id ?>">Review and sign</a>
    </div>
    <form method="post" class="formcard flat" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="daa_return">
      <label>Not ready to sign? Return to the Exam Cell with remarks<textarea name="reason" rows="2" required maxlength="450"></textarea></label>
      <button class="btn ghost" type="submit" data-confirm="Return this request to the Exam Cell?">Return to Exam Cell</button>
    </form>
  </section>
  <?php endif; ?>
</div>

<div>
  <section class="card">
    <h2>History</h2>
    <?php if (!$logs): ?><p class="empty">Nothing yet.</p><?php endif; ?>
    <ol class="timeline">
      <?php foreach ($logs as $l): if ($isAlum && in_array($l['action'], ['sent_daa', 'daa_returned'], true)) { continue; } ?>
        <li><b><?= e(ACTION_LABELS[$l['action']] ?? $l['action']) ?></b>
          <span class="muted"><?= e($l['actor_name']) ?> · <?= e(fmt_date($l['created_at'])) ?></span>
          <?php if ($l['note']): ?><div><?= e($l['note']) ?></div><?php endif; ?></li>
      <?php endforeach; ?>
    </ol>
  </section>
  <?php if ($isCell): ?>
  <section class="card">
    <h2>Emails</h2>
    <?php if (!$emails): ?><p class="empty">No emails yet.</p><?php endif; ?>
    <?php foreach ($emails as $m): ?>
      <details class="mailrow">
        <summary><b><?= e($m['subject']) ?></b>
          <span class="muted"><?= e(fmt_date($m['created_at'])) ?> · <?= e($m['to_email']) ?> ·
            <?= ['sent' => 'sent', 'logged' => 'saved only (not delivered)', 'failed' => 'FAILED'][$m['status']] ?></span></summary>
        <?php if ($m['error']): ?><div class="flash err"><?= e($m['error']) ?></div><?php endif; ?>
        <?php if ($m['attachments'] !== ''): ?><div class="muted">Attachments: <?= e($m['attachments']) ?></div><?php endif; ?>
        <pre class="mailbody"><?= e($m['body']) ?></pre>
      </details>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
</div>
</div>
<?php layout_end();
