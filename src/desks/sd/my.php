<?php
namespace CAS\SignDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
$u = require_login();
if ($u['role'] === 'daa') { redirect('queue.php'); }
$st = db()->prepare('SELECT r.*, u.name AS user_name, IF(CHAR_LENGTH(r.department) > 0, r.department, u.department) AS dept FROM sd_doc_requests r JOIN sd_users u ON u.id = r.user_id WHERE r.user_id = ? ORDER BY r.created_at DESC');
$st->execute([$u['id']]);
layout_start('My documents', 'mine');
?>
<p class="lead">Every document you sent. Open one to download the signed copy.</p>
<section class="card"><?php render_table($st->fetchAll(), false); ?></section>
<?php layout_end();
