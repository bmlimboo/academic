# Administrator access update — 19 October 2026

This update makes Admin the management role across the existing application. It supersedes the earlier attendance restriction that excluded Admin. Other roles keep their current permissions.

## Admin can now

- Open, schedule, enable and close attendance submission periods.
- View all submitted attendance, supporting PDFs and eligibility decisions; correct attendance and DPD hours with a reason; verify supporting documents.
- Open all tutors' attendance modules and drafts, add attendance and create modules, including outside tutor submission dates.
- Use the attendance portal directly after **Official login**, without a separate email code. Email-only verification does not grant administrator powers.
- View all academic drafts, submitted marks and student-level reports across departments, and download full records.
- Edit drafts and correct submitted marks using a complete replacement spreadsheet and a required reason. The original owner is retained. Previous and replacement entries are stored in the audit history.
- Act at the current HoD, DAA or President substitution approval stage, with buttons explicitly labelled Admin. Actions are recorded under the administrator's account.
- Update Exam Cell, Library and Consultation request stages.
- Upload and supersede programme documents.

Existing Admin management of users, roles, departments, teaching timetables, Exam Cell periods, question papers, reports and substitution deletion remains available.

Data-validation and workflow rules remain in force: percentages and mark caps, complete attendance rows, valid files, required reasons, current-stage approval order, concurrent-update checks and no self-approval. Admin access does not turn invalid data into valid data. Submitted marks corrections use the existing module structure; re-assessment retains its existing calculation rules. Review associated re-assessment records when correcting original marks.

## Install

This cumulative package includes the compact email-verification form. Install over the existing Student Attendance Portal update. No new database migration is needed for this permission change.

On the Mac:

```bash
scp /Users/budhimanlimboo/Documents/myproject/deliverables/cnr-admin-access-update.zip root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

On Ubuntu, use the same terminal and stop if a command fails:

```bash
cd /var/www/academic-system
backup_file="/root/cnr-before-admin-access-$(date +%Y%m%d-%H%M%S).tar.gz"
tar -czf "$backup_file" public src bin migrations
chmod 600 "$backup_file"
unzip -o /tmp/cnr-admin-access-update.zip -d /var/www/academic-system
php -l public/index.php
php -l src/bootstrap.php
php -l src/staff-management.php
php -l src/academic/core.php
php -l src/academic/student-attendance.php
php -l src/academic/student-attendance-view.php
chown -R root:www-data public src bin migrations
find public src bin migrations -type d -exec chmod 755 {} \;
find public src bin migrations -type f -exec chmod 644 {} \;
systemctl reload apache2
```

Configuration, uploaded files, database contents and passwords are not included or replaced. No cron change is needed.

## Check after installation

1. Sign in using your Admin account through Official login; confirm the header shows ADMIN.
2. Open Student Attendance. Submission periods and Attendance review should be visible.
3. Submission periods allows Admin to open attendance dates. Submit / add attendance shows all modules without requesting an email code.
4. Attendance review shows submitted student entries and correction/document-verification controls.
5. Academic workspace shows all drafts and receipts. In Reports, select a cohort to load student-level data and export. A submitted marks record has Administrator: correct submitted marks.
6. Programme Documents shows upload and supersede controls.
7. Refresh with Command+Shift+R if an old screen remains cached.

Validation: 57 PHP syntax checks; local Admin permission checks; attendance and academic HTTP checks; existing public form, approval, Exam Cell and substitution regressions. Test data is fictional; no real mail was sent. This package has not been installed on your Ubuntu server by the assistant.
