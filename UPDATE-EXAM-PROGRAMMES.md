# Programme selection, status filters and period deletion — 14 October 2026

This cumulative update supersedes the earlier examination-options patch. Install this ZIP and run the migration. Configuration, passwords, PDFs and existing records are preserved. Do not install the older options-only ZIP after this update.

## Changes

- Tutors select Department, then Programme. No prior programme assignment by Admin is required. The server checks that the selected programme belongs to the department. Cross-department teaching remains possible.
- All 12 programmes in the supplied list are included. Existing department identity “Department of Forest Science” is retained for compatibility with staff and approval records; its programme choices are BSc. Forestry, MSc. Natural Resource Management and MSc. Conservation Biology.
- Papers are distinguished by examination + tutor + programme + module code. The same module code can be submitted for two different programmes. Repeated submissions for the same combination are blocked.
- Programme is recorded in the archive, confirmation email and PDF download filename.
- Before a published period reaches its opening time, the public Question Paper Submission page displays an announcement-pending message with no dropdowns or upload form. Only announced examination types appear. Closed, announced periods still show their closed/late-submission status.
- Exam Cell and Adm Section are available in account creation, editing and staff-directory filters. They are not academic programme departments.
- Teaching staff status: ten rows per page, name/email, department, programme and Submitted/Not submitted filters. Dropdown changes apply filters immediately; use Filter or Enter for a name search. Page links preserve filters.
- Counts show Submitted and Not submitted totals for the selected name/department/programme scope. A status filter narrows displayed rows but keeps both totals visible. Submitted means at least one active paper in that scope.
- Programme is chosen at submission, so a tutor who has never submitted has no recorded programme. Those tutors remain visible in the full list and “Programme not yet recorded” filter. The system cannot reliably identify all missing submissions for a particular programme without a prior teaching-programme list. Department filtering also includes recorded teaching departments, not only the home department.
- Admin and Exam Cell can delete draft or published submission periods with a reason. This closes uploads, cancels queued/failed emails for that period and prevents future notifications. Delivered emails cannot be recalled. PDFs and audit information remain in a restricted archive.

## 1. Copy from your Mac

```bash
scp /Users/budhimanlimboo/Documents/myproject/deliverables/cnr-exam-programmes-update.zip root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

## 2. Pause the application and its mail worker

Run in the same Ubuntu terminal session. Stop if any command fails.

```bash
cd /var/www/academic-system
backup_dir="/root/cnr-before-exam-programmes-$(date +%Y%m%d-%H%M%S)"
mkdir -m 700 "$backup_dir"
cp /etc/cron.d/cnr-academic-mail "$backup_dir/cnr-academic-mail"
mv /etc/cron.d/cnr-academic-mail "$backup_dir/cnr-academic-mail.paused"
systemctl stop apache2
```

Wait at least one minute, then check:

```bash
pgrep -af '[p]hp.*mail-worker.php'
```

No output is correct: no worker is running. If a worker appears, let it finish before proceeding. If the cron file was already paused during an earlier installation, locate that backup rather than creating a duplicate schedule.

## 3. Back up

```bash
tar -czf "$backup_dir/application.tar.gz" public src bin migrations config storage
mysqldump --single-transaction cnr_academic > "$backup_dir/database.sql"
chmod 600 "$backup_dir/database.sql"
ls -lh "$backup_dir/application.tar.gz" "$backup_dir/database.sql"
```

Both files must exist and be non-empty, and both commands must succeed. Use `mysqldump -u root -p --single-transaction cnr_academic > "$backup_dir/database.sql"` if MySQL requires a password. Use the actual database name if yours differs.

## 4. Install and migrate

```bash
unzip -o /tmp/cnr-exam-programmes-update.zip -d /var/www/academic-system
php -l src/exam-portal.php
php -l src/exam-programmes.php
php -l src/exam-view.php
php -l src/exam-status.php
php -l bin/migrate-exam-programmes.php
php -l bin/mail-worker.php
php bin/migrate.php
```

All syntax checks must pass. The final migration message should be:

```text
Exam programmes, notification links and period deletion migration complete.
```

This migration adds programme and period-deletion fields, changes the unique module constraint to include programme, and links existing notification messages to their examination period. It preserves existing record IDs. Re-running it is supported.

```bash
chown -R root:www-data public src bin migrations
find public src bin migrations -type d -exec chmod 755 {} \;
find public src bin migrations -type f -exec chmod 644 {} \;
systemctl start apache2
```

Keep the mail schedule paused while removing your unwanted test period in the next step. This prevents its pending reminders from being sent during cleanup.

## 5. Delete the test period

1. Sign in as Admin or Exam Cell.
2. Open Exam Cell Portal → Examination dashboard & archive.
3. Select the test period by its academic year, semester, type and dates; click View.
4. Expand **Delete this submission period**.
5. Enter a reason such as “Test period created while checking the portal”.
6. Click **Delete submission period** and confirm.
7. The period disappears from the normal selector. Use **View archive including deleted periods** to inspect its retained PDFs.

This action is never performed automatically by installing the update. Check that you have selected the intended test period before deleting.

## 6. Restore automatic email processing

After the test period is deleted:

```bash
mv "$backup_dir/cnr-academic-mail.paused" /etc/cron.d/cnr-academic-mail
chown root:root /etc/cron.d/cnr-academic-mail
chmod 644 /etc/cron.d/cnr-academic-mail
systemctl is-active apache2 cron
```

Both should show active. Wait one minute, then:

```bash
journalctl -t cnr-academic-mail --since "5 minutes ago" --no-pager
```

No additional cron job is needed. Refresh the browser; the updated JavaScript URL refreshes cached programme selection behaviour.

## Existing question papers

Older papers were uploaded without a programme. They remain available and count toward overall submission status. Exam Cell can open their **Edit details** control, select a programme and save. Their department follows the chosen programme. A conflicting existing tutor/programme/module record will prevent the correction from being saved; resolve duplicates through Exam Cell first.

An active legacy paper with no programme recorded blocks another upload of the same tutor/module in that examination until Exam Cell corrects or removes it. This prevents accidental duplicates during the transition.

## Verification and recovery

Local tests cover migration repeatability and preservation, programme validation, duplicate rules, ten-row pagination and filters, deletion permissions, cancellation isolation, hidden pre-announcement forms, PDF uploads/downloads, and account creation with Exam Cell. Desktop/mobile layout checked. Live MySQL migration and production mail delivery must be verified on your server.

Do not restore old application files over this migrated database casually: old software does not understand deleted examination periods. If rollback is necessary, keep Apache and the mail job paused and restore the matching application AND database backup together, provided no newer submissions would be lost. Then resume the old application and schedule. Keep backups protected because they include configuration and question papers.
