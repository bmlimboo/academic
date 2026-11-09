<?php
namespace CAS\ResearchDesk;
use PDO; use PDOException; use RuntimeException; use Throwable; use ZipArchive; use finfo; use DateTime; use DateTimeImmutable;

// One-line status for an activity on the dashboard (what the student sees for themselves, counts for everyone else).


function activity_status(array $a, array $u, string $dept): string
{
    $aid = (int)$a['id'];
    $scope = students_in_scope($u, $dept, eval_kind($a));
    $ids = ids_of($scope); $n = count($ids); $in = in_list($ids);
    $kind = $a['kind'];
    $overdue = act_overdue($a);

    if ($kind === 'publish') {
        $c = 0; foreach (files_of($aid) as $f) { if (in_array($u['role'], explode(',', $f['receivers']), true) || $u['role'] === 'daa') { $c++; } }
        return $c ? badge($c . ' document' . ($c > 1 ? 's' : '') . ' shared', 'green') : badge('No document yet', 'amber');
    }
    // ---------- student's own view
    if ($u['role'] === 'student') {
        $s = $scope[0] ?? null; if (!$s) { return ''; }
        $sub = submission($aid, (int)$u['id']);
        switch ($kind) {
            case 'supervisor_select':
                return match ($s['sv_status'] ?? '') { 'requested' => badge('Waiting for ' . $s['sup_name'], 'amber'), 'accepted', 'assigned' => badge('Supervisor: ' . $s['sup_name'], 'green'), 'rejected' => badge('Not accepted. Choose again', 'red'), default => badge($overdue ? 'Deadline crossed' : 'Not submitted', $overdue ? 'red' : 'amber') };
            case 'topic_final':
                return match ($s['final_status'] ?? 'none') { 'approved' => badge('Approved and completed', 'green'), 'submitted' => badge('Submitted. Waiting for approval', 'blue'), default => badge($overdue ? 'Deadline crossed' : 'Not submitted', $overdue ? 'red' : 'amber') };
            case 'student_file': case 'student_text':
                if (!$sub) { return badge($overdue ? 'Deadline crossed' : 'Not submitted', $overdue ? 'red' : 'amber'); }
                return badge($a['ack_role'] && $sub['ack_status'] === 'received' ? 'Submitted and received' : 'Submitted', 'green');
            case 'supervisor_file':
                if (!$sub) { return badge('Waiting for your supervisor', 'amber'); }
                return $sub['ack_status'] === 'received' ? badge('Received and completed', 'green') : badge('Returned to you. Mark as received', 'blue');
            case 'defense':
                return !$sub ? badge('Pending', 'amber') : badge($sub['status'] === 'presented' ? 'Presented and completed' : 'Did not present', $sub['status'] === 'presented' ? 'green' : 'red');
            case 'assign_evaluators':
                $c = (int)db()->query("SELECT COUNT(*) FROM rd_allocations WHERE kind = 'project' AND student_id = " . (int)$u['id'])->fetchColumn();
                return $c ? badge('Sent to evaluators', 'green') : badge('Pending', 'amber');
            case 'evaluate':
                $c = (int)db()->query("SELECT COUNT(*) FROM rd_marks WHERE form_no = 6 AND status = 'submitted' AND student_id = " . (int)$u['id'])->fetchColumn();
                return $c ? badge('Returned to Programme Leader', 'green') : badge('With evaluators', 'amber');
            case 'grades':
                $c = (int)db()->query("SELECT COUNT(DISTINCT form_no) FROM rd_marks WHERE form_no IN (5,8) AND status = 'submitted' AND student_id = " . (int)$u['id'])->fetchColumn();
                return $c >= 2 ? badge('Procedure completed', 'green') : badge('In progress', 'amber');
        }
        return '';
    }
    // ---------- evaluator's own view
    if ($u['role'] === 'evaluator') {
        if ($kind === 'defense' || $kind === 'evaluate') {
            $form = (int)$a['form_no']; if (!$form) { return ''; }
            $done = (int)db()->query("SELECT COUNT(*) FROM rd_marks WHERE form_no = $form AND status = 'submitted' AND evaluator_id = " . (int)$u['id'] . " AND student_id IN ($in)")->fetchColumn();
            return $n ? badge($done . ' of ' . $n . ' marked', $done >= $n ? 'green' : 'amber') : badge('Nothing assigned', 'gray');
        }
        if ($kind === 'assign_evaluators') {
            $rec = (int)db()->query("SELECT COUNT(*) FROM rd_allocations WHERE kind = 'project' AND received_at IS NOT NULL AND evaluator_id = " . (int)$u['id'])->fetchColumn();
            return $n ? badge($rec . ' of ' . $n . ' received', $rec >= $n ? 'green' : 'amber') : badge('Nothing assigned', 'gray');
        }
        return $n ? badge($n . ' assigned', 'blue') : badge('Nothing assigned', 'gray');
    }
    // ---------- supervisor, programme leader, DAA: counts
    if ($kind === 'supervisor_select') {
        if ($u['role'] === 'supervisor') {
            $st = db()->prepare("SELECT SUM(status = 'requested') r, SUM(status IN ('accepted','assigned')) a FROM rd_supervision WHERE supervisor_id = ?"); $st->execute([$u['id']]); $r = $st->fetch();
            return badge((int)$r['r'] . ' waiting', (int)$r['r'] ? 'amber' : 'gray') . ' ' . badge((int)$r['a'] . ' accepted', 'green');
        }
        $all = students_in_scope($u, $dept); $ok = count(array_filter($all, fn($s) => in_array($s['sv_status'], ['accepted', 'assigned'], true)));
        return badge($ok . ' of ' . count($all) . ' matched', $ok >= count($all) && $all ? 'green' : 'amber');
    }
    if ($kind === 'topic_final') {
        $ok = count(array_filter($scope, fn($s) => $s['final_status'] === 'approved')); $sub = count(array_filter($scope, fn($s) => $s['final_status'] === 'submitted'));
        return badge($ok . ' of ' . $n . ' approved', $ok >= $n && $n ? 'green' : 'amber') . ($sub ? ' ' . badge($sub . ' to approve', 'blue') : '');
    }
    $subs = subs_for($aid, $ids);
    if (in_array($kind, ['student_file', 'student_text', 'supervisor_file'], true)) {
        $c = count($subs); $h = badge($c . ' of ' . $n . ($kind === 'supervisor_file' ? ' returned' : ' submitted'), $c >= $n && $n ? 'green' : 'amber');
        if ($a['ack_role']) { $r = count(array_filter($subs, fn($x) => $x['ack_status'] === 'received')); $h .= ' ' . badge($r . ' received', $r >= $c && $c ? 'green' : 'blue'); }
        return $h;
    }
    if ($kind === 'defense') {
        $c = count(array_filter($subs, fn($x) => $x['status'] === 'presented'));
        $h = badge($c . ' of ' . $n . ' presented', $c >= $n && $n ? 'green' : 'amber');
        $form = (int)$a['form_no']; $mm = marks_map($form, $ids);
        return $h . ' ' . badge(count($mm) . ' marked', count($mm) >= $n && $n ? 'green' : 'blue');
    }
    if ($kind === 'assign_evaluators') {
        $c = (int)db()->query("SELECT COUNT(*) FROM (SELECT student_id FROM rd_allocations WHERE kind = 'project' AND student_id IN ($in) GROUP BY student_id HAVING COUNT(*) >= 2) x")->fetchColumn();
        return badge($c . ' of ' . $n . ' sent to evaluators', $c >= $n && $n ? 'green' : 'amber');
    }
    if ($kind === 'evaluate') { $mm = marks_map(6, $ids); return badge(count($mm) . ' of ' . $n . ' returned', count($mm) >= $n && $n ? 'green' : 'amber'); }
    if ($kind === 'grades') {
        $m5 = marks_map(5, $ids); $m8 = marks_map(8, $ids); $c = count(array_intersect_key($m5, $m8));
        return badge($c . ' of ' . $n . ' graded', $c >= $n && $n ? 'green' : 'amber');
    }
    return '';
}
