<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;



require_once dirname(__DIR__).'/bridge.php';
\deskBoot('rd');
require __DIR__ . '/config.php';

// ---- evaluation forms from the RES402 timeline document (Forms 4 to 8). Each item: [component, criteria, full mark] ----
const FORMS = [
    4 => ['title' => 'Form No. 4: Research Proposal Evaluation (15%)', 'weight' => 15, 'max' => 15, 'who' => 'Examiner', 'topic' => 'Research Topic', 'items' => [
        ['Introduction', 'Background · Problem statement · Objectives', 5],
        ['Literature Review', 'Relevant to the topic · Comprehensive and credible references', 2],
        ['Methods & Materials', 'Appropriate research design / appropriate methods', 2],
        ['Methods & Materials', 'Adequate sample size · Appropriate tests outline · Fulfil study objective/s', 3],
        ['Work Plan', 'Comprehensive work plan · Achievable on time', 2],
        ['Overall presentation/format', 'Comprehensive / technically sound', 1],
    ]],
    5 => ['title' => 'Form No. 5: Research Process Evaluation (5%)', 'weight' => 5, 'max' => 5, 'who' => 'Supervisor', 'topic' => 'Research Project Title', 'items' => [
        ['Research process', 'Completion of activities on time as per the work plan', 2.5],
        ['Research process', 'Reporting to and interaction with Supervisor/s', 2.5],
    ]],
    6 => ['title' => 'Form No. 6: Research Project Evaluation (30%)', 'weight' => 30, 'max' => 100, 'who' => 'Examiner', 'topic' => 'Research project title', 'items' => [
        ['Abstract', 'Summary of the whole research project (study objectives, methods & results, main conclusion)', 7],
        ['Introduction', 'Appropriate context · Logical problem statement · Appropriate objective(s)', 12],
        ['Literature Review', 'Comprehensive literature · Credible and recent citations · Relevant literature (thematic)', 20],
        ['Research Methodology', 'Appropriate research design/method · Data analysis · Fulfil study objective', 20],
        ['Results & Discussion', 'Relevant to study objective(s) · Logically ordered/presented · Adequately illustrated (graphs, tables, figures, models) · Each result critically discussed', 20],
        ['Conclusion', 'Significant results summarized · Appropriate conclusion(s) drawn', 7],
        ['References', 'Citation should be listed in reference · Adequate number of references · Correct referencing style', 7],
        ['Overall presentation', 'Well formatted: inclusion as per the guidelines', 7],
    ]],
    7 => ['title' => 'Form No. 7: Research Project Defense Evaluation (40%)', 'weight' => 40, 'max' => 40, 'who' => 'Programme Leader', 'topic' => 'Research Project Topic', 'items' => [
        ['Defense', 'Ability to articulate the rationale of the study; presence of coherent contents and flow', 2],
        ['Defense', 'Ability to design appropriate research tools aligned with the study objectives', 4],
        ['Defense', 'Ability to make appropriate presentation of results/findings (tables, figures and illustrations, etc.). Fulfills the study objectives', 10],
        ['Defense', 'Ability to critically discuss results; ability to respond to questions', 8],
        ['Defense', 'Ability to make reasoned conclusions and recommendations. Identifies further knowledge gaps', 5],
        ['Defense', 'Ability to communicate clearly and displays originality', 11],
    ]],
    8 => ['title' => 'Form No. 8: Article Evaluation (10%)', 'weight' => 10, 'max' => 10, 'who' => 'Supervisor', 'topic' => 'Research Article Topic', 'items' => [
        ['Abstract', 'Summary of the whole project (study objectives, methods & results, main conclusion)', 1],
        ['Introduction', 'Appropriate context · Logical problem statement · Appropriate objective(s) · Relation to existing literature · Credible and recent citations · Relevant literature (thematic)', 2],
        ['Research Methodology', 'Appropriate design/method · Data analysis · Fulfil study objective', 2],
        ['Results & Discussion', 'Relevant to study objective(s) · Logically ordered/presented · Adequately illustrated (graphs, tables, figures, models) · Each result critically discussed', 2],
        ['Conclusion', 'Significant results summarized · Appropriate conclusion(s) drawn', 1],
        ['References', 'Citation should be listed in reference · Adequate number of references · Correct referencing style', 1],
        ['Overall presentation', 'Well formatted, structured as per manuscript guidelines · Grammatically correct language', 1],
    ]],
];
const KIND_LABELS = [
    'publish' => 'Document from DAA', 'supervisor_select' => 'Choose supervisor', 'topic_final' => 'Finalize topic', 'student_file' => 'Student upload',
    'supervisor_file' => 'Supervisor upload', 'student_text' => 'Student comments', 'defense' => 'Presentation and marks', 'assign_evaluators' => 'Allocate evaluators',
    'evaluate' => 'Evaluator marks', 'grades' => 'Supervisor grades',
];
const EVAL_FILES = [0 => 'No', 1 => 'Proposal examiners', 2 => 'Project evaluators'];

