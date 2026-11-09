<?php
require_once dirname(__DIR__).'/bridge.php';
if(session_status()!==PHP_SESSION_ACTIVE){ini_set('session.use_strict_mode','1');session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','samesite'=>'Lax']);session_start();}
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: SAMEORIGIN');
$_SESSION['csrf']??=bin2hex(random_bytes(32));
$current=isset($_SESSION['user'])&&is_numeric($_SESSION['user'])?one('SELECT * FROM users WHERE id=? AND active=1',[(int)$_SESSION['user']]):null;
$valid=$current&&accountLogin($current)&&hash_equals(hash('sha256',$current['password']),$_SESSION['password_version']??'');
if($valid&&($current['role']==='admin'||(($_SESSION['research_login_user']??0)===$current['id']&&in_array($_SESSION['desk_role_rd']??'',deskOptions('rd',$current),true)))){header('Location: /desks/rd/index.php');exit;}
$roles=['daa'=>'DAA (Coordinator)','pl'=>'Programme Leader (PL)','supervisor'=>'Staff / Supervisor','evaluator'=>'Evaluator','student'=>'Student'];
$error='';$role=(string)($_POST['role']??'');$email=trim((string)($_POST['email']??''));
if($_SERVER['REQUEST_METHOD']==='POST')try{
 if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Your session expired. Refresh and try again.');
 $identity=hash('sha256',strtolower($email).'|'.($_SERVER['REMOTE_ADDR']??''));
 if((int)q('SELECT COUNT(*) FROM login_attempts WHERE identity=? AND attempted_at>?',[$identity,time()-900])->fetchColumn()>=10)throw new RuntimeException('Too many login attempts. Try again in 15 minutes.');
 q('INSERT INTO login_attempts(identity,attempted_at) VALUES(?,?)',[$identity,time()]);
 $u=one('SELECT * FROM users WHERE LOWER(email)=? AND active=1',[strtolower($email)]);
 if(!$u||!accountLogin($u)||!isset($roles[$role])||!password_verify((string)($_POST['password']??''),$u['password'])||!in_array($role,deskOptions('rd',$u),true))throw new RuntimeException('The email, password or role is not correct. Choose the role assigned to you.');
 session_regenerate_id(true);unset($_SESSION['desk_role_sd'],$_SESSION['desk_role_cd']);$_SESSION['user']=$u['id'];$_SESSION['password_version']=hash('sha256',$u['password']);$_SESSION['research_login_user']=$u['id'];$_SESSION['desk_role_rd']=$role;
 q('DELETE FROM login_attempts WHERE identity=?',[$identity]);
 $next=$_SESSION['research_login_next']??'/desks/rd/index.php';unset($_SESSION['research_login_next']);if(!preg_match('~^/desks/rd/(?!login)[a-z_]+\.php(?:\?[^\r\n]*)?$~D',$next))$next='/desks/rd/index.php';header('Location: '.$next);exit;
}catch(Throwable $ex){$error=$ex instanceof PDOException?'Sign-in is temporarily unavailable. Please contact Admin.':$ex->getMessage();}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in · Research Tracking Desk</title><link rel="stylesheet" href="/desks/rd/assets/style.css"></head><body class="loginpage"><div class="logincard"><div class="brand dark">Research Tracking Desk</div><p class="muted">RES402 Research project</p><?php if($error):?><div class="flash err" role="alert"><?=e($error)?></div><?php endif;?><form method="post" class="formcard flat"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><label>I am signing in as<select name="role" required><option value="">Choose your role</option><?php foreach($roles as $key=>$label):?><option value="<?=e($key)?>" <?=$key===$role?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label><label>Email<input type="email" name="email" required autofocus autocomplete="username" value="<?=e($email)?>"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="btn" type="submit">Sign in</button></form><p class="muted small">Use your registered CAS email and password. New students: contact Admin for an account.</p><p><a href="/?page=forgot_password">Forgot password?</a></p><p><a href="/">← Back to CAS</a> · <a href="/?page=login">Admin login</a></p></div></body></html>
