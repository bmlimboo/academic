<?php
require_once __DIR__.'/exam-controls.php';
function examTypes():array{return ['End-semester Exam','Mid-semester Exam','Special Exam','Re-assessment Exam'];}
function examTypeLabel(string $type):string{return match($type){'Semester-end examination'=>'End-semester Exam','Mid-semester examination'=>'Mid-semester Exam','Special examination'=>'Special Exam',default=>$type};}

function examReady():bool {try{q('SELECT deleted_at FROM exam_windows LIMIT 1');q('SELECT programme FROM exam_expected LIMIT 1');q('SELECT outbox_id FROM exam_mail_windows LIMIT 1');return true;}catch(PDOException $e){return false;}}
function examManager(?array $u):bool{return $u&&!empty($u['active'])&&in_array($u['role'],['admin','exam'],true);}
function examRequireManager(?array $u):void{if(!examManager($u))throw new RuntimeException('Exam Cell or administrator access required.');}
function examAudit(int $actor,string $action,array $details):void{q('INSERT INTO exam_audit(actor_id,action,details,created_at) VALUES(?,?,?,?)',[$actor,$action,json_encode($details),now()]);}
function examLock(int $id):array{q('UPDATE exam_windows SET id=id WHERE id=?',[$id]);$w=one('SELECT * FROM exam_windows WHERE id=?',[$id]);if(!$w||!empty($w['deleted_at']))throw new RuntimeException('This examination period is unavailable or has been deleted.');return $w;}
function examTeachers(bool $notifiable=false):array{return array_values(array_filter(teachingDirectory(),fn($u)=>!empty($u['active'])&&(!$notifiable||!empty(examStaffPreference((int)$u['id'])['enabled']))));}
function examDateTime(string $value):string{$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value);if(!$d||$d->format('Y-m-d\TH:i')!==$value)throw new RuntimeException('Enter valid opening and closing dates and times.');return $d->format('Y-m-d H:i:s');}
function examMail(string $email,string $subject,string $body,?int $windowId=null):void{q('INSERT INTO outbox(recipient,subject,body,created_at) VALUES(?,?,?,?)',[$email,$subject,$body,now()]);$mid=(int)db()->lastInsertId();if($windowId===null&&preg_match('/[?]page=exam_submit&exam_id=(\d+)(?:\s|$)/',$body,$match))$windowId=(int)$match[1];if($windowId!==null)q('INSERT INTO exam_mail_windows(outbox_id,window_id) VALUES(?,?)',[$mid,$windowId]);}

