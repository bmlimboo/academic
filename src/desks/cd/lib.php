<?php
namespace CAS\CertDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;



require_once dirname(__DIR__).'/bridge.php';
\deskBoot('cd');
require __DIR__ . '/config.php';

const STATUS = [
    'submitted'         => ['With Exam Cell', 'blue'],
    'returned'          => ['Returned to requester', 'orange'],
    'with_daa'          => ['Awaiting DAA approval', 'amber'],
    'awaiting_payment'  => ['Approved, awaiting payment', 'amber'],
    'payment_submitted' => ['Payment proof received', 'blue'],
    'ready'             => ['Ready to release', 'blue'],
    'released'          => ['Released', 'green'],
    'rejected'          => ['Rejected', 'red'],
];
// Statuses that the DAA is allowed to see (the request has reached the DAA).
const DAA_STATUSES = ['with_daa', 'awaiting_payment', 'payment_submitted', 'ready', 'released'];
const KINDS = [
    'application'    => 'Application letter',
    'marksheet'      => 'Marksheet',
    'degree'         => 'Degree / diploma',
    'supporting'     => 'Other supporting document',
    'release_letter' => 'Release letter (Exam Cell)',
    'signed_letter'  => 'Release letter signed by DAA',
    'deposit_proof'  => 'Deposit proof',
    'certificate'    => 'Certificate',
];
const ACTION_LABELS = [
    'submitted'     => 'Request submitted',
    'resubmitted'   => 'Resubmitted by the requester',
    'returned'      => 'Returned to the requester',
    'rejected'      => 'Request rejected',
    'sent_daa'      => 'Sent to the DAA for approval',
    'daa_returned'  => 'Returned by the DAA to the Exam Cell',
    'approved'      => 'Approved and signed by the DAA',
    'payment_email' => 'Payment email',
    'proof_uploaded' => 'Deposit proof submitted',
    'proof_refused' => 'Deposit proof not accepted',
    'certs_uploaded' => 'Certificate files uploaded',
    'released'      => 'Certificates released by email',
    'file_deleted'  => 'File deleted',
];
const DEFAULT_SETTINGS = [
    'general_instructions' =>
        "Attach clear, readable scans (PDF, JPG or PNG, up to 10 MB each).\n"
        . "1. Application letter: addressed to the Head, Examination Cell, signed by you, listing the certificates you need and why.\n"
        . "2. Marksheets: all semester marksheets or grade sheets, every page.\n"
        . "3. Degree certificate or diploma: a copy of your degree or diploma.\n"
        . "4. Any extra document listed under the certificate you ask for.\n"
        . "Requests with missing or unreadable documents will be returned to you.",
    'payment_details' =>
        "Account name: (enter account name)\nBank: (enter bank and branch)\nAccount number: (enter account number)\nReference to write on the deposit: your request number",
    'email_payment_subject' => 'Payment required for your certificate request {ref}',
    'email_payment_body' =>
        "Dear {name},\n\nYour request {ref} has been approved for:\n{certificates}\n\n"
        . "Please deposit {currency} {amount} using the details below:\n\n{payment_details}\n\n"
        . "After depositing, sign in at {portal_url}, open request {ref} and upload a scan of the deposit slip or transfer receipt. "
        . "We will release your certificates once we receive it.\n\nRegards,\n{cell_name}",
    'email_release_subject' => 'Your certificates for request {ref}',
    'email_release_body' =>
        "Dear {name},\n\nThank you for the payment. Please find attached the following for request {ref}:\n{certificates}\n\n"
        . "You can also collect the certificates in person from the {cell_name}. Please bring a valid photo ID and mention request {ref}.\n\n"
        . "Regards,\n{cell_name}",
];

function db(): \DeskDatabase { return \deskDB(); }

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_date(?string $d): string { return $d ? date('d M Y, H:i', strtotime($d)) : ''; }
function money($n): string { return CURRENCY . ' ' . number_format((float)$n, 2); }
function redirect(string $url): void { header('Location: ' . $url); exit; }
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = [$msg, $type]; }
function now_sql(): string { return date('Y-m-d H:i:s'); }

