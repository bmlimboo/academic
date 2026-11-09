<?php
namespace CAS\CertDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_role('examcell');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$r = load_request($id);
if (!$r) { http_response_code(404); exit('Request not found.'); }
if (!in_array($r['status'], ['payment_submitted', 'ready', 'released'], true)) {
    flash('The release email can be prepared once the deposit is received.', 'err');
    redirect('request.php?id=' . $id);
}
$items = request_items($id);
$files = request_files($id);
$certs = []; $attachable = [];
foreach ($files as $f) {
    if (in_array($f['kind'], ['certificate', 'signed_letter'], true)) { $attachable[(int)$f['id']] = $f; }
    if ($f['kind'] === 'certificate') { $certs[(int)$f['item_id']] = $f; }
}
if ($r['status'] !== 'released' && count($certs) < count($items)) {
    flash('Upload a certificate file for every requested certificate first.', 'err');
    redirect('request.php?id=' . $id);
}
$itemName = [];
foreach ($items as $it) { $itemName[(int)$it['id']] = item_label($it); }

$vars = template_vars($r, $items);
$v = ['to' => $r['email'], 'subject' => render_template(setting('email_release_subject'), $vars), 'body' => render_template(setting('email_release_body'), $vars)];
$picked = [];
foreach ($attachable as $fid => $f) { if ($f['kind'] === 'certificate') { $picked[$fid] = true; } }
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $v = ['to' => trim($_POST['to'] ?? ''), 'subject' => trim($_POST['subject'] ?? ''), 'body' => str_replace("\r\n", "\n", (string)($_POST['body'] ?? ''))];
    $picked = [];
    foreach ((array)($_POST['att'] ?? []) as $fid) { if (isset($attachable[(int)$fid])) { $picked[(int)$fid] = true; } }
    if (!filter_var($v['to'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid recipient email address.'; }
    if ($v['subject'] === '' || mb_strlen($v['subject']) > 250) { $errors[] = 'Enter a subject (250 characters at most).'; }
    if (trim($v['body']) === '' || mb_strlen($v['body']) > 20000) { $errors[] = 'Write the message.'; }
    if ($r['status'] !== 'released' && !$picked) { $errors[] = 'Attach at least one certificate.'; }
    $atts = []; $total = 0;
    foreach (array_keys($picked) as $fid) {
        $f = $attachable[$fid]; $path = paper_path($f['stored']);
        if (!is_file($path)) { $errors[] = 'The file "' . $f['original_name'] . '" is missing from the storage folder.'; continue; }
        $total += filesize($path);
        $label = $f['kind'] === 'certificate' ? ($itemName[(int)$f['item_id']] ?? 'Certificate') : 'Release letter';
        $ext = strtolower(pathinfo($f['stored'], PATHINFO_EXTENSION));
        $atts[] = [$path, name_part($label, 70) . '.' . $ext, file_mime($f['stored'])];
    }
    if ($total > 20 * 1048576) { $errors[] = 'The attachments are larger than 20 MB in all. Remove some or send two emails.'; }
    if (!$errors) {
        [$s, $err] = deliver_email($r, 'release', $v['to'], $v['subject'], $v['body'], $atts, $u['name']);
        if ($s === 'failed') {
            $errors[] = 'The email could not be sent: ' . $err;
        } else {
            if ($r['status'] !== 'released') { set_status($id, 'released'); }
            log_request($id, $u['name'], 'released', 'Email queued to ' . $v['to'] . ($atts ? ' with ' . count($atts) . ' attachment(s)' : ''));
            flash($s === 'queued' ? 'Email queued to ' . $v['to'] . '. The request is marked as released.' : 'Request marked as released, but NO email was delivered: mail is not configured (MAIL_MODE is "log"). The email is saved in the request history.', $s === 'queued' ? 'ok' : 'err');
            redirect('request.php?id=' . $id);
        }
    }
}

layout_start('Email to ' . $r['full_name'], 'requests', $r['ref']);
?>
<p class="lead">This message was prepared from the template. Change anything you like, then press Send email.</p>
<div class="flash <?= MAIL_MODE === 'log' ? 'err' : 'ok' ?>" style="font-weight:500"><?= e(mail_mode_note()) ?></div>
<?php foreach ($errors as $er): ?><div class="flash err"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" class="card formcard">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <label>To<input type="email" name="to" value="<?= e($v['to']) ?>" required></label>
  <label>Subject<input name="subject" value="<?= e($v['subject']) ?>" required maxlength="250"></label>
  <label>Message<textarea name="body" rows="14" required class="mono"><?= e($v['body']) ?></textarea></label>
  <div style="margin-bottom:14px"><b>Attachments</b>
    <?php foreach ($attachable as $fid => $f): $isC = $f['kind'] === 'certificate'; ?>
      <label class="check inline" style="display:flex;margin:6px 0"><input type="checkbox" name="att[]" value="<?= $fid ?>" <?= isset($picked[$fid]) ? 'checked' : '' ?>>
        <span><?= e($isC ? ($itemName[(int)$f['item_id']] ?? 'Certificate') : 'Release letter signed by the DAA') ?> <span class="muted">· <?= e($f['original_name']) ?> · <?= round((int)@filesize(paper_path($f['stored'])) / 1024) ?> KB</span></span></label>
    <?php endforeach; ?>
  </div>
  <div class="files" style="margin:0">
    <button class="btn" type="submit" data-confirm="Send this email to the requester?">Send email</button>
    <a class="btn ghost" href="email.php?id=<?= $id ?>" data-confirm="Discard your edits and start again from the template?">Reset to template</a>
    <a class="btn ghost" href="request.php?id=<?= $id ?>">Cancel</a>
  </div>
</form>
<?php layout_end();
