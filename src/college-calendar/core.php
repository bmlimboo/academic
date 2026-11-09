<?php
function ccManage(?array $u):bool{return $u&&!empty($u['active'])&&in_array($u['role'],['admin','exam','daa'],true);}
function ccReady():bool{try{q('SELECT id FROM college_calendar_events LIMIT 1');return true;}catch(Throwable $e){return false;}}
function ccCategories():array{return ['meeting'=>'Meeting','assessment'=>'Assessment','other'=>'Other college events','holiday'=>'National holiday','local'=>'Local observance'];}
function ccYear(mixed $v):int{if(!is_scalar($v)||!preg_match('/^\d{4}$/',(string)$v)||(int)$v<2000||(int)$v>2100)throw new RuntimeException('Choose a year from 2000 to 2100.');return (int)$v;}
function ccDate(string $v):string{$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new RuntimeException('Enter a valid date.');ccYear(substr($v,0,4));return $v;}
function ccValidate(array $p):array{
 $v=[];foreach(['title'=>240,'location'=>240,'description'=>4000,'source'=>500] as $k=>$max){$v[$k]=trim((string)($p[$k]??''));if(mb_strlen($v[$k])>$max)throw new RuntimeException(ucfirst($k).' is too long.');}
 if($v['title']==='')throw new RuntimeException('Enter an event title.');
 $v['category']=(string)($p['category']??'');if(!isset(ccCategories()[$v['category']]))throw new RuntimeException('Choose an event category.');
 $v['start_date']=ccDate((string)($p['start_date']??''));$v['end_date']=ccDate((string)($p['end_date']??''));if($v['end_date']<$v['start_date'])throw new RuntimeException('End date must be on or after start date.');
 if((new DateTimeImmutable($v['start_date']))->diff(new DateTimeImmutable($v['end_date']))->days>366)throw new RuntimeException('Limit one event to 366 days.');
 foreach(['start_time','end_time'] as $k){$v[$k]=trim((string)($p[$k]??''));if($v[$k]!==''&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$v[$k]))throw new RuntimeException('Enter valid 24-hour times.');}
 if(($v['start_time']==='')!==($v['end_time']===''))throw new RuntimeException('Enter both times, or leave both blank for an all-day event.');
 if($v['start_date']===$v['end_date']&&$v['start_time']!==''&&$v['end_time']<=$v['start_time'])throw new RuntimeException('End time must follow start time.');
 $v['status']=(string)($p['status']??'draft');if(!in_array($v['status'],['draft','published'],true))throw new RuntimeException('Choose Draft or Published.');return $v;
}
function ccAudit(int $actor,int $id,string $action,array $details):void{q('INSERT INTO college_calendar_audit(event_id,actor_id,action,details,created_at) VALUES(?,?,?,?,?)',[$id,$actor,$action,json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),now()]);}
function ccInsert(array $v,int $actor,?string $key=null):int{
 q('INSERT INTO college_calendar_events(title,category,start_date,end_date,start_time,end_time,location,description,source,status,import_key,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$v['title'],$v['category'],$v['start_date'],$v['end_date'],$v['start_time']??'',$v['end_time']??'',$v['location']??'',$v['description']??'',$v['source']??'',$v['status']??'draft',$key,$actor,$actor,now(),now()]);$id=(int)db()->lastInsertId();ccAudit($actor,$id,'Created',$v);return $id;
}
function ccPost(?array $u,string $action,array $p):void{
 if(!ccManage($u))throw new RuntimeException('Only Admin, Exam Cell and DAA can manage the college calendar.');
 if(!ccReady())throw new RuntimeException('Install the college calendar migration first.');
 db()->beginTransaction();try{
 if($action==='cc_save'){$v=ccValidate($p);$id=(int)($p['id']??0);if(!$id)ccInsert($v,(int)$u['id']);else{$old=one('SELECT * FROM college_calendar_events WHERE id=?',[$id]);if(!$old||$old['status']==='deleted')throw new RuntimeException('Event not found.');$fields=array_keys($v);$sql=implode(',',array_map(fn($k)=>$k.'=?',$fields));$stmt=q('UPDATE college_calendar_events SET '.$sql.',updated_by=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?', [...array_values($v),$u['id'],now(),$id,(int)($p['revision']??0)]);if($stmt->rowCount()!==1)throw new RuntimeException('Another editor changed this event. Reload it before saving.');ccAudit((int)$u['id'],$id,'Updated',['before'=>$old,'after'=>$v]);}}
 elseif($action==='cc_delete'){$old=one('SELECT * FROM college_calendar_events WHERE id=?',[(int)($p['id']??0)]);if(!$old||$old['status']==='deleted')throw new RuntimeException('Event not found.');$stmt=q("UPDATE college_calendar_events SET status='deleted',revision=revision+1,updated_by=?,updated_at=? WHERE id=? AND revision=?",[$u['id'],now(),$old['id'],(int)($p['revision']??0)]);if($stmt->rowCount()!==1)throw new RuntimeException('Event changed. Refresh before deleting.');ccAudit((int)$u['id'],(int)$old['id'],'Deleted',$old);}
 elseif($action==='cc_holidays'){
 $year=ccYear($p['year']??'');$fixed=[['02-21','02-23',"King's Birth Anniversary"],['05-02','05-02','Birth Anniversary of the Third Druk Gyalpo'],['11-01','11-01',"King's Coronation Day"],['11-11','11-11',"Fourth King's Birth Anniversary / Constitution Day"],['12-17','12-17','National Day']];
 foreach($fixed as [$a,$b,$title]){$date=$year.'-'.$a;$key=hash('sha256','fixed|'.$date);if(one('SELECT id FROM college_calendar_events WHERE import_key=?',[$key])||one("SELECT id FROM college_calendar_events WHERE category='holiday' AND start_date=? AND status<>'deleted'",[$date]))continue;ccInsert(['title'=>$title,'category'=>'holiday','start_date'=>$date,'end_date'=>$year.'-'.$b,'status'=>'draft','source'=>'Fixed-date suggestion — check the official holiday notice before publishing.','description'=>''],(int)$u['id'],$key);}}
 else throw new RuntimeException('Unknown calendar action.');
 db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function ccEvents(int $year,bool $manage=false):array{return all("SELECT * FROM college_calendar_events WHERE start_date<=? AND end_date>=? AND ".($manage?"status<>'deleted'":"status='published'").' ORDER BY start_date,start_time,title',[$year.'-12-31',$year.'-01-01']);}
function ccRange(array $e):string{$a=(new DateTimeImmutable($e['start_date']))->format('j M');$b=(new DateTimeImmutable($e['end_date']))->format('j M');return $a.($e['end_date']!==$e['start_date']?' – '.$b:'').($e['start_time']!==''?' · '.$e['start_time'].'–'.$e['end_time']:'');}