// ---- basics -------------------------------------------------------------------
function db(): \DeskDatabase { return \deskDB(); }

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_date(?string $d): string { return $d ? date('d M Y, H:i', strtotime($d)) : ''; }
function fmt_day(?string $d): string { return $d ? date('d M Y', strtotime($d)) : ''; }
function redirect(string $url): void { header('Location: ' . $url); exit; }
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = [$msg, $type]; }
function now_sql(): string { return date('Y-m-d H:i:s'); }
function current_user(): ?array { return \deskUser('rd'); }
function require_login(): array { $u = current_user(); if (!$u) { \deskLogin('rd'); } return $u; }
function require_role(string ...$roles): array
{
    $u = require_login();
    if (($u['cas_role']??'')!=='admin' && !in_array($u['role'], $roles, true)) { http_response_code(403); exit('You do not have access to this page.'); }
    return $u;
}
function csrf_token(): string { if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); } return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); exit('Session expired. Go back, refresh the page and try again.'); }
}
function normalize_dept(string $d): ?string
{
    foreach (DEPARTMENTS as $x) { if (strcasecmp(trim($d), $x) === 0) { return $x; } }
    return null;
}
function name_part(string $s, int $max): string
{
    $s = preg_replace('/[^\p{L}\p{N}\- ]+/u', '', $s);
    return mb_substr(trim(preg_replace('/\s+/', ' ', $s)), 0, $max);
}
function temp_password(): string
{
    $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $p = '';
    for ($i = 0; $i < 9; $i++) { $p .= $a[random_int(0, strlen($a) - 1)]; }
    return $p;
}
function users_by_role(string $role, string $dept = ''): array
{
    if(in_array($role,['supervisor','evaluator'],true)){ $st=db()->prepare("SELECT * FROM rd_users WHERE role IN ('supervisor','pl','daa') AND active=1".($dept!==''?' AND department=?':'').' ORDER BY name');$st->execute($dept!==''?[$dept]:[]);return $st->fetchAll(); }
    $st = db()->prepare("SELECT * FROM rd_users WHERE role = ? AND active = 1" . ($dept !== '' ? ' AND department = ?' : '') . ' ORDER BY department, name');
    $st->execute($dept !== '' ? [$role, $dept] : [$role]);
    return $st->fetchAll();
}
function user_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM rd_users WHERE id = ?'); $st->execute([$id]);
    return $st->fetch() ?: null;
}

// ---- timeline, on/off and "upcoming" rule -----------------------------------------
function all_activities(): array { return db()->query('SELECT * FROM rd_activities ORDER BY seq, id')->fetchAll(); }
function activity(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM rd_activities WHERE id = ?'); $st->execute([$id]);
    return $st->fetch() ?: null;
}
function act_roles(array $a): array { return array_filter(explode(',', (string)$a['roles'])); }
function act_assigned(array $a, string $role): bool { return $role === 'daa' || in_array($role, act_roles($a), true); }

