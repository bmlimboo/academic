# Exam Cell Portal and office-order notifications — 13 October 2026

This update has been tested locally; installing the ZIP is required to change the live system. It preserves configuration, passwords, existing records and uploads. It adds database tables through bin/migrate.php. It does not publish an examination or send an opening announcement on installation.

## 1. Copy the update from your Mac

Run in your Mac Terminal:

```bash
scp /Users/budhimanlimboo/Documents/myproject/deliverables/cnr-exam-portal-update.zip root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

## 2. Back up on Ubuntu

Use a quiet period. Run each block separately. Stop if any backup or check fails. Your database is assumed to be named cnr_academic; substitute the actual name if different.

```bash
cd /var/www/academic-system
backup_dir="/root/cnr-before-exam-portal-$(date +%Y%m%d-%H%M%S)"
mkdir -m 700 "$backup_dir"
cp /etc/cron.d/cnr-academic-mail "$backup_dir/cnr-academic-mail"
mv /etc/cron.d/cnr-academic-mail "$backup_dir/cnr-academic-mail.paused"
systemctl stop apache2
```

Wait at least one minute for any existing worker to finish. Check:

```bash
pgrep -af '[p]hp.*mail-worker.php'
```

No output means no PHP mail worker is running. If one appears, wait for it to finish before proceeding. Other cron jobs remain enabled.

```bash
tar -czf "$backup_dir/application.tar.gz" public src bin migrations config storage
mysqldump --single-transaction cnr_academic > "$backup_dir/database.sql"
chmod 600 "$backup_dir/database.sql"
ls -lh "$backup_dir/application.tar.gz" "$backup_dir/database.sql"
```

If MySQL asks for authentication, run `mysqldump -u root -p --single-transaction cnr_academic > "$backup_dir/database.sql"` instead and enter the password at the prompt. Do not proceed with a failed or empty database backup.

## 3. Install and migrate

```bash
unzip -o /tmp/cnr-exam-portal-update.zip -d /var/www/academic-system
php -l src/exam-portal.php
php -l src/exam-view.php
php -l src/office-order.php
php -l src/bootstrap.php
php -l public/index.php
php -l bin/mail-worker.php
php bin/migrate.php
```

All syntax checks must pass. The migration must end with `Exam Cell portal migration complete.`

```bash
chown -R root:www-data public src bin migrations
find public src bin migrations -type d -exec chmod 755 {} \;
find public src bin migrations -type f -exec chmod 644 {} \;
systemctl start apache2
mv "$backup_dir/cnr-academic-mail.paused" /etc/cron.d/cnr-academic-mail
chown root:root /etc/cron.d/cnr-academic-mail
chmod 644 /etc/cron.d/cnr-academic-mail
systemctl is-active apache2 cron
```

Both should show active. Your existing cron job now also queues examination reminders. Do not create a second cron job.

PDFs have a 10 MB limit each. Your previously requested PHP settings of upload_max_filesize=20M and post_max_size=50M support the two PDFs needed for late submissions. Storage/uploads must remain writable by www-data, as for existing attachments. Do not expose storage through Apache aliases or a public directory.

## 4. Set the official account

1. Sign in as administrator → Users & Notifications.
2. Edit the existing Examination/Student Records staff account.
3. Select **Examination & Student Records (exam)**, keep the account Active, and set an initial password if newly granting official access.
4. This single existing role covers both Examination and Student Records officials. Each official can have their own account with this role.
5. Sign in with that account → Exam Cell Portal → Examination dashboard & archive.

## 5. Open submissions

1. Create a draft examination: academic year, semester I/II, examination type, opening time and deadline. Times are Bhutan time.
2. Select the draft in the examination selector and click View. Review dates; Edit draft dates is available before publishing.
3. Click Publish submission period. If the opening time has arrived, opening emails are queued immediately; otherwise the worker queues them when it arrives.
4. The opening announcement goes to every active teaching staff member. No expected-module list needs to be entered.
5. Published deadlines are fixed to match the notification. Review carefully before publishing.

Public portal: https://cas.cnr.edu.bt/?page=exam_portal

Paper submission: https://cas.cnr.edu.bt/?page=exam_submit

Officials dashboard: https://cas.cnr.edu.bt/?page=exam_manage

## 6. Staff submissions and status

- Public form; teaching staff do not need to log in.
- Select the examination, staff name, department; enter the exact registered staff email, module code and module title; upload a PDF and confirm.
- Department may be the department being taught, even if different from the tutor's home department.
- The registered email receives a receipt. Name/email matching is not proof of identity; Exam Cell should investigate any unexpected submission reported by the staff member.
- One active paper per staff member + module code + examination. Additional distinct modules are allowed.
- Submitted means at least one active paper received for that examination. Not submitted means none. The dashboard lists all currently active teaching staff, including teaching HoDs/DAA/PLs; it does not infer that every module paper is complete.
- Text entries are retained on errors; browser file inputs must be selected again.

## 7. Reminders and late submissions

- At 48 hours before the deadline, the worker queues one reminder per active teaching staff member with no submitted paper.
- If a period is opened with fewer than 48 hours remaining, the reminder is queued on the next notification run.
- When the deadline closes, a separate notification is queued for active staff with no regular paper submitted before the deadline. Late papers do not count as on-time submission.
- Each notification phase runs once per examination; it does not repeatedly email new/reactivated staff about old examinations.
- Cron checks every minute. Notifications are queued on the first successful run at or after the scheduled time, not guaranteed to arrive at the exact second. The worker sends batches of up to 50 messages per run; a larger announcement can take several minutes. Mail-server/inbox delays are separate.
- Exam Cell can enable the late-submission checkbox after the regular deadline. The tutor must then upload the question paper AND an approval PDF containing HoD and DAA approvals (each at most 10 MB).
- Exam Cell reviews the approval PDF manually; the system checks the file format, not the authenticity of the signatures.
- Disable late submissions when the exceptional submission period is over.

## 8. Corrections, deletion and archive

- Archive is grouped by selected academic year / semester / examination; downloads include year, examination, module and staff name in their filename.
- Only Exam Cell and administrators can download question papers and approval PDFs. They are not shown in the public services list.
- Exam Cell can edit module title and department, or remove an incorrect paper with a reason. Removed versions are retained in the restricted archive and no longer count as Submitted unless another active paper exists.
- Replacement is possible only during an open regular period or an enabled late period. Removing a paper does not bypass deadlines.
- Existing Exam Cell requisitions remain available under Requisition records. Exam Cell can update their status, correct purpose/details, or remove a mistaken requisition with a recorded reason.

## 9. Office-order notifications

New final substitution approvals queue the requested travel-details email to singyew.cnr@rub.edu.bt. Faculty wording mentions HoD and DAA; HoD applicants mention DAA only; DAA applicants mention President. It includes staff, department, substitutes, purpose, destination and travel dates, plus the official request link.

No old historical approvals are emailed automatically. The notification is queued once per final approval; administrators can see it in the request's email-delivery table. Deleting a request cancels this notification if it is still queued/failed, but cannot recall an already delivered email.

## 10. Verify and recover

Check one minute after installation:

```bash
journalctl -t cnr-academic-mail --since "5 minutes ago" --no-pager
```

Use a test examination and a dummy non-sensitive PDF to verify submission, the receipt, authorized download and deletion before opening a real examination. Publishing sends announcements, so do not publish a test examination against real staff unless you intend those notifications.

If installation fails, keep the mail schedule paused until application files and database are consistent. Restore the saved application with `tar -xzf "$backup_dir/application.tar.gz" -C /var/www/academic-system`. Existing tables were not altered by the new migration, so the previous application can use its existing data while the new unused tables remain. Start Apache and restore the paused cron file as in step 3 after checking the restored application. A database restore is only needed if necessary and would remove newer writes; do not import it casually.

Local verification: notification/workflow tests, public access regression tests, real multipart PDF upload/late-upload/download/deletion tests against an isolated SQLite server, and desktop/mobile layout review. Live MySQL, production mail delivery and live deployment were not executed from this chat.
