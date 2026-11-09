<?php
namespace CAS\CertDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_role('alumnus');
if((\one("SELECT setting_value FROM desk_settings WHERE setting_key='cert_open'")['setting_value']??'0')!=='1'){layout_start('Certificate Requests','new');echo '<div class="flash">Certificate requests are not open yet. Exam Cell will configure fees, instructions and payment details before opening submissions.</div>';layout_end();return;}
$types = cert_types(true);
$byId = [];
foreach ($types as $t) { $byId[(int)$t['id']] = $t; }
$thisYear = (int)date('Y');
$errors = [];
$v = ['full_name' => $u['name'], 'student_id' => '', 'programme' => '', 'grad_year' => '', 'phone' => '', 'purpose' => '', 'types' => [], 'other' => []];
$ph = db()->prepare('SELECT phone FROM cd_users WHERE id = ?'); $ph->execute([$u['id']]);
$v['phone'] = (string)($ph->fetchColumn() ?: '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $v['full_name'] = trim($_POST['full_name'] ?? '');
    $v['student_id'] = trim($_POST['student_id'] ?? '');
    $v['programme'] = trim($_POST['programme'] ?? '');
    $v['grad_year'] = trim($_POST['grad_year'] ?? '');
    $v['phone'] = trim($_POST['phone'] ?? '');
    $v['purpose'] = trim($_POST['purpose'] ?? '');
    $v['types'] = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['types'] ?? [])), fn($i) => isset($byId[$i]))));
    $v['other'] = (array)($_POST['other_text'] ?? []);

    if ($v['full_name'] === '' || mb_strlen($v['full_name']) > 120) { $errors[] = 'Enter your full name as it appears on your certificates.'; }
    if ($v['student_id'] === '' || mb_strlen($v['student_id']) > 40) { $errors[] = 'Enter your student ID.'; }
    if ($v['programme'] === '' || mb_strlen($v['programme']) > 160) { $errors[] = 'Enter the programme you studied.'; }
    $year = (int)$v['grad_year'];
    if ($year < 1980 || $year > $thisYear) { $errors[] = 'Enter your year of graduation (for example ' . ($thisYear - 1) . ').'; }
    if (mb_strlen($v['phone']) > 40) { $errors[] = 'The phone number is too long.'; }
    if (mb_strlen($v['purpose']) > 400) { $errors[] = 'The purpose is too long (400 characters at most).'; }
    if (!$v['types']) { $errors[] = 'Tick at least one certificate you need.'; }
    $others = [];
    foreach ($v['types'] as $tid) {
        if ($byId[$tid]['is_other']) {
            $txt = trim((string)($v['other'][$tid] ?? ''));
            if ($txt === '' || mb_strlen($txt) > 200) { $errors[] = 'For "' . $byId[$tid]['name'] . '", write what you need (200 characters at most).'; }
            $others[$tid] = $txt;
        }
    }
    $groups = ['application' => files_list('application'), 'marksheet' => files_list('marksheet'), 'degree' => files_list('degree'), 'supporting' => files_list('supporting')];
    if (count($groups['application']) !== 1) { $errors[] = 'Upload your signed application letter (one file).'; }
    if (!$groups['marksheet']) { $errors[] = 'Upload your marksheet(s).'; }
    if (!$groups['degree']) { $errors[] = 'Upload your degree certificate or diploma.'; }
    if (!isset($_POST['declare'])) { $errors[] = 'Tick the declaration to confirm that the details and documents are correct.'; }
    if (array_sum(array_map('count', $groups)) > 25) { $errors[] = 'Too many files. Upload at most 25 files in all.'; }

    if (!$errors) {
        $saved = [];
        try {
            foreach ($groups as $kind => $list) {
                foreach ($list as $f) { [$stored, $orig] = save_upload($f, ['pdf', 'jpg', 'png'], 'rq'); $saved[] = [$kind, $stored, $orig]; }
            }
            db()->beginTransaction();
            db()->prepare('INSERT INTO cd_requests (user_id, full_name, student_id, programme, grad_year, email, phone, purpose, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$u['id'], $v['full_name'], $v['student_id'], $v['programme'], $year, $u['email'], $v['phone'], $v['purpose'], 'submitted', now_sql(), now_sql()]);
            $rid = (int)db()->lastInsertId();
            $ref = 'CR-' . date('Y') . '-' . str_pad((string)$rid, 4, '0', STR_PAD_LEFT);
            db()->prepare('UPDATE cd_requests SET ref = ? WHERE id = ?')->execute([$ref, $rid]);
            $insItem = db()->prepare('INSERT INTO cd_request_items (request_id, type_id, other_text) VALUES (?,?,?)');
            foreach ($v['types'] as $tid) { $insItem->execute([$rid, $tid, $others[$tid] ?? null]); }
            foreach ($saved as [$kind, $stored, $orig]) { add_file($rid, null, $kind, $stored, $orig, $u['name']); }
            log_request($rid, $u['name'], 'submitted');
            db()->commit();
            flash('Request ' . $ref . ' submitted. The Exam Cell will review it. You can follow it here.');
            redirect('request.php?id=' . $rid);
        } catch (Throwable $ex) {
            if (db()->inTransaction()) { db()->rollBack(); }
            foreach ($saved as [, $stored]) { @unlink(paper_path($stored)); }
            $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not save the request: ' . $ex->getMessage();
            $errors[] = 'Please choose the files again before pressing Submit.';
        }
    } else {
        $errors[] = 'Please choose the files again before pressing Submit (the browser does not keep them).';
    }
}

