<?php
function staffMetadataReady():bool{try{q('SELECT user_id FROM staff_profiles LIMIT 1');return true;}catch(PDOException $e){return false;}}
function staffProfile(int $id):array{return staffMetadataReady()?(one('SELECT * FROM staff_profiles WHERE user_id=?',[$id])?:['staff_type'=>'Regular','status_reason'=>'']):['staff_type'=>'Regular','status_reason'=>''];}
function saveStaffProfile(int $id,array $p):void{
 if(!staffMetadataReady()){if(isset($p['staff_type']))throw new RuntimeException('Run bin/migrate.php before saving staff classification.');return;}
 $old=staffProfile($id);$type=$p['staff_type']??$old['staff_type'];$reason=trim($p['status_reason']??$old['status_reason']);
 if(!in_array($type,['Regular','Adjunct'],true)||mb_strlen($reason)>1000)throw new RuntimeException('Select Regular or Adjunct and keep the status reason below 1000 characters.');
 if(one('SELECT user_id FROM staff_profiles WHERE user_id=?',[$id]))q('UPDATE staff_profiles SET staff_type=?,status_reason=? WHERE user_id=?',[$type,$reason,$id]);else q('INSERT INTO staff_profiles(user_id,staff_type,status_reason) VALUES(?,?,?)',[$id,$type,$reason]);
}
function transferPendingDepartment(int $id,string $old,string $new,int $actor):void{
 if($old===$new)return;
 foreach(all("SELECT id FROM requests WHERE applicant_id=? AND module='substitution' AND status='Pending HoD/PL'",[$id])as $r){
  $changed=q("UPDATE requests SET department=?,updated_at=? WHERE id=? AND status='Pending HoD/PL'",[$new,now(),$r['id']]);
  if($changed->rowCount()===1)audit((int)$r['id'],$actor,'Department reassigned','Awaiting HoD approval: '.$old.' → '.$new);
 }
}
function setStaffStatus(array $actor,int $id,bool $active,string $reason=''):void{
 if($actor['role']!=='admin')throw new RuntimeException('Administrator access required.');
 if($id===(int)$actor['id'])throw new RuntimeException('Another administrator must change your account status.');
 $old=one("SELECT * FROM users WHERE id=? AND role NOT IN ('student','guest')",[$id]);if(!$old)throw new RuntimeException('Staff entry not found.');
 saveStaffProfile($id,['status_reason'=>$reason]);q('UPDATE users SET active=? WHERE id=?',[$active?1:0,$id]);
 if(attReady()){
  if(!$active){q('UPDATE tutor_registered_devices SET active=0,revoked_at=? WHERE staff_id=?',[now(),$id]);q('UPDATE tutor_enrolment_links SET used_at=? WHERE staff_id=? AND used_at IS NULL',[time(),$id]);}
  attAudit((int)$actor['id'],$active?'Staff enabled':'Staff disabled',$id,['reason'=>$reason]);
 }
}
function filteredStaff(array $p):array{
 $name=trim($p['staff_search']??'');$dept=$p['staff_department']??'';$status=$p['staff_status']??'';
 return array_values(array_filter(all("SELECT * FROM users WHERE role NOT IN ('student','guest') ORDER BY name"),fn($u)=>($name===''||mb_stripos($u['name'].' '.$u['email'],$name)!==false)&&($dept===''||$u['department']===$dept)&&($status===''||(int)$u['active']===($status==='Active'?1:0))));
}
function approvalRouteForRole(string $role):string{return match($role){'hod'=>'hod_daa','daa'=>'daa_president',default=>'faculty_hod_daa'};}
function initialApprovalStatus(string $route):string{return match($route){'hod_daa'=>'Pending DAA','daa_president'=>'Pending President',default=>'Pending HoD/PL'};}
function requestApprovalRoute(array $r):string{$p=json_decode($r['payload']??'{}',true);return $p['approval_route']??'faculty_hod_daa';}
function substitutionActions(array $u,array $r):array{
 if(empty($u['active'])||(int)$r['applicant_id']===(int)$u['id']||str_starts_with($r['status'],'Historical'))return [];
 $route=requestApprovalRoute($r);
 if($u['role']==='admin')return match(true){
  $route==='faculty_hod_daa'&&$r['status']==='Pending HoD/PL'=>['Pending DAA'=>'Approve HoD stage (Admin)','Rejected by HoD/PL'=>'Reject HoD stage (Admin)'],
  in_array($route,['faculty_hod_daa','hod_daa'],true)&&$r['status']==='Pending DAA'=>['Approved'=>'Approve DAA stage (Admin)','Rejected by DAA'=>'Reject DAA stage (Admin)'],
  $route==='daa_president'&&$r['status']==='Pending President'=>['Approved'=>'Approve President stage (Admin)','Rejected by President'=>'Reject President stage (Admin)'],default=>[]};
 if($route==='faculty_hod_daa'&&$r['status']==='Pending HoD/PL'&&$u['role']==='hod'&&$u['department']!==''&&$u['department']===$r['department'])return ['Pending DAA'=>'Approve as HoD','Rejected by HoD/PL'=>'Reject as HoD'];
 if(in_array($route,['faculty_hod_daa','hod_daa'],true)&&$r['status']==='Pending DAA'&&$u['role']==='daa')return ['Approved'=>'Approve as DAA','Rejected by DAA'=>'Reject as DAA'];
 if($route==='daa_president'&&$r['status']==='Pending President'&&$u['role']==='president')return ['Approved'=>'Approve as President','Rejected by President'=>'Reject as President'];
 return [];
}
function approvalStages(array $r):array{
 $route=requestApprovalRoute($r);$blank=['status'=>'Pending','name'=>'—','date'=>'—'];$na=['status'=>'Not applicable','name'=>'—','date'=>'—'];
 $stages=['HoD'=>$route==='faculty_hod_daa'?$blank:$na,'DAA'=>$route==='daa_president'?$na:$blank];
 if($route==='daa_president')$stages['President']=$blank;
 $final=$route==='daa_president'?'President':'DAA';
 $known=match($r['status']){'Pending DAA'=>$route==='faculty_hod_daa'?['HoD'=>'Approved']:[],'Approved'=>$route==='faculty_hod_daa'?['HoD'=>'Approved','DAA'=>'Approved']:[$final=>'Approved'],'Rejected by HoD/PL'=>['HoD'=>'Rejected'],'Rejected by DAA'=>$route==='faculty_hod_daa'?['HoD'=>'Approved','DAA'=>'Rejected']:['DAA'=>'Rejected'],'Rejected by President'=>['President'=>'Rejected'],default=>[]};
 foreach($known as $label=>$status)if(isset($stages[$label])&&$stages[$label]['status']!=='Not applicable')$stages[$label]=['status'=>$status,'name'=>'Not recorded','date'=>'Not recorded'];
 if(str_starts_with($r['status'],'Historical'))foreach($stages as &$stage)$stage['status']='Not recorded';unset($stage);
 foreach(all("SELECT h.*,u.name FROM history h LEFT JOIN users u ON u.id=h.actor_id WHERE request_id=? ORDER BY h.id",[$r['id']])as $h){
  $stage=match($h['action']){'Pending DAA','Rejected by HoD/PL'=>'HoD','Approved'=>$final,'Rejected by DAA'=>'DAA','Rejected by President'=>'President',default=>null};
  if($stage&&isset($stages[$stage])&&$stages[$stage]['status']!=='Not applicable')$stages[$stage]=['status'=>str_starts_with($h['action'],'Rejected')?'Rejected':'Approved','name'=>$h['name']??'Unavailable','date'=>$h['created_at']];
 }
 return $stages;
}
function renderApprovals(array $r,array $u):void{
 $route=requestApprovalRoute($r);
 echo '<section class="card"><h2>Substitution approvals</h2><p>Department: '.e($r['department']).'</p><p>Approval route: '.e(match($route){'hod_daa'=>'HoD applicant → DAA','daa_president'=>'DAA applicant → President',default=>'Faculty → HoD → DAA'}).'</p><div class="table-wrap"><table><thead><tr><th>Approval stage</th><th>Status</th><th>Approved / rejected by</th><th>Approval / rejection date and time</th></tr></thead><tbody>';
 foreach(approvalStages($r)as $label=>$stage)echo '<tr><th>'.e($label).' Approval</th><td>'.e($stage['status']).'</td><td>'.e($stage['name']).'</td><td>'.e($stage['date']).'</td></tr>';
 echo '</tbody></table></div>';
 if($u['role']==='daa')echo '<p>HoD approval is read-only. HoD applicants go directly to DAA; DAA applicants go to President. Self-approval is not permitted.</p><button disabled type="button">HoD action unavailable to DAA</button>';
 if($r['status']==='Rejected by HoD/PL')echo '<p>DAA approval has not started because HoD rejected this request.</p>';
 if(str_starts_with($r['status'],'Historical'))echo '<p>Imported records may lack separate approval-stage evidence. Original imported values remain in the request history.</p>';
 echo '</section>';
}