// What a user sees. DAA: everything, with On/Off. Others: activities before the first switched-off activity are open;
// from the first switched-off activity onward everything is hidden except the next two (that apply to the role), shown as "upcoming".
function visible_activities(array $u): array
{
    $all = all_activities(); $out = [];
    if ($u['role'] === 'daa') {
        foreach ($all as $a) { $out[] = ['a' => $a, 'mode' => $a['enabled'] ? 'open' : 'off']; }
        return $out;
    }
    $frontier = null;
    foreach ($all as $a) { if (!$a['enabled']) { $frontier = (int)$a['seq']; break; } }
    $up = 0;
    foreach ($all as $a) {
        if (!act_assigned($a, $u['role'])) { continue; }
        if ($frontier === null || (int)$a['seq'] < $frontier) { $out[] = ['a' => $a, 'mode' => 'open']; }
        elseif ($up < 2) { $out[] = ['a' => $a, 'mode' => 'upcoming']; $up++; }
    }
    return $out;
}
// The mode of one activity for a user: 'open', 'upcoming', 'off' (DAA only) or null (not visible)
function activity_mode(array $u, int $id): ?string
{
    foreach (visible_activities($u) as $v) { if ((int)$v['a']['id'] === $id) { return $v['mode']; } }
    return null;
}
function act_deadline_end(array $a): ?string { return $a['deadline_to'] ?: $a['deadline_from']; }
function act_overdue(array $a): bool { $d = act_deadline_end($a); return $d !== null && date('Y-m-d') > $d; }
function act_deadline_text(array $a): string
{
    if (!$a['deadline_from']) { return ''; }
    if (!$a['deadline_to']) { return date('j M Y', strtotime($a['deadline_from'])); }
    $f = strtotime($a['deadline_from']); $t = strtotime($a['deadline_to']);
    return date('Y-m', $f) === date('Y-m', $t) ? date('j', $f) . ' – ' . date('j M Y', $t) : date('j M', $f) . ' – ' . date('j M Y', $t);
}
function role_text(array $a, string $role): string { return (string)($a['txt_' . $role] ?? ''); }