layout_start('New certificate request', 'new');
?>
<p class="lead">Tell us who you are, tick what you need, and attach your documents. You can ask for more than one certificate in one request.</p>
<?php foreach ($errors as $er): ?><div class="flash err"><?= e($er) ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="newreq" id="reqform">
  <?= csrf_field() ?>
  <section class="card">
    <h2>1. Your details</h2>
    <div class="row2" style="margin-top:14px">
      <label>Full name<input name="full_name" value="<?= e($v['full_name']) ?>" required maxlength="120"></label>
      <label>Student ID<input name="student_id" value="<?= e($v['student_id']) ?>" required maxlength="40"></label>
    </div>
    <div class="row2">
      <label>Programme of study
        <input name="programme" value="<?= e($v['programme']) ?>" list="programmes" required maxlength="160" placeholder="Choose or type your programme">
        <datalist id="programmes"><?php foreach (PROGRAMMES as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist></label>
      <label>Year of graduation<input type="number" name="grad_year" value="<?= e($v['grad_year']) ?>" required min="1980" max="<?= $thisYear ?>" placeholder="<?= $thisYear - 1 ?>"></label>
    </div>
    <div class="row2">
      <label>Email (for all updates)<input value="<?= e($u['email']) ?>" disabled></label>
      <label>Phone (optional)<input name="phone" value="<?= e($v['phone']) ?>" maxlength="40"></label>
    </div>
  </section>

  <section class="card">
    <h2>2. What do you need?</h2>
    <p class="muted">The fee for each certificate is shown. The final amount is confirmed when your request is approved.</p>
    <div class="typelist">
    <?php foreach ($types as $t): $tid = (int)$t['id']; $on = in_array($tid, $v['types'], true); ?>
      <div class="typeitem" data-type="<?= $tid ?>">
        <label class="check"><input type="checkbox" name="types[]" value="<?= $tid ?>" <?= $on ? 'checked' : '' ?>>
          <span><?= e($t['name']) ?> <span class="muted"><?= $t['is_other'] ? '· fee set by the Exam Cell' : ((float)$t['fee'] > 0 ? '· ' . e(money($t['fee'])) : '· no fee') ?></span></span></label>
        <div class="typeinfo">
          <?php if ($t['is_other']): ?>
            <label>Which certificate or document do you need?<input name="other_text[<?= $tid ?>]" value="<?= e($v['other'][$tid] ?? '') ?>" maxlength="200"></label>
          <?php endif; ?>
          <?php if (trim((string)$t['instructions']) !== ''): ?><div class="note"><b>For this certificate:</b> <?= e($t['instructions']) ?></div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <label style="margin-top:16px">Purpose, or the institution it is addressed to (optional)
      <textarea name="purpose" rows="2" maxlength="400" placeholder="For example: admission to a Master's programme at ..."><?= e($v['purpose']) ?></textarea></label>
  </section>

  <section class="card">
    <h2>3. Application and supporting documents</h2>
    <div class="note big"><b>Before you upload, please read:</b><br><?= nl2br(e(setting('general_instructions'))) ?></div>
    <p class="muted">Accepted: PDF, JPG or PNG, up to <?= MAX_UPLOAD / 1048576 ?> MB per file. You can select several files at once where it says so.</p>
    <label>Application letter (signed) <span class="req">required</span>
      <input type="file" name="application" accept=".pdf,.jpg,.jpeg,.png" required data-max="<?= MAX_UPLOAD ?>"></label>
    <label>Marksheet(s) <span class="req">required</span>
      <input type="file" name="marksheet[]" accept=".pdf,.jpg,.jpeg,.png" multiple required data-max="<?= MAX_UPLOAD ?>"></label>
    <label>Degree certificate or diploma <span class="req">required</span>
      <input type="file" name="degree[]" accept=".pdf,.jpg,.jpeg,.png" multiple required data-max="<?= MAX_UPLOAD ?>"></label>
    <label>Other supporting documents (optional)
      <input type="file" name="supporting[]" accept=".pdf,.jpg,.jpeg,.png" multiple data-max="<?= MAX_UPLOAD ?>"></label>
  </section>

  <section class="card">
    <label class="check"><input type="checkbox" name="declare" value="1" required><span>I confirm that the details above are correct and that the documents I attached are true copies.</span></label>
    <button class="btn" type="submit">Submit request</button>
  </section>
</form>
<?php layout_end();
