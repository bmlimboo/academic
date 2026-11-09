<?php
namespace CAS\CertDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_role('examcell');
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='cas_open'){csrf_check();if(empty($_POST['reviewed'])){flash('Confirm fees, instructions and payment details have been reviewed.','err');}else{\q("UPDATE desk_settings SET setting_value=? WHERE setting_key='cert_open'",[!empty($_POST['open'])?'1':'0']);flash('Certificate submission availability updated.');}redirect('settings.php');}

$errors = [];

function fee_value($raw): float
{
    $raw = str_replace(',', '', trim((string)$raw));
    if ($raw === '' || !is_numeric($raw) || (float)$raw < 0 || (float)$raw > 10000000) { throw new RuntimeException('Enter a fee of 0 or more.'); }
    return round((float)$raw, 2);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_text') {
            $gi = trim($_POST['general_instructions'] ?? ''); $pd = trim($_POST['payment_details'] ?? '');
            if ($gi === '' || mb_strlen($gi) > 3000) { throw new RuntimeException('Write the instructions for supporting documents (3000 characters at most).'); }
            if ($pd === '' || mb_strlen($pd) > 1500) { throw new RuntimeException('Write the payment details (1500 characters at most).'); }
            save_setting('general_instructions', str_replace("\r\n", "\n", $gi));
            save_setting('payment_details', str_replace("\r\n", "\n", $pd));
            flash('Saved.');
        } elseif ($action === 'save_tpl' || $action === 'reset_tpl') {
            $tpl = $_POST['tpl'] ?? '';
            if (!in_array($tpl, ['payment', 'release'], true)) { throw new RuntimeException('Unknown template.'); }
            if ($action === 'reset_tpl') {
                reset_setting("email_{$tpl}_subject"); reset_setting("email_{$tpl}_body");
                flash('Template reset to the default.');
            } else {
                $sub = trim($_POST['subject'] ?? ''); $body = str_replace("\r\n", "\n", trim($_POST['body'] ?? ''));
                if ($sub === '' || mb_strlen($sub) > 250) { throw new RuntimeException('Write a subject (250 characters at most).'); }
                if ($body === '' || mb_strlen($body) > 5000) { throw new RuntimeException('Write the message (5000 characters at most).'); }
                save_setting("email_{$tpl}_subject", $sub); save_setting("email_{$tpl}_body", $body);
                flash('Template saved.');
            }
        } elseif ($action === 'save_type' || $action === 'add_type') {
            $name = trim($_POST['name'] ?? ''); $ins = trim($_POST['instructions'] ?? '');
            if ($name === '' || mb_strlen($name) > 120) { throw new RuntimeException('Write the certificate name (120 characters at most).'); }
            if (mb_strlen($ins) > 1000) { throw new RuntimeException('The instructions are too long (1000 characters at most).'); }
            $fee = fee_value($_POST['fee'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            try {
                if ($action === 'add_type') {
                    db()->prepare('INSERT INTO cd_cert_types (name, fee, instructions, is_other, active, sort_order) VALUES (?,?,?,0,1,?)')->execute([$name, $fee, $ins, $sort ?: 50]);
                    flash('Certificate type added.');
                } else {
                    db()->prepare('UPDATE cd_cert_types SET name = ?, fee = ?, instructions = ?, active = ?, sort_order = ? WHERE id = ?')
                        ->execute([$name, $fee, $ins, isset($_POST['active']) ? 1 : 0, $sort, (int)($_POST['type_id'] ?? 0)]);
                    flash('Saved "' . $name . '".');
                }
            } catch (PDOException $ex) {
                if (($ex->errorInfo[1] ?? 0) === 1062) { throw new RuntimeException('A certificate with the name "' . $name . '" already exists.'); }
                throw $ex;
            }
        }
        redirect('settings.php');
    } catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
}

$types = cert_types(false);
layout_start('Settings', 'settings', 'Exam Cell');
?>
<p class="lead">What requesters see, the fees, and the wording of the emails.</p>
<?php foreach ($errors as $er): ?><div class="flash err"><?= e($er) ?></div><?php endforeach; ?>

