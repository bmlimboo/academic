<?php
require_once __DIR__.'/../src/bootstrap.php';
$mysql=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$columns=fn($table)=>$mysql?array_column(all('SHOW COLUMNS FROM '.$table),'Field'):array_column(all('PRAGMA table_info('.$table.')'),'name');
foreach(['deleted_at'=>'VARCHAR(30) NULL','deleted_by'=>'INTEGER NULL','delete_reason'=>'TEXT NULL']as $col=>$type)if(!in_array($col,$columns('exam_windows'),true))db()->exec("ALTER TABLE exam_windows ADD COLUMN $col $type");
if(!in_array('programme',$columns('exam_expected'),true))db()->exec("ALTER TABLE exam_expected ADD COLUMN programme VARCHAR(160) NOT NULL DEFAULT ''");
if($mysql){
 $indexes=[];foreach(all("SELECT INDEX_NAME,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='exam_expected' AND NON_UNIQUE=0 ORDER BY INDEX_NAME,SEQ_IN_INDEX")as $i)$indexes[$i['INDEX_NAME']][]=$i['COLUMN_NAME'];
 foreach($indexes as $name=>$cols)if($cols===['window_id','staff_id','module_code'])db()->exec('ALTER TABLE exam_expected DROP INDEX `'.str_replace('`','``',$name).'`');
 if(!isset($indexes['exam_programme_identity']))db()->exec('CREATE UNIQUE INDEX exam_programme_identity ON exam_expected(window_id,staff_id,programme,module_code)');
}else{
 $old=false;foreach(all('PRAGMA index_list(exam_expected)')as $index){$cols=array_column(all('PRAGMA index_info("'.str_replace('"','""',$index['name']).'")'),'name');if($cols===['window_id','staff_id','module_code'])$old=true;}
 if($old){db()->beginTransaction();try{db()->exec("CREATE TABLE exam_expected_new (id INTEGER PRIMARY KEY AUTOINCREMENT,window_id INTEGER NOT NULL,staff_id INTEGER NOT NULL,department VARCHAR(160) NOT NULL,programme VARCHAR(160) NOT NULL DEFAULT '',module_code VARCHAR(50) NOT NULL,module_title VARCHAR(190) NOT NULL,UNIQUE(window_id,staff_id,programme,module_code))");db()->exec('INSERT INTO exam_expected_new SELECT id,window_id,staff_id,department,programme,module_code,module_title FROM exam_expected');db()->exec('DROP TABLE exam_expected');db()->exec('ALTER TABLE exam_expected_new RENAME TO exam_expected');db()->commit();}catch(Throwable $e){db()->rollBack();throw $e;}}
}
db()->exec('CREATE TABLE IF NOT EXISTS exam_mail_windows (outbox_id INTEGER PRIMARY KEY,window_id INTEGER NOT NULL)');
// Match the exact examination URL in legacy notifications, never by a broad subject pattern.
foreach(all('SELECT id,body,subject FROM outbox WHERE id NOT IN (SELECT outbox_id FROM exam_mail_windows)')as $m){
 $windowId=null;
 if(preg_match('/[?]page=exam_submit&exam_id=(\d+)(?:\s|$)/',$m['body'],$match))$windowId=(int)$match[1];
 elseif($m['subject']==='Question Paper Submission Confirmation'&&preg_match_all('/\nReceipt: #(\d+)\n/',$m['body'],$matches)){$paperId=(int)end($matches[1]);$paper=one('SELECT e.window_id FROM exam_papers p JOIN exam_expected e ON e.id=p.expected_id WHERE p.id=?',[$paperId]);if($paper)$windowId=(int)$paper['window_id'];}
 if($windowId&&one('SELECT id FROM exam_windows WHERE id=?',[$windowId]))q('INSERT INTO exam_mail_windows(outbox_id,window_id) VALUES(?,?)',[$m['id'],$windowId]);
}
echo "Exam programmes, notification links and period deletion migration complete.\n";
