<?php
require_once dirname(__DIR__).'/bootstrap.php';
function deskOptions(string $key,array $u):array{
 if(empty($u['active']))return [];$r=$u['role'];
 if($key==='sd')return in_array($r,['admin','daa'],true)?['daa','staff']:(in_array($r,['student','alumnus','guest'],true)?[]:['staff']);
 if($key==='cd')return $r==='admin'?['examcell','daa','alumnus']:($r==='exam'?['examcell']:($r==='daa'?['daa']:['alumnus']));
 if($key==='rd'){
  if($r==='student')return ['student'];if($r==='admin')return ['daa','supervisor','evaluator','pl'];
  $options=[];if($r==='daa')$options[]='daa';if($r==='pl')$options[]='pl';
  if(in_array($r,['faculty','hod','pl','daa'],true))$options[]='supervisor';
  if(one('SELECT id FROM rd_allocations WHERE evaluator_id=? LIMIT 1',[$u['id']]))$options[]='evaluator';return $options;
 }return [];
}
function deskBoot(string $key):void{
 umask(0077);
 if(session_status()!==PHP_SESSION_ACTIVE){ini_set('session.use_strict_mode','1');session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','samesite'=>'Lax']);session_start();}
 header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');header('X-Frame-Options: SAMEORIGIN');header('Cache-Control: no-store');
 // Bundled signing UI uses inline scripts and PDF workers; no remote script hosts are permitted.
 header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; worker-src 'self' blob:; frame-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
 $_SESSION['csrf']??=bin2hex(random_bytes(32));
 $u=isset($_SESSION['user'])&&is_numeric($_SESSION['user'])?one('SELECT * FROM users WHERE id=? AND active=1',[(int)$_SESSION['user']]):null;
 if(!$u||!accountLogin($u)||!hash_equals(hash('sha256',$u['password']),$_SESSION['password_version']??'')){deskLogin($key);}
 if($key==='rd'&&$u['role']!=='admin'&&($_SESSION['research_login_user']??null)!==$u['id'])deskLogin('rd');
 $GLOBALS['desk_identity']=$u;
 $options=deskOptions($key,$u);if(!$options){http_response_code(403);exit('This CAS account does not have access to this service.');}
 if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['desk_action']??'')==='switch_role'){
  if(!hash_equals($_SESSION['csrf'],$_POST['csrf']??'')||!in_array($_POST['desk_role']??'', $options,true)){http_response_code(403);exit('Invalid workspace selection.');}
  $_SESSION['desk_role_'.$key]=$_POST['desk_role'];header('Location: /desks/'.$key.'/index.php');exit;
 }
 if(!in_array($_SESSION['desk_role_'.$key]??'', $options,true))$_SESSION['desk_role_'.$key]=$options[0];
 if(!one('SELECT id FROM desk_profiles WHERE id=?',[$u['id']])){q('INSERT INTO desk_profiles(id) VALUES(?)',[$u['id']]);}
 if($_SERVER['REQUEST_METHOD']==='POST'&&db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
  $lock='cas_desk_'.$key;if((int)q('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1){http_response_code(409);exit('Another update is in progress. Please try again.');}
  register_shutdown_function(function()use($lock){try{q('SELECT RELEASE_LOCK(?)',[$lock]);}catch(Throwable $e){}});
 }
 if($_SERVER['REQUEST_METHOD']==='POST'&&!hash_equals($_SESSION['csrf'],$_POST['csrf']??'')){http_response_code(400);exit('Your session expired. Refresh and try again.');}
}
function deskLogin(string $key):never{
 if($key==='rd'){ $path=$_SERVER['REQUEST_URI']??'';if(preg_match('~^/desks/rd/(?!login)[a-z_]+\.php(?:\?[^\r\n]*)?$~D',$path))$_SESSION['research_login_next']=$path;header('Location: /desks/rd/login.php');exit; }
 $path=$_SERVER['REQUEST_URI']??'/desks/'.$key.'/index.php';
 if(preg_match('~^/desks/(sd|cd|rd)/[a-z_]+\.php(?:\?[^\r\n]*)?$~D',$path))$_SESSION['after_login_desk']=$path;
 header('Location: /?page=login');exit;
}
function deskUser(string $key):?array{
 $u=$GLOBALS['desk_identity']??null;if(!$u)return null;$u['cas_role']=$u['role'];$u['role']=$_SESSION['desk_role_'.$key];$u['password_hash']=$u['password'];
 $p=one('SELECT * FROM desk_profiles WHERE id=?',[$u['id']])?:[];return array_merge($p,$u);
}
function deskRoleBar(string $key):void{
 $u=$GLOBALS['desk_identity'];$options=deskOptions($key,$u);
 echo '<div class="cas-bar"><a href="/">CNR · Academic Services</a><span>'.e($u['name']).'</span>';
 if(count($options)>1){echo '<form method="post"><input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'"><input type="hidden" name="desk_action" value="switch_role"><label>Workspace <select name="desk_role">';foreach($options as $role)echo '<option value="'.e($role).'" '.($_SESSION['desk_role_'.$key]===$role?'selected':'').'>'.e(ucfirst($role)).'</option>';echo '</select></label><button type="submit">Switch</button></form>';}
 echo '</div><link rel="stylesheet" href="/desks/cas.css">';
}
function deskNavigation(string $key):void{
 echo '<aside class="portal"><details><summary>☰ CAS menu</summary><a href="/">Home · Academic Services</a>';
 foreach(navigationExtras($GLOBALS['desk_identity'])as $url=>$label){if(str_starts_with($url,'?'))$url='/'.$url;elseif(!str_starts_with($url,'/'))$url='/'.$url;echo '<a href="'.e($url).'">'.e($label).'</a>';}
 echo '</details></aside>';
}
// SQLite translations are for isolated local tests. Production uses the existing MySQL connection.
class DeskDatabase {
 function sql(string $sql):string{
  if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')return $sql;
  $sql=preg_replace('/INSERT IGNORE INTO/i','INSERT OR IGNORE INTO',$sql);
  if(stripos($sql,'ON DUPLICATE KEY UPDATE')!==false){$sql=preg_replace('/ON DUPLICATE KEY UPDATE/i','ON CONFLICT DO UPDATE SET',$sql);$sql=preg_replace('/VALUES\((\w+)\)/i','excluded.$1',$sql);}
  return $sql;
 }
 function prepare(string $sql):PDOStatement{return db()->prepare($this->sql($sql));}
 function query(string $sql):PDOStatement{return db()->query($this->sql($sql));}
 function exec(string $sql):int|false{return db()->exec($this->sql($sql));}
 function beginTransaction():bool{return db()->beginTransaction();}
 function commit():bool{return db()->commit();}
 function rollBack():bool{return db()->rollBack();}
 function inTransaction():bool{return db()->inTransaction();}
 function lastInsertId():string|false{return db()->lastInsertId();}
}
function deskDB():DeskDatabase{static $d;if(!$d){$d=new DeskDatabase();if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){db()->sqliteCreateFunction('CHAR_LENGTH','mb_strlen',1);db()->sqliteCreateFunction('IF',fn($c,$a,$b)=>$c?$a:$b,3);db()->sqliteCreateFunction('NOW',fn()=>now(),0);db()->sqliteCreateFunction('FIELD',fn($v,...$items)=>(($n=array_search($v,$items,true))===false?0:$n+1));db()->sqliteCreateFunction('CONCAT',fn(...$v)=>implode('',$v));}}return $d;}
function deskQueueMail(string $to,string $subject,string $body,array $atts=[]):int{
 if(!filter_var($to,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$subject))throw new RuntimeException('Invalid email recipient or subject.');
 $own=!db()->inTransaction();if($own)db()->beginTransaction();$saved=[];
 try{
  q('INSERT INTO outbox(recipient,subject,body,created_at) VALUES(?,?,?,?)',[$to,$subject,$body,now()]);$oid=(int)db()->lastInsertId();
  foreach($atts as [$path,$name,$mime]){
   $real=realpath($path);$base=realpath(dirname(__DIR__,2).'/storage/desks/cd');if(!$real||!$base||!str_starts_with($real,$base.DIRECTORY_SEPARATOR)||filesize($real)>20*1048576)throw new RuntimeException('Attachment unavailable.');
   $key=bin2hex(random_bytes(24));$dest=dirname(__DIR__,2).'/storage/uploads/'.$key;if(!copy($real,$dest))throw new RuntimeException('Attachment could not be queued.');chmod($dest,0600);$saved[]=$dest;
   q('INSERT INTO files(name,path,mime,size,label) VALUES(?,?,?,?,?)',[mb_substr(basename($name),0,190),$key,$mime,filesize($dest),'Certificate email attachment']);$fid=(int)db()->lastInsertId();q('INSERT INTO mail_attachments(outbox_id,file_id) VALUES(?,?)',[$oid,$fid]);
  }
  if($own)db()->commit();return $oid;
 }catch(Throwable $e){if($own&&db()->inTransaction())db()->rollBack();foreach($saved as $f)@unlink($f);throw $e;}
}
function deskResearchNotice(int $aid,string $message):void{
 $u=$GLOBALS['desk_identity'];$sid=(int)($_POST['student_id']??($u['role']==='student'?$u['id']:0));if(!$sid)return;
 $s=one('SELECT email,name,department FROM users WHERE id=? AND active=1',[$sid]);if(!$s)return;
 $emails=[$s['email']];$sv=one('SELECT u.email FROM rd_supervision r JOIN users u ON u.id=r.supervisor_id WHERE r.student_id=? AND u.active=1',[$sid]);if($sv)$emails[]=$sv['email'];
 foreach(all("SELECT email FROM users WHERE role='pl' AND active=1 AND department=?",[$s['department']])as $p)$emails[]=$p['email'];
 if(($_POST['action']??'')==='allocate')foreach(all('SELECT u.email FROM rd_allocations a JOIN users u ON u.id=a.evaluator_id WHERE a.student_id=? AND u.active=1',[$sid])as $ev)$emails[]=$ev['email'];
 foreach(array_unique($emails)as $email)deskQueueMail($email,'Research Tracking — '.$s['name'],$message."\n\nOpen Research Tracking: ".rtrim($GLOBALS['config']['url'],'/').'/desks/rd/index.php');
}