function requestStatusLabel(string $status):string{return match($status){'Historical Approved'=>'Approved','Historical Pending'=>'Pending','Historical Rejected'=>'Rejected',default=>str_replace('HoD/PL','HoD',$status)};}

function bulkTeachingAttendance(array $actor,array $p):int{
 if($actor['role']!=='admin')throw new RuntimeException('Administrator access required.');
 attRequire(attReady(),'Run the attendance migration first.');
 $ids=$p['staff_ids']??[];attRequire(is_array($ids)&&count($ids)>0&&count($ids)<=500,'Select between 1 and 500 staff entries.');
 attRequire(in_array($p['enabled']??'', ['0','1'],true),'Choose Enable attendance or Disable attendance.');
 foreach($ids as $id)attRequire(is_scalar($id)&&ctype_digit((string)$id)&&(int)$id>0,'Invalid staff selection.');
 $ids=array_unique(array_map('intval',$ids));$enabled=(int)$p['enabled'];attLock();
 // Validate the entire selection before changing any permission.
 foreach($ids as $id){$staff=one("SELECT * FROM users WHERE id=? AND role NOT IN ('student','guest')",[$id]);attRequire((bool)$staff,'A selected staff entry no longer exists. Refresh the directory.');attRequire(!$enabled||!empty($staff['active']),'A selected staff entry is inactive. Enable its account first or remove it from the selection.');}
 foreach($ids as $id){if(one('SELECT user_id FROM tutor_permissions WHERE user_id=?',[$id]))q('UPDATE tutor_permissions SET enabled=? WHERE user_id=?',[$enabled,$id]);else q('INSERT INTO tutor_permissions(user_id,enabled) VALUES(?,?)',[$id,$enabled]);
  if(!$enabled){q('UPDATE tutor_registered_devices SET active=0,revoked_at=? WHERE staff_id=?',[now(),$id]);q('UPDATE tutor_enrolment_links SET used_at=? WHERE staff_id=? AND used_at IS NULL',[time(),$id]);}
  attAudit((int)$actor['id'],'Teaching attendance bulk '.($enabled?'enabled':'disabled'),$id);
 }
 return count($ids);
}
