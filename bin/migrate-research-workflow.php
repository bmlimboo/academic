<?php
require_once __DIR__.'/../src/bootstrap.php';
$sql=file_get_contents(__DIR__.'/../migrations/013_research_workflow.sql');
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql=str_replace('INTEGER PRIMARY KEY AUTOINCREMENT','INTEGER PRIMARY KEY AUTO_INCREMENT',$sql);
db()->exec($sql);echo "Research workflow tables ready. Existing records retained.\n";