function examOnce(string $key):bool{if(one('SELECT event_key FROM exam_events WHERE event_key=?',[$key]))return false;q('INSERT INTO exam_events(event_key,created_at) VALUES(?,?)',[$key,now()]);return true;}
/** Caller holds a write lock on this examination, so event keys and recipients are atomic. */
function examWindowNotifications(array $w,?string $at=null):void{
 global $config;$at??=now();if(!$w['published']||!empty($w['deleted_at']))return;$id=(int)$w['id'];$link=rtrim($config['url'],'/').'/?page=exam_submit&exam_id='.$id;
 $label=$w['academic_year'].' · Semester '.$w['semester'].' · '.$w['exam_type'];
 if($at>=$w['opens_at']&&$at<$w['closes_at']&&examOnce("exam:$id:open"))foreach(examTeachers(true)as $u){
  examMail($u['email'],'Notification: Question Paper Submission Now Open',"Dear Teaching Staff,\n\nThe question paper submission portal is now open for $label. All concerned teaching staff are requested to upload their respective module question papers in PDF format within the prescribed submission period.\n\nSubmission Opens: {$w['opens_at']} (Bhutan time)\nSubmission Deadline: {$w['closes_at']} (Bhutan time)\nSubmission Portal: $link\n\nPlease ensure that the correct module code, module title, department, PDF file and staff details are provided. \n\nRe-submissions and submission after the deadline will not be accepted through the regular form. Please complete submissions within the stipulated period.\n\nFor queries or technical assistance, contact the Exam Cell.\n\nThank you for your cooperation.\nExam Cell\n\nThis is an automated notification. Please do not reply to this email.");
 }
 $phase=$at>=$w['closes_at']?'closed':($at>=$w['opens_at']&&strtotime($at)>=strtotime($w['closes_at'])-172800?'reminder':null);
 if(!$phase||!examOnce("exam:$id:$phase:batch"))return;
 $by=[];foreach(examTeachers(true)as $u){if(one('SELECT p.id FROM exam_papers p JOIN exam_expected e ON e.id=p.expected_id WHERE e.window_id=? AND e.staff_id=? AND p.active_key IS NOT NULL AND (?=0 OR (p.is_late=0 AND p.submitted_at<?))',[$id,$u['id'],$phase==='closed'?1:0,$w['closes_at']]))continue;$by[$u['id']]=['u'=>$u,'modules'=>['No paper submitted for this examination']];}
 foreach($by as $sid=>$entry){if(!examOnce("exam:$id:$phase:$sid"))continue;$u=$entry['u'];$modules=implode("\n",$entry['modules']);
 $body=$phase==='reminder'?"This is a gentle reminder that you have pending question papers for printing. Please upload the papers in PDF format on or before the deadline.":"The deadline for submitting question papers for printing has passed, and our records indicate that your submission is still pending. Please contact Exam Cell at the earliest. The regular portal is closed. Late submissions require Department Head and DAA approval and Exam Cell must enable the late-submission facility.";
 examMail($u['email'],$phase==='reminder'?'Reminder: Pending Question Paper Submission':'Submission Deadline Closed: Question Paper Not Submitted',"Dear {$u['name']},\n\n$body\n\nExamination: $label\nPending modules:\n$modules\n\nSubmission Deadline: {$w['closes_at']} (Bhutan time)\nSubmission Portal: $link\n\nThank you for your cooperation.\nRegards,\nExam Cell\n\nThis is an automated notification. Please do not reply to this email.");
 }
}
function examRunNotifications():void{if(!examReady())return;foreach(all('SELECT id FROM exam_windows WHERE published=1 AND deleted_at IS NULL')as $item){db()->beginTransaction();try{q('UPDATE exam_windows SET id=id WHERE id=?',[$item['id']]);$w=one('SELECT * FROM exam_windows WHERE id=?',[$item['id']]);if($w&&!$w['deleted_at'])examWindowNotifications($w);db()->commit();}catch(Throwable $e){db()->rollBack();throw $e;}}}
function examValidatePdf(array $f):void{if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($f['size']??0)<=0||$f['size']>10*1024*1024)throw new RuntimeException('Choose a PDF no larger than 10 MB for each required upload.');if(strtolower(pathinfo($f['name'],PATHINFO_EXTENSION))!=='pdf'||(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'])!=='application/pdf'||file_get_contents($f['tmp_name'],false,null,0,5)!=='%PDF-')throw new RuntimeException('Only PDF files are accepted.');}
function examSubmissionContext(array $p):array{
 $w=examLock((int)($p['window_id']??0));if(!$w['published'])throw new RuntimeException('Submission is not open.');
 $late=(string)($p['late']??'')==='1';$at=now();if($late){if(!$w['late_enabled']||$at<$w['closes_at'])throw new RuntimeException('Late submission has not been enabled by Exam Cell.');}elseif($at<$w['opens_at']||$at>=$w['closes_at'])throw new RuntimeException('The regular submission period is closed.');
 $u=one('SELECT * FROM users WHERE id=? AND active=1',[(int)($p['staff_id']??0)]);
 if(!$u||!in_array((int)$u['id'],array_map(fn($s)=>(int)$s['id'],examTeachers()),true)||strcasecmp(trim($p['email']??''),$u['email'])!==0)throw new RuntimeException('Select an active teaching staff member and enter their exact registered email.');
 $programme=required($p,'programme');$dept=examProgrammeDepartment($programme);if(($p['department']??'')!==$dept)throw new RuntimeException('The programme does not belong to the selected department.');

 $code=strtoupper(required($p,'module_code'));if(!preg_match('/^[A-Z0-9_-]{1,50}$/',$code))throw new RuntimeException('Module code must contain letters, numbers, hyphens or underscores only.');$title=required($p,'module_title');if(mb_strlen($title)>190)throw new RuntimeException('Module title is too long.');
 if(one("SELECT p.id FROM exam_expected e JOIN exam_papers p ON p.active_key=e.id WHERE e.window_id=? AND e.staff_id=? AND e.module_code=? AND e.programme=''",[$w['id'],$u['id'],$code]))throw new RuntimeException('An earlier paper for this module has no programme recorded. Contact Exam Cell to correct that record before submitting again.');
 $e=one('SELECT * FROM exam_expected WHERE window_id=? AND staff_id=? AND programme=? AND module_code=?',[$w['id'],$u['id'],$programme,$code]);
 if(!$e){q('INSERT INTO exam_expected(window_id,staff_id,department,programme,module_code,module_title) VALUES(?,?,?,?,?,?)',[$w['id'],$u['id'],$dept,$programme,$code,$title]);$e=one('SELECT * FROM exam_expected WHERE id=?',[(int)db()->lastInsertId()]);}
 if(one('SELECT id FROM exam_papers WHERE active_key=?',[$e['id']]))throw new RuntimeException('This paper has already been submitted. Contact Exam Cell and DAA if a replacement is required.');
 q('UPDATE exam_expected SET department=?,module_title=? WHERE id=?',[$dept,$title,$e['id']]);$e['department']=$dept;$e['module_title']=$title;
 if(empty($p['confirmed']))throw new RuntimeException('Confirm that the information and question paper are correct.');return [$w,$u,$e,$late];
}
function examPost(?array $actor,string $action,array $p):string{
 if(!examReady())throw new RuntimeException('Run the Exam Cell database migration first.');
 $paths=[];db()->beginTransaction();try{
 if($action==='exam_submit'){
  [$w,$u,$e,$late]=examSubmissionContext($p);examValidatePdf($_FILES['paper']??[]);if($late)examValidatePdf($_FILES['approval']??[]);
  foreach($late?['paper','approval']:['paper']as $field){$key=bin2hex(random_bytes(24));$path=__DIR__.'/../storage/uploads/'.$key;if(!move_uploaded_file($_FILES[$field]['tmp_name'],$path))throw new RuntimeException('Unable to store the PDF. Contact ICT.');$paths[$field]=$path;$keys[$field]=$key;}
  q('INSERT INTO exam_papers(expected_id,active_key,paper_path,approval_path,is_late,staff_name,staff_email,submitted_at,metadata) VALUES(?,?,?,?,?,?,?,?,?)',[$e['id'],$e['id'],$keys['paper'],$keys['approval']??null,$late?1:0,$u['name'],$u['email'],now(),json_encode(['module_code'=>$e['module_code'],'module_title'=>$e['module_title'],'department'=>$e['department'],'programme'=>$e['programme']])]);
  $id=(int)db()->lastInsertId();examAudit(0,'Question paper submitted',['paper'=>$id,'staff'=>$u['id'],'identity'=>'Public form: name/email match, not authenticated']);
  examMail($u['email'],'Question Paper Submission Confirmation',"Dear {$u['name']},\n\nYour question paper has been received.\nProgramme: {$e['programme']}\nModule: {$e['module_code']} — {$e['module_title']}\nExamination: {$w['academic_year']} / {$w['semester']} / {$w['exam_type']}\nSubmitted: ".now()." (Bhutan time)\nReceipt: #$id\n\nIf you did not submit this paper or need to correct it, contact Exam Cell and DAA.\n\nExam Cell",(int)$w['id']);$message='Question paper received. A confirmation email has been queued. No further submission is permitted for this module unless Exam Cell removes the earlier submission.';
 }else{
  examRequireManager($actor);$aid=(int)$actor['id'];
  if($action==='exam_edit_period'){$message=examEditPeriod($actor,$p);
  }elseif($action==='exam_staff_preference'){$message=examSetStaffPreference($actor,$p);
  }elseif($action==='exam_window'){
   $start=examDateTime(required($p,'opens_at'));$end=examDateTime(required($p,'closes_at'));if($end<=$start)throw new RuntimeException('Deadline must be after opening time.');
   $sem=required($p,'semester');if(!in_array($sem,['I','II'],true))throw new RuntimeException('Select semester I or II.');$type=examTypeLabel(required($p,'exam_type'));if(!in_array($type,examTypes(),true))throw new RuntimeException('Select a valid examination type.');
   if(mb_strlen(required($p,'academic_year'))>40||mb_strlen($type)>160)throw new RuntimeException('Academic year or examination type is too long.');
   q('INSERT INTO exam_windows(academic_year,semester,exam_type,opens_at,closes_at,created_at) VALUES(?,?,?,?,?,?)',[required($p,'academic_year'),$sem,$type,$start,$end,now()]);examAudit($aid,'Examination created',['id'=>db()->lastInsertId()]);$message='Examination created as draft. Review the dates, then publish to open or schedule submissions.';
  }elseif($action==='exam_delete_window'){
   $w=examLock((int)($p['window_id']??0));$reason=required($p,'reason');q('UPDATE exam_windows SET published=0,late_enabled=0,deleted_at=?,deleted_by=?,delete_reason=? WHERE id=?',[now(),$aid,$reason,$w['id']]);
   q("UPDATE outbox SET status='superseded' WHERE status IN ('queued','failed') AND id IN (SELECT outbox_id FROM exam_mail_windows WHERE window_id=?)",[$w['id']]);examAudit($aid,'Examination period deleted',['window'=>$w['id'],'reason'=>$reason]);$message='Submission period deleted. Uploads are closed and unsent notifications cancelled. Existing papers remain in the restricted archive. Emails already sent cannot be recalled.';
  }elseif($action==='exam_window_dates'){
   $w=examLock((int)$p['window_id']);if($w['published'])throw new RuntimeException('Published deadlines are fixed to match the notifications.');$start=examDateTime(required($p,'opens_at'));$end=examDateTime(required($p,'closes_at'));if($end<=$start)throw new RuntimeException('Deadline must be after opening.');q('UPDATE exam_windows SET opens_at=?,closes_at=? WHERE id=?',[$start,$end,$w['id']]);examAudit($aid,'Draft dates changed',['window'=>$w['id'],'previous_open'=>$w['opens_at'],'previous_deadline'=>$w['closes_at'],'opens_at'=>$start,'closes_at'=>$end]);$message='Draft dates updated.';
  }elseif($action==='exam_publish'){
   $w=examLock((int)$p['window_id']);if($w['published']||$w['closes_at']<=now())throw new RuntimeException('Set a future deadline before publishing.');q('UPDATE exam_windows SET published=1 WHERE id=?',[$w['id']]);$w['published']=1;examWindowNotifications($w);examAudit($aid,'Examination published',['window'=>$w['id']]);$message='Published. Opening notification will be queued when the submission period opens.';
  }elseif($action==='exam_late'){
   $w=examLock((int)$p['window_id']);if(!$w['published']||now()<$w['closes_at'])throw new RuntimeException('Late submissions can be enabled only after the regular deadline.');q('UPDATE exam_windows SET late_enabled=? WHERE id=?',[empty($p['enabled'])?0:1,$w['id']]);examAudit($aid,'Late submission access changed',['window'=>$w['id'],'enabled'=>!empty($p['enabled'])]);$message='Late-submission setting updated.';
  }elseif($action==='exam_delete_paper'){
   $row=one('SELECT p.*,e.window_id FROM exam_papers p JOIN exam_expected e ON e.id=p.expected_id WHERE p.id=?',[(int)$p['paper_id']]);if(!$row)throw new RuntimeException('Paper not found.');examLock((int)$row['window_id']);$reason=required($p,'reason');$changed=q('UPDATE exam_papers SET active_key=NULL,deleted_at=?,deleted_by=?,delete_reason=? WHERE id=? AND active_key IS NOT NULL',[now(),$aid,$reason,$row['id']]);if(!$changed->rowCount())throw new RuntimeException('Paper already removed.');examAudit($aid,'Question paper removed',['paper'=>$row['id'],'reason'=>$reason]);$message='Paper removed from active submissions; the archived version is retained. The tutor can submit again during an allowed submission period.';
  }elseif($action==='exam_requisition_edit'||$action==='exam_requisition_delete'){
   $id=(int)($p['request_id']??0);$r=one('SELECT * FROM requests WHERE id=?'.(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$id]);if(!$r||$r['module']!=='exam'||$r['status']==='Deleted')throw new RuntimeException('Active Exam Cell requisition not found.');$reason=required($p,'reason');
   if($action==='exam_requisition_delete'){q("UPDATE requests SET status='Deleted',updated_at=? WHERE id=?",[now(),$id]);q("UPDATE outbox SET status='superseded' WHERE status IN ('queued','failed') AND subject LIKE ?",['Exam Cell Requisition #'.$id.' — %']);audit($id,$aid,'Deleted',$reason);}
   else{$payload=json_decode($r['payload'],true);$oldPurpose=$payload['purpose']??'';$payload['purpose']=required($p,'purpose');q('UPDATE requests SET payload=?,updated_at=? WHERE id=?',[json_encode($payload),now(),$id]);audit($id,$aid,'Requisition details edited',$reason.' Previous purpose: '.$oldPurpose);}
   $message='Requisition updated. Audit history retained.';
  }elseif($action==='exam_edit_expected'){
   $e=one('SELECT * FROM exam_expected WHERE id=?',[(int)$p['expected_id']]);if(!$e)throw new RuntimeException('Assignment not found.');$w=examLock((int)$e['window_id']);$programme=required($p,'programme');$dept=examProgrammeDepartment($programme);q('UPDATE exam_expected SET department=?,programme=?,module_title=? WHERE id=?',[$dept,$programme,required($p,'module_title'),$e['id']]);q('UPDATE exam_papers SET metadata=? WHERE active_key=?',[json_encode(['module_code'=>$e['module_code'],'module_title'=>$p['module_title'],'department'=>$dept,'programme'=>$programme]),$e['id']]);examAudit($aid,'Module details updated',['expected'=>$e['id'],'before'=>$e,'department'=>$dept,'title'=>$p['module_title']]);$message='Module details updated.';
  }else throw new RuntimeException('Unknown Exam Cell action.');
 }
 db()->commit();return $message;
 }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();foreach($paths as $path)if(is_file($path))unlink($path);throw $ex;}
}
function examDownload(?array $u):never{
 examRequireManager($u);$r=one('SELECT p.*,e.module_code,w.academic_year,w.exam_type FROM exam_papers p JOIN exam_expected e ON e.id=p.expected_id JOIN exam_windows w ON w.id=e.window_id WHERE p.id=?',[(int)($_GET['id']??0)]);if(!$r)throw new RuntimeException('Paper not found.');$snapshot=json_decode($r['metadata']??'null',true);if(is_array($snapshot))$r=array_merge($r,$snapshot);$field=($_GET['file']??'')==='approval'?'approval_path':'paper_path';$key=$r[$field];if(!$key||!preg_match('/^[a-f0-9]{48}$/',$key))throw new RuntimeException('PDF not available.');$path=__DIR__.'/../storage/uploads/'.$key;if(!is_file($path))throw new RuntimeException('PDF not available.');examAudit((int)$u['id'],'PDF downloaded',['paper'=>$r['id'],'file'=>$field]);header('Content-Type: application/pdf');header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($r['academic_year'].'-'.$r['exam_type'].'-'.($r['programme']??'').'－'.$r['module_code'].'-'.$r['staff_name'].'-'.$field.'.pdf'));readfile($path);exit;
}
