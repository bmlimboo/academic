<?php
namespace CAS\CertDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_role('daa');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$r = load_request($id);
if (!$r || !can_see_request($u, $r)) { http_response_code(404); exit('Request not found.'); }
$letter = null;
foreach (request_files($id) as $f) { if ($f['kind'] === 'release_letter') { $letter = $f; } }

$isAjax = !empty($_POST['ajax']);
function reply(bool $ok, string $msg, string $to = ''): void
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok, 'error' => $ok ? null : $msg, 'redirect' => $to]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if ($r['status'] !== 'with_daa') { throw new RuntimeException('This request is not waiting for your approval.'); }
        $list = files_list('signed');
        if (!$list) { throw new RuntimeException('The signed PDF was not received.'); }
        $remarks = mb_substr(trim($_POST['remarks'] ?? ''), 0, 400);

        [$stored, $orig] = save_upload($list[0], ['pdf'], 'sl');
        foreach (request_files($id) as $old) { if ($old['kind'] === 'signed_letter') { delete_file_row($old); } }
        add_file($id, null, 'signed_letter', $stored, $orig, $u['name']);
        $amount = (float)$r['amount'];
        set_status($id, $amount > 0 ? 'awaiting_payment' : 'ready', ['daa_note' => $remarks !== '' ? $remarks : null, 'cell_note' => null]);
        log_request($id, $u['name'], 'approved', $remarks !== '' ? $remarks : null);
        $msg = 'Approved. The signed letter is with the Exam Cell.';
        if ($amount > 0) {
            // Automatic payment email to the requester.
            [$s, $err] = send_payment_email(load_request($id));
            log_request($id, 'System', 'payment_email', $s === 'queued' ? 'Queued to ' . $r['email'] : ($s === 'logged' ? 'Saved in the email log only (mail not configured)' : 'FAILED: ' . $err));
            $msg .= $s === 'queued' ? ' The payment email is queued for the requester.' : ' Note: the payment email was not delivered, the Exam Cell can resend it.';
        } else {
            $msg .= ' No fee is payable, so the Exam Cell can release the certificates.';
        }
        flash($msg);
        if ($isAjax) { reply(true, '', 'request.php?id=' . $id); }
        redirect('request.php?id=' . $id);
    } catch (Throwable $ex) {
        $m = $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not save: ' . $ex->getMessage();
        if ($isAjax) { reply(false, $m); }
        flash($m, 'err');
        redirect('sign.php?id=' . $id);
    }
}

if ($r['status'] !== 'with_daa' || !$letter) {
    flash($letter ? 'This request is not waiting for your approval.' : 'There is no release letter to sign.', 'err');
    redirect('request.php?id=' . $id);
}
$st = db()->prepare('SELECT designation, signature_file, seal_file FROM cd_users WHERE id = ?');
$st->execute([$u['id']]);
$me = $st->fetch();
$cfg = [
    'id' => $id, 'csrf' => csrf_token(), 'post' => 'sign.php',
    'pdfUrl' => 'file.php?id=' . (int)$letter['id'],
    'sigUrl' => $me['signature_file'] ? 'signature.php?img=signature' : null,
    'sealUrl' => $me['seal_file'] ? 'signature.php?img=seal' : null,
    'name' => $u['name'], 'designation' => $me['designation'] ?: 'Dean of Academic Affairs',
    'date' => date('d M Y, H:i'),
    'libs' => ['pdfjs' => ['assets/vendor/pdf.min.js', PDFJS_URL], 'worker' => ['assets/vendor/pdf.worker.min.js', PDFJS_WORKER_URL], 'pdflib' => ['assets/vendor/pdf-lib.min.js', PDFLIB_URL]],
];
layout_start('Sign: ' . $r['ref'], 'requests', 'Dean of Academic Affairs');
?>
<p class="lead">Release letter for <?= e($r['full_name']) ?> (<?= e($r['student_id']) ?>). Drag the signature and the seal to where they should go, then press Approve and sign.</p>
<?php if (!$me['signature_file'] && !$me['seal_file']): ?>
  <div class="flash err">You have not uploaded a signature or seal yet. <a href="signature.php">Upload them here</a> first, or use the upload option at the bottom of this page.</div>
<?php endif; ?>
<div class="signgrid">
  <section class="card">
    <div class="signbar">
      <button type="button" class="btn small ghost" id="prev">Previous page</button>
      <span>Page <b id="pageno">1</b> of <b id="pagecount">1</b></span>
      <button type="button" class="btn small ghost" id="next">Next page</button>
    </div>
    <div id="signmsg" class="flash" style="background:#eef2fb;color:var(--ink)">Loading the letter...</div>
    <div class="stagewrap"><div id="stage"><canvas id="pdfcanvas"></canvas></div></div>
  </section>
  <section class="card">
    <h2>Put on the letter</h2>
    <label class="check"><input type="checkbox" id="useSig" <?= $me['signature_file'] ? 'checked' : 'disabled' ?>><span>Signature</span></label>
    <label>Signature size<input type="range" id="sigSize" min="60" max="300" value="150"></label>
    <label class="check"><input type="checkbox" id="useSeal" <?= $me['seal_file'] ? 'checked' : 'disabled' ?>><span>Seal</span></label>
    <label>Seal size<input type="range" id="sealSize" min="60" max="300" value="130"></label>
    <label class="check"><input type="checkbox" id="useLine" checked><span>Print name, designation and date under the signature</span></label>
    <label>Remarks for the Exam Cell (optional)<input id="remarks" maxlength="400"></label>
    <button class="btn" type="button" id="apply" disabled>Approve and sign</button>
    <p class="muted">This approves the request. The requester then gets the payment email automatically. A signed copy is saved; the original letter stays unchanged.</p>
    <p><a href="request.php?id=<?= $id ?>">Back to the request</a></p>
  </section>
</div>

<section class="card">
  <h2>Or upload a letter you already signed</h2>
  <p class="muted">If the signing screen does not work (for example no internet for its libraries), sign the PDF yourself and upload it here. This also approves the request.</p>
  <form method="post" enctype="multipart/form-data" class="formcard flat">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <label>Signed PDF<input type="file" name="signed" accept=".pdf,application/pdf" required data-max="<?= MAX_UPLOAD ?>"></label>
    <label>Remarks (optional)<input name="remarks" maxlength="400"></label>
    <button class="btn ghost" type="submit" data-confirm="Approve this request with the uploaded signed letter?">Upload and approve</button>
  </form>
</section>
<script>window.SIGN = <?= json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/sign.js"></script>
<?php layout_end();
