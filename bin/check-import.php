<?php
// Read-only check using the same configuration as the application. Never prints credentials.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../src/bootstrap.php';
try{
 echo 'Database driver: '.db()->getAttribute(PDO::ATTR_DRIVER_NAME)."\n";
 if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')echo 'Database name: '.q('SELECT DATABASE()')->fetchColumn()."\n";
 echo 'All requests: '.q('SELECT COUNT(*) FROM requests')->fetchColumn()."\n";
 echo 'Substitution records: '.q("SELECT COUNT(*) FROM requests WHERE module='substitution'")->fetchColumn()."\n";
 echo 'Historical substitution imports: '.q("SELECT COUNT(*) FROM requests WHERE module='substitution' AND import_key IS NOT NULL")->fetchColumn()."\n";
 echo 'Missing applicant links: '.q('SELECT COUNT(*) FROM requests r LEFT JOIN users u ON u.id=r.applicant_id WHERE u.id IS NULL')->fetchColumn()."\n";
 foreach(all('SELECT module,status,COUNT(*) AS total FROM requests GROUP BY module,status ORDER BY module,status')as $r)echo $r['module'].' | '.$r['status'].' | '.$r['total']."\n";
}catch(Throwable $e){fwrite(STDERR,"Could not read the configured database. Check application configuration and database access.\n");exit(1);}
