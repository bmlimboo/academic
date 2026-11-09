# Update: HoD/DAA approvals and staff Active/Inactive management

This cumulative update includes the preceding timetable, QR and mobile changes. It retains configuration, uploaded documents and existing records. Run the migration to add the staff classification table. Do not reinstall the application or recreate users.

## 1. Copy the update from your Mac

In the Mac Terminal (not the Ubuntu terminal), replace YOUR_USERNAME with your SSH username. Use root only if SSH root access is already enabled.

```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-academic-staff-approvals-update.zip" YOUR_USERNAME@10.10.140.137:/tmp/
ssh YOUR_USERNAME@10.10.140.137
```

## 2. Back up on Ubuntu

```bash
sudo -i
ls -lh /tmp/cnr-academic-staff-approvals-update.zip
ls /var/www/academic-system/public/index.php
systemctl stop apache2
tar -czf "/root/cnr-before-staff-approvals-$(date +%Y%m%d-%H%M%S).tar.gz" -C /var/www academic-system
mysqldump --single-transaction cnr_academic > "/root/cnr-before-staff-approvals-$(date +%Y%m%d-%H%M%S).sql"
ls -lh /root/cnr-before-staff-approvals-*
```

Check that both backups succeeded before continuing. If either fails, restart Apache with `systemctl start apache2` and resolve the backup first. The database command assumes the existing root socket authentication. Do not put your database password into the command line.

## 3. Install the update

PHP 8.2+ and the extensions used by the previous attendance release are required (mysql, gd, mbstring, xml, zip). The bundled vendor directory avoids needing Composer on the VM.

```bash
unzip -l /tmp/cnr-academic-staff-approvals-update.zip
unzip -o /tmp/cnr-academic-staff-approvals-update.zip -d /var/www/
cd /var/www/academic-system
php vendor/composer/platform_check.php
php bin/migrate.php
```

The migration ends with `Staff classification and status migration complete.` It retains existing requests, accounts, passwords, documents and attendance. Do not run setup.php or demo.php on the existing server. If migration fails, keep the site offline, correct the error and rerun migrate.php; MySQL schema changes are not automatically rolled back.

```bash
chown -R root:www-data src public vendor migrations bin
find src public vendor migrations bin -type d -exec chmod 750 {} \;
find src public vendor migrations bin -type f -exec chmod 640 {} \;
php -l public/index.php
apache2ctl configtest
systemctl start apache2
```

## 4. Manage existing staff

Admin → Users & Notifications → Account directory — all staff:

- Search by name or email, choose department, and optionally filter Active/Inactive.
- Edit a person to update department, official role, Regular/Adjunct classification and an optional status reason (semester ended, study, training, deputation, etc.).
- Use Disable to make a person Inactive. Use Enable when they return. This works for faculty and official staff roles. Another administrator manages your own status.
- No staff record, request or approval history is deleted. Inactive people disappear from active staff dropdowns and cannot be used in new substitution applications, including when submitted manually to the server.
- Disabling also prevents official login and revokes the registered attendance phone and unused enrolment links. After returning, attendance-enabled staff need a fresh one-time phone enrolment link. Staff type, teaching permission and existing schedules are retained; review term dates before resuming.
- To save a status reason, open Edit. The directory buttons retain the saved reason; update it when someone returns.

Existing staff default to Regular until you explicitly mark Adjunct; the system cannot infer employment category from names. Add teaching staff also offers Regular/Adjunct. There is no automatic end-of-semester disabling; the administrator chooses when to change status.

## 5. Substitution approvals

First stage: only an active HoD in the request's department can approve/reject. PL and DAA cannot act as HoD. The existing internal database statuses containing HoD/PL are retained for compatibility but displayed as HoD in request status labels.

Second stage: an active DAA can approve/reject requests from every department, only after HoD approval. DAA cannot bypass or overwrite the HoD stage. Rejections require a reason. Existing protection against approving your own request remains in effect.

Open a substitution request's details to see HoD Approval and DAA Approval separately, together with decision-maker and date/time in Bhutan time. DAA sees a disabled HoD action and only the DAA decision form when the request reaches that stage. After HoD rejection, DAA remains Pending and the explanation says its stage did not start.

Decision details are derived from existing request history. Older imported records without separate stage evidence show Not recorded; no approver name or date is fabricated. If a live record's status establishes an approval but lacks an audit entry, its stage status is shown with Not recorded for actor/date.

## 6. Department transfers and reports

Edit the department on the existing staff account. The same user ID remains linked to all prior records.

- New submissions use the updated department.
- Existing requests still awaiting HoD approval move to the new department and receive a Department reassigned history entry. The former department's HoD can no longer approve them.
- Requests already approved/rejected by HoD or completed keep the department recorded for that workflow. This preserves historical approval and department reporting context rather than rewriting completed records when a person transfers.
- Staff lists and staff-directory filters use the current department. Official reports include a Department filter based on each request's recorded department.

## 7. Verify after update

Check a faculty entry marked Adjunct, disable it, confirm it disappears from public substitution selectors, then enable the same entry again. Verify department search and a department transfer. Test one request through the correct HoD and then DAA, using separate official accounts. Confirm DAA cannot perform an HoD action and another department's HoD cannot approve it.

Local validation: 211 checks across workflow, staff roles, Active/Inactive and department changes, approval permissions, HTTP requests, and responsive browser layouts. Database tests use SQLite. The MySQL migration and real server configuration must be checked on your VM during installation; no live deployment was performed here.

## Rollback

Use the matching pre-update application and database backups together. Stop Apache first and take a backup of the current state. Restoring SQL removes submissions made since that backup, so update during a maintenance window. Restart Apache after restoring the matching code and database.
