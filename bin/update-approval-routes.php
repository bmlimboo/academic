<?php
require __DIR__.'/../src/bootstrap.php';
if(PHP_SAPI!=='cli')exit('CLI only.');
$apply=in_array('--apply',$argv,true);$count=0;
foreach(all("SELECT r.*,u.role AS applicant_role FROM requests r JOIN users u ON u.id=r.applicant_id WHERE r.module='substitution' AND r.status IN ('Pending HoD/PL','Pending DAA','Pending President')")as $r){
 $p=json_decode($r['payload'],true);if(isset($p['approval_route']))continue;
 $route=approvalRouteForRole($r['applicant_role']);$target=$route==='faculty_hod_daa'?$r['status']:initialApprovalStatus($route);
 echo '#'.$r['id'].': '.$r['status'].' -> '.$target.' ('.$route.")\n";$count++;
 if(!$apply)continue;
 db()->beginTransaction();try{
  $p['approval_route']=$route;
  $changed=q('UPDATE requests SET payload=?,status=?,updated_at=? WHERE id=? AND status=? AND payload=?',[json_encode($p,JSON_THROW_ON_ERROR),$target,now(),$r['id'],$r['status'],$r['payload']]);
  if($changed->rowCount()!==1)throw new RuntimeException('Request changed during migration. Run again.');
  if($route!=='faculty_hod_daa'){
   audit((int)$r['id'],(int)$r['applicant_id'],'Approval route updated','System migration: '.$route.'. This is routing only, not an approval.');
   q("UPDATE outbox SET status='superseded' WHERE status IN ('queued','failed') AND subject LIKE ?",['Staff Substitution #'.$r['id'].' — %']);
   notifyRequest((int)$r['id'],'Approval route updated');
  }
  db()->commit();
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
// Update unsent legacy notification links; never resend already-sent emails.
if($apply)foreach(all("SELECT id,body FROM outbox WHERE status IN ('queued','failed')")as $mail){$body=preg_replace('#https?://(?:10\\.10\\.140\\.137(?::[0-9]+)?|localhost(?::[0-9]+)?)/#','https://cas.cnr.edu.bt/',$mail['body']);if($body!==$mail['body'])q('UPDATE outbox SET body=? WHERE id=?',[$body,$mail['id']]);}
echo $apply?"Updated $count pending routes. Completed and historical requests unchanged.\n":"Preview: $count pending routes. Run with --apply to save and queue routing notifications.\n";
