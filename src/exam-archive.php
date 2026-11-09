<?php
// Uses existing audit history, including downloads made before this view was added.
function examArchive(int $window, array $filters):array {
 $rows=all('SELECT p.*,e.module_code,e.module_title,e.department,e.programme,e.id AS assignment_id FROM exam_papers p JOIN exam_expected e ON e.id=p.expected_id WHERE e.window_id=? ORDER BY p.id DESC',[$window]);
 $downloads=[];
 foreach(all("SELECT a.details,a.created_at,u.name FROM exam_audit a LEFT JOIN users u ON u.id=a.actor_id WHERE a.action='PDF downloaded' ORDER BY a.id") as $event){
  $d=json_decode($event['details'],true);if(($d['file']??'')!=='paper_path')continue;
  $id=(int)($d['paper']??0);$downloads[$id]=['count'=>($downloads[$id]['count']??0)+1,'at'=>$event['created_at'],'by'=>$event['name']?:'Former account'];
 }
 $departments=[];$programmes=[];$downloaded=0;$active=0;
 foreach($rows as &$row){$snapshot=json_decode($row['metadata']??'null',true);if(is_array($snapshot))foreach(['module_code','module_title','department','programme'] as $key)if(isset($snapshot[$key]))$row[$key]=$snapshot[$key];$row['download']=$downloads[(int)$row['id']]??null;$departments[$row['department']]=$row['department'];$programmes[$row['programme']??'']=$row['programme']??'';if($row['active_key']){$active++;if($row['download'])$downloaded++;}}
 unset($row);ksort($departments);ksort($programmes);unset($programmes['']);
 $rows=array_values(array_filter($rows,function($r)use($filters){
  $search=trim($filters['paper_search']??'');if($search!==''&&stripos(implode(' ',[$r['staff_name'],$r['staff_email'],$r['module_code'],$r['module_title']]),$search)===false)return false;
  foreach(['department','programme']as $key)if(($filters['paper_'.$key]??'')!==''&&($filters['paper_'.$key]!==($r[$key]??'')))return false;
  $status=$filters['paper_download']??'';if($status==='yes'&&!$r['download']||$status==='no'&&$r['download'])return false;
  $state=$filters['paper_state']??'active';return $state==='all'||($state==='removed'?!$r['active_key']:!!$r['active_key']);
 }));
 $count=count($rows);$pages=max(1,(int)ceil($count/10));$page=max(1,min($pages,(int)($filters['paper_page']??1)));
 return compact('departments','programmes','active','downloaded','count','pages','page')+['rows'=>array_slice($rows,($page-1)*10,10)];
}
