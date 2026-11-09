<?php
namespace CAS\SignDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_daa();
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT r.*, u.name AS user_name, IF(CHAR_LENGTH(r.department) > 0, r.department, u.department) AS dept FROM sd_doc_requests r JOIN sd_users u ON u.id = r.user_id WHERE r.id = ?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r) { http_response_code(404); exit('Document not found.'); }
if (!in_array($r['status'], ['submitted', 'under_review'], true)) {
    flash('This document has already been handled.', 'err');
    redirect('view.php?id=' . $id);
}
$hasSig = stamp_path($u['id'], 'signature') !== null;
$hasSeal = stamp_path($u['id'], 'seal') !== null;
if (!$hasSig && !$hasSeal) {
    flash('Upload your signature and seal first.', 'err');
    redirect('stamps.php');
}
if ($r['status'] === 'submitted') {
    db()->prepare("UPDATE sd_doc_requests SET status='under_review' WHERE id=?")->execute([$id]);
    log_action($id, $u['id'], 'opened');
}
$error = null;
$origPath = STORAGE_DIR . DIRECTORY_SEPARATOR . basename((string)$r['original_file']);
if (strtolower(pathinfo($origPath, PATHINFO_EXTENSION)) !== 'pdf') {
    $error = 'This document was submitted as a Word file, and only PDFs can be stamped.';
} elseif (!is_file($origPath)) {
    $error = 'The submitted file is missing from the storage folder.';
}
$last = last_dispatch_number();

layout_start('Sign & stamp', 'queue', $r['ref_no'] . ' · ' . $r['title']);
if ($error): ?>
  <section class="card">
    <h2>This document cannot be stamped</h2>
    <div class="flash err"><?= e($error) ?></div>
    <p>Return it to the submitter and ask them to upload a PDF copy.</p>
    <a class="btn ghost" href="view.php?id=<?= $id ?>">Back to the document</a>
  </section>
<?php else: ?>
<div class="stamper" id="stamper"
     data-id="<?= $id ?>" data-csrf="<?= e(csrf_token()) ?>" data-date="<?= e(date('j F Y')) ?>"
     data-pdf="download.php?id=<?= $id ?>&amp;type=original"
     <?php if ($hasSig): ?>data-signature="stamp_image.php?kind=signature"<?php endif; ?>
     <?php if ($hasSeal): ?>data-seal="stamp_image.php?kind=seal"<?php endif; ?>>
  <div id="pages" class="pages"><p class="muted">Loading the preview…</p></div>
  <aside class="panel card">
    <h2>Place on the document</h2>
    <p class="muted">Add an item, then drag it where you want it. Click an item to resize or remove it. New items go on the page you are looking at.</p>
    <div class="addbtns">
      <?php if ($hasSig): ?><button type="button" class="btn ghost small" data-add="signature">+ Signature</button><?php endif; ?>
      <?php if ($hasSeal): ?><button type="button" class="btn ghost small" data-add="seal">+ Seal</button><?php endif; ?>
      <button type="button" class="btn ghost small" data-add="dispatch">+ Dispatch no.</button>
      <button type="button" class="btn ghost small" data-add="date">+ Date</button>
    </div>
    <div id="selbox" hidden>
      <label>Size <input type="range" id="size" min="5" max="60" step="1"></label>
      <button type="button" class="btn small danger" id="remove">Remove this item</button>
    </div>
    <hr>
    <label>Dispatch number
      <input id="dispatch" maxlength="80" autocomplete="off" placeholder="e.g. <?= e(DISPATCH_PREFIX . '/' . academic_year() . '/627') ?>">
      <span class="muted">Type it from your register.<?= $last !== '' ? ' Last issued here: ' . e($last) : '' ?></span>
    </label>
    <label>Your password
      <input type="password" id="password" autocomplete="current-password">
      <span class="muted">Needed to confirm that it is you.</span>
    </label>
    <button class="btn" id="apply" type="button">Apply &amp; save signed copy</button>
    <div id="msg" class="flash" hidden></div>
    <p><a href="view.php?id=<?= $id ?>">Cancel</a></p>
  </aside>
</div>
<script src="assets/vendor/pdf.min.js"></script>
<script src="assets/vendor/pdf-lib.min.js"></script>
<script src="assets/stamp.js"></script>
<?php endif;
layout_end();
