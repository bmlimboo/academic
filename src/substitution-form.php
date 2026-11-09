<?php
class SubstitutionValidation extends RuntimeException{public function __construct(public array $fields){parent::__construct('Please correct the highlighted fields. Your entries have been kept.');}}
function substitutionErrors(array $p,?array $u=null,bool $identity=false):array{
 $errors=[];$v=fn($k)=>is_scalar($p[$k]??null)?trim((string)$p[$k]):'';
 if($identity){$u=one("SELECT * FROM users WHERE id=? AND active=1 AND role NOT IN ('student','guest')",[(int)$v('applicant_id')])?:null;if(!$u)$errors['applicant_id']='Select your name from the staff directory.';if(!filter_var($v('applicant_email'),FILTER_VALIDATE_EMAIL)||($u&&strcasecmp($v('applicant_email'),$u['email'])!==0))$errors['applicant_email']='Enter the email registered for the selected staff member.';}
 if(!in_array($v('department'),departments(),true))$errors['department']='Select your department.';elseif($u&&!acAdmin($u)&&$v('department')!==$u['department'])$errors['department']='Select your registered department. Ask the administrator to correct the directory if it has changed.';
 foreach(['place'=>'Enter the place of travel.','funding_agency'=>'Enter the funding agency or Not applicable.']as $k=>$m)if($v($k)==='')$errors[$k]=$m;
 if(!in_array($v('substitution_required'),['Yes','No'],true))$errors['substitution_required']='Select Yes or No.';
 if($v('substitution_required')==='No'&&$v('no_substitution_reason')==='')$errors['no_substitution_reason']='Explain why substitution is not required.';
 if($v('purpose_category')===''||mb_strlen($v('purpose_category'))>2000)$errors['purpose_category']='Describe the purpose in 1–2,000 characters.';
 foreach(['travel_start','travel_end']as $k)if(!dateValid($v($k)))$errors[$k]='Enter a valid date.';
 if(!isset($errors['travel_start'])&&!isset($errors['travel_end'])&&$v('travel_end')<$v('travel_start'))$errors['travel_end']='End date must be on or after start date.';
 if(!in_array($v('transportation'),['Personal with mileage','Personal (with mileage)','Pool','Other'],true))$errors['transportation']='Select transportation.';
 if($v('transportation')==='Other'&&$v('transport_details')==='')$errors['transport_details']='Specify the transportation details.';
 if($v('resource_link')&&(!filter_var($v('resource_link'),FILTER_VALIDATE_URL)||!in_array(parse_url($v('resource_link'),PHP_URL_SCHEME),['http','https'],true)))$errors['resource_link']='Enter a complete http:// or https:// link.';
 if($v('substitution_required')==='Yes'){
  $affected=0;foreach(is_array($p['sessions']??null)?$p['sessions']:[] as $i=>$s){if(!is_array($s))continue;$prefix='sessions['.$i.']';$val=fn($k)=>is_scalar($s[$k]??null)?trim((string)$s[$k]):'';
   if(!in_array($val('kind'),['Affected class','Compensation'],true))$errors[$prefix.'[kind]']='Select a session type.';if($val('kind')==='Affected class')$affected++;
   if(!dateValid($val('day')))$errors[$prefix.'[day]']='Enter the class date.';elseif($val('kind')==='Affected class'&&($val('day')<$v('travel_start')||$val('day')>$v('travel_end')))$errors[$prefix.'[day]']='Affected class must fall within the travel dates.';
   foreach(['start_time','end_time']as $k)if(!timeValid($val($k)))$errors[$prefix.'['.$k.']']='Enter a valid time.';
   if(timeValid($val('start_time'))&&timeValid($val('end_time'))&&$val('end_time')<=$val('start_time'))$errors[$prefix.'[end_time]']='End time must be later than start time.';
   foreach(['module_name','module_code','classroom']as $k)if(!$val($k))$errors[$prefix.'['.$k.']']='Complete this field.';
   if(!filter_var($val('students'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))$errors[$prefix.'[students]']='Enter a positive student count.';
   $t=one("SELECT * FROM users WHERE id=? AND active=1 AND role NOT IN ('student','guest')",[(int)$val('faculty_id')]);
   if(!$t)$errors[$prefix.'[faculty_id]']='Select an active substitute / teaching faculty member.';
   if(!filter_var($val('faculty_email'),FILTER_VALIDATE_EMAIL)||($t&&strcasecmp($val('faculty_email'),$t['email'])!==0))$errors[$prefix.'[faculty_email]']='Email must match the selected faculty member.';
  }if(!$affected)$errors['sessions']='Add at least one affected class session.';
 }
 foreach(['attachment','invitation']as $k){$f=$_FILES[$k]??null;if(!$f||($f['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
  if($f['error']!==UPLOAD_ERR_OK||$f['size']>20*1024*1024)$errors[$k]='Upload a file up to 20 MB. Check the server upload limit if this persists.';
  else{$allowed=['pdf'=>['application/pdf'],'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip'],'xlsx'=>['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip'],'png'=>['image/png'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg']];$ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));$mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);if(!isset($allowed[$ext])||!in_array($mime,$allowed[$ext],true))$errors[$k]='Use PDF, DOCX, XLSX, PNG or JPEG.';}
 }return $errors;
}
function subField(string $name,string $label,string $type='text',array $options=[],bool $required=true):void{
 global $subValues,$fieldErrors;$v=$subValues;foreach(preg_split('/\[|\]/',$name,-1,PREG_SPLIT_NO_EMPTY)as $part)$v=is_array($v)?($v[$part]??''):'';$v=is_scalar($v)?(string)$v:'';$id='sub-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$name);$err=$fieldErrors[$name]??'';
 echo '<label for="'.e($id).'">'.e($label).($required?' <span class="required-mark">*</span>':'');$attrs=' id="'.e($id).'" name="'.e($name).'" '.($required?'required ':'').' aria-describedby="'.$id.'-error"'.($err?' aria-invalid="true"':'');
 if($type==='select'){echo '<select'.$attrs.'><option value="">Select…</option>';foreach($options as $key=>$text){$key=array_is_list($options)?$text:$key;echo '<option value="'.e((string)$key).'"'.($v===(string)$key?' selected':'').'>'.e($text).'</option>';}echo '</select>';}
 elseif($type==='textarea')echo '<textarea'.$attrs.' rows="3">'.e($v).'</textarea>';
 else echo '<input'.$attrs.' type="'.e($type).'"'.($type!=='file'?' value="'.e($v).'"':'').($type==='number'?' min="1"':'').'>';
 echo '<span class="field-error" id="'.$id.'-error" data-error-for="'.e($name).'">'.e($err).'</span></label>';
}
function subPeople(string $name,string $label):void{$options=[];foreach(staff()as $u)$options[(string)$u['id']]=$u['name'];subField($name,$label,'select',$options);}
function subSession(string $i):void{echo '<div class="session-row"><div class="form-grid">';foreach(['kind'=>['Session type','select',['Affected class','Compensation']],'day'=>['Class date','date',[]],'start_time'=>['Start time','time',[]],'end_time'=>['End time','time',[]],'module_name'=>['Module name','text',[]],'module_code'=>['Module code','text',[]]]as $k=>[$label,$type,$opts])subField("sessions[$i][$k]",$label,$type,$opts);subPeople("sessions[$i][faculty_id]",'Substitute / teaching faculty');subField("sessions[$i][faculty_email]",'Substitute / teaching faculty email','email');subField("sessions[$i][classroom]",'Classroom');subField("sessions[$i][students]",'Number of students','number');echo '</div><button class="secondary remove-session" type="button">Remove session</button></div>';}
function notifySubstitutes(int $id):void{
 global $config;$r=one('SELECT r.*,u.name FROM requests r JOIN users u ON u.id=r.applicant_id WHERE r.id=?',[$id]);$groups=[];
 foreach(all('SELECT s.*,u.email FROM sessions s JOIN users u ON u.id=s.faculty_id WHERE request_id=? ORDER BY day,start_time',[$id])as $s)$groups[$s['email']][]=$s;
 foreach($groups as $email=>$sessions){$nl="\n\n";$body='Dear colleague,'.$nl;$body.=$r['name'].' has selected you as substitute / teaching faculty during their absence for request #'.$id.'. The request is awaiting approval; this notification does not confirm approval.'.$nl;
 foreach($sessions as $s)$body.=$s['kind'].': '.$s['module_name'].' ('.$s['module_code'].') - '.$s['day'].' '.$s['start_time'].' to '.$s['end_time'].'; room '.$s['classroom']."\n";
 $body.=$nl.'Please coordinate the teaching arrangements with '.$r['name'].'.'.$nl.'View service status: '.rtrim($config['url'],'/').'/?module=substitution';q('INSERT INTO outbox(recipient,subject,body,created_at) VALUES(?,?,?,?)',[$email,'Teaching substitution assignment #'.$id,$body,now()]);
 }
}
