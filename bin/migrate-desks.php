<?php
require_once __DIR__.'/../src/bootstrap.php';
$sql=file_get_contents(__DIR__.'/../migrations/016_desks.sql');
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY AUTO_INCREMENT',$sql);
db()->exec($sql);
$views=[
 'sd_users'=>"SELECT id,name,email,password AS password_hash,role,department,active FROM users",
 'cd_users'=>"SELECT u.id,u.name,u.email,u.password AS password_hash,u.role,u.active,p.phone,p.designation,p.signature_file,p.seal_file FROM users u LEFT JOIN desk_profiles p ON p.id=u.id",
 'rd_users'=>"SELECT u.id,u.name,u.email,u.password AS password_hash,CASE WHEN u.role='admin' THEN 'daa' WHEN u.role IN ('faculty','hod') THEN 'supervisor' ELSE u.role END AS role,u.department,u.active,COALESCE(p.batch,'') AS batch,COALESCE(NULLIF(p.student_no,''),s.student_number,'') AS student_no FROM users u LEFT JOIN desk_profiles p ON p.id=u.id LEFT JOIN research_students s ON s.user_id=u.id"
];
foreach($views as $name=>$select){db()->exec('DROP VIEW IF EXISTS '.$name);db()->exec('CREATE VIEW '.$name.' AS '.$select);}
if(!(int)q('SELECT COUNT(*) FROM rd_activities')->fetchColumn()){
 $rows=require __DIR__.'/../src/desks/rd/seed_data.php';
 foreach($rows as $a)q('INSERT INTO rd_activities(seq,title,kind,form_no,ack_role,deadline_from,deadline_to,t0_text,enabled,roles,eval_files,pl_download,is_core,txt_student,txt_supervisor,txt_daa,txt_pl,txt_evaluator) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$a['seq'],$a['title'],$a['kind'],$a['form_no'],$a['ack_role'],null,null,$a['t0_text'],0,$a['roles'],$a['eval_files'],$a['pl_download'],1,...$a['txt']]);
}
foreach(['Transfer Certificate','Character Certificate','English Proficiency Certificate','Certificate of Migration','Certificate of Appreciation','Certificate of Medium of Instruction','Support for Recognition of Prior Learning (RPL)','Others']as $i=>$name){if(!one('SELECT id FROM cd_cert_types WHERE name=?',[$name]))q('INSERT INTO cd_cert_types(name,fee,instructions,is_other,active,sort_order) VALUES(?,0,?, ?,1,?)',[$name,'Exam Cell must confirm the required documents and fee.',$name==='Others'?1:0,$i+1]);}
if(!one("SELECT setting_key FROM desk_settings WHERE setting_key='cert_open'"))q("INSERT INTO desk_settings(setting_key,setting_value) VALUES('cert_open','0')");
echo "CAS desks installed. No demo users, uploaded files, signatures or seals imported. Research dates require DAA review; certificate requests remain closed until Exam Cell configures fees/payment.\n";
// Carry forward the latest non-deleted legacy topic/supervisor for each existing student.
// Files, earlier projects, decisions and marks remain in the readable legacy archive.
$seen=[];
foreach(all("SELECT * FROM research_projects WHERE status<>'Deleted' ORDER BY id DESC")as $p){
 $sid=(int)$p['student_id'];if(isset($seen[$sid]))continue;$seen[$sid]=true;
 if(one('SELECT student_id FROM rd_supervision WHERE student_id=?',[$sid]))continue;
 $status=in_array($p['status'],['Approved','Completed'],true)?'accepted':(str_contains($p['status'],'Rejected')?'rejected':'requested');
 q('INSERT INTO rd_supervision(student_id,supervisor_id,topic,status,updated_at) VALUES(?,?,?,?,?)',[$sid,$p['supervisor_id'],mb_substr($p['title'],0,300),$status,now()]);
}
echo "Existing research topics/supervisors linked where absent; legacy documents and marks retained in Research Archive.\n";
