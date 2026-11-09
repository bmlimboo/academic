<?php
namespace CAS\SignDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;



require_once dirname(__DIR__).'/bridge.php';
\deskBoot('sd');
require __DIR__ . '/config.php';

const STATUS = [
    'submitted'    => ['Submitted', 'blue'],
    'under_review' => ['Under review', 'amber'],
    'signed'       => ['Signed', 'green'],
    'returned'     => ['Returned', 'orange'],
    'rejected'     => ['Rejected', 'red'],
];
const ACTION_LABELS = [
    'submitted'   => 'Submitted',
    'opened'      => 'Opened for review',
    'signed'      => 'Signed copy uploaded',
    'returned'    => 'Returned for correction',
    'rejected'    => 'Rejected',
    'resubmitted' => 'Corrected copy submitted',
    'stamped'     => 'Signed and stamped online',
];

function db(): \DeskDatabase { return \deskDB(); }

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_date(?string $d): string { return $d ? date('d M Y, H:i', strtotime($d)) : ''; }
function redirect(string $url): void { header('Location: ' . $url); exit; }

function current_user(): ?array { return \deskUser('sd'); }
function require_login(): array
{
    $u = current_user();
    if (!$u) { \deskLogin('sd'); }
    return $u;
}
function require_daa(): array
{
    $u = require_login();
    if ($u['role'] !== 'daa') { http_response_code(403); exit('This page is for the DAA only.'); }
    return $u;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Session expired. Go back, refresh the page and try again.');
    }
}

function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = [$msg, $type]; }

function log_action(int $rid, int $uid, string $action, ?string $note = null): void
{
    db()->prepare('INSERT INTO sd_doc_logs (request_id, actor_id, action, note) VALUES (?,?,?,?)')
        ->execute([$rid, $uid, $action, $note]);
    if(in_array($action,['submitted','resubmitted','signed','stamped','returned','rejected'],true)){
      $r=\one('SELECT r.*,u.email FROM sd_doc_requests r JOIN users u ON u.id=r.user_id WHERE r.id=?',[$rid]);
      if($r){$emails=in_array($action,['submitted','resubmitted'],true)?['daa.cnr@rub.edu.bt']:[$r['email']];foreach($emails as $email)\deskQueueMail($email,'Document Signing '.$r['ref_no'].' — '.$action,$r['title']."\n\n".($note?:'')."\n\nOpen in CAS: ".rtrim($GLOBALS['config']['url'],'/').'/desks/sd/view.php?id='.$rid);}
    }

}