function current_user(): ?array { return \deskUser('cd'); }
function require_login(): array
{
    $u = current_user();
    if (!$u) { \deskLogin('cd'); }
    return $u;
}
function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) { http_response_code(403); exit('You do not have access to this page.'); }
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

// ---- settings ----------------------------------------------------------------

function setting(string $key): string
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (db()->query('SELECT skey, svalue FROM cd_settings')->fetchAll() as $r) { $all[$r['skey']] = $r['svalue']; }
    }
    return $all[$key] ?? (DEFAULT_SETTINGS[$key] ?? '');
}
function save_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO cd_settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute([$key, $value]);
}
function reset_setting(string $key): void
{
    db()->prepare('DELETE FROM cd_settings WHERE skey = ?')->execute([$key]);
}

// ---- badges --------------------------------------------------------------------

function badge(string $key): string
{
    [$label, $cls] = STATUS[$key] ?? [$key, 'gray'];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

// ---- certificate types, requests ---------------------------------------------------

function cert_types(bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM cd_cert_types' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, id';
    return db()->query($sql)->fetchAll();
}

function load_request(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM cd_requests WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function request_items(int $rid): array
{
    $st = db()->prepare('SELECT i.*, t.name, t.is_other, t.fee FROM cd_request_items i JOIN cd_cert_types t ON t.id = i.type_id WHERE i.request_id = ? ORDER BY i.id');
    $st->execute([$rid]);
    return $st->fetchAll();
}
function item_label(array $it): string
{
    return !empty($it['is_other']) ? $it['name'] . ': ' . ($it['other_text'] ?? '') : $it['name'];
}
function request_files(int $rid): array
{
    $st = db()->prepare('SELECT * FROM cd_request_files WHERE request_id = ? ORDER BY id');
    $st->execute([$rid]);
    return $st->fetchAll();
}
function request_logs(int $rid): array
{
    $st = db()->prepare('SELECT * FROM cd_request_logs WHERE request_id = ? ORDER BY id');
    $st->execute([$rid]);
    return $st->fetchAll();
}
function request_emails(int $rid): array
{
    $st = db()->prepare('SELECT e.*,COALESCE(o.status,e.status) AS status FROM cd_emails e LEFT JOIN desk_mail_links l ON l.email_id=e.id LEFT JOIN outbox o ON o.id=l.outbox_id WHERE e.request_id = ? ORDER BY e.id');
    $st->execute([$rid]);
    return $st->fetchAll();
}
function log_request(int $rid, string $actor, string $action, ?string $note = null): void
{
    db()->prepare('INSERT INTO cd_request_logs (request_id, actor_name, action, note, created_at) VALUES (?,?,?,?,?)')
        ->execute([$rid, $actor, $action, $note === null ? null : mb_substr($note, 0, 500), now_sql()]);
    $r=\one('SELECT * FROM cd_requests WHERE id=?',[$rid]);
    if($r&&in_array($action,['submitted','resubmitted','sent_daa','returned','rejected','daa_returned','proof_uploaded'],true)){
     $emails=$action==='sent_daa'?['daa.cnr@rub.edu.bt']:(in_array($action,['returned','rejected'],true)?[$r['email']]:array_column(\all("SELECT email FROM users WHERE role='exam' AND active=1"),'email'));
     foreach(array_unique($emails)as $email)\deskQueueMail($email,'Certificate request '.$r['ref'].' — '.$action,$r['full_name']."\n".($note?:'')."\n\nOpen in CAS: ".rtrim($GLOBALS['config']['url'],'/').'/desks/cd/request.php?id='.$rid);
    }

}
function set_status(int $rid, string $status, array $extra = []): void
{
    $sets = ['status = ?', 'updated_at = ?']; $vals = [$status, now_sql()];
    foreach ($extra as $col => $val) {
        if (!in_array($col, ['amount', 'cell_note', 'daa_note', 'deposit_ref'], true)) { continue; }
        $sets[] = "$col = ?"; $vals[] = $val;
    }
    $vals[] = $rid;
    db()->prepare('UPDATE cd_requests SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
}
function default_amount(array $items): float
{
    $sum = 0.0;
    foreach ($items as $it) { $sum += (float)$it['fee']; }
    return $sum;
}

function can_see_request(array $u, array $r): bool
{
    if ($u['role'] === 'examcell') { return true; }
    if ($u['role'] === 'daa') { return in_array($r['status'], DAA_STATUSES, true); }
    return (int)$r['user_id'] === (int)$u['id'];
}
function can_see_file(array $u, array $r, array $f): bool
{
    if (!can_see_request($u, $r)) { return false; }
    if ($u['role'] !== 'alumnus') { return true; }
    if (in_array($f['kind'], ['release_letter', 'signed_letter'], true)) { return false; }
    if ($f['kind'] === 'certificate') { return $r['status'] === 'released'; }
    return true;
}

// Filters: type (cert type id), status, year, q.  $daaOnly limits the list to what the DAA may see.
function requests_rows(array $src, bool $daaOnly = false, ?int $userId = null): array
{
    $where = []; $p = [];
    $type = (int)($src['type'] ?? 0);
    if ($type > 0) { $where[] = 'EXISTS (SELECT 1 FROM cd_request_items i WHERE i.request_id = r.id AND i.type_id = ?)'; $p[] = $type; }
    $status = array_key_exists($src['status'] ?? '', STATUS) ? $src['status'] : '';
    if ($status !== '') { $where[] = 'r.status = ?'; $p[] = $status; }
    $year = (int)($src['year'] ?? 0);
    if ($year > 0) { $where[] = 'r.grad_year = ?'; $p[] = $year; }
    $q = trim((string)($src['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(r.ref LIKE ? OR r.full_name LIKE ? OR r.student_id LIKE ? OR r.email LIKE ?)';
        $like = '%' . addcslashes($q, '%_\\') . '%';
        array_push($p, $like, $like, $like, $like);
    }
    if ($daaOnly) { $where[] = "r.status IN ('" . implode("','", DAA_STATUSES) . "')"; }
    if ($userId !== null) { $where[] = 'r.user_id = ?'; $p[] = $userId; }
    $sql = 'SELECT r.* FROM cd_requests r' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY r.created_at DESC, r.id DESC';
    $st = db()->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    attach_items($rows);
    return [$rows, ['type' => $type, 'status' => $status, 'year' => $year, 'q' => $q]];
}
function attach_items(array &$rows): void
{
    if (!$rows) { return; }
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $st = db()->query('SELECT i.*, t.name, t.is_other, t.fee FROM cd_request_items i JOIN cd_cert_types t ON t.id = i.type_id WHERE i.request_id IN (' . implode(',', $ids) . ') ORDER BY i.id');
    $by = [];
    foreach ($st->fetchAll() as $it) { $by[(int)$it['request_id']][] = $it; }
    foreach ($rows as &$r) { $r['items'] = $by[(int)$r['id']] ?? []; }
    unset($r);
}
function items_html(array $items): string
{
    $out = [];
    foreach ($items as $it) { $out[] = '<span class="chip">' . e(item_label($it)) . '</span>'; }
    return '<div class="chips">' . implode('', $out) . '</div>';
}

// ---- uploads ------------------------------------------------------------------------

function paper_path(?string $file): string { return STORAGE_DIR . DIRECTORY_SEPARATOR . basename((string)$file); }

// Real type of an uploaded file, from its first bytes (not from its name).
function sniff_ext(string $path): ?string
{
    $h = (string)file_get_contents($path, false, null, 0, 8);
    if (strncmp($h, '%PDF-', 5) === 0) { return 'pdf'; }
    if (strncmp($h, "\x89PNG", 4) === 0) { return 'png'; }
    if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) { return 'jpg'; }
    return null;
}
function type_label(array $allowed): string { return strtoupper(implode(', ', $allowed)); }

// Normalises $_FILES[$key] (single or multiple) into a list of single-file arrays, skipping empty slots.
function files_list(string $key): array
{
    if (empty($_FILES[$key])) { return []; }
    $f = $_FILES[$key]; $out = [];
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $n) {
            $one = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'size' => $f['size'][$i], 'error' => $f['error'][$i]];
            if ($one['error'] !== UPLOAD_ERR_NO_FILE) { $out[] = $one; }
        }
    } elseif ($f['error'] !== UPLOAD_ERR_NO_FILE) {
        $out[] = $f;
    }
    return $out;
}

function save_upload(array $f, array $allowed = ['pdf', 'jpg', 'png'], string $prefix = 'f'): array
{
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { throw new RuntimeException('A file is larger than the server allows. Use a file of ' . (MAX_UPLOAD / 1048576) . ' MB or less.'); }
    if ($err !== UPLOAD_ERR_OK) { throw new RuntimeException('Choose a file to upload (' . type_label($allowed) . ').'); }
    if ($f['size'] > MAX_UPLOAD) { throw new RuntimeException('"' . basename($f['name']) . '" is larger than ' . (MAX_UPLOAD / 1048576) . ' MB.'); }
    $orig = basename((string)$f['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') { $ext = 'jpg'; }
    $real = sniff_ext($f['tmp_name']);
    if (!in_array($ext, $allowed, true) || $real === null || $real !== $ext || !in_array($real, $allowed, true)) {
        throw new RuntimeException('"' . $orig . '" is not accepted. Only ' . type_label($allowed) . ' files are allowed.');
    }
    if (!is_dir(STORAGE_DIR) && !@mkdir(STORAGE_DIR, 0700, true)) { throw new RuntimeException('The storage folder does not exist and could not be created: ' . STORAGE_DIR); }
    if (!is_writable(STORAGE_DIR)) { throw new RuntimeException('The storage folder is not writable: ' . STORAGE_DIR); }
    $stored = $prefix . '_' . bin2hex(random_bytes(12)) . '.' . $real;
    $target = paper_path($stored);
    if (!@move_uploaded_file($f['tmp_name'], $target) && !@copy($f['tmp_name'], $target)) {
        throw new RuntimeException('Could not save the file to ' . $target . ' (' . (error_get_last()['message'] ?? 'unknown error') . ').');
    }
    return [$stored, mb_substr((string)preg_replace('/[^\w\s.\-()]/u', '_', $orig), 0, 190)];
}

function add_file(int $rid, ?int $itemId, string $kind, string $stored, string $orig, string $by): int
{
    db()->prepare('INSERT INTO cd_request_files (request_id, item_id, kind, `stored`, original_name, uploaded_by, uploaded_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$rid, $itemId, $kind, $stored, $orig, $by, now_sql()]);
    return (int)db()->lastInsertId();
}
function delete_file_row(array $f): void
{
    @unlink(paper_path($f['stored']));
    db()->prepare('DELETE FROM cd_request_files WHERE id = ?')->execute([$f['id']]);
}
function file_mime(string $stored): string
{
    return match (strtolower(pathinfo($stored, PATHINFO_EXTENSION))) { 'pdf' => 'application/pdf', 'png' => 'image/png', default => 'image/jpeg' };
}
function name_part(string $s, int $max): string
{
    $s = (string)preg_replace('/[^\p{L}\p{N}\- ]+/u', '', $s);
    return mb_substr(trim((string)preg_replace('/\s+/', ' ', $s)), 0, $max);
}

// ---- email templates -------------------------------------------------------------------

function template_vars(array $r, array $items): array
{
    $lines = [];
    foreach ($items as $it) { $lines[] = '- ' . item_label($it); }
    return [
        '{name}' => $r['full_name'], '{ref}' => (string)$r['ref'], '{student_id}' => $r['student_id'], '{programme}' => $r['programme'],
        '{certificates}' => implode("\n", $lines), '{amount}' => number_format((float)($r['amount'] ?? 0), 2), '{currency}' => CURRENCY,
        '{payment_details}' => setting('payment_details'), '{portal_url}' => BASE_URL . '/index.php', '{cell_name}' => CELL_NAME,
    ];
}
function render_template(string $tpl, array $vars): string { return strtr($tpl, $vars); }

function mime_encode(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

// $atts: list of [path, name, mime].  Returns [header lines, body].
function build_message(string $body, array $atts): array
{
    $from = MAIL_FROM_NAME !== '' ? mime_encode(MAIL_FROM_NAME) . ' <' . MAIL_FROM . '>' : MAIL_FROM;
    $h = ['From: ' . $from, 'Reply-To: ' . MAIL_FROM, 'MIME-Version: 1.0'];
    $body = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $body));
    if (!$atts) {
        $h[] = 'Content-Type: text/plain; charset=UTF-8';
        $h[] = 'Content-Transfer-Encoding: base64';
        return [$h, chunk_split(base64_encode($body))];
    }
    $b = 'b' . bin2hex(random_bytes(12));
    $h[] = 'Content-Type: multipart/mixed; boundary="' . $b . '"';
    $msg = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body));
    foreach ($atts as [$path, $name, $mime]) {
        $n = mime_encode((string)preg_replace('/["\r\n]/', '', $name));
        $msg .= "--$b\r\nContent-Type: $mime; name=\"$n\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$n\"\r\n\r\n"
              . chunk_split(base64_encode((string)file_get_contents($path)));
    }
    return [$h, $msg . "--$b--\r\n"];
}

