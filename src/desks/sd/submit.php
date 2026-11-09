<?php
namespace CAS\SignDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_login();
if ($u['role'] === 'daa') { redirect('queue.php'); }
$errors = [];
$v = ['stype' => '', 'dept' => '', 'title' => '', 'doc_type' => DOC_TYPES[0], 'urgency' => 'normal', 'remarks' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $v['stype'] = $_POST['stype'] ?? '';
    $v['dept'] = $_POST['dept'] ?? '';
    $v['title'] = trim($_POST['title'] ?? '');
    $v['doc_type'] = $_POST['doc_type'] ?? '';
    $v['urgency'] = ($_POST['urgency'] ?? '') === 'urgent' ? 'urgent' : 'normal';
    $v['remarks'] = trim($_POST['remarks'] ?? '');
    if (!in_array($v['stype'], SUBMITTER_TYPES, true)) { $errors[] = 'Choose whether you are Staff or Faculty.'; }
    if (!in_array($v['dept'], DEPARTMENTS, true)) { $errors[] = 'Choose your department.'; }
    if ($v['title'] === '' || mb_strlen($v['title']) > 200) { $errors[] = 'Enter a title (up to 200 characters).'; }
    if (!in_array($v['doc_type'], DOC_TYPES, true)) { $errors[] = 'Choose a document type.'; }
    if (!$errors) {
        try {
            [$file, $orig] = save_document($_FILES['file'] ?? [], 'orig', ACCEPT_SUBMIT);
            $pdo = db();
            $pdo->prepare('INSERT INTO sd_doc_requests (user_id,submitter_type,department,title,doc_type,urgency,remarks,original_file,original_name) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$u['id'], $v['stype'], $v['dept'], $v['title'], $v['doc_type'], $v['urgency'], $v['remarks'], $file, $orig]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE sd_doc_requests SET ref_no = ? WHERE id = ?')->execute([sprintf('DAA-%s-%04d', date('Y'), $id), $id]);
            log_action($id, $u['id'], 'submitted', $v['remarks'] ?: null);
            flash('Sent to the DAA. You will see the status here when it changes.');
            redirect('view.php?id=' . $id);
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }
}
layout_start('New submission', 'new');
?>
<p class="lead">Upload your document as a PDF (in Word, use Save as PDF). It goes straight to the DAA's signing queue. The PDF must not be password-protected.</p>
<?php foreach ($errors as $er): ?><div class="flash err"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" enctype="multipart/form-data" class="card formcard">
  <?= csrf_field() ?>
  <div class="row2">
    <label>I am
      <select name="stype" required><option value="">Select…</option><?php foreach (SUBMITTER_TYPES as $t): ?><option <?= $t === $v['stype'] ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    </label>
    <label>Department
      <select name="dept" required><option value="">Select…</option><?php foreach (DEPARTMENTS as $t): ?><option <?= $t === $v['dept'] ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    </label>
  </div>
  <label>Title<input name="title" maxlength="200" required value="<?= e($v['title']) ?>" placeholder="e.g. Internship request for 3rd year students"></label>
  <div class="row2">
    <label>Document type
      <select name="doc_type"><?php foreach (DOC_TYPES as $t): ?><option <?= $t === $v['doc_type'] ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    </label>
    <label>Urgency
      <select name="urgency"><option value="normal">Normal</option><option value="urgent" <?= $v['urgency'] === 'urgent' ? 'selected' : '' ?>>Urgent</option></select>
    </label>
  </div>
  <label>Remarks for the DAA (optional)<textarea name="remarks" rows="3"><?= e($v['remarks']) ?></textarea></label>
  <label>PDF file (up to <?= MAX_UPLOAD / 1048576 ?> MB)
    <input type="file" name="file" accept="application/pdf,.pdf" required data-filename>
  </label>
  <button class="btn" type="submit">Send to DAA</button>
</form>
<?php layout_end();
