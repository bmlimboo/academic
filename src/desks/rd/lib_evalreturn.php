<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

// Evaluators return a document and/or a comment (activities 8 and 17).
const EVAL_RETURN_WORDS = 1000;

function eval_return_ensure(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
}

function eval_word_count(string $t): int
{
    $t = trim($t);
    return $t === '' ? 0 : count(preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY));
}

// Called from act.php before the action switch.
function eval_return_handle(array $u, array $a, string $act, int $id): void
{
    if ($act !== 'eval_return') { return; }
    if ($u['role'] !== 'evaluator' || !in_array($a['kind'], ['defense', 'evaluate'], true)) {
        throw new RuntimeException('That action is not available.');
    }
    eval_return_ensure();
    $sid = (int)($_POST['student_id'] ?? 0);
    $mine = array_map('intval', array_column(students_in_scope($u, '', eval_kind($a)), 'id'));
    if (!in_array($sid, $mine, true)) { throw new RuntimeException('That student is not allocated to you.'); }

    $comment = trim((string)($_POST['comment'] ?? ''));
    if (eval_word_count($comment) > EVAL_RETURN_WORDS) {
        throw new RuntimeException('The comment is longer than ' . EVAL_RETURN_WORDS . ' words.');
    }
    $hasFile = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (!$hasFile && $comment === '') { throw new RuntimeException('Upload a document or write a comment.'); }

    if ($hasFile) {
        [$s, $o] = save_doc($_FILES['file']);
        add_file((int)$a['id'], $sid, $u, $s, $o, 'eval_return');
    }
    if ($comment !== '') {
        db()->prepare('INSERT INTO rd_eval_returns (activity_id, student_id, evaluator_id, comment, updated_at) VALUES (?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE comment = VALUES(comment), updated_at = VALUES(updated_at)')
            ->execute([(int)$a['id'], $sid, (int)$u['id'], $comment, now_sql()]);
    }
    back($id, 'Returned to the Programme Leader.');
}

// Shown under the table in activities 8 and 17.
function eval_return_panel(array $u, array $a, array $scope, int $aid): void
{
    if (!in_array($a['kind'], ['defense', 'evaluate'], true) || !$scope) { return; }
    $role = $u['role'];
    if (!in_array($role, ['evaluator', 'pl', 'daa'], true)) { return; }
    eval_return_ensure();
    $ids = ids_of($scope);

    $st = db()->prepare('SELECT r.*, ev.name evaluator_name FROM rd_eval_returns r JOIN rd_users ev ON ev.id = r.evaluator_id
                         WHERE r.activity_id = ? AND r.student_id IN (' . in_list($ids) . ') ORDER BY r.updated_at');
    $st->execute([$aid]);
    $comments = []; foreach ($st->fetchAll() as $r) { $comments[$r['student_id']][$r['evaluator_id']] = $r; }

    $st = db()->prepare("SELECT f.*, ev.name evaluator_name FROM rd_files f JOIN rd_users ev ON ev.id = f.uploader_id
                         WHERE f.activity_id = ? AND f.label = 'eval_return' AND f.student_id IN (" . in_list($ids) . ') ORDER BY f.id DESC');
    $st->execute([$aid]);
    $files = []; foreach ($st->fetchAll() as $f) { $files[$f['student_id']][$f['uploader_id']][] = $f; }

    $who = fn(array $s) => '<b>' . e($s['name']) . '</b> <span class="muted">' . e($s['student_no'] ?: '') . ' ' . e($s['department'] ?: '')
        . (work_title($s) ? ' · ' . e(work_title($s)) : '') . '</span>';

    if ($role === 'evaluator') { ?>
      <section class="card"><h2>Return to Programme Leader</h2>
        <p class="muted">Upload a document (PDF or Word), write a comment (up to <?= EVAL_RETURN_WORDS ?> words), or do both.</p>
        <?php foreach ($scope as $s): $sid = (int)$s['id'];
            $c = $comments[$sid][(int)$u['id']] ?? null; $fl = $files[$sid][(int)$u['id']] ?? []; ?>
        <div class="retblock">
          <div class="rethead"><?= $who($s) ?>
            <?= ($c || $fl) ? badge('Returned', 'green') : badge('Not yet', 'amber') ?>
            <?= $c ? '<span class="muted">' . e(fmt_day($c['updated_at'])) . '</span>' : '' ?></div>
          <?php if ($fl): ?><div class="retfiles"><?= file_links($u, $fl) ?></div><?php endif; ?>
          <form method="post" enctype="multipart/form-data" class="retform"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$aid ?>"><input type="hidden" name="action" value="eval_return"><input type="hidden" name="student_id" value="<?= $sid ?>">
            <label>Document (PDF or Word)<input type="file" name="file" accept=".pdf,.docx,.doc" data-max="<?= MAX_UPLOAD ?>"></label>
            <label>Comment<textarea name="comment" class="autogrow retbox" rows="8" data-maxwords="<?= EVAL_RETURN_WORDS ?>" placeholder="Write your comment here (up to <?= EVAL_RETURN_WORDS ?> words)"><?= e($c['comment'] ?? '') ?></textarea></label>
            <div class="wc muted" aria-live="polite">0 / <?= EVAL_RETURN_WORDS ?> words</div>
            <button class="btn" type="submit"><?= ($c || $fl) ? 'Submit again' : 'Submit' ?></button>
          </form>
        </div>
        <?php endforeach; ?>
      </section>
    <?php } else { $any = false; ?>
      <section class="card"><h2>Returned by evaluators</h2>
        <?php foreach ($scope as $s): $sid = (int)$s['id'];
            $evIds = array_unique(array_merge(array_keys($comments[$sid] ?? []), array_keys($files[$sid] ?? [])));
            if (!$evIds) { continue; } $any = true; ?>
        <div class="retblock"><div class="rethead"><?= $who($s) ?></div>
          <?php foreach ($evIds as $eid): $c = $comments[$sid][$eid] ?? null; $fl = $files[$sid][$eid] ?? [];
              $nm = $c['evaluator_name'] ?? ($fl[0]['evaluator_name'] ?? 'Evaluator'); ?>
            <div class="retitem"><div><b><?= e($nm) ?></b> <?= $c ? '<span class="muted">' . e(fmt_day($c['updated_at'])) . '</span>' : '' ?></div>
              <?php if ($fl): ?><?= file_links($u, $fl) ?><?php endif; ?>
              <?php if ($c): ?><div class="retprev"><?= nl2br(e($c['comment'])) ?></div><?php endif; ?></div>
          <?php endforeach; ?></div>
        <?php endforeach; ?>
        <?php if (!$any): ?><p class="empty">Nothing has been returned by the evaluators yet.</p><?php endif; ?>
      </section>
    <?php }
}
