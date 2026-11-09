<?php
function readSheet(string $path,string $name):array {
 if(strtolower(pathinfo($name,PATHINFO_EXTENSION))==='csv'){if(!mb_check_encoding(file_get_contents($path),'UTF-8'))throw new RuntimeException('CSV encoding is not UTF-8. In Excel choose Save As → CSV UTF-8 (Comma delimited), then upload again.');$f=fopen($path,'r');$rows=[];while(($row=fgetcsv($f))!==false){$rows[]=$row;if(count($rows)>5001)throw new RuntimeException('Import at most 5,000 rows at a time.');}fclose($f);return $rows;}
 if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='xlsx')throw new RuntimeException('Upload CSV or XLSX.');$z=new ZipArchive();if($z->open($path)!==true)throw new RuntimeException('Invalid workbook.');$total=0;for($i=0;$i<$z->numFiles;$i++){$total+=$z->statIndex($i)['size'];if($total>40*1024*1024)throw new RuntimeException('Workbook is too large when expanded.');}
 $strings=[];$xml=$z->getFromName('xl/sharedStrings.xml');if($xml){$s=simplexml_load_string($xml,SimpleXMLElement::class,LIBXML_NONET);foreach($s->si as $si){$text='';foreach($si->xpath('.//*[local-name()="t"]')as $t)$text.=(string)$t;$strings[]=$text;}}
 $sheet=$z->getFromName('xl/worksheets/sheet1.xml');if(!$sheet)throw new RuntimeException('Put the data in the first worksheet.');$s=simplexml_load_string($sheet,SimpleXMLElement::class,LIBXML_NONET);if(!$s)throw new RuntimeException('Unreadable workbook.');$rows=[];foreach($s->sheetData->row as $r){$row=[];foreach($r->c as $c){preg_match('/^([A-Z]+)/',(string)$c['r'],$match);$i=0;foreach(str_split($match[1])as $char)$i=$i*26+ord($char)-64;$value=(string)$c->v;if((string)$c['t']==='s')$value=$strings[(int)$value]??'';if((string)$c['t']==='inlineStr')$value=(string)$c->is->t;$row[$i-1]=$value;}if($row){$filled=array_fill(0,max(array_keys($row))+1,'');$rows[]=array_replace($filled,$row);}if(count($rows)>5001)throw new RuntimeException('Import at most 5,000 rows at a time.');}$z->close();return $rows;
}
function normalizedImport(array $rows):array {
 $headers=array_map(fn($s)=>trim(ltrim((string)$s,"\xEF\xBB\xBF")),array_shift($rows)??[]);$needed=['Name','Email','Department','Travel Start Date','Travel End Date','Purpose','Status'];foreach($needed as $h)if(!in_array($h,$headers,true))throw new RuntimeException('Missing column: '.$h.'. Use the import template and ISO dates (YYYY-MM-DD).');$result=[];$seen=[];foreach($rows as $n=>$row){if(!array_filter($row,fn($v)=>trim((string)$v)!==''))continue;$r=array_combine($headers,array_slice(array_pad($row,count($headers),''),0,count($headers)));foreach($needed as $h)if(trim($r[$h])==='')throw new RuntimeException('Row '.($n+2).': '.$h.' is required.');$u=one("SELECT * FROM users WHERE LOWER(email)=? AND active=1 AND role <> 'student'",[strtolower(trim($r['Email']))]);if(!$u)throw new RuntimeException('Row '.($n+2).': add '.$r['Email'].' to Teaching Staff first (individually or by bulk upload). No login account is required.');if(!dateValid($r['Travel Start Date'])||!dateValid($r['Travel End Date'])||$r['Travel End Date']<$r['Travel Start Date'])throw new RuntimeException('Row '.($n+2).': use valid ISO travel dates.');if(!in_array($r['Department'],departments()))throw new RuntimeException('Row '.($n+2).': department must match a system department.');if(!in_array($r['Status'],['Approved','Rejected','Pending','Cancelled']))throw new RuntimeException('Row '.($n+2).': status must be Approved, Rejected, Pending or Cancelled.');$key=hash('sha256',json_encode($r,JSON_THROW_ON_ERROR));if(isset($seen[$key])||one('SELECT id FROM requests WHERE import_key=?',[$key]))continue;$seen[$key]=true;$result[]=['user'=>$u['id'],'row'=>$r,'key'=>$key];}return $result;
}
function commitImport(array $items,int $actor):int{$count=0;foreach($items as $item){if(one('SELECT id FROM requests WHERE import_key=?',[$item['key']]))continue;$r=$item['row'];$p=['travel_start'=>$r['Travel Start Date'],'travel_end'=>$r['Travel End Date'],'purpose'=>$r['Purpose'],'purpose_category'=>$r['Purpose Category']??'Historical / uncategorized','place'=>$r['Place']??'','calendar_days'=>(new DateTimeImmutable($r['Travel Start Date']))->diff(new DateTimeImmutable($r['Travel End Date']))->days+1,'historical_source'=>$r];q('INSERT INTO requests(module,applicant_id,department,status,payload,created_at,updated_at,import_key) VALUES(?,?,?,?,?,?,?,?)',['substitution',$item['user'],$r['Department'],'Historical '.$r['Status'],json_encode($p,JSON_THROW_ON_ERROR),now(),now(),$item['key']]);audit((int)db()->lastInsertId(),$actor,'Historical import','Source values retained; no notifications sent.');$count++;}return $count;}

function previewTeachingImport(array $rows):array {
 $headers=array_map(fn($s)=>trim(ltrim((string)$s,"\xEF\xBB\xBF")),array_shift($rows)??[]);
 foreach(['Name','Email','Department'] as $h)if(!in_array($h,$headers,true))throw new RuntimeException('Missing staff column: '.$h.'. Download the teaching staff template.');
 if(count(array_unique($headers))!==count($headers))throw new RuntimeException('Column headers must be unique.');
 $items=[];$seen=[];
 foreach($rows as $i=>$row){
  if(!array_filter($row,fn($v)=>trim((string)$v)!==''))continue;
  $r=array_combine($headers,array_slice(array_pad($row,count($headers),''),0,count($headers)));
  $name=trim($r['Name']);$email=strtolower(trim($r['Email']));$dept=trim($r['Department']);
  if(!$name||mb_strlen($name)>160||strlen($email)>190||!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($dept,departments(),true))throw new RuntimeException('Row '.($i+2).': check name, email and department. Use a department listed in Teaching Staff.');
  if(isset($seen[$email]))throw new RuntimeException('Row '.($i+2).': duplicate email '.$email.' in this file.');$seen[$email]=true;
  $existing=one('SELECT id FROM users WHERE LOWER(email)=?',[$email]);
  $items[]=['name'=>$name,'email'=>$email,'department'=>$dept,'result'=>$existing?'Skip — email already exists':'Add'];
 }
 if(!$items)throw new RuntimeException('No staff rows found.');return $items;
}
function commitTeachingImport(array $actor,array $items):array {
 if($actor['role']!=='admin')throw new RuntimeException('Administrator access required.');
 $added=0;$skipped=0;
 foreach($items as $item){
  if($item['result']!=='Add'||one('SELECT id FROM users WHERE LOWER(email)=?',[$item['email']])){$skipped++;continue;}
  saveTeachingStaff($actor,$item);$added++;
 }
 return ['added'=>$added,'skipped'=>$skipped];
}
