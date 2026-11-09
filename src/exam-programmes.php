<?php
function examProgrammeMap():array{return [
 'Department of Agriculture'=>['BSc. Agriculture','Diploma in Agriculture'],
 'Department of Animal Science'=>['BSc. Animal Science','Diploma in Animal Science'],
 'Department of Food Science & Technology'=>['BSc. Food Science and Technology'],
 'Department of Environment and Climate Studies'=>['BSc. Environment and Climate Studies','PhD in Climate Studies'],
 'Department of Sustainable Development'=>['BSc. Sustainable Development','Master in Development Practice'],
 'Department of Forest Science'=>['BSc. Forestry','MSc. Natural Resource Management','MSc. Conservation Biology'],
];}
function examProgrammes():array{return array_merge(...array_values(examProgrammeMap()));}
function examProgrammeDepartment(string $programme):string{foreach(examProgrammeMap()as $dept=>$list)if(in_array($programme,$list,true))return $dept;throw new RuntimeException('Select a valid programme.');}
/** Counts refer to the name/department/programme scope, before the status filter. */
function examStaffReport(int $window,array $filters):array{
 $rows=[];$search=trim($filters['teacher_search']??'');$dept=$filters['teacher_department']??'';$prog=$filters['teacher_programme']??'';$status=$filters['teacher_status']??'';
 foreach(examTeachers()as $u){$assigned=array_values(array_filter(array_column(all('SELECT DISTINCT programme FROM exam_expected WHERE window_id=? AND staff_id=?',[$window,$u['id']]),'programme')));$papers=all('SELECT e.programme,e.module_code,e.department FROM exam_papers p JOIN exam_expected e ON e.id=p.expected_id WHERE e.window_id=? AND e.staff_id=? AND p.active_key IS NOT NULL',[$window,$u['id']]);
  if($search!==''&&mb_stripos($u['name'].' '.$u['email'],$search)===false)continue;
  if($prog==='__unknown'&&$assigned)continue;
  if($prog!==''&&$prog!=='__unknown'&&!in_array($prog,$assigned,true)&&!in_array($prog,array_column($papers,'programme'),true))continue;
  if($dept!==''&&$u['department']!==$dept&&!array_filter($assigned,fn($v)=>examProgrammeDepartment($v)===$dept)&&!in_array($dept,array_column($papers,'department'),true))continue;
  if($prog!==''&&$prog!=='__unknown')$papers=array_values(array_filter($papers,fn($v)=>$v['programme']===$prog));elseif($dept!=='')$papers=array_values(array_filter($papers,fn($v)=>$v['department']===$dept));
  $pref=examStaffPreference((int)$u['id']);$rows[]=['enabled'=>(bool)$pref['enabled'],'reason'=>$pref['reason'],'user'=>$u,'programmes'=>$assigned,'papers'=>$papers,'submitted'=>(bool)$papers];
 }
 $excluded=count(array_filter($rows,fn($v)=>!$v['enabled']));$submitted=count(array_filter($rows,fn($v)=>$v['enabled']&&$v['submitted']));$total=count($rows)-$excluded;$mailFilter=$filters['teacher_notifications']??'';if(in_array($mailFilter,['enabled','excluded'],true))$rows=array_values(array_filter($rows,fn($v)=>$v['enabled']===($mailFilter==='enabled')));if(in_array($status,['Submitted','Not submitted'],true))$rows=array_values(array_filter($rows,fn($v)=>$v['enabled']&&$v['submitted']===($status==='Submitted')));
 $pages=max(1,(int)ceil(count($rows)/10));$page=min($pages,max(1,(int)($filters['teacher_page']??1)));
 return ['excluded'=>$excluded,'total'=>$total,'submitted'=>$submitted,'pending'=>$total-$submitted,'matches'=>count($rows),'page'=>$page,'pages'=>$pages,'rows'=>array_slice($rows,($page-1)*10,10)];
}
