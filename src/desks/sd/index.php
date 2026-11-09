<?php
namespace CAS\SignDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_login();
$isDaa = $u['role'] === 'daa';
$where = $isDaa ? '1=1' : 'r.user_id = ' . (int)$u['id'];

$counts = array_fill_keys(array_keys(STATUS), 0);
foreach (db()->query("SELECT status, COUNT(*) c FROM sd_doc_requests r WHERE $where GROUP BY status") as $row) {
    $counts[$row['status']] = (int)$row['c'];
}
$recent = db()->query("SELECT r.*, u.name AS user_name, COALESCE(NULLIF(r.department,''), u.department) AS dept FROM sd_doc_requests r JOIN sd_users u ON u.id = r.user_id WHERE $where ORDER BY r.updated_at DESC LIMIT 8")->fetchAll();

layout_start('Dashboard', 'dash');
?>
<p class="lead"><?= $isDaa ? 'Documents waiting for your signature, and what has been handled.' : 'Track the documents you sent to the DAA.' ?></p>
<div class="stats">
  <div class="stat"><span>Awaiting signature</span><b><?= $counts['submitted'] + $counts['under_review'] ?></b></div>
  <div class="stat"><span>Signed</span><b><?= $counts['signed'] ?></b></div>
  <div class="stat"><span>Returned for correction</span><b><?= $counts['returned'] ?></b></div>
  <div class="stat"><span>Rejected</span><b><?= $counts['rejected'] ?></b></div>
</div>
<section class="card">
  <div class="cardhead"><h2>Recent documents</h2>
    <a class="btn small" href="<?= $isDaa ? 'queue.php' : 'submit.php' ?>"><?= $isDaa ? 'Open signing queue' : 'New submission' ?></a></div>
  <?php render_table($recent, $isDaa); ?>
</section>
<?php layout_end();
