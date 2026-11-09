<?php
require_once __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/college-calendar/core.php';
$sql=file_get_contents(__DIR__.'/../migrations/010_college_calendar.sql');
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY AUTO_INCREMENT',$sql);
db()->exec($sql);
echo "College calendar tables ready. Existing events preserved.\n";
if(in_array('--import-2026',$argv??[],true)){
 $actor=one("SELECT id FROM users WHERE role='admin' AND active=1 ORDER BY id LIMIT 1");if(!$actor)throw new RuntimeException('Create an active Admin first.');$count=0;
 db()->beginTransaction();try{foreach(json_decode(file_get_contents(__DIR__.'/../src/college-calendar/2026.json'),true,512,JSON_THROW_ON_ERROR) as $v){$key=hash('sha256','college2026|'.$v['start_date'].'|'.$v['end_date'].'|'.$v['category'].'|'.$v['title']);if(one('SELECT id FROM college_calendar_events WHERE import_key=?',[$key]))continue;$v['status']='published';ccInsert(ccValidate($v),(int)$actor['id'],$key);$count++;}db()->commit();}catch(Throwable $e){db()->rollBack();throw $e;}
 echo "$count events imported from College Calendar 2026.xlsx. Repeat runs do not overwrite edits or restore deletions.\n";
}
