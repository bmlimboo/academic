<?php
function requestNotificationRows(int $id):array{return all("SELECT * FROM outbox WHERE subject LIKE ? OR subject=? OR subject=? ORDER BY id DESC",['Staff Substitution #'.$id.' — %','Teaching substitution assignment #'.$id,'Request for Issuance of Office Order – Staff Substitution #'.$id]);}
function cancelRequestNotifications(int $id):void{q("UPDATE outbox SET status='superseded' WHERE status IN ('queued','failed') AND (subject LIKE ? OR subject=?)",['Staff Substitution #'.$id.' — %','Teaching substitution assignment #'.$id]);}
function adminRequestAction(array $actor,int $id,string $action,string $reason=''):void{
 if($actor['role']!=='admin'||empty($actor['active']))throw new RuntimeException('Administrator access required.');
 $r=one('SELECT * FROM requests WHERE id=?'.(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$id]);if(!$r||$r['module']!=='substitution'||$r['status']==='Deleted')throw new RuntimeException('Active substitution request not found.');
 if($action==='delete_request'){
  if(trim($reason)==='')throw new RuntimeException('Enter the reason for deleting this request.');
  $changed=q("UPDATE requests SET status='Deleted',updated_at=? WHERE id=? AND status=?",[now(),$id,$r['status']]);if($changed->rowCount()!==1)throw new RuntimeException('Request changed. Refresh before deleting.');
  cancelRequestNotifications($id);q("UPDATE outbox SET status='superseded' WHERE status IN ('queued','failed') AND subject=?",['Request for Issuance of Office Order – Staff Substitution #'.$id]);audit($id,(int)$actor['id'],'Deleted','Previous status: '.$r['status'].'. Reason: '.trim($reason));
 }elseif($action==='resend_request_notifications'){
  $last=one("SELECT created_at FROM history WHERE request_id=? AND action='Notifications requeued' ORDER BY id DESC LIMIT 1",[$id]);if($last&&strtotime($last['created_at'])>time()-60)throw new RuntimeException('Wait one minute before retrying notifications again.');
  cancelRequestNotifications($id);notifyRequest($id,'Notification reminder');audit($id,(int)$actor['id'],'Notifications requeued','Administrator requested notifications using current directory and DAA office addresses.');
 }else throw new RuntimeException('Unsupported action.');
}
