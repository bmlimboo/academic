<?php
function passwordResetMessage():string{return 'If this email belongs to an active account, a password reset link will be emailed shortly. Check your inbox and spam folder.';}
function requestPasswordReset(string $email,string $ip,bool $trustedInvitation=false):void{
 global $config;$email=strtolower(trim($email));$keys=[hash('sha256','reset-email:'.$email),hash('sha256','reset-ip:'.$ip)];
 if(!$trustedInvitation)foreach($keys as $i=>$key){$n=(int)q('SELECT COUNT(*) FROM login_attempts WHERE identity=? AND attempted_at>?',[$key,time()-900])->fetchColumn();if($n>=($i===0?3:10))return;}
 if(!$trustedInvitation)foreach($keys as $key)q('INSERT INTO login_attempts(identity,attempted_at) VALUES(?,?)',[$key,time()]);
 if(!filter_var($email,FILTER_VALIDATE_EMAIL))return;
 $u=one('SELECT * FROM users WHERE LOWER(email)=? AND active=1',[$email]);if(!$u||!accountLogin($u))return;
 $ownTransaction=!db()->inTransaction();if($ownTransaction)db()->beginTransaction();try{
  // Serialize issuance for this user and invalidate older links.
  q('UPDATE users SET active=active WHERE id=?',[$u['id']]);
  q('UPDATE password_reset_tokens SET used=1 WHERE user_id=? AND used=0',[$u['id']]);
  $token=bin2hex(random_bytes(32));q('INSERT INTO password_reset_tokens(token_hash,user_id,password_version,expires_at,used) VALUES(?,?,?,?,0)',[hash('sha256',$token),$u['id'],hash('sha256',$u['password']),time()+1800]);
  $url=rtrim($config['url'],'/').'/?page=reset_password&token='.$token;
  q('INSERT INTO outbox(recipient,subject,body,created_at) VALUES(?,?,?,?)',[$u['email'],'CNR Academic Services - Reset your password',"A password reset was requested for your CNR Academic Services account.\n\nOpen this link to set a new password:\n".$url."\n\nThis link expires in 30 minutes and can be used once. Only the newest reset link works. If you did not request this, ignore this email; your password has not changed.\nNever share this link or your password.",now()]);
  if($ownTransaction)db()->commit();
 }catch(Throwable $e){if($ownTransaction&&db()->inTransaction())db()->rollBack();throw $e;}
}
function resetOfficialPassword(string $token,string $password,string $confirm):void{
 if(strlen($password)<12||strlen($password)>72)throw new RuntimeException('Use a password between 12 and 72 bytes.');
 if($password!==$confirm)throw new RuntimeException('The passwords do not match.');
 if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('This reset link is invalid or expired. Request a new link.');
 db()->beginTransaction();try{
  $t=one('SELECT * FROM password_reset_tokens WHERE token_hash=?',[hash('sha256',$token)]);
  $u=$t?one('SELECT * FROM users WHERE id=?',[$t['user_id']]):false;
  if(!$t||$t['used']||(int)$t['expires_at']<=time()||!$u||!accountLogin($u)||!hash_equals($t['password_version'],hash('sha256',$u['password'])))throw new RuntimeException('This reset link is invalid or expired. Request a new link.');
  $used=q('UPDATE password_reset_tokens SET used=1 WHERE token_hash=? AND used=0 AND expires_at>?',[$t['token_hash'],time()]);
  if($used->rowCount()!==1)throw new RuntimeException('This reset link has already been used. Request a new link.');
  $changed=q('UPDATE users SET password=? WHERE id=? AND password=? AND active=1',[password_hash($password,PASSWORD_DEFAULT),$u['id'],$u['password']]);
  if($changed->rowCount()!==1)throw new RuntimeException('This account changed. Request a new link.');
  q('UPDATE password_reset_tokens SET used=1 WHERE user_id=?',[$u['id']]);
  q('INSERT INTO outbox(recipient,subject,body,created_at) VALUES(?,?,?,?)',[$u['email'],'CNR Academic Services - Password changed','Your official account password was reset. If you did not make this change, contact the ICT Office immediately.',now()]);
  db()->commit();
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
