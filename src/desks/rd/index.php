<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
require __DIR__ . '/progress.php';
$u = require_login();
$dept = current_dept($u);
$isDaa = $u['role'] === 'daa';
$showDept = $u['role'] !== 'student';
$rows = visible_activities($u);
$tl = db()->query("SELECT * FROM rd_files WHERE label = 'timeline' ORDER BY id DESC LIMIT 1")->fetch();

layout_start('Research project timeline', 'dash', 'RES402 · ' . ACADEMIC_YEAR);
?>
<p class="lead">
  <?= match ($u['role']) {
      'student' => 'Your activities and deadlines. Each action opens in a separate window.',
      'supervisor' => 'Your students\' activities. Each action opens in a separate window.',
      'evaluator' => 'The work assigned to you for evaluation.',
      'pl' => 'Activities, submitted work and marks for your department.',
      default => 'Switch activities on as the process moves ahead. People only see activities up to the first one that is switched off, plus the next two marked as upcoming.',
  } ?>
</p>

<div class="cols2">
  <?php if ($tl): ?>
  <section class="card slim"><div class="cardhead"><h2>Timeline document</h2></div>
    <div class="fileline"><a href="file.php?id=<?= (int)$tl['id'] ?>" target="_blank"><?= e($tl['original_name']) ?></a></div>
    <div class="muted">Shared by the DAA · <?= e(fmt_day($tl['created_at'])) ?> · <a href="file.php?id=<?= (int)$tl['id'] ?>&amp;dl=1">Download</a></div>
  </section>
  <?php endif; ?>
  <?php if ($showDept): ?><section class="card slim"><div class="cardhead"><h2>View</h2></div><?= dept_select($dept) ?><div class="muted" style="margin-top:6px">Counts and lists follow this choice.</div></section><?php endif; ?>
</div>

<section class="card">
  <div class="tablewrap"><table class="tl">
    <thead><tr><th>#</th><th>Activity</th><th>Deadline</th><th>T0</th><th>What happens for you</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as ['a' => $a, 'mode' => $mode]): $own = role_text($a, $u['role']); ?>
      <tr class="<?= $mode === 'upcoming' ? 'dim' : '' ?>">
        <td><?= (int)$a['seq'] ?></td>
        <td><b><?= e($a['title']) ?></b>
          <?php if ($mode === 'upcoming'): ?><div><?= badge('Upcoming', 'gray') ?></div><?php endif; ?>
          <?php if ($isDaa): ?><div><?= $mode === 'open' ? badge('On', 'green') : badge('Off', 'red') ?> <span class="muted"><?= e(KIND_LABELS[$a['kind']] ?? '') ?></span></div><?php endif; ?></td>
        <td class="nowrap"><?= e(act_deadline_text($a)) ?><?php if ($mode === 'open' && act_overdue($a)): ?><div class="muted">date passed</div><?php endif; ?></td>
        <td class="nowrap"><?= e($a['t0_text']) ?></td>
        <td class="small"><?php if ($mode === 'upcoming'): ?><span class="muted">Opens soon.</span><?php elseif ($own !== ''): ?><details><summary>Instructions</summary><?= nl2br(e($own)) ?></details><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><?= $mode === 'upcoming' ? '' : activity_status($a, $u, $dept) ?></td>
        <td class="nowrap"><?php if ($mode === 'open' || ($isDaa)): echo popup_link('act.php?id=' . (int)$a['id'], 'Open', 'btn small'); endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">No activities are open for you yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php layout_end();
