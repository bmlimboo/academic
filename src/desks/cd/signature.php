<?php
namespace CAS\CertDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_role('daa');
$st = db()->prepare('SELECT designation, signature_file, seal_file FROM cd_users WHERE id = ?');
$st->execute([$u['id']]);
$me = $st->fetch();

// Serves the DAA's own signature or seal image.
if (isset($_GET['img'])) {
    $file = $_GET['img'] === 'seal' ? $me['seal_file'] : ($_GET['img'] === 'signature' ? $me['signature_file'] : null);
    $path = $file ? paper_path($file) : '';
    if (!$path || !is_file($path)) { http_response_code(404); exit('Not found.'); }
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $designation = mb_substr(trim($_POST['designation'] ?? ''), 0, 120);
        $new = [];
        foreach (['signature' => 'signature_file', 'seal' => 'seal_file'] as $field => $col) {
            $list = files_list($field);
            if ($list) { [$stored] = save_upload($list[0], ['png'], 'sg'); $new[$col] = $stored; }
        }
        foreach ($new as $col => $stored) {
            if ($me[$col]) { @unlink(paper_path($me[$col])); }
            db()->prepare("UPDATE desk_profiles SET $col = ? WHERE id = ?")->execute([$stored, $u['id']]);
        }
        db()->prepare('UPDATE desk_profiles SET designation = ? WHERE id = ?')->execute([$designation, $u['id']]);
        flash('Saved.');
        redirect('signature.php');
    } catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
}

layout_start('Signature and seal', 'signature', 'Dean of Academic Affairs');
?>
<p class="lead">These images are put on the release letters you approve. Upload them once. Use PNG files with a transparent background so the letter text stays readable.</p>
<?php foreach ($errors as $er): ?><div class="flash err"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" enctype="multipart/form-data" class="card formcard">
  <?= csrf_field() ?>
  <label>Designation (printed under the signature)<input name="designation" value="<?= e($me['designation']) ?>" maxlength="120" placeholder="Dean of Academic Affairs"></label>
  <div class="cols2">
    <div>
      <label>Signature (PNG)<input type="file" name="signature" accept=".png,image/png" data-max="<?= MAX_UPLOAD ?>"></label>
      <?php if ($me['signature_file']): ?><div class="stamp-preview"><img src="signature.php?img=signature" alt="Your signature"></div><?php else: ?><p class="muted">No signature uploaded yet.</p><?php endif; ?>
    </div>
    <div>
      <label>Seal (PNG)<input type="file" name="seal" accept=".png,image/png" data-max="<?= MAX_UPLOAD ?>"></label>
      <?php if ($me['seal_file']): ?><div class="stamp-preview"><img src="signature.php?img=seal" alt="Your seal"></div><?php else: ?><p class="muted">No seal uploaded yet.</p><?php endif; ?>
    </div>
  </div>
  <button class="btn" type="submit">Save</button>
</form>
<?php layout_end();
