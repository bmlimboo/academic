<?php
namespace CAS\SignDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_daa();
$kinds = ['signature' => 'Signature', 'seal' => 'Seal'];
$dir = STORAGE_DIR . DIRECTORY_SEPARATOR . 'stamps';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $kind = $_POST['kind'] ?? '';
    if (!isset($kinds[$kind])) { http_response_code(400); exit('Unknown item.'); }
    if (($_POST['action'] ?? '') === 'remove') {
        foreach (['png', 'jpg'] as $e) { @unlink($dir . DIRECTORY_SEPARATOR . 'stamp_' . $u['id'] . '_' . $kind . '.' . $e); }
        flash($kinds[$kind] . ' removed.');
        redirect('stamps.php');
    }
    try {
        $f = $_FILES['image'] ?? [];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { throw new RuntimeException('Choose an image file.'); }
        if ($f['size'] > STAMP_IMG_MAX) { throw new RuntimeException('The image is larger than ' . (STAMP_IMG_MAX / 1048576) . ' MB.'); }
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) { throw new RuntimeException('Use a PNG or JPG image.'); }
        if ($info[0] > 4000 || $info[1] > 4000) { throw new RuntimeException('The image is too large (4000 pixels at most on each side).'); }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) { throw new RuntimeException('Could not create ' . $dir); }
        $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
        foreach (['png', 'jpg'] as $e) { @unlink($dir . DIRECTORY_SEPARATOR . 'stamp_' . $u['id'] . '_' . $kind . '.' . $e); }
        $target = $dir . DIRECTORY_SEPARATOR . 'stamp_' . $u['id'] . '_' . $kind . '.' . $ext;
        if (!@move_uploaded_file($f['tmp_name'], $target) && !@copy($f['tmp_name'], $target)) {
            throw new RuntimeException('Could not save the image to ' . $target);
        }
        flash($kinds[$kind] . ' saved.');
        redirect('stamps.php');
    } catch (RuntimeException $ex) {
        $errors[] = $ex->getMessage();
    }
}

layout_start('Signature & seal', 'stamps', 'Stamping');
?>
<p class="lead">Upload your signature and the college seal once. They are kept private and used only when you sign and stamp a document. A PNG with a transparent background looks best.</p>
<?php foreach ($errors as $er): ?><div class="flash err"><?= e($er) ?></div><?php endforeach; ?>
<div class="cols2">
<?php foreach ($kinds as $kind => $label): $p = stamp_path($u['id'], $kind); ?>
  <section class="card">
    <h2><?= e($label) ?></h2>
    <div class="stampprev"><?php if ($p): ?><img src="stamp_image.php?kind=<?= $kind ?>&amp;v=<?= filemtime($p) ?>" alt="<?= e($label) ?>"><?php else: ?><span class="muted">Not uploaded yet</span><?php endif; ?></div>
    <form method="post" enctype="multipart/form-data" class="formcard flat">
      <?= csrf_field() ?><input type="hidden" name="kind" value="<?= $kind ?>">
      <label><?= $p ? 'Replace image' : 'Upload image' ?> (PNG or JPG, up to <?= STAMP_IMG_MAX / 1048576 ?> MB)
        <input type="file" name="image" accept="image/png,image/jpeg" required></label>
      <div class="files"><button class="btn" type="submit">Save <?= e(strtolower($label)) ?></button>
      <?php if ($p): ?><button class="btn ghost" name="action" value="remove" type="submit" formnovalidate data-confirm="Remove the saved <?= e(strtolower($label)) ?>?">Remove</button><?php endif; ?></div>
    </form>
  </section>
<?php endforeach; ?>
</div>
<?php layout_end();