function smtp_send(string $to, string $subject, array $h, string $msg): void
{
    $fp = @stream_socket_client((SMTP_SECURE === 'ssl' ? 'ssl://' : 'tcp://') . SMTP_HOST . ':' . SMTP_PORT, $en, $es, 20);
    if (!$fp) { throw new RuntimeException('Could not connect to the mail server (' . $es . ').'); }
    stream_set_timeout($fp, 30);
    $read = function () use ($fp): string {
        $out = '';
        while (($l = fgets($fp, 515)) !== false) { $out .= $l; if (strlen($l) < 4 || $l[3] === ' ') { break; } }
        return $out;
    };
    $expect = function (string $resp, array $codes): void {
        if (!in_array((int)substr($resp, 0, 3), $codes, true)) { throw new RuntimeException('The mail server replied: ' . trim($resp)); }
    };
    $cmd = function (string $c, array $codes) use ($fp, $read, $expect): string {
        fwrite($fp, $c . "\r\n");
        $r = $read(); $expect($r, $codes);
        return $r;
    };
    try {
        $expect($read(), [220]);
        $host = parse_url(BASE_URL, PHP_URL_HOST) ?: 'localhost';
        $cmd('EHLO ' . $host, [250]);
        if (SMTP_SECURE === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { throw new RuntimeException('Could not start a secure (TLS) connection to the mail server.'); }
            $cmd('EHLO ' . $host, [250]);
        }
        if (SMTP_USER !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode(SMTP_USER), [334]);
            $cmd(base64_encode(SMTP_PASS), [235]);
        }
        $cmd('MAIL FROM:<' . MAIL_FROM . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $head = array_merge(['To: ' . $to, 'Subject: ' . mime_encode($subject), 'Date: ' . date('r'), 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $host . '>'], $h);
        $data = implode("\r\n", $head) . "\r\n\r\n" . $msg;
        $data = (string)preg_replace('/^\./m', '..', $data);
        fwrite($fp, $data . "\r\n.\r\n");
        $expect($read(), [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        @fclose($fp);
    }
}

// Sends (or, in 'log' mode, only records) an email and always writes it to the email log. Returns [status, error].
function deliver_email(array $r, string $kind, string $to, string $subject, string $body, array $atts, string $by): array
{
    try {
        $oid=\deskQueueMail($to,$subject,$body,$atts);
        db()->prepare('INSERT INTO cd_emails(request_id,kind,to_email,subject,body,attachments,status,error,sent_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$r['id'],$kind,$to,$subject,$body,implode(', ',array_column($atts,1)),'queued','',$by,now_sql()]);
        $eid=(int)db()->lastInsertId();db()->prepare('INSERT INTO desk_mail_links(email_id,outbox_id) VALUES(?,?)')->execute([$eid,$oid]);
        return ['queued',''];
    } catch(Throwable $e){return ['failed','Unable to queue email. Contact ICT.'];}
}


function mail_mode_note(): string { return 'Notifications use the CAS mail queue. Queued does not confirm inbox delivery.'; }

function payment_email_parts(array $r, string $extra = ''): array
{
    $v = template_vars($r, request_items((int)$r['id']));
    $body = render_template(setting('email_payment_body'), $v);
    if ($extra !== '') { $body = $extra . "\n\n" . $body; }
    return [render_template(setting('email_payment_subject'), $v), $body];
}
function send_payment_email(array $r, string $by = 'System (automatic)', string $extra = ''): array
{
    [$subject, $body] = payment_email_parts($r, $extra);
    return deliver_email($r, 'payment', $r['email'], $subject, $body, [], $by);
}

// ---- layout ----------------------------------------------------------------------------------

function layout_start(string $title, string $active, string $crumb = 'Certificate requests'): void
{
    $u = require_login();
    $nav = match ($u['role']) {
        'alumnus'  => [['dash', 'index.php', 'Dashboard'], ['new', 'request_new.php', 'New request'], ['mine', 'my.php', 'My requests']],
        'examcell' => [['dash', 'index.php', 'Dashboard'], ['requests', 'requests.php', 'Requests'], ['settings', 'settings.php', 'Settings']],
        default    => [['dash', 'index.php', 'Dashboard'], ['requests', 'requests.php', 'Requests'], ['signature', 'signature.php', 'Signature and seal']],
    };
    $role = ['alumnus' => 'Alumnus', 'examcell' => 'Exam Cell', 'daa' => 'DAA'][$u['role']];
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= APP_NAME ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body><?php \deskRoleBar('cd'); ?>
<div class="shell">
  <!-- Placeholder for the college portal sidebar. On the live site, the portal's own sidebar replaces this. -->
  <?php \deskNavigation('cd'); ?>
  <aside class="module">
    <div class="brand"><?= APP_NAME ?></div>
    <div class="sub">Certificate requests</div>
    <nav>
      <?php foreach ($nav as [$key, $href, $label]): ?>
        <a href="<?= e($href) ?>" class="<?= $key === $active ? 'on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="who">
      <strong><?= e($u['name']) ?></strong>
      <span><?= e($role) ?></span>
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

// Compact table of requests (used on dashboards and lists).
function render_request_table(array $rows, string $empty, bool $showWho = false): void
{
    if (!$rows) { echo '<p class="empty">' . e($empty) . '</p>'; return; }
    echo '<div class="tablewrap"><table><thead><tr><th>Request</th>' . ($showWho ? '<th>Requester</th>' : '') . '<th>Certificates</th><th>Submitted</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td class="nowrap"><a href="request.php?id=' . (int)$r['id'] . '">' . e($r['ref']) . '</a></td>';
        if ($showWho) { echo '<td>' . e($r['full_name']) . '<div class="muted">' . e($r['student_id']) . ' · ' . e($r['programme']) . ' · ' . (int)$r['grad_year'] . '</div></td>'; }
        echo '<td>' . items_html($r['items'] ?? []) . '</td>';
        echo '<td class="nowrap">' . e(fmt_date($r['created_at'])) . '</td><td>' . badge($r['status']) . '</td>';
        echo '<td class="nowrap"><a class="btn small ghost" href="request.php?id=' . (int)$r['id'] . '">Open</a></td></tr>';
    }
    echo '</tbody></table></div>';
}
