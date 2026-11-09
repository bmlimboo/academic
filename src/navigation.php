<?php
function navigationModules(array $u):array{
 $keys=match($u['role']??'guest'){
 'lab'=>[],'librarian'=>['library'],'president'=>['substitution'],'pl'=>['substitution'],
 'exam'=>['substitution','consultation','exam'],'hod','faculty'=>['substitution','consultation'],
 'student','alumnus'=>[],default=>array_keys(modules())};return array_intersect_key(modules(),array_flip($keys));
}
function navigationExtras(array $u):array{
 $role=$u['role']??'guest';$links=[];$links['/researchtrack/']='Research Tracking';$links['?page=lab_booking']='Online Lab Booking';if(labManager($u))$links['?page=lab_manage']='Laboratory approvals';
 if(!in_array($role,['lab','librarian','president','pl','student','exam'],true)){$links['?page=calendar']='College Calendar';$links['?page=sa_portal']='Student Attendance Submission';$links['?page=timetable']='Consultation Timetable';if(attReady()&&attAccess($u))$links['?page=att_dashboard']='Tutor Attendance';$links['qr-display.php']='Classroom Display';}
 if($role==='exam')$links['?page=sa_portal']='Student Attendance Submission';
 if(canDocuments($u)&&!in_array($role,['hod','faculty','president','pl'],true))$links['?page=documents']='Programme Documents (DPD)';
 if($role==='admin'){$links['?page=teaching']='Teaching Staff';$links['?page=admin']='Users & Notifications';$links['?page=import']='Import Historical Data';}
 if(!in_array($role,['guest','student','alumnus'],true))$links['/desks/sd/index.php']='Document Signing';
 $links[$role==='guest'?'?page=alumni_register':'/desks/cd/index.php']='Certificate Requests';
 // Academic access remains beside Help, at the end of navigation.
 if(acAccess($u))$links['?page=ac_home']='Academic · Attendance & Marks';elseif($role==='guest')$links['?page=login']='Academic · Staff login';
 return $links;
}
