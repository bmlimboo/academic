<?php
$stored=(int)q("SELECT COUNT(*) FROM requests WHERE module='substitution'")->fetchColumn();
$historical=(int)q("SELECT COUNT(*) FROM requests WHERE module='substitution' AND import_key IS NOT NULL")->fetchColumn();
$visible=(int)q("SELECT COUNT(*) FROM requests r JOIN users u ON u.id=r.applicant_id WHERE r.module='substitution'")->fetchColumn();
?><section class="card"><h2>Records actually saved</h2><p><strong><?=$stored?></strong> substitution records in this application's database, including <strong><?=$historical?></strong> historical imports.</p><p><a class="button secondary" href="?page=reports&module=substitution">View substitution report</a> <a class="button secondary" href="?module=substitution">View dashboard records</a></p>
<?php if($stored===0):?><div class="notice">No substitution records are stored yet. Upload your file, review the preview, then click <b>Save these records to database</b>. Validation alone does not save records.</div><?php endif;?>
<?php if($stored!==$visible):?><div class="notice error"><?=$stored-$visible?> stored requests have a missing applicant link. Do not re-import them yet; ask the administrator to check the database.</div><?php endif;?></section>