<section class="card">
  <h2>Instructions and payment details</h2>
  <form method="post" class="formcard flat" style="margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_text">
    <label>Instructions for supporting documents (shown on the request form)
      <textarea name="general_instructions" rows="8" maxlength="3000" required><?= e(setting('general_instructions')) ?></textarea></label>
    <label>Payment details (put into the payment email and shown to the requester)
      <textarea name="payment_details" rows="5" maxlength="1500" required><?= e(setting('payment_details')) ?></textarea></label>
    <button class="btn" type="submit">Save</button>
  </form>
</section>

<section class="card">
  <h2>Email templates</h2>
  <p class="muted">You can use these placeholders: <code>{name}</code> <code>{ref}</code> <code>{student_id}</code> <code>{programme}</code> <code>{certificates}</code> <code>{amount}</code> <code>{currency}</code> <code>{payment_details}</code> <code>{portal_url}</code> <code>{cell_name}</code>.
    The payment email is sent automatically when the DAA approves. The release email is prepared for you to edit and send.</p>
  <?php foreach (['payment' => 'Payment email (automatic, after DAA approval)', 'release' => 'Release email (prepared for you to edit and send)'] as $tpl => $label): ?>
  <form method="post" class="formcard flat tplform">
    <?= csrf_field() ?><input type="hidden" name="tpl" value="<?= $tpl ?>">
    <h3><?= e($label) ?></h3>
    <label>Subject<input name="subject" value="<?= e(setting("email_{$tpl}_subject")) ?>" maxlength="250" required></label>
    <label>Message<textarea name="body" rows="10" maxlength="5000" required class="mono"><?= e(setting("email_{$tpl}_body")) ?></textarea></label>
    <button class="btn" type="submit" name="action" value="save_tpl">Save template</button>
    <button class="btn ghost" type="submit" name="action" value="reset_tpl" formnovalidate data-confirm="Reset this template to the default wording?">Reset to default</button>
  </form>
  <?php endforeach; ?>
</section>

<section class="card">
  <h2>Certificate types and fees</h2>
  <p class="muted">The instructions are shown to the requester under that certificate. A type that is switched off no longer appears on the form, but old requests keep it.</p>
  <?php foreach ($types as $t): ?>
  <form method="post" class="typeform">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_type"><input type="hidden" name="type_id" value="<?= (int)$t['id'] ?>">
    <div class="row3">
      <label>Name<input name="name" value="<?= e($t['name']) ?>" maxlength="120" required></label>
      <label>Fee (<?= e(CURRENCY) ?>)<input name="fee" value="<?= e(number_format((float)$t['fee'], 2, '.', '')) ?>" inputmode="decimal" required></label>
      <label>Order<input type="number" name="sort_order" value="<?= (int)$t['sort_order'] ?>"></label>
    </div>
    <label>Instructions for this certificate<textarea name="instructions" rows="2" maxlength="1000"><?= e($t['instructions']) ?></textarea></label>
    <label class="check inline"><input type="checkbox" name="active" value="1" <?= $t['active'] ? 'checked' : '' ?>><span>Show on the request form<?= $t['is_other'] ? ' (the "write your own" option)' : '' ?></span></label>
    <button class="btn small" type="submit">Save</button>
  </form>
  <?php endforeach; ?>
  <form method="post" class="typeform add">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_type">
    <h3>Add a certificate type</h3>
    <div class="row3">
      <label>Name<input name="name" maxlength="120" required></label>
      <label>Fee (<?= e(CURRENCY) ?>)<input name="fee" value="0.00" inputmode="decimal" required></label>
      <label>Order<input type="number" name="sort_order" value="50"></label>
    </div>
    <label>Instructions for this certificate<textarea name="instructions" rows="2" maxlength="1000"></textarea></label>
    <button class="btn small" type="submit">Add</button>
  </form>
</section>
<?php ?>
<section class="card"><h2>Open / close certificate requests</h2><p>Review the fees, instructions and payment details above before opening this service.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="cas_open"><label><input type="checkbox" name="reviewed" value="1" required>I have reviewed the fees, instructions and payment details.</label><label><input type="checkbox" name="open" value="1" <?= (\one("SELECT setting_value FROM desk_settings WHERE setting_key='cert_open'")['setting_value']??'0')==='1'?'checked':''?>>Allow new certificate requests</label><button class="btn">Save availability</button></form></section>
<?php layout_end();
