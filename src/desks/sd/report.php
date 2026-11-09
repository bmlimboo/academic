<?php
namespace CAS\SignDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
require_daa();
[$rows, $f] = report_rows($_GET);
$urgent = count(array_filter($rows, fn($r) => $r['urgency'] === 'urgent'));
$byDept = [];
foreach ($rows as $r) { $k = $r['dept'] ?: 'Not given'; $byDept[$k] = ($byDept[$k] ?? 0) + 1; }
arsort($byDept);

layout_start('Report & downloads', 'report', 'Dean of Academic Affairs');
?>
<p class="lead">See every document in the queue and download them together.</p>

<form method="get" class="card filters">
  <label>Show
    <select name="view"><?php foreach (REPORT_VIEWS as $k => [$label]): ?><option value="<?= $k ?>" <?= $k === $f['view'] ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
  </label>
  <label>Department
    <select name="dept"><option value="">All departments</option><?php foreach (DEPARTMENTS as $d): ?><option <?= $d === $f['dept'] ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select>
  </label>
  <label>Document type
    <select name="type"><option value="">All types</option><?php foreach (DOC_TYPES as $d): ?><option <?= $d === $f['type'] ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select>
  </label>
  <label>Staff or Faculty
    <select name="stype"><option value="">Both</option><?php foreach (SUBMITTER_TYPES as $d): ?><option <?= $d === $f['stype'] ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select>
  </label>
  <label>Dispatch no.
    <input name="q" value="<?= e($f['q']) ?>" placeholder="e.g. 2026-2027/627">
  </label>
  <button class="btn" type="submit">Apply filters</button>
</form>

<section class="card">
  <div class="cardhead"><h2><?= count($rows) ?> document<?= count($rows) === 1 ? '' : 's' ?><?= $urgent ? ' · ' . $urgent . ' urgent' : '' ?></h2></div>
  <?php if ($byDept): ?><div class="chips"><?php foreach ($byDept as $d => $n): ?><span class="chip"><?= e($d) ?>: <?= $n ?></span><?php endforeach; ?></div><?php endif; ?>

  <?php if (!$rows): ?>
    <p class="empty">No documents match these filters.</p>
  <?php else: ?>
  <form method="post" action="report_download.php" id="bulk">
    <?= csrf_field() ?>
    <?php foreach ($f as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <div class="bulkbar">
      <button class="btn" type="submit" name="mode" value="selected" id="dl-selected" disabled>Download selected (<span id="count">0</span>)</button>
      <button class="btn ghost" type="submit" name="mode" value="all">Download all <?= count($rows) ?> as ZIP</button>
      <button class="btn danger" type="submit" formaction="report_delete.php" id="del-selected" data-confirm="Permanently delete the selected documents and their files? This cannot be undone." disabled>Delete selected</button>
      <span class="muted">Each ZIP holds the submitted PDFs, plus signed copies where they exist.</span>
    </div>
    <div class="tablewrap"><table>
      <thead><tr><th class="chk"><input type="checkbox" id="all" aria-label="Select all"></th><th>Reference</th><th>Document</th><th>Submitted by</th><th>Department</th><th>Status</th><th>Dispatch no.</th><th>Submitted</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="chk"><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" class="pick" aria-label="Select <?= e($r['ref_no']) ?>"></td>
          <td class="nowrap"><?= e($r['ref_no']) ?></td>
          <td><a href="view.php?id=<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a>
            <div class="muted"><?= e($r['doc_type']) ?><?= $r['urgency'] === 'urgent' ? ' <span class="badge red">Urgent</span>' : '' ?></div></td>
          <td><?= e($r['user_name']) ?><div class="muted"><?= e($r['submitter_type'] ?: '') ?></div></td>
          <td><?= e($r['dept']) ?></td>
          <td><?= badge($r['status']) ?></td>
          <td class="nowrap"><?= e($r['dispatch_no'] ?? '') ?></td>
          <td class="nowrap"><?= e(fmt_date($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </form>
  <?php endif; ?>
</section>
<script>
(function () {
  var all = document.getElementById('all'), picks = document.querySelectorAll('.pick'),
      btn = document.getElementById('dl-selected'), del = document.getElementById('del-selected'), count = document.getElementById('count');
  if (!all) return;
  function update() {
    var n = document.querySelectorAll('.pick:checked').length;
    count.textContent = n; btn.disabled = n === 0; del.disabled = n === 0;
    all.checked = n === picks.length; all.indeterminate = n > 0 && n < picks.length;
  }
  all.addEventListener('change', function () { picks.forEach(function (p) { p.checked = all.checked; }); update(); });
  picks.forEach(function (p) { p.addEventListener('change', update); });
})();
</script>
<?php layout_end();
