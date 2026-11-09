<?php
namespace CAS\ResearchDesk;
use RuntimeException;use Throwable;
require __DIR__.'/lib.php';
$u=require_role('daa');$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();try{
  if(($_POST['action']??'')==='student_details'){
   $sid=(int)($_POST['student_id']??0);$student=user_by_id($sid);$number=trim($_POST['student_no']??'');$batch=trim($_POST['batch']??'');
   if(!$student||$student['role']!=='student'||strlen($number)>60||strlen($batch)>100)throw new RuntimeException('Choose a student and enter a valid student number and batch.');
   db()->prepare('INSERT IGNORE INTO desk_profiles(id) VALUES(?)')->execute([$sid]);db()->prepare('UPDATE desk_profiles SET student_no=?,batch=? WHERE id=?')->execute([$number,$batch,$sid]);flash('Student details saved.');redirect('daa_users.php');
  }
  if(($_POST['action']??'')==='allocate_proposal'){
   $sid=(int)($_POST['student_id']??0);$eid=(int)($_POST['evaluator_id']??0);$student=user_by_id($sid);$examiner=user_by_id($eid);
   if(!$student||$student['role']!=='student'||!$student['active']||!$examiner||!$examiner['active']||!in_array($examiner['role'],['supervisor','pl','daa'],true)||$sid===$eid)throw new RuntimeException('Choose an active student and examiner.');
   db()->prepare("INSERT IGNORE INTO rd_allocations(kind,student_id,evaluator_id) VALUES('proposal',?,?)")->execute([$sid,$eid]);flash('Examiner assigned using their existing CAS account.');redirect('daa_users.php');
  }
  if(($_POST['action']??'')==='remove_allocation'){db()->prepare('DELETE FROM rd_allocations WHERE id=?')->execute([(int)($_POST['allocation_id']??0)]);flash('Allocation removed. Existing marks retained.');redirect('daa_users.php');}
 }catch(Throwable $e){$errors[]=$e->getMessage();}
}
layout_start('CAS users & examiner allocations','users');
foreach($errors as $e)echo '<div class="flash err">'.e($e).'</div>';
?><section class="card"><h2>One CAS account per person</h2><p>Accounts, email addresses, passwords, departments and active status come from CAS. No demo accounts are imported. Faculty can work as supervisors and as assigned evaluators without changing their CAS role.</p><?php if($u['cas_role']==='admin'):?><a class="btn" href="/?page=admin">Manage CAS accounts</a><?php endif;?><p>Admin can create student accounts in CAS. Existing students keep their current accounts. Students choose their supervisor and topic in the research workspace.</p></section>
<form method="post" class="card"><?=csrf_field()?><input type="hidden" name="action" value="student_details"><h2>Student research details</h2><label>Student<select name="student_id" required><option value="">Select…</option><?php foreach(users_by_role('student')as $x):?><option value="<?=$x['id']?>"><?=e($x['name'].' · '.$x['student_no'].' · '.$x['batch'])?></option><?php endforeach;?></select></label><label>Student number<input name="student_no" maxlength="60" required></label><label>Batch<input name="batch" maxlength="100" placeholder="2026–2027" required></label><button class="btn">Save student details</button></form>
<form method="post" class="card"><?=csrf_field()?><input type="hidden" name="action" value="allocate_proposal"><h2>Assign proposal examiner (Form 4)</h2><label>Student<select name="student_id" required><option value="">Select…</option><?php foreach(users_by_role('student')as $x):?><option value="<?=$x['id']?>"><?=e($x['name'].' · '.$x['department'])?></option><?php endforeach;?></select></label><label>Examiner<select name="evaluator_id" required><option value="">Select…</option><?php foreach(users_by_role('evaluator')as $x):?><option value="<?=$x['id']?>"><?=e($x['name'].' · '.$x['email'])?></option><?php endforeach;?></select></label><button class="btn">Assign examiner</button></form>
<section class="card"><h2>Examiner / evaluator assignments</h2><table><thead><tr><th>Student</th><th>Evaluator</th><th>Stage</th><th></th></tr></thead><tbody><?php foreach(db()->query('SELECT a.*,s.name student_name,e.name evaluator_name FROM rd_allocations a JOIN rd_users s ON s.id=a.student_id JOIN rd_users e ON e.id=a.evaluator_id ORDER BY s.name')->fetchAll()as $a):?><tr><td><?=e($a['student_name'])?></td><td><?=e($a['evaluator_name'])?></td><td><?=e($a['kind'])?></td><td><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="remove_allocation"><input type="hidden" name="allocation_id" value="<?=$a['id']?>"><button class="btn ghost" data-confirm="Remove this allocation? Existing marks will remain.">Remove</button></form></td></tr><?php endforeach;?></tbody></table></section><?php layout_end();
