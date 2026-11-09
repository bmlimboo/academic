<?php
namespace CAS\CertDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_role('alumnus');
[$rows, $f] = requests_rows(['status' => $_GET['status'] ?? ''], false, (int)$u['id']);
layout_start('My requests', 'mine');
?>
<p class="lead">Every request you have made, with its status.</p>
<div class="files" style="margin-top:0">
  <a class="btn small <?= $f['status'] === '' ? '' : 'ghost' ?>" href="my.php">All</a>
  <?php foreach (STATUS as $k => [$label]): ?><a class="btn small <?= $f['status'] === $k ? '' : 'ghost' ?>" href="my.php?status=<?= $k ?>"><?= e($label) ?></a><?php endforeach; ?>
</div>
<section class="card">
  <?php render_request_table($rows, 'No requests yet. Use "New request" to make one.'); ?>
</section>
<?php layout_end();
