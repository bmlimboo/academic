<div class="heading"><div><span class="eyebrow">USER SUPPORT</span><h1>Help &amp; Manuals</h1><p>Simple illustrated guides for substitution requests and approvals.</p></div></div>
<section class="card"><h2>Choose your guide</h2><p>Faculty can submit without signing in. HoD, DAA and President must sign in with their official accounts to approve requests.</p><div class="form-grid">
<?php foreach([
 ['faculty-substitution-manual.pdf','Faculty: Submit a substitution request','Fill in travel details, add affected classes, upload supporting documents and check your request status.'],
 ['hod-substitution-manual.pdf','HoD: Review and approve','Review requests from your department and forward approved requests to DAA.'],
 ['daa-substitution-manual.pdf','DAA: Final approval','Approve faculty requests after HoD review and approve HoD applications directly.'],
 ['president-substitution-manual.pdf','President: Review DAA requests','Review and give the final decision on substitution requests submitted by DAA.']
] as [$file,$title,$description]):?><article><h2><?=e($title)?></h2><p><?=e($description)?></p><p><a class="button" href="manuals/<?=e($file)?>" target="_blank" rel="noopener">Open guide (PDF)</a></p><p><a href="manuals/<?=e($file)?>" download>Download PDF</a></p></article><?php endforeach;?></div></section>
<section class="card"><h2>Need assistance?</h2><p><a href="?page=forgot_password">Reset your official-account password</a>. Reset links expire after 30 minutes. Substitution forms remain available without login. Academic attendance and marks require staff login.</p><p>If your name is missing, your email does not match, or an approval action is unavailable, contact the system administrator or ICT Office. Give the request reference number and describe the issue. Never share your password.</p><p>The screenshots use fictional demonstration records. Button labels and steps follow the application workflow.</p><a href="?page=new&amp;module=substitution">Open substitution form</a></section>

<section class="card"><h2>Academic: marks &amp; re-assessment</h2><ol>
<li>Choose <b>Academic · Staff login</b>. Existing active teaching staff can use <b>Forgot password</b> to set a password through their registered email.</li>
<li>Create a module draft under CA &amp; semester marks. Select the teaching programme, year, semester and academic year; enter the exact DPD contact hours.</li>
<li>Download that module’s Excel template. Fill the Data sheet, upload it, and review the preview. You can merge student rows or replace a corrected sheet before submitting.</li>
<li>Assessment: configure CA components and optional ESE. Weights must total 100%. Enter raw marks; blank marks are incomplete. Pass requires 40% of CA, 40% of ESE where applicable, and total 50 or above.</li>
<li>Choose <b>Submit final record</b> only after checking everything. The tutor receives a receipt and cannot view or edit the submitted entries. For marks, PL and DAA view reports; Admin can view all records and correct submitted marks or contact hours with a reason. For attendance, use the Exam Cell Portal workflow below.</li>
</ol><h3>Re-assessment</h3><p>Exam Cell/Admin opens entry dates and confirms the cohort’s expected module codes. Once all original marks are present, one or two failed modules permit re-assessment; three or more indicate Repeat semester. Tutors select their original module and enter the new ESE raw mark during the open window. Original CA is retained. Successful re-assessment is capped at 50 and updates the consolidated result. Original results are retained. CA-only modules need a separate re-assessment rule.</p><p><a class="button" href="?page=ac_home">Open Academic workspace</a></p></section>

<section class="card"><h2>Student attendance — Exam Cell Portal</h2><ol>
<li>Open <a href="?page=sa_portal">Student Attendance</a>. An enabled submission period must match your academic year, semester and programme.</li>
<li>Enter your registered teaching email and verify the six-digit code. No account password is needed. Check spam; codes expire after 15 minutes and verification lasts two hours.</li>
<li>For a new module, fill the existing module details and DPD hours. For another upload to the same module, choose <b>Add attendance</b>.</li>
<li>Download the register template. Dates are in row 1, periods in row 2, Regular/Extra in row 3 and classroom in row 4. Enter each student once from row 5. Copy period columns to the right for more dates; use YYYY-MM-DD dates.</li>
<li>Use P/A for classes taught; leave unused periods blank for everyone. Extra present hours count towards fixed DPD hours. Upload, check the preview and submit.</li>
<li>Later uploads add dates/periods without counting identical entries twice. Only Admin/Exam Cell/DAA can correct submitted attendance or DPD hours.</li>
</ol><p><b>Admin / Exam Cell / DAA:</b> sign in and open Attendance → Submission periods to schedule or close uploads. Attendance review shows the date-across-top register. Download the full register, correct it and upload it with a reason to make an audited replacement.</p>
<p><b>Eligibility:</b> below 80% is not allowed; 80% to below 90% needs a supporting PDF and verification; 90% or above is allowed. Verify incomplete coverage before deciding. Any attendance or DPD correction invalidates earlier verification decisions until reviewed again.</p></section>
