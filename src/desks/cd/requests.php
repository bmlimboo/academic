<?php
namespace CAS\CertDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_role('examcell', 'daa');
$isCell = $u['role'] === 'examcell';
[$rows, $f] = requests_rows($_GET, !$isCell);
$types = cert_types(false);
$years = db()->query('SELECT DISTINCT grad_year FROM cd_requests ORDER BY grad_year DESC')->fetchAll(PDO::FETCH_COLUMN);

// Per certificate type: how many requests and files fall in the current list.
$perType = [];
foreach ($types as $t) { $perType[(int)$t['id']] = ['name' => $t['name'], 'requests' => 0, 'ids' => []]; }
foreach ($rows as $r) {
    foreach ($r['items'] as $it) {
        $tid = (int)$it['type_id'];
        if (!isset($perType[$tid]) || isset($perType[$tid]['ids'][$r['id']])) { continue; }
        $perType[$tid]['ids'][$r['id']] = true; $perType[$tid]['requests']++;
    }
}

layout_start('Requests', 'requests', $isCell ? 'Exam Cell' : 'Dean of Academic Affairs');
?>
<p class="lead"><?= $isCell ? 'All certificate requests. Filter them, open one, or download the files grouped by certificate type.' : 'Requests that have reached you, read-only.' ?></p>

<form method="get" class="card filters">
  <label>Certificate
    <select name="type"><option value="0">All</option><?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $f['type'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></label>
  <label>Status
    <select name="status"><option value="">All</option><?php foreach (STATUS as $k => [$label]): if (!$isCell && !in_array($k, DAA_STATUSES, true)) { continue; } ?><option value="<?= $k ?>" <?= $k === $f['status'] ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
  <label>Graduation year
    <select name="year"><option value="0">All</option><?php foreach ($years as $y): ?><option <?= (int)$y === $f['year'] ? 'selected' : '' ?>><?= (int)$y ?></option><?php endforeach; ?></select></label>
  <label>Search<input name="q" value="<?= e($f['q']) ?>" placeholder="Reference, name, student ID, email"></label>
  <button class="btn" type="submit">Apply</button>
</form>

<form method="post" action="requests_download.php" id="bulk">
  <?= csrf_field() ?>
  <?php foreach (['type', 'status', 'year', 'q'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e($f[$k]) ?>"><?php endforeach; ?>

  <?php if ($isCell): ?>
  <section class="card">
    <div class="cardhead"><h2>Download files by certificate type</h2></div>
    <p class="muted" style="margin-top:0">Each ZIP has one folder per certificate type, and inside it one folder per request with all its files. It follows the filters above.</p>
    <div class="checks">
      <b>Include:</b>
      <?php foreach (['application' => 'Application', 'marksheet' => 'Marksheets', 'degree' => 'Degree / diploma', 'supporting' => 'Other supporting', 'release_letter' => 'Release letters', 'deposit_proof' => 'Deposit proofs', 'certificate' => 'Certificates'] as $k => $label): ?>
        <label class="check inline"><input type="checkbox" name="kinds[]" value="<?= $k ?>" checked><span><?= e($label) ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="tablewrap"><table>
      <thead><tr><th>Certificate type</th><th>Requests in this list</th><th></th></tr></thead><tbody>
      <?php foreach ($perType as $tid => $c): ?>
        <tr><td><?= e($c['name']) ?></td><td><?= $c['requests'] ?></td>
          <td class="nowrap"><button class="btn small ghost" type="submit" name="type_dl" value="<?= $tid ?>" <?= $c['requests'] ? '' : 'disabled' ?>>Download ZIP</button></td></tr>
      <?php endforeach; ?></tbody></table></div>
  </section>
  <?php endif; ?>

  <section class="card">
    <div class="cardhead"><h2><?= count($rows) ?> request<?= count($rows) === 1 ? '' : 's' ?></h2></div>
    <?php if ($isCell && $rows): ?>
    <div class="bulkbar">
      <button class="btn" type="submit" name="mode" value="selected" id="dl-selected" disabled>Download selected (<span id="count">0</span>)</button>
      <button class="btn ghost" type="submit" name="mode" value="all">Download all <?= count($rows) ?> in this list</button>
      <button class="btn danger" type="submit" formaction="requests_delete.php" id="del-selected" disabled data-confirm="Permanently delete the selected requests with all their files, history and emails? This cannot be undone.">Delete selected (<span id="delcount">0</span>)</button>
      <span class="muted">Uses the "Include" choices above. Files are grouped by certificate type.</span>
    </div>
    <?php endif; ?>
    <?php if (!$rows): ?><p class="empty">No requests match these filters.</p><?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><?php if ($isCell): ?><th class="chk"><input type="checkbox" id="all" aria-label="Select all"></th><?php endif; ?>
        <th>Request</th><th>Requester</th><th>Certificates</th><th>Status</th><th>Submitted</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <?php if ($isCell): ?><td class="chk"><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" class="pick" aria-label="Select <?= e($r['ref']) ?>"></td><?php endif; ?>
          <td class="nowrap"><a href="request.php?id=<?= (int)$r['id'] ?>"><?= e($r['ref']) ?></a></td>
          <td><?= e($r['full_name']) ?><div class="muted"><?= e($r['student_id']) ?> · <?= e($r['programme']) ?> · <?= (int)$r['grad_year'] ?></div></td>
          <td><?= items_html($r['items']) ?></td>
          <td><?= badge($r['status']) ?></td>
          <td class="nowrap"><?= e(fmt_date($r['created_at'])) ?></td>
          <td><a class="btn small ghost" href="request.php?id=<?= (int)$r['id'] ?>">Open</a></td>
        </tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </section>
</form>
<?php if ($isCell): ?>
<script>
(function () {
  var picks = [].slice.call(document.querySelectorAll('.pick')), all = document.getElementById('all');
  var btn = document.getElementById('dl-selected'), count = document.getElementById('count');
  function update() {
    var n = picks.filter(function (p) { return p.checked; }).length;
    if (count) count.textContent = n; if (btn) btn.disabled = n === 0;
    var dc = document.getElementById('delcount'), db = document.getElementById('del-selected');
    if (dc) dc.textContent = n; if (db) db.disabled = n === 0;
  }
  if (all) all.addEventListener('change', function () { picks.forEach(function (p) { p.checked = all.checked; }); update(); });
  picks.forEach(function (p) { p.addEventListener('change', update); });
  update();
})();
</script>
<?php endif; ?>
<?php layout_end();
