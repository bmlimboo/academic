<?php
require_once __DIR__.'/../src/bootstrap.php';
$sql=file_get_contents(__DIR__.'/../migrations/012_results.sql');
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY AUTO_INCREMENT',$sql);
db()->exec($sql);
if(!one('SELECT id FROM result_settings WHERE id=1'))q('INSERT INTO result_settings(id,payload) VALUES(1,?)',[json_encode(rtDefaults(),JSON_THROW_ON_ERROR)]);
echo "Results portal ready. Existing records preserved.\n";