// ---- department view ("view by department or all") ----------------------------------
function current_dept(array $u): string
{
    if ($u['role']==='pl') { return (string)$u['department']; }
    if (isset($_GET['dept'])) { $_SESSION['dept'] = in_array($_GET['dept'], DEPARTMENTS, true) ? $_GET['dept'] : ''; }
    if (!isset($_SESSION['dept'])) { $_SESSION['dept'] = ($u['role'] === 'pl' && $u['department']) ? $u['department'] : ''; }
    return $_SESSION['dept'];
}
function dept_select(string $dept, array $keep = []): string
{
    $h = '<form method="get" class="deptform">';
    foreach ($keep as $k => $v) { $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">'; }
    $h .= '<label>View<select name="dept" onchange="this.form.submit()"><option value="">All departments</option>';
    foreach (DEPARTMENTS as $d) { $h .= '<option value="' . e($d) . '"' . ($d === $dept ? ' selected' : '') . '>' . e($d) . '</option>'; }
    return $h . '</select></label></form>';
}

// ---- students in scope for a user ---------------------------------------------------
// $evalKind: 'proposal' | 'project' decides which allocations an evaluator sees.
function students_in_scope(array $u, string $dept = '', ?string $evalKind = null): array
{
    $sql = "SELECT s.*, sv.supervisor_id, sv.status sv_status, sv.topic, sv.sup_comment, sv.final_topic, sv.final_status, sv.final_comment, sup.name sup_name
            FROM rd_users s LEFT JOIN rd_supervision sv ON sv.student_id = s.id LEFT JOIN rd_users sup ON sup.id = sv.supervisor_id
            WHERE s.role = 'student' AND s.active = 1";
    $p = [];
    if ($u['role']==='pl') { $sql .= ' AND s.department = ?'; $p[]=$u['department']; }
    if ($u['role'] === 'student') { $sql .= ' AND s.id = ?'; $p[] = $u['id']; }
    elseif ($u['role'] === 'supervisor') { $sql .= " AND sv.supervisor_id = ? AND sv.status IN ('accepted','assigned')"; $p[] = $u['id']; }
    elseif ($u['role'] === 'evaluator') { $sql .= ' AND EXISTS (SELECT 1 FROM rd_allocations al WHERE al.student_id = s.id AND al.evaluator_id = ? AND al.kind = ?)'; $p[] = $u['id']; $p[] = $evalKind ?? 'project'; }
    if ($dept !== '') { $sql .= ' AND s.department = ?'; $p[] = $dept; }
    $st = db()->prepare($sql . ' ORDER BY s.department, s.name');
    $st->execute($p);
    return $st->fetchAll();
}
function work_title(array $s): string { return (string)($s['final_topic'] ?: $s['topic'] ?: ''); }
function eval_kind(array $a): ?string
{
    if ($a['kind'] === 'defense') { return $a['form_no'] == 4 ? 'proposal' : null; }
    if (in_array($a['kind'], ['assign_evaluators', 'evaluate'], true)) { return 'project'; }
    return [1 => 'proposal', 2 => 'project'][(int)$a['eval_files']] ?? null;
}
function ids_of(array $rows): array { return array_map(fn($r) => (int)$r['id'], $rows); }
function in_list(array $ids): string { return $ids ? implode(',', array_map('intval', $ids)) : '0'; }

// ---- submissions (one row per activity + student) ------------------------------------
function submission(int $aid, int $sid): ?array
{
    $st = db()->prepare('SELECT * FROM rd_submissions WHERE activity_id = ? AND student_id = ?'); $st->execute([$aid, $sid]);
    return $st->fetch() ?: null;
}
function subs_for(int $aid, array $studentIds): array
{
    if (!$studentIds) { return []; }
    $st = db()->prepare('SELECT * FROM rd_submissions WHERE activity_id = ? AND student_id IN (' . in_list($studentIds) . ')'); $st->execute([$aid]);
    $m = []; foreach ($st->fetchAll() as $r) { $m[$r['student_id']] = $r; }
    return $m;
}
function save_submission(int $aid, int $sid, string $status, ?string $body = null, ?string $note = null): void
{
    db()->prepare('INSERT INTO rd_submissions (activity_id, student_id, status, body, note, submitted_at, ack_status) VALUES (?,?,?,?,?,?,\'pending\')
                   ON DUPLICATE KEY UPDATE status = VALUES(status), body = VALUES(body), note = VALUES(note), submitted_at = VALUES(submitted_at), ack_status = \'pending\', ack_by = NULL, ack_at = NULL, ack_note = NULL')
        ->execute([$aid, $sid, $status, $body, $note, now_sql()]);
}
function ack_submission(int $aid, int $sid, int $by, ?string $note = null): void
{
    db()->prepare("UPDATE rd_submissions SET ack_status = 'received', ack_by = ?, ack_at = ?, ack_note = ? WHERE activity_id = ? AND student_id = ?")->execute([$by, now_sql(), $note, $aid, $sid]);
}

// ---- files ----------------------------------------------------------------------------
function file_path(?string $f): string { return STORAGE_DIR . DIRECTORY_SEPARATOR . basename((string)$f); }
function save_doc(array $f): array     // PDF, DOCX or DOC. Returns [stored name, original name]
{
    $orig = basename((string)($f['name'] ?? ''));
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) { throw new RuntimeException('Choose a file to upload.'); }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || ($f['size'] ?? 0) > MAX_UPLOAD) { throw new RuntimeException($orig . ' is larger than ' . (MAX_UPLOAD / 1048576) . ' MB.'); }
    if ($err !== UPLOAD_ERR_OK) { throw new RuntimeException('Upload failed (code ' . $err . ').'); }
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 8);
    $ok = match ($ext) { 'pdf' => strncmp($head, '%PDF-', 5) === 0, 'docx' => strncmp($head, "PK\x03\x04", 4) === 0, 'doc' => strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0, default => null };
    if ($ok === null) { throw new RuntimeException('Only PDF or Word (.docx, .doc) files are accepted.'); }
    if (!$ok) { throw new RuntimeException($orig . ' is not a valid ' . strtoupper($ext) . ' file.'); }
    if (!is_dir(STORAGE_DIR) && !@mkdir(STORAGE_DIR, 0700, true)) { throw new RuntimeException('The storage folder could not be created.'); }
    if (!is_writable(STORAGE_DIR)) { throw new RuntimeException('The storage folder is not writable.'); }
    $stored = 'rd_' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], file_path($stored)) && !@copy($f['tmp_name'], file_path($stored))) { throw new RuntimeException('Could not save the file.'); }
    return [$stored, mb_substr(preg_replace('/[^\w\s.\-()]/u', '_', $orig), 0, 190)];
}
function add_file(?int $aid, ?int $sid, array $u, string $stored, string $orig, string $label = '', string $receivers = ''): int
{
    db()->prepare('INSERT INTO rd_files (activity_id, student_id, uploader_id, uploader_role, receivers, label, file, original_name, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$aid, $sid, $u['id'], $u['role'], $receivers, mb_substr($label, 0, 200), $stored, $orig, now_sql()]);
    return (int)db()->lastInsertId();
}
function files_of(int $aid, ?int $sid = null): array
{
    $st = db()->prepare('SELECT * FROM rd_files WHERE activity_id = ?' . ($sid !== null ? ' AND student_id = ?' : '') . ' ORDER BY id DESC');
    $st->execute($sid !== null ? [$aid, $sid] : [$aid]);
    return $st->fetchAll();
}
function files_map(int $aid, array $studentIds): array     // student_id => files (newest first)
{
    if (!$studentIds) { return []; }
    $st = db()->prepare('SELECT * FROM rd_files WHERE activity_id = ? AND student_id IN (' . in_list($studentIds) . ') ORDER BY id DESC'); $st->execute([$aid]);
    $m = []; foreach ($st->fetchAll() as $f) { $m[$f['student_id']][] = $f; }
    return $m;
}
function delete_file_row(array $f): void { @unlink(file_path($f['file'])); db()->prepare('DELETE FROM rd_files WHERE id = ?')->execute([$f['id']]); }
// May this user open this file? $download: PL downloads only where the activity allows it.
function can_open_file(array $u, array $f, bool $download = false): bool
{
    if ($u['role'] === 'daa' || ($u['cas_role']??'')==='admin') { return true; }
    if ($u['role']==='pl' && !empty($f['student_id'])) { $student=user_by_id((int)$f['student_id']); if(!$student || $student['department']!==$u['department'])return false; }
    if ((int)$f['uploader_id'] === (int)$u['id']) { return true; }
    if ($f['label'] === 'timeline') { return true; }
    if ($f['label'] === 'eval_return' && in_array($u['role'], ['pl', 'daa'], true)) { return true; }
    $a = $f['activity_id'] ? activity((int)$f['activity_id']) : null;
    if (!$a) { return false; }
    if ($u['role'] === 'evaluator') {      // evaluators reach student work only through their allocations
        $k = [1 => 'proposal', 2 => 'project'][(int)$a['eval_files']] ?? null;
        if (!$k || !$a['enabled'] || $f['uploader_role'] !== 'student') { return false; }
        $st = db()->prepare('SELECT 1 FROM rd_allocations WHERE student_id = ? AND evaluator_id = ? AND kind = ?'); $st->execute([(int)$f['student_id'], $u['id'], $k]); return (bool)$st->fetch();
    }
    if (activity_mode($u, (int)$a['id']) !== 'open') { return false; }
    if ($a['kind'] === 'publish') { return in_array($u['role'], explode(',', $f['receivers']), true); }
    $sid = (int)$f['student_id'];
    switch ($u['role']) {
        case 'student': return $sid === (int)$u['id'];
        case 'supervisor':
            $st = db()->prepare("SELECT 1 FROM rd_supervision WHERE student_id = ? AND supervisor_id = ? AND status IN ('accepted','assigned')"); $st->execute([$sid, $u['id']]); return (bool)$st->fetch();
        case 'pl': return !$download || (int)$a['pl_download'] === 1;
    }
    return false;
}
function file_links(array $u, array $files, bool $canDelete = false, string $back = ''): string
{
    if (!$files) { return '<span class="muted">—</span>'; }
    $h = '';
    foreach ($files as $f) {
        $h .= '<div class="fileline"><a href="file.php?id=' . (int)$f['id'] . '" target="_blank">' . e($f['original_name']) . '</a> <span class="muted">' . e(fmt_day($f['created_at'])) . '</span>';
        if (can_open_file($u, $f, true)) { $h .= ' <a class="small" href="file.php?id=' . (int)$f['id'] . '&amp;dl=1">Download</a>'; }
        if ($canDelete) { $h .= '<form method="post" class="inlineform">' . csrf_field() . '<input type="hidden" name="action" value="delete_file"><input type="hidden" name="fid" value="' . (int)$f['id'] . '"><button class="linkbtn" data-confirm="Delete this file permanently?">delete</button></form>'; }
        $h .= '</div>';
    }
    return $h;
}

// ---- marks ----------------------------------------------------------------------------
function form_max(int $no): float { return (float)FORMS[$no]['max']; }
function marks_row(int $form, int $sid, int $evalId): ?array
{
    $st = db()->prepare('SELECT * FROM rd_marks WHERE form_no = ? AND student_id = ? AND evaluator_id = ?'); $st->execute([$form, $sid, $evalId]);
    return $st->fetch() ?: null;
}
// student_id => list of submitted rows (with the entering person's name)
function marks_map(int $form, array $studentIds): array
{
    if (!$studentIds) { return []; }
    $st = db()->query("SELECT m.*, u.name evaluator_name FROM rd_marks m JOIN rd_users u ON u.id = m.evaluator_id WHERE m.form_no = " . (int)$form . " AND m.status = 'submitted' AND m.student_id IN (" . in_list($studentIds) . ') ORDER BY m.id');
    $m = []; foreach ($st->fetchAll() as $r) { $m[$r['student_id']][] = $r; }
    return $m;
}
function avg_marks(array $rows): ?float { return $rows ? array_sum(array_map(fn($r) => (float)$r['total'], $rows)) / count($rows) : null; }
function fmt_mark(?float $v): string { return $v === null ? '–' : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'); }
// Weighted contribution to the 100 marks: marks / max * weight
function weighted(int $form, ?float $v): ?float { return $v === null ? null : $v / form_max($form) * FORMS[$form]['weight']; }

// ---- status badges -----------------------------------------------------------------------
function badge(string $text, string $cls = 'gray'): string { return '<span class="badge ' . e($cls) . '">' . e($text) . '</span>'; }

// ---- layout ----------------------------------------------------------------------------------
function nav_for(string $role): array
{
    $n = [['dash', 'index.php', 'Timeline']];
    if (in_array($role, ['pl', 'daa'], true)) { $n[] = ['marks', 'marks.php', 'Consolidated marks']; $n[] = ['rmarks', 'report_marks.php', 'Report: Marks']; $n[] = ['rprog', 'report_progress.php', 'Report: Completion']; }
    if ($role === 'daa') { $n = array_merge($n, [['tl', 'daa_timeline.php', 'Manage timeline'], ['files', 'daa_files.php', 'Files'], ['users', 'daa_users.php', 'CAS users & examiner allocations']]); }
    $n[] = ['pw', '/?page=forgot_password', 'Reset CAS password'];
    return $n;
}
function layout_start(string $title, string $active, string $crumb = ''): void
{
    $u = require_login();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= filemtime(dirname(__DIR__,3).'/public/desks/rd/assets/style.css') ?>">
</head>
<body><?php \deskRoleBar('rd'); ?>
<div class="shell">
  <!-- Placeholder for the college portal sidebar. On the live site, the portal's own sidebar replaces this. -->
  <?php \deskNavigation('rd'); ?>
  <aside class="module">
    <div class="brand"><?= e(APP_NAME) ?></div>
    <div class="sub"><?= e(APP_SUB) ?></div>
    <nav>
      <?php foreach (nav_for($u['role']) as [$key, $href, $label]): ?>
        <a href="<?= e($href) ?>" class="<?= $key === $active ? 'on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="who">
      <strong><?= e($u['name']) ?></strong>
      <span><?= e(ROLES[$u['role']]) ?><?= $u['department'] ? ' · ' . e($u['department']) : '' ?></span>
      <a href="/">Back to CAS</a>
    </div>
  </aside>
  <main>
    <div class="crumb"><?= e($crumb ?: ROLES[$u['role']]) ?></div>
    <h1><?= e($title) ?></h1>
    <?php if (!empty($_SESSION['flash'])): [$m, $t] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
      <div class="flash <?= e($t) ?>"><?= e($m) ?></div>
    <?php endif; ?>
<?php
}
function layout_end(): void
{
    echo "  </main>\n</div>\n<script src=\"assets/app.js?v=5\"></script>\n</body>\n</html>";
}
// Actions and uploads open in their own window. This is the layout for that window.
function popup_start(string $title, string $sub = ''): void
{
    require_login();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= filemtime(dirname(__DIR__,3).'/public/desks/rd/assets/style.css') ?>">
</head>
<body class="popup" data-saved="<?= isset($_GET['saved']) ? '1' : '' ?>">
<header class="popbar"><div><b><?= e(APP_NAME) ?></b><?= $sub ? ' · <span>' . e($sub) . '</span>' : '' ?></div><button type="button" class="btn small ghost" onclick="try{if(window.opener&&!window.opener.closed)window.opener.location.reload();}catch(e){}window.close()">Close window</button></header>
<main class="popmain">
  <h1><?= e($title) ?></h1>
  <?php if (!empty($_SESSION['flash'])): [$m, $t] = $_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="flash <?= e($t) ?>"><?= e($m) ?></div>
  <?php endif; ?>
<?php
}
function popup_end(): void
{
    echo "</main>\n<script src=\"assets/app.js?v=5\"></script>\n</body>\n</html>";
}
// A link that opens in a separate window
function popup_link(string $href, string $label, string $cls = 'btn small'): string
{
    return '<a class="' . e($cls) . ' popup-link" href="' . e($href) . '" target="_blank" rel="noopener">' . e($label) . '</a>';
}
