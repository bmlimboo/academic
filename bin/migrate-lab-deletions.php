<?php
require_once __DIR__.'/../src/bootstrap.php';
db()->exec(file_get_contents(__DIR__.'/../migrations/015_lab_deletions.sql'));
echo "Lab deletion audit ready.\n";
