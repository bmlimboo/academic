<?php
require_once __DIR__.'/../src/bootstrap.php';
db()->exec(file_get_contents(__DIR__.'/../migrations/017_exam_controls.sql'));
echo "Question paper staff notification preferences ready. Existing users and submissions preserved.\n";
