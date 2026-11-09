<?php
function researchReady():bool {try{q('SELECT user_id FROM research_students LIMIT 1');return true;}catch(PDOException $e){return false;}}
function researchManager(?array $u):bool{return $u&&!empty($u['active'])&&in_array($u['role'],['admin','daa'],true);}
function researchStudent(array $u):bool{return !empty($u['active'])&&$u['role']==='student';}
function researchAccess(array $u):bool{return !empty($u['active'])&&(researchManager($u)||researchStudent($u)||in_array($u['role'],['faculty','hod','pl'],true));}
function researchCanView(array $u,array $r):bool{return researchAccess($u)&&(researchManager($u)||(int)$u['id']===(int)$r['student_id']||(int)$u['id']===(int)$r['supervisor_id']||(function_exists('rwPL')&&rwPL($u,$r))||(function_exists('rwEvaluator')&&rwEvaluator($u,$r)));}
function researchGet(array $u,int $id):array{$r=one('SELECT * FROM research_projects WHERE id=?',[$id]);if(!$r||!researchCanView($u,$r))throw new RuntimeException('Research project not available.');return $r;}
function researchText(array $p,string $key,int $max=10000):string{$s=required($p,$key);if(mb_strlen($s)>$max)throw new RuntimeException('Please shorten '.str_replace('_',' ',$key).'.');return $s;}
function researchNotify(array $r,string $event,array $ids,bool $daa=false):void{global $config;if($daa)foreach(all("SELECT id FROM users WHERE role='daa' AND active=1")as $u)$ids[]=$u['id'];foreach(array_unique($ids)as $id){$u=one('SELECT email FROM users WHERE id=? AND active=1',[$id]);if($u)q('INSERT INTO outbox(recipient,subject,body,created_at) VALUES(?,?,?,?)',[$u['email'],'Research #'.$r['id'].' — '.$event,$r['title']."\n".$event."\nSign in to view the project: ".rtrim($config['url'],'/').'/?page=rw_access&project='.$r['id'],now()]);}}
function researchPost(?array $u,array $p):int{
 if(!$u||!researchAccess($u)||!researchReady())throw new RuntimeException('Research access or setup required.');
 db()->beginTransaction();try{$action=$p['action'];$id=(int)($p['id']??0);
 if($action==='research_student'){
  if(!researchManager($u)||$u['role']!=='admin')throw new RuntimeException('Admin creates student accounts.');
  $email=researchText($p,'email',190);$name=researchText($p,'name',160);$number=researchText($p,'student_number',60);$dept=$p['department']??'';$programme=$p['programme']??'';
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($dept,departments(),true)||!in_array($programme,programmes(),true))throw new RuntimeException('Check email, department and programme.');
  $existing=one('SELECT * FROM users WHERE email=?',[$email]);
  if($existing){if($existing['role']!=='student')throw new RuntimeException('This email belongs to a staff account.');$sid=$existing['id'];q('UPDATE users SET name=?,department=?,active=1 WHERE id=?',[$name,$dept,$sid]);}
  else{q('INSERT INTO users(name,email,password,role,department) VALUES(?,?,?,?,?)',[$name,$email,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),'student',$dept]);$sid=(int)db()->lastInsertId();}
  if(one('SELECT user_id FROM research_students WHERE user_id=?',[$sid]))q('UPDATE research_students SET student_number=?,programme=? WHERE user_id=?',[$number,$programme,$sid]);else q('INSERT INTO research_students(user_id,student_number,programme) VALUES(?,?,?)',[$sid,$number,$programme]);
  // The existing password-reset flow verifies mailbox ownership before setting a password.
  requestPasswordReset($email,'admin-research-'.$u['id'],true);
 }elseif($action==='research_student_status'){
  if($u['role']!=='admin')throw new RuntimeException('Admin access required.');$sid=(int)($p['student_id']??0);if(!one('SELECT user_id FROM research_students WHERE user_id=?',[$sid])||!in_array($p['active']??'', ['0','1'],true))throw new RuntimeException('Select a registered student and status.');q("UPDATE users SET active=? WHERE id=? AND role='student'",[(int)$p['active'],$sid]);
 }elseif($action==='research_period'){
  if(!researchManager($u))throw new RuntimeException('DAA or Admin access required.');$dept=$p['department']??'';
  foreach(['opens_on','closes_on','due_on']as $key)if(!dateValid($p[$key]??''))throw new RuntimeException('Enter valid timeline dates.');
  if(!in_array($dept,departments(),true)||$p['opens_on']>$p['closes_on']||$p['closes_on']>$p['due_on'])throw new RuntimeException('Registration dates must be ordered and before the project deadline.');
  $periodId=(int)($p['period_id']??0);
  if($periodId){$old=one('SELECT * FROM research_periods WHERE id=?',[$periodId]);if(!$old||$old['department']!==$dept)throw new RuntimeException('The period department cannot be changed.');q('UPDATE research_periods SET title=?,opens_on=?,closes_on=?,due_on=? WHERE id=?',[researchText($p,'title',190),$p['opens_on'],$p['closes_on'],$p['due_on'],$periodId]);}
  else q('INSERT INTO research_periods(title,department,opens_on,closes_on,due_on) VALUES(?,?,?,?,?)',[researchText($p,'title',190),$dept,$p['opens_on'],$p['closes_on'],$p['due_on']]);
 }elseif($action==='research_period_toggle'){
  if(!researchManager($u))throw new RuntimeException('DAA or Admin access required.');q('UPDATE research_periods SET active=1-active WHERE id=?',[(int)$p['period_id']]);
 }elseif($action==='research_delete'){
  if(!researchManager($u))throw new RuntimeException('Only Admin and DAA can delete research projects.');
  $r=researchGet($u,$id);$reason=researchText($p,'reason',2000);
  if(($p['confirmed']??'')!=='1')throw new RuntimeException('Confirm project deletion.');
  $deleted=q('DELETE FROM research_projects WHERE id=? AND revision=?',[$id,(int)($p['revision']??0)]);
  if(!$deleted->rowCount())throw new RuntimeException('Project changed. Refresh before deleting.');
  // Retain the project snapshot and its existing history for an accountable deletion.
  q('INSERT INTO research_updates(project_id,actor_id,kind,details,created_at) VALUES(?,?,?,?,?)',[$id,$u['id'],'Project deleted',json_encode(['reason'=>$reason,'project'=>$r],JSON_THROW_ON_ERROR),now()]);
  q("UPDATE outbox SET status='superseded' WHERE subject LIKE ? AND status IN ('queued','failed')",['Research #'.$id.' — %']);
  $id=0;
 }elseif($action==='research_submit'){
  if(!researchStudent($u))throw new RuntimeException('Sign in with a registered student account.');
  $period=one('SELECT * FROM research_periods WHERE id=?',[(int)($p['period_id']??0)]);$today=date('Y-m-d');
  if(!$period||!$period['active']||$period['department']!==$u['department']||$today<$period['opens_on']||$today>$period['closes_on'])throw new RuntimeException('Proposal registration is closed for this department.');
  $supervisor=one("SELECT * FROM users WHERE id=? AND active=1 AND role IN ('faculty','hod','pl','daa')",[(int)($p['supervisor_id']??0)]);
  if(!$supervisor||$supervisor['department']!==$u['department'])throw new RuntimeException('Choose an active supervisor from your department.');
  $values=[];foreach(['title'=>190,'objectives'=>10000,'methodology'=>10000,'team'=>5000,'outcomes'=>10000]as $key=>$max)$values[]=researchText($p,$key,$max);
  $funding=$p['funding']??'0';if(!is_numeric($funding)||(float)$funding<0||(float)$funding>999999999)throw new RuntimeException('Enter a valid funding amount in Nu.');
  if($id){$old=researchGet($u,$id);if((int)$old['student_id']!==(int)$u['id']||$old['status']!=='Changes requested'||(int)$old['period_id']!==(int)$period['id'])throw new RuntimeException('Only a returned proposal can be resubmitted.');$changed=q("UPDATE research_projects SET supervisor_id=?,title=?,objectives=?,methodology=?,team=?,outcomes=?,funding=?,status='Submitted',revision=revision+1,updated_at=? WHERE id=? AND revision=?",[$supervisor['id'],...$values,$funding,now(),$id,(int)($p['revision']??0)]);if(!$changed->rowCount())throw new RuntimeException('Project changed. Refresh and try again.');}
  else{if(one('SELECT id FROM research_projects WHERE student_id=? AND period_id=?',[$u['id'],$period['id']]))throw new RuntimeException('You already have a proposal for this registration period. Open it from your project list.');q("INSERT INTO research_projects(student_id,supervisor_id,period_id,department,title,objectives,methodology,team,outcomes,funding,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,'Submitted',?,?)",[$u['id'],$supervisor['id'],$period['id'],$u['department'],...$values,$funding,now(),now()]);$id=(int)db()->lastInsertId();}
  q('INSERT INTO research_updates(project_id,actor_id,kind,details,created_at) VALUES(?,?,?,?,?)',[$id,$u['id'],'Proposal submitted',json_encode(array_intersect_key($p,array_flip(['title','objectives','methodology','team','outcomes','funding','period_id','supervisor_id'])),JSON_THROW_ON_ERROR),now()]);$r=researchGet($u,$id);researchNotify($r,'Proposal submitted',[$r['supervisor_id'],$r['student_id']],true);
 }else{
  $r=researchGet($u,$id);if(!researchManager($u)&&!rwPL($u,$r)&&(int)$r['student_id']!==(int)$u['id']&&(int)$r['supervisor_id']!==(int)$u['id'])throw new RuntimeException('Use the assigned evaluation milestones.');$manager=researchManager($u);$supervisor=(int)$r['supervisor_id']===(int)$u['id'];$student=(int)$r['student_id']===(int)$u['id'];$kind='';$details=researchText($p,'details');$amount=0;$link='';$status=$r['status'];
  if($action==='research_decision'){
   if(!$supervisor&&!$manager)throw new RuntimeException('Supervisor or coordinator access required.');$status=$p['status']??'';
   if(!in_array($status,['Approved','Changes requested','Rejected','Completed'],true)||($status==='Completed'?$r['status']!=='Approved':$r['status']!=='Submitted'))throw new RuntimeException('Decision not available at this stage.');$kind=$status;
  }elseif($action==='research_update'){
   $kind=$p['kind']??'';if(!in_array($kind,['Progress','Milestone','Activity','Expenditure','Challenge','Journal article','Conference paper','Report','Dataset','Patent','Other output','Comment'],true))throw new RuntimeException('Choose an update type.');
   if($kind!=='Comment'&&(!$student||$r['status']!=='Approved'))throw new RuntimeException('Only the project student can record progress after approval.');
   if($kind==='Expenditure'){$amount=$p['amount']??'';if(!is_numeric($amount)||(float)$amount<0||(float)$amount>999999999)throw new RuntimeException('Enter a valid expenditure.');}
   $link=trim($p['link']??'');if($link!==''&&(!filter_var($link,FILTER_VALIDATE_URL)||!in_array(parse_url($link,PHP_URL_SCHEME),['https','http'],true)||strlen($link)>2000))throw new RuntimeException('Use an HTTP or HTTPS output link.');
  }else throw new RuntimeException('Unknown research action.');
  $changed=q('UPDATE research_projects SET status=?,revision=revision+1,updated_at=? WHERE id=? AND revision=?',[$status,now(),$id,(int)($p['revision']??0)]);if(!$changed->rowCount())throw new RuntimeException('Project changed. Refresh before saving.');
  q('INSERT INTO research_updates(project_id,actor_id,kind,details,amount,link,created_at) VALUES(?,?,?,?,?,?,?)',[$id,$u['id'],$kind,$details,$amount,$link,now()]);researchNotify($r,$kind,function_exists('rwRecipients')?rwRecipients($r):($student?[$r['supervisor_id']]:[$r['student_id'],$r['supervisor_id']]));
 }
 db()->commit();return $id;
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
