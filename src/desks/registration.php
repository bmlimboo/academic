<?php
function alumniPost(array $p):void{
 if(($p['action']??'')==='alumni_code'){
  $name=trim((string)($p['name']??''));$email=strtolower(trim((string)($p['email']??'')));$password=(string)($p['password']??'');
  if($name===''||mb_strlen($name)>160||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)throw new RuntimeException('Enter your name and a valid email address.');
  if(strlen($password)<12||strlen($password)>72)throw new RuntimeException('Choose a password between 12 and 72 characters.');
  if($password!==($p['password_confirm']??''))throw new RuntimeException('Passwords do not match.');
  if(one('SELECT id FROM users WHERE LOWER(email)=?',[$email]))throw new RuntimeException('This email already has a CAS account. Sign in or use Forgot password.');
  foreach(['alumni-email:'.$email,'alumni-ip:'.($_SERVER['REMOTE_ADDR']??'')]as $raw){$key=hash('sha256',$raw);if((int)q('SELECT COUNT(*) FROM login_attempts WHERE identity=? AND attempted_at>?',[$key,time()-900])->fetchColumn()>=5)throw new RuntimeException('Too many code requests. Try again in 15 minutes.');}
  foreach(['alumni-email:'.$email,'alumni-ip:'.($_SERVER['REMOTE_ADDR']??'')]as $raw)q('INSERT INTO login_attempts(identity,attempted_at) VALUES(?,?)',[hash('sha256',$raw),time()]);
  $code=(string)random_int(100000,999999);$_SESSION['alumni_pending']=['name'=>$name,'email'=>$email,'password'=>password_hash($password,PASSWORD_DEFAULT),'hash'=>password_hash($code,PASSWORD_DEFAULT),'expires'=>time()+600,'tries'=>0];
  q('INSERT INTO outbox(recipient,subject,body,created_at) VALUES(?,?,?,?)',[$email,'CNR Certificate Requests — Verify your email',"Your verification code is: $code\n\nIt expires in 10 minutes. Do not share this code. If you did not request registration, ignore this email.",now()]);return;
 }
 $v=&$_SESSION['alumni_pending'];if(!$v||$v['expires']<time()||$v['tries']>=5)throw new RuntimeException('The code has expired or too many attempts were made. Request a new code.');$v['tries']++;
 if(!preg_match('/^\d{6}$/D',(string)($p['code']??''))||!password_verify($p['code'],$v['hash']))throw new RuntimeException('Incorrect verification code.');
 db()->beginTransaction();try{if(one('SELECT id FROM users WHERE LOWER(email)=?',[$v['email']]))throw new RuntimeException('An account already exists. Sign in instead.');q("INSERT INTO users(name,email,password,role,department,active) VALUES(?,?,?,'alumnus','',1)",[$v['name'],$v['email'],$v['password']]);$id=(int)db()->lastInsertId();$version=hash('sha256',$v['password']);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
 unset($_SESSION['alumni_pending']);session_regenerate_id(true);$_SESSION['user']=$id;$_SESSION['password_version']=$version;
}
