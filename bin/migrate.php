<?php
require_once __DIR__.'/../src/bootstrap.php';
$sql=file_get_contents(__DIR__.'/../migrations/002_attendance.sql');
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY AUTO_INCREMENT',$sql);
db()->exec($sql);
foreach(['early_minutes'=>'10','late_minutes'=>'5','close_minutes'=>'15','qr_seconds'=>'45']as $k=>$v)if(!one('SELECT setting_key FROM attendance_settings WHERE setting_key=?',[$k]))q('INSERT INTO attendance_settings(setting_key,setting_value) VALUES(?,?)',[$k,$v]);
if(!one('SELECT id FROM attendance_mutex WHERE id=1'))q('INSERT INTO attendance_mutex(id,revision) VALUES(1,0)');
$indexes=['att_schedule_staff'=>['tutor_timetable','staff_id,weekday,valid_from,valid_until'],'att_schedule_room'=>['tutor_timetable','classroom_id,weekday,valid_from,valid_until'],'att_record_date'=>['tutor_attendance','attendance_date,staff_id'],'att_qr_expiry'=>['attendance_qr_sessions','expires_at'],'att_device_staff'=>['tutor_registered_devices','staff_id,active'],'consultation_time'=>['consultation_appointments','faculty_id,day,start_time,end_time']];
foreach($indexes as $name=>[$table,$cols]){
 $exists=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?one('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$table,$name]):one("SELECT name FROM sqlite_master WHERE type='index' AND name=?",[$name]);
 if(!$exists)db()->exec("CREATE INDEX $name ON $table ($cols)");
}
// Preserve old consultation bookings, including ones with retired slots.
foreach(all('SELECT b.*,s.faculty_id,s.start_time,s.end_time FROM bookings b JOIN slots s ON s.id=b.slot_id')as $b)if(!one('SELECT request_id FROM consultation_appointments WHERE request_id=?',[$b['request_id']]))q('INSERT INTO consultation_appointments(request_id,faculty_id,day,start_time,end_time) VALUES(?,?,?,?,?)',[$b['request_id'],$b['faculty_id'],$b['day'],$b['start_time'],$b['end_time']]);
echo "Attendance and consultation migration complete. Existing records and passwords preserved.\n";
$sql=file_get_contents(__DIR__.'/../migrations/003_samples.sql');
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY AUTO_INCREMENT',$sql);
db()->exec($sql);
echo "Timetable layouts and password-free enrolment migration complete.\n";
db()->exec(file_get_contents(__DIR__.'/../migrations/004_staff_management.sql'));
echo "Staff classification and status migration complete.\n";
$sql=file_get_contents(__DIR__.'/../migrations/005_teaching_calendar.sql');
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY AUTO_INCREMENT',$sql);
db()->exec($sql);
echo "Teaching calendar and timing settings migration complete.\n";

$columns=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?array_column(all('SHOW COLUMNS FROM tutor_timetable'),'Field'):array_column(all('PRAGMA table_info(tutor_timetable)'),'name');
foreach(['participant_groups'=>'TEXT NULL','student_count'=>'INTEGER NULL']as $column=>$type)if(!in_array($column,$columns,true))db()->exec("ALTER TABLE tutor_timetable ADD COLUMN $column $type");
echo "Combined teaching sessions migration complete.\n";

db()->exec("CREATE TABLE IF NOT EXISTS timetable_import_results (id VARCHAR(40) PRIMARY KEY, created_at VARCHAR(30) NOT NULL, actor_id INTEGER NOT NULL, results LONGTEXT NOT NULL)");
echo "Timetable import results migration complete.\n";

$columns=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?array_column(all('SHOW COLUMNS FROM tutor_timetable'),'Field'):array_column(all('PRAGMA table_info(tutor_timetable)'),'name');
foreach(['delivery_mode'=>"VARCHAR(20) NOT NULL DEFAULT 'In person'",'attendance_required'=>'INTEGER NOT NULL DEFAULT 1','flexible'=>'INTEGER NOT NULL DEFAULT 0']as $column=>$type)if(!in_array($column,$columns,true))db()->exec("ALTER TABLE tutor_timetable ADD COLUMN $column $type");
echo "Flexible delivery settings migration complete.\n";

db()->exec(file_get_contents(__DIR__.'/../migrations/006_password_reset.sql'));
echo "Official-account password reset migration complete.\n";

$sql=file_get_contents(__DIR__.'/../migrations/007_exam_portal.sql');
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY AUTO_INCREMENT',$sql);
db()->exec($sql);
echo "Exam Cell portal migration complete.\n";
require __DIR__.'/migrate-exam-programmes.php';

$sql=file_get_contents(__DIR__.'/../migrations/008_academic.sql');
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY AUTO_INCREMENT',$sql);
db()->exec($sql);
echo "Academic student attendance and assessment migration complete.\n";

$sql=file_get_contents(__DIR__.'/../migrations/009_student_attendance.sql');
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY AUTO_INCREMENT',$sql);
db()->exec($sql);
echo "Student attendance portal migration complete.\n";

require __DIR__.'/migrate-college-calendar.php';

require __DIR__.'/migrate-research-library.php';

require __DIR__.'/migrate-results.php';

require __DIR__.'/migrate-research-workflow.php';

require __DIR__.'/migrate-lab-booking.php';

require __DIR__.'/migrate-lab-deletions.php';

require __DIR__.'/migrate-desks.php';
