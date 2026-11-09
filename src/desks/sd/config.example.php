<?php
namespace CAS\SignDesk;
// SignDesk configuration. Defaults match a fresh XAMPP install.
define(__NAMESPACE__.'\\APP_NAME', 'SignDesk');

// Uploaded files live here. In production, move this folder OUTSIDE the web root.
define(__NAMESPACE__.'\\MAX_UPLOAD', 10 * 1024 * 1024); // 10 MB

const DOC_TYPES = [
    'Internship',
    'Block day request',
    'Block week request',
    'Others',
];

// Dispatch numbers are typed by the DAA. This prefix and the academic-year month only feed the example/placeholder.
define(__NAMESPACE__.'\\DISPATCH_PREFIX', 'CNR/DAA-06');

const SUBMITTER_TYPES = ['Staff', 'Faculty'];
define(__NAMESPACE__.'\\DEPARTMENTS', \departments());

// Everything is PDF: submitted documents and signed copies.
const ACCEPT_SUBMIT = ['pdf'];
const ACCEPT_SIGNED = ['pdf'];

define(__NAMESPACE__.'\\STAMP_IMG_MAX', 2 * 1024 * 1024); // signature / seal image, 2 MB
define(__NAMESPACE__.'\\STORAGE_DIR', dirname(__DIR__,3).'/storage/desks/sd');
const ACADEMIC_YEAR_START_MONTH = 7;
