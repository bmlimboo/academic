<?php
namespace CAS\CertDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_login();

if ($u['role'] === 'alumnus') {
    [$rows] = requests_rows([], false, (int)$u['id']);
    $c = array_fill_keys(array_keys(STATUS), 0);
    foreach ($rows as $r) { $c[$r['status']]++; }
    $attention = array_values(array_filter($rows, fn($r) => in_array($r['status'], ['returned', 'awaiting_payment'], true)));
    $inProgress = $c['submitted'] + $c['with_daa'] + $c['payment_submitted'] + $c['ready'];
    layout_start('Dashboard', 'dash');
    ?>
    <p class="lead">Request certificates from the Examination Cell and follow each request here.</p>
    <div class="stats">
      <div class="stat"><span>In progress</span><b><?= $inProgress ?></b></div>
      <div class="stat"><span>Needs your action</span><b><?= count($attention) ?></b></div>
      <div class="stat"><span>Released</span><b><?= $c['released'] ?></b></div>
      <div class="stat"><span>Returned or rejected</span><b><?= $c['returned'] + $c['rejected'] ?></b></div>
    </div>
    <section class="card">
      <div class="cardhead"><h2>Needs your attention</h2><a class="btn small" href="request_new.php">New request</a></div>
      <?php render_request_table($attention, 'Nothing is waiting for you.'); ?>
    </section>
    <?php
    layout_end();
    exit;
}

if ($u['role'] === 'daa') {
    [$all] = requests_rows([], true);
    $pending = array_values(array_filter($all, fn($r) => $r['status'] === 'with_daa'));
    $done = count(array_filter($all, fn($r) => $r['status'] !== 'with_daa'));
    layout_start('Dashboard', 'dash', 'Dean of Academic Affairs');
    ?>
    <p class="lead">Release letters sent by the Exam Cell that wait for your seal and signature.</p>
    <div class="stats">
      <div class="stat"><span>Waiting for you</span><b><?= count($pending) ?></b></div>
      <div class="stat"><span>Approved so far</span><b><?= $done ?></b></div>
    </div>
    <section class="card">
      <div class="cardhead"><h2>Awaiting your approval</h2><a class="btn small ghost" href="requests.php">All requests</a></div>
      <?php render_request_table($pending, 'No letters are waiting.', true); ?>
    </section>
    <?php
    layout_end();
    exit;
}

// Exam Cell
[$all] = requests_rows([]);
$c = array_fill_keys(array_keys(STATUS), 0);
foreach ($all as $r) { $c[$r['status']]++; }
$act = array_values(array_filter($all, fn($r) => in_array($r['status'], ['submitted', 'payment_submitted', 'ready'], true)));
layout_start('Dashboard', 'dash', 'Exam Cell');
?>
<p class="lead">Requests that need the Exam Cell, and where the others are.</p>
<div class="stats">
  <div class="stat"><span>New, to review</span><b><?= $c['submitted'] ?></b></div>
  <div class="stat"><span>With the DAA</span><b><?= $c['with_daa'] ?></b></div>
  <div class="stat"><span>Awaiting payment</span><b><?= $c['awaiting_payment'] ?></b></div>
  <div class="stat"><span>Ready to release</span><b><?= $c['payment_submitted'] + $c['ready'] ?></b></div>
</div>
<section class="card">
  <div class="cardhead"><h2>Needs the Exam Cell</h2><a class="btn small ghost" href="requests.php">All requests</a></div>
  <?php render_request_table($act, 'Nothing is waiting. All caught up.', true); ?>
</section>
<section class="card">
  <div class="cardhead"><h2>By certificate type</h2></div>
  <?php
  $st = db()->query("SELECT t.name, COUNT(i.id) total, COALESCE(SUM(r.status = 'released'), 0) released
                     FROM cd_cert_types t LEFT JOIN cd_request_items i ON i.type_id = t.id LEFT JOIN cd_requests r ON r.id = i.request_id
                     GROUP BY t.id, t.name, t.sort_order ORDER BY t.sort_order, t.id");
  ?>
  <div class="tablewrap"><table><thead><tr><th>Certificate</th><th>Requested</th><th>Released</th></tr></thead><tbody>
  <?php foreach ($st->fetchAll() as $t): ?><tr><td><?= e($t['name']) ?></td><td><?= (int)$t['total'] ?></td><td><?= (int)$t['released'] ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php layout_end();
