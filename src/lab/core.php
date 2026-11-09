<?php
function labManager(?array $u):bool{return $u&&!empty($u['active'])&&in_array($u['role'],['admin','lab'],true);}
function labRooms():array{return ['Nutrient Laboratory','Analytical Laboratory','Bio-Technology Laboratory','Common Laboratory','Museum Room','Entomology Laboratory','Molecular Biology Laboratory','eDNA Laboratory','Processing & Post Harvest Laboratory','Other'];}
function labCriteria():array{return ['Academic Practical','Food Product Development','Assignment','Research','Other'];}
function labReady():bool{try{q('SELECT id FROM lab_booking_lock LIMIT 1');return true;}catch(Throwable $e){return false;}}
function labRequire(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function labText(array $p,string $key,int $max=240):string{$v=trim((string)($p[$key]??''));labRequire($v!==''&&mb_strlen($v)<=$max,'Complete '.str_replace('_',' ',$key).' (maximum '.$max.' characters).');return $v;}
function labConflict(array $r,int $except=0):?array{return one("SELECT id,laboratory,practical_date,start_time,end_time,status FROM lab_bookings WHERE LOWER(laboratory)=LOWER(?) AND practical_date=? AND status IN ('Pending','Approved') AND start_time<? AND end_time>? AND id<>?",[$r['laboratory'],$r['practical_date'],$r['end_time'],$r['start_time'],$except])?:null;}
function labCheckConflict(array $r,int $except=0):void{if($c=labConflict($r,$except))throw new RuntimeException('Booking #'.$c['id'].' — '.$c['laboratory'].' on '.$c['practical_date'].' '.$c['start_time'].'–'.$c['end_time'].' is '.$c['status'].'. Please choose another time or laboratory.');}
function labDetails(array $r):string{return "Tutor: {$r['tutor']}\nPractical topic: {$r['topic']}\nCriteria: {$r['criteria']}\nModule: {$r['module_code']} — {$r['module_name']}\nProgramme: {$r['programme']}\nYear: {$r['study_year']}\nLaboratory: {$r['laboratory']}\nPractical date: {$r['practical_date']}\nTime: {$r['start_time']}–{$r['end_time']} (Bhutan time)\nMaterials required: {$r['materials']}";}
function labMail(string $to,string $subject,string $body):void{q('INSERT INTO outbox(recipient,subject,body,created_at) VALUES(?,?,?,?)',[$to,$subject,$body,now()]);}
function labPost(?array $u,array $p):int{
 global $config;labRequire(labReady(),'Lab booking setup is not complete. Contact ICT.');$action=$p['action']??'';
 if($action==='lab_submit'){
  $r=[];foreach(['tutor'=>160,'email'=>190,'topic'=>240,'module_name'=>240,'module_code'=>80,'materials'=>10000]as $key=>$max)$r[$key]=labText($p,$key,$max);
  $r['email']=strtolower($r['email']);labRequire((bool)filter_var($r['email'],FILTER_VALIDATE_EMAIL),'Enter a valid email address. Adjunct and external email addresses are accepted.');
  foreach(['criteria'=>labCriteria(),'laboratory'=>labRooms(),'programme'=>examProgrammes(),'study_year'=>['I','II','III','IV','V','VI']]as $key=>$options){$r[$key]=labText($p,$key);labRequire(in_array($r[$key],$options,true),'Select a valid '.str_replace('_',' ',$key).'.');if($r[$key]==='Other')$r[$key]=labText($p,$key.'_other',200);}
  $r['practical_date']=labText($p,'practical_date',10);$r['start_time']=labText($p,'start_time',5);$r['end_time']=labText($p,'end_time',5);
  labRequire(dateValid($r['practical_date'])&&timeValid($r['start_time'])&&timeValid($r['end_time']),'Enter a valid date and times.');
  labRequire($r['end_time']>$r['start_time'],'End time must be later than start time on the same day.');labRequire($r['practical_date'].' '.$r['start_time']>date('Y-m-d H:i'),'Choose a future practical date and start time.');
  $key=(string)($p['submission_key']??'');labRequire(strlen($key)===64&&hash_equals($_SESSION['lab_submission_key']??'',$key),'Refresh the form before submitting.');
 }elseif($action==='lab_delete'){labRequire($u&&!empty($u['active'])&&$u['role']==='admin','Admin access required to delete bookings.');labRequire(($p['confirm_delete']??'')==='1','Confirm deletion of this booking.');$reason=labText($p,'reason',2000);
 }else{labRequire($action==='lab_decide'&&labManager($u),'Laboratory technician or Admin access required.');$target=labText($p,'decision',20);labRequire(in_array($target,['Approved','Rejected'],true),'Choose Approve or Reject.');$handled=labText($p,'handled_by',160);$note=trim((string)($p['decision_note']??''));labRequire(mb_strlen($note)<=4000,'Decision note is too long.');labRequire($target!=='Rejected'||$note!=='','Enter a reason for rejection.');}
 db()->beginTransaction();try{
  // One write lock serializes conflict checks and inserts on MySQL and SQLite.
  q('UPDATE lab_booking_lock SET revision=revision+1 WHERE id=1');
  if($action==='lab_delete'){
   $id=(int)($p['id']??0);$r=one('SELECT * FROM lab_bookings WHERE id=?',[$id]);labRequire($r&&$r['status']!=='Deleted','Booking not available.');labRequire($r['status']===($p['expected_status']??''),'Booking changed. Refresh before deleting.');
   q('INSERT INTO lab_booking_deletions(booking_id,actor_id,reason,snapshot,created_at) VALUES(?,?,?,?,?)',[$id,$u['id'],$reason,json_encode($r),now()]);
   q("UPDATE lab_bookings SET status='Deleted' WHERE id=?",[$id]);
   q("UPDATE outbox SET status='superseded' WHERE subject LIKE ? AND status IN ('queued','failed')",['Lab booking #'.$id.' — %']);
   labMail($r['email'],'Lab booking #'.$id.' — Cancelled',"This laboratory booking has been removed by the administrator and no longer reserves the laboratory.\n\n".labDetails($r)."\n\nReason: ".$reason);
   db()->commit();return 0;
  }
  if($action==='lab_submit'){
   $existing=one('SELECT id FROM lab_bookings WHERE submission_key=?',[$key]);if($existing){db()->commit();return (int)$existing['id'];}
   $rate=hash('sha256','lab:'.($_SERVER['REMOTE_ADDR']??'local'));labRequire((int)q('SELECT COUNT(*) FROM login_attempts WHERE identity=? AND attempted_at>?',[$rate,time()-3600])->fetchColumn()<20,'Too many requests from this network. Try later or contact the laboratory.');
   labCheckConflict($r);$r['created_at']=now();$r['submission_key']=$key;
   q('INSERT INTO lab_bookings('.implode(',',array_keys($r)).') VALUES('.implode(',',array_fill(0,count($r),'?')).')',array_values($r));$id=(int)db()->lastInsertId();
   q('INSERT INTO login_attempts(identity,attempted_at) VALUES(?,?)',[$rate,time()]);
   $url=rtrim($config['url'],'/').'/?page=lab_manage&id='.$id;
   labMail('sciencelab.cnr@rub.edu.bt','Lab booking #'.$id.' — Pending approval',"A laboratory booking requires your review.\n\n".labDetails($r)."\nTutor email: ".$r['email']."\n\nClick here to review and approve or reject (sign in with the laboratory account):\n".$url);
   labMail($r['email'],'Lab booking #'.$id.' — Request received',"Your laboratory booking request has been received and is pending approval. It is not yet confirmed.\n\n".labDetails($r)."\n\nFor emergencies: +975 17 500 970 · sciencelab.cnr@rub.edu.bt");
  }else{
   $id=(int)($p['id']??0);$r=one('SELECT * FROM lab_bookings WHERE id=?',[$id]);labRequire((bool)$r,'Booking not found.');labRequire($r['status']==='Pending','This booking has already been decided. Refresh the list.');
   if($target==='Approved'){labRequire($r['practical_date'].' '.$r['start_time']>date('Y-m-d H:i'),'This session has already started. Reject it and ask the tutor to book a future session.');labCheckConflict($r,$id);}
   q('UPDATE lab_bookings SET status=?,decided_at=?,decided_by=?,handled_by=?,decision_note=? WHERE id=?',[$target,now(),$u['id'],$handled,$note,$id]);
   labMail($r['email'],'Lab booking #'.$id.' — '.$target,($target==='Approved'?'Your practical session is approved and booked.':'Your laboratory booking request has been rejected. Please review the reason below and submit a new request if needed.')."\n\n".labDetails($r)."\n\nHandled by: ".$handled."\nDecision note: ".($note?:'None')."\n\nFor emergencies: +975 17 500 970 · sciencelab.cnr@rub.edu.bt");
  }
  db()->commit();return $id;
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
