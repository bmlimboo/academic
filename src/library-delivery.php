<?php
function libraryReply(array $u,array $p):void {
 $id=(int)($p['id']??0);$r=one("SELECT * FROM requests WHERE id=? AND module='library'",[$id]);
 if(!$r||empty($u['active'])||!in_array($u['role'],['admin','librarian'],true))throw new RuntimeException('Library or Admin access required.');
 $file=$_FILES['response_document']??[];$saved=null;$fid=null;
 try{
 if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
  if($file['error']!==UPLOAD_ERR_OK||$file['size']>10*1024*1024||!is_uploaded_file($file['tmp_name']))throw new RuntimeException('Choose a PDF or Word file up to 10 MB.');
  $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
  if(!in_array($ext,['pdf','doc','docx'],true))throw new RuntimeException('Only PDF, DOC or DOCX is accepted.');
  if($ext==='pdf'&&($mime!=='application/pdf'||file_get_contents($file['tmp_name'],false,null,0,5)!=='%PDF-'))throw new RuntimeException('Invalid PDF document.');
  if($ext==='doc'&&!in_array($mime,['application/msword','application/x-ole-storage','application/CDFV2'],true))throw new RuntimeException('Invalid Word document.');
  if($ext==='docx'){$zip=new ZipArchive();if($zip->open($file['tmp_name'])!==true)throw new RuntimeException('Invalid DOCX document.');$valid=$zip->locateName('word/document.xml')!==false&&$zip->locateName('[Content_Types].xml')!==false;$zip->close();if(!$valid)throw new RuntimeException('Invalid DOCX document.');$mime='application/vnd.openxmlformats-officedocument.wordprocessingml.document';}
  $key=bin2hex(random_bytes(24));$saved=__DIR__.'/../storage/uploads/'.$key;if(!move_uploaded_file($file['tmp_name'],$saved))throw new RuntimeException('Unable to store response document.');chmod($saved,0600);
  q('INSERT INTO files(request_id,name,path,mime,size,label) VALUES(?,?,?,?,?,?)',[$id,basename($file['name']),$key,$mime,$file['size'],'Library response document']);$fid=(int)db()->lastInsertId();
 }
 $before=(int)q('SELECT COALESCE(MAX(id),0) FROM outbox')->fetchColumn();
 transition($u,$id,required($p,'status'),trim($p['note']??''));
 $applicant=one('SELECT email FROM users WHERE id=?',[$r['applicant_id']]);$mail=one('SELECT id,body FROM outbox WHERE id>? AND recipient=? ORDER BY id DESC',[$before,$applicant['email']]);if(!$mail)throw new RuntimeException('Unable to queue applicant document delivery.');
 $body=$mail['body']."\n\nLibrary response: ".trim($p['note']??'');if($fid)$body.="\nThe response document is attached.";q('UPDATE outbox SET body=? WHERE id=?',[$body,$mail['id']]);
 if($fid){q('INSERT INTO mail_attachments(outbox_id,file_id) VALUES(?,?)',[$mail['id'],$fid]);audit($id,(int)$u['id'],'Response document queued',basename($file['name']));}
 }catch(Throwable $e){if($saved&&is_file($saved))unlink($saved);throw $e;}
}
function queuedMailContent(array $m):array {
 global $config;$headers=['From'=>$config['mail_from'],'Content-Type'=>'text/plain; charset=UTF-8'];
 try{$files=all('SELECT f.* FROM mail_attachments a JOIN files f ON f.id=a.file_id WHERE a.outbox_id=?',[$m['id']]);}catch(PDOException $e){if($e->getCode()!=='42S02'&&!str_contains($e->getMessage(),'no such table: mail_attachments'))throw $e;$files=[];}
 if(!$files)return [$m['body'],$headers];
 $boundary='cnr-'.bin2hex(random_bytes(18));$headers['MIME-Version']='1.0';$headers['Content-Type']='multipart/mixed; boundary="'.$boundary.'"';
 $body='--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($m['body']));
 foreach($files as $f){if(!preg_match('/^[a-f0-9]{48}$/',$f['path']))throw new RuntimeException('Invalid attachment path.');$path=__DIR__.'/../storage/uploads/'.$f['path'];if(!is_readable($path))throw new RuntimeException('Email attachment missing.');$name=preg_replace('/[^A-Za-z0-9._ -]/','_',basename($f['name']));$body.='--'.$boundary."\r\nContent-Type: ".$f['mime']."\r\nContent-Disposition: attachment; filename=\"".$name."\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode(file_get_contents($path)));}
 return [$body.'--'.$boundary."--\r\n",$headers];
}
