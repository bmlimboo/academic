<?php
namespace CAS\ResearchDesk;
// Research Tracking Desk configuration. Defaults match a fresh XAMPP install.
date_default_timezone_set('Asia/Thimphu');
define(__NAMESPACE__.'\\APP_NAME', 'Research Tracking Desk');
define(__NAMESPACE__.'\\APP_SUB', 'RES402 Research project');

// Uploaded files (proposals, drafts, articles) are private. In production, move this folder OUTSIDE the web root.
define(__NAMESPACE__.'\\MAX_UPLOAD', 15 * 1024 * 1024);   // 15 MB per file

define(__NAMESPACE__.'\\DEPARTMENTS', \departments());
// Login role names (the login page lets the user choose one of these)
const ROLES = [
    'student'    => 'Student',
    'supervisor' => 'Supervisor',
    'evaluator'  => 'Evaluator',
    'pl'         => 'Programme Leader',
    'daa'        => 'DAA (Coordinator)',
];
define(__NAMESPACE__.'\\STORAGE_DIR', dirname(__DIR__,3).'/storage/desks/rd');
define(__NAMESPACE__.'\\ACADEMIC_YEAR', date('Y').'/'.(date('Y')+1));
