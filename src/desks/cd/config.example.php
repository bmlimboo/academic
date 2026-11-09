<?php
namespace CAS\CertDesk;
// CertDesk uses the existing CAS database, identity and mail transport.
date_default_timezone_set('Asia/Thimphu');
define(__NAMESPACE__.'\\APP_NAME', 'CertDesk');

// Confidential uploads are stored outside the public document root.
define(__NAMESPACE__.'\\MAX_UPLOAD', 10 * 1024 * 1024); // 10 MB per file
define(__NAMESPACE__.'\\CURRENCY', 'Nu.');
define(__NAMESPACE__.'\\CELL_NAME', 'Examination Cell');

// Address of this app, used for the links inside emails (no trailing slash).

// All notifications use the shared CAS mail queue. Signing libraries are bundled locally.

// Suggestions shown in the Programme field (the requester can still type any programme).
define(__NAMESPACE__.'\\PROGRAMMES', \examProgrammes());
define(__NAMESPACE__.'\\STORAGE_DIR', dirname(__DIR__,3).'/storage/desks/cd');
define(__NAMESPACE__.'\\BASE_URL', rtrim($GLOBALS['config']['url'],'/').'/desks/cd');
define(__NAMESPACE__.'\\MAIL_MODE', 'queue');
define(__NAMESPACE__.'\\PDFJS_URL','../sd/assets/vendor/pdf.min.js');
define(__NAMESPACE__.'\\PDFJS_WORKER_URL','../sd/assets/vendor/pdf.worker.min.js');
define(__NAMESPACE__.'\\PDFLIB_URL','../sd/assets/vendor/pdf-lib.min.js');
