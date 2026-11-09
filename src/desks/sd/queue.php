<?php
namespace CAS\SignDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

require __DIR__ . '/lib.php';
require_daa();
$done = ($_GET['view'] ?? 'pending') === 'done';
$in = $done ? "('signed','returned','rejected')" : "('submitted','under_review')";
$order = $done ? 'r.updated_at DESC' : "r.urgency = 'urgent' DESC, r.created_at ASC";
$rows = db()->query("SELECT r.*, u.name AS user_name, COALESCE(NULLIF(r.department,''), u.department) AS dept FROM sd_doc_requests r JOIN sd_users u ON u.id = r.user_id WHERE r.status IN $in ORDER BY $order")->fetchAll();
layout_start($done ? 'Signed archive' : 'Signing queue', $done ? 'archive' : 'queue');
?>
<p class="lead"><?= $done ? 'Documents already signed, returned or rejected.' : 'Urgent documents first, then oldest first.' ?></p>
<section class="card"><?php render_table($rows, true); ?></section>
<?php layout_end();
