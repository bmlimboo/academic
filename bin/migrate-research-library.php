<?php
require_once __DIR__.'/../src/bootstrap.php';
$sql=file_get_contents(__DIR__.'/../migrations/011_research_library.sql');
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY AUTO_INCREMENT',$sql);
db()->exec($sql);
echo "Research and Library attachment tables ready. Existing records preserved.\n";