function save_document(array $f, string $prefix, array $allowedExt): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Choose a file to upload.');
    }
    if ($f['size'] > MAX_UPLOAD) {
        throw new RuntimeException('The file is larger than ' . (MAX_UPLOAD / 1048576) . ' MB.');
    }
    $orig = basename($f['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $okTypes = 'Accepted file types: ' . implode(', ', array_map(fn($x) => '.' . $x, $allowedExt)) . '.';
    if (!in_array($ext, $allowedExt, true)) {
        throw new RuntimeException($okTypes);
    }
    $sigs = ['pdf' => '%PDF', 'docx' => "PK\x03\x04", 'doc' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"];
    $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 8);
    if (strncmp($head, $sigs[$ext], strlen($sigs[$ext])) !== 0) {
        throw new RuntimeException('This file is not a real .' . $ext . ' document. ' . $okTypes);
    }
    if ($ext === 'pdf' && strpos((string)file_get_contents($f['tmp_name']), '/Encrypt') !== false) {
        throw new RuntimeException('This PDF is password-protected, so it cannot be signed. Save an unprotected copy and upload that.');
    }
    if (!is_dir(STORAGE_DIR) && !@mkdir(STORAGE_DIR, 0700, true)) {
        throw new RuntimeException('The storage folder does not exist and could not be created: ' . STORAGE_DIR);
    }
    if (!is_writable(STORAGE_DIR)) {
        throw new RuntimeException('The storage folder is not writable: ' . STORAGE_DIR);
    }
    $stored = $prefix . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $target = STORAGE_DIR . DIRECTORY_SEPARATOR . $stored;
    if (!@move_uploaded_file($f['tmp_name'], $target) && !@copy($f['tmp_name'], $target)) {
        $err = error_get_last()['message'] ?? 'unknown error';
        throw new RuntimeException('Could not save the file to ' . $target . ' (' . $err . ').');
    }
    $clean = preg_replace('/[^\w\s.\-()]/u', '_', $orig);
    return [$stored, mb_substr($clean, 0, 190)];
}

function name_part(string $s, int $max): string
{
    $s = preg_replace('/[^\p{L}\p{N}\- ]+/u', '', $s);
    return mb_substr(trim(preg_replace('/\s+/', ' ', $s)), 0, $max);
}

// Download name: Department_Document name_Staff name.ext  (signed copies end with _signed)
function doc_filename(array $r, bool $signed, string $ext): string
{
    $dept = name_part((string)($r['dept'] ?? $r['department'] ?? ''), 40) ?: 'Department';
    $title = name_part((string)$r['title'], 60) ?: 'Document';
    $who = name_part((string)($r['user_name'] ?? ''), 40) ?: 'Staff';
    return $dept . '_' . $title . '_' . $who . ($signed ? '_signed' : '') . '.' . $ext;
}

// Permanently removes a document: its history, its database record and its files on disk.
function delete_document(int $id): bool
{
    $st = db()->prepare('SELECT original_file, signed_file FROM sd_doc_requests WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) { return false; }
    db()->prepare('UPDATE sd_dispatch_numbers SET request_id = NULL WHERE request_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM sd_doc_logs WHERE request_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM sd_doc_requests WHERE id = ?')->execute([$id]);
    foreach ([$r['original_file'], $r['signed_file']] as $f) {
        if ($f) { @unlink(STORAGE_DIR . DIRECTORY_SEPARATOR . basename($f)); }
    }
    return true;
}

function academic_year(?int $ts = null): string
{
    $ts = $ts ?? time();
    $y = (int)date('Y', $ts);
    $start = (int)date('n', $ts) >= ACADEMIC_YEAR_START_MONTH ? $y : $y - 1;
    return $start . '-' . ($start + 1);
}

function normalize_dispatch(string $s): string
{
    return trim(preg_replace('#\s*/\s*#', '/', preg_replace('/\s+/', ' ', $s)));
}

function last_dispatch_number(): string
{
    return (string)db()->query('SELECT dispatch_no FROM sd_dispatch_numbers ORDER BY id DESC LIMIT 1')->fetchColumn();
}

function register_dispatch(int $requestId, int $userId, string $no): void
{
    $ay = null; $serial = null;
    if (preg_match('#(\d{4}-\d{4})/(\d+)$#', $no, $m)) { $ay = $m[1]; $serial = (int)$m[2]; }
    try {
        db()->prepare('INSERT INTO sd_dispatch_numbers (dispatch_no, ay, serial_no, request_id, issued_by) VALUES (?,?,?,?,?)')
            ->execute([$no, $ay, $serial, $requestId, $userId]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) { throw new RuntimeException('The dispatch number ' . $no . ' has already been used.'); }
        throw $e;
    }
}

function stamp_path(int $uid, string $kind): ?string
{
    foreach (['png', 'jpg'] as $e) {
        $p = STORAGE_DIR . DIRECTORY_SEPARATOR . 'stamps' . DIRECTORY_SEPARATOR . 'stamp_' . $uid . '_' . $kind . '.' . $e;
        if (is_file($p)) { return $p; }
    }
    return null;
}

function json_out(array $a, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($a);
    exit;
}

function badge(string $status): string
{
    [$label, $cls] = STATUS[$status] ?? [$status, 'blue'];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

function render_table(array $rows, bool $showUser): void
{
    if (!$rows) {
        echo '<p class="empty">No documents here yet.</p>';
        return;
    }
    echo '<div class="tablewrap"><table><thead><tr><th>Reference</th><th>Document</th>';
    if ($showUser) { echo '<th>Submitted by</th>'; }
    echo '<th>Status</th><th>Submitted</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td class="nowrap">' . e($r['ref_no']) . '</td>';
        echo '<td><a href="view.php?id=' . (int)$r['id'] . '">' . e($r['title']) . '</a>';
        echo '<div class="muted">' . e($r['doc_type']) . ($r['urgency'] === 'urgent' ? ' <span class="badge red">Urgent</span>' : '') . (!empty($r['dispatch_no']) ? ' · Dispatch ' . e($r['dispatch_no']) : '') . '</div></td>';
        if ($showUser) {
            echo '<td>' . e($r['user_name']) . '<div class="muted">' . e(trim(($r['submitter_type'] ? $r['submitter_type'] . ', ' : '') . $r['dept'], ', ')) . '</div></td>';
        }
        echo '<td>' . badge($r['status']) . '</td><td class="nowrap">' . e(fmt_date($r['created_at'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

const REPORT_VIEWS = [
    'queue'  => ['In queue (awaiting signature)', "('submitted','under_review')"],
    'signed' => ['Signed', "('signed')"],
    'closed' => ['Returned or rejected', "('returned','rejected')"],
    'all'    => ['All documents', null],
];

function report_rows(array $src): array
{
    $view = array_key_exists($src['view'] ?? '', REPORT_VIEWS) ? $src['view'] : 'queue';
    $where = []; $p = [];
    if (REPORT_VIEWS[$view][1]) { $where[] = 'r.status IN ' . REPORT_VIEWS[$view][1]; }
    $dept = in_array($src['dept'] ?? '', DEPARTMENTS, true) ? $src['dept'] : '';
    if ($dept !== '') { $where[] = "COALESCE(NULLIF(r.department,''), u.department) = ?"; $p[] = $dept; }
    $type = in_array($src['type'] ?? '', DOC_TYPES, true) ? $src['type'] : '';
    if ($type !== '') { $where[] = 'r.doc_type = ?'; $p[] = $type; }
    $stype = in_array($src['stype'] ?? '', SUBMITTER_TYPES, true) ? $src['stype'] : '';
    if ($stype !== '') { $where[] = 'r.submitter_type = ?'; $p[] = $stype; }
    $q = trim((string)($src['q'] ?? ''));
    if ($q !== '') { $where[] = 'r.dispatch_no LIKE ?'; $p[] = '%' . addcslashes($q, '%_\\') . '%'; }
    $sql = "SELECT r.*, u.name AS user_name, COALESCE(NULLIF(r.department,''), u.department) AS dept FROM sd_doc_requests r JOIN sd_users u ON u.id = r.user_id"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . " ORDER BY r.urgency = 'urgent' DESC, r.created_at ASC";
    $st = db()->prepare($sql);
    $st->execute($p);
    return [$st->fetchAll(), ['view' => $view, 'dept' => $dept, 'type' => $type, 'stype' => $stype, 'q' => $q]];
}

function layout_start(string $title, string $active, string $crumb = 'Document signing'): void
{
    $u = require_login();
    $pending = 0;
    if ($u['role'] === 'daa') {
        $pending = (int)db()->query("SELECT COUNT(*) FROM sd_doc_requests WHERE status IN ('submitted','under_review')")->fetchColumn();
    }
    $nav = $u['role'] === 'daa'
        ? [['dash', 'index.php', 'Dashboard'], ['queue', 'queue.php', 'Signing queue' . ($pending ? " ($pending)" : '')], ['archive', 'queue.php?view=done', 'Signed archive'], ['report', 'report.php', 'Report & downloads'], ['stamps', 'stamps.php', 'Signature & seal']]
        : [['dash', 'index.php', 'Dashboard'], ['new', 'submit.php', 'New submission'], ['mine', 'my.php', 'My documents']];
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= APP_NAME ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body><?php \deskRoleBar('sd'); ?>
<div class="shell">
  <!-- Placeholder for the college portal sidebar. On the live site, the portal's own sidebar replaces this. -->
  <?php \deskNavigation('sd'); ?>
  <aside class="module">
    <div class="brand"><?= APP_NAME ?></div>
    <div class="sub">Documents for DAA signature</div>
    <nav>
      <?php foreach ($nav as [$key, $href, $label]): ?>
        <a href="<?= e($href) ?>" class="<?= $key === $active ? 'on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="who">
      <strong><?= e($u['name']) ?></strong>
      <span><?= $u['role'] === 'daa' ? 'DAA' : e($u['department']) ?></span>
      <a href="/">Back to CAS</a>
    </div>
  </aside>
  <main>
    <div class="crumb"><?= e($crumb) ?></div>
    <h1><?= e($title) ?></h1>
    <?php if (!empty($_SESSION['flash'])): [$m, $t] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
      <div class="flash <?= e($t) ?>"><?= e($m) ?></div>
    <?php endif; ?>
<?php
}

function layout_end(): void
{
    echo "  </main>\n</div>\n<script src=\"assets/app.js\"></script>\n</body>\n</html>";
}
