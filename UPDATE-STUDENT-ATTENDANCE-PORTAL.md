# Student attendance register and Exam Cell Portal update

19 October 2026. This update is prepared and tested locally; it is not installed on the Ubuntu server yet. Use this cumulative ZIP instead of an older update. It preserves configuration, passwords, uploaded documents and existing academic records.

## What changes

- **Exam Cell Portal → Student Attendance**, also linked in the sidebar.
- **Dates across the top**, with periods below each date and one row per student. Excel exports merge the date headings. Regular/Extra class type and classroom are header rows. The existing module-details form is retained.
- Existing flat CSV/XLSX attendance files are still accepted. A date entered once at the start of a group is carried down to following rows.
- **Add attendance** for the same module. New modules use the module-details form. Existing drafts and submitted module receipts remain available to their original tutor.
- Tutors verify their registered email with a six-digit code; **no account password or official login is required**. Codes expire in 15 minutes, allow five attempts and work in the requesting browser. Verified access lasts two hours. Use Finish / clear access on a shared computer.
- **Exam Cell and DAA only** can view and correct submitted attendance, verify documents, and manage attendance submission periods. PL and Admin do not receive attendance review/edit access. Tutors can preview their own drafts and add new dates after submission, but cannot read or overwrite submitted student entries. Assessment/RA access rules are unchanged.
- Submission periods match academic year, semester and optionally programme. Uploads, draft changes, initial submission and later additions require an enabled period, at or after its opening time and strictly before its deadline. All times are Bhutan time. Review corrections remain possible after the deadline.
- Identical re-uploaded cells are counted once. Additional periods are merged without removing earlier periods. Conflicting submitted P/A marks are rejected with an instruction to contact Exam Cell/DAA. Staff must include every student for every recorded period.
- Corrected full registers and DPD hours require a reason. Previous and replacement attendance are retained in the database audit history. Changes invalidate earlier document-verification decisions until reviewed again.
- **Attendance below 80%:** not allowed to sit the exam.
- **80% to below 90%:** supporting PDF and Exam Cell/DAA verification required. Approval makes the student eligible; rejection does not. A PDF cannot override attendance below 80%.
- **90% or above:** allowed to sit the exam.
- Eligibility uses the unrounded percentage. Display is truncated to two decimal places so a value just below a threshold does not display as passing it.
- Formula remains `(regular present hours + extra present hours) / fixed DPD contact hours × 100`, capped at 100%. Extra classes do not increase the denominator. Incomplete teaching-hour coverage is marked provisional; check missing uploads before deciding exam eligibility.

The application still relies on the roster supplied in each module; it cannot detect a student omitted entirely from all uploads. Each period represents one hour. The existing eight period choices are retained. This is student attendance, separate from classroom QR/tutor attendance.

## 1. Copy the ZIP from your Mac

```bash
scp /Users/budhimanlimboo/Documents/myproject/deliverables/cnr-student-attendance-portal-update.zip root@10.10.140.137:/tmp/
scp /Users/budhimanlimboo/Documents/myproject/deliverables/cnr-student-attendance-portal-update.sha256 root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

## 2. Verify, pause and back up on Ubuntu

Use the same terminal throughout. Stop if any command fails. Verify the ZIP before stopping the site:

```bash
cd /tmp
sha256sum -c cnr-student-attendance-portal-update.sha256
cd /var/www/academic-system
backup_dir="/root/cnr-before-student-attendance-$(date +%Y%m%d-%H%M%S)"
mkdir -m 700 "$backup_dir"
cp /etc/cron.d/cnr-academic-mail "$backup_dir/cnr-academic-mail"
mv /etc/cron.d/cnr-academic-mail "$backup_dir/cnr-academic-mail.paused"
systemctl stop apache2
```

Wait one minute, then check:

```bash
pgrep -af '[p]hp.*mail-worker.php'
```

**No output means no mail worker is running**, which is expected. If a worker is shown, wait for it to finish. If the cron was already paused, use its existing saved copy rather than creating another cron entry.

```bash
tar -czf "$backup_dir/application.tar.gz" public src bin migrations config storage
mysqldump --single-transaction cnr_academic > "$backup_dir/database.sql"
chmod 600 "$backup_dir/database.sql"
ls -lh "$backup_dir/application.tar.gz" "$backup_dir/database.sql"
```

Use your actual database name if it differs from `cnr_academic`. If authentication is required, use `mysqldump -u root -p --single-transaction cnr_academic > "$backup_dir/database.sql"`. Both backups must be non-empty and complete without errors.

## 3. Install and migrate

```bash
unzip -o /tmp/cnr-student-attendance-portal-update.zip -d /var/www/academic-system
php -l public/index.php
php -l src/academic/core.php
php -l src/academic/student-attendance.php
php -l src/academic/student-attendance-view.php
php -l src/academic/attendance-matrix.php
php bin/migrate.php
```

The last message should read:

```text
Student attendance portal migration complete.
```

Migration 009 creates submission-period and document-verification tables. It does not delete or convert existing attendance entries. Repeated migration runs are supported. Existing module records are displayed using the new register format.

Verify the existing Excel dependency:

```bash
php -r 'require "vendor/autoload.php"; echo class_exists("PhpOffice\\PhpSpreadsheet\\Spreadsheet") ? "Excel support OK\n" : "Excel support missing\n";'
```

If migration or the dependency check fails, keep the site paused and resolve the error before continuing.

```bash
chown -R root:www-data public src bin migrations
find public src bin migrations -type d -exec chmod 755 {} \;
find public src bin migrations -type f -exec chmod 644 {} \;
install -d -o www-data -g www-data -m 700 storage/attendance-support
systemctl start apache2
mv "$backup_dir/cnr-academic-mail.paused" /etc/cron.d/cnr-academic-mail
chown root:root /etc/cron.d/cnr-academic-mail
chmod 644 /etc/cron.d/cnr-academic-mail
systemctl is-active apache2 cron
```

The supporting-document directory must remain outside Apache's public document root. Continue serving `/var/www/academic-system/public`. Supporting PDFs are downloaded only through authorized application routes.

After one minute:

```bash
journalctl -t cnr-academic-mail --since "5 minutes ago" --no-pager
```

The existing worker sends requested verification codes automatically. You do not need to run it manually. Installing this update or opening an attendance period does not send a mass announcement email.

## 4. Exam Cell / DAA: open attendance submissions

1. Sign in with the **Exam Cell** or **DAA** account.
2. Open **Exam Cell Portal → Student Attendance → Submission periods**.
3. Enter a title, academic year (for example `2026-2027`), semester, opening time and deadline. Leave programme blank for all programmes, or select one programme.
4. Select **Open / schedule period**. The public form becomes available at its opening time.
5. **Close period** stops uploads immediately. Re-enabling uses the original dates; if the deadline has passed, create a new period with new dates.
6. Share the attendance page: `https://cas.cnr.edu.bt/?page=sa_portal`.

## 5. Tutor: first upload and later additions

1. Open Student Attendance, choose the relevant open period and verify the code sent to your registered email.
2. For a different/new module, fill the existing module form. For an existing draft, choose Continue draft.
3. Download **Excel register template**. The first sheet is Register:

| Row / column | Enter |
|---|---|
| Row 1 | Full date, e.g. `2026-08-03`, above each group of periods |
| Row 2 | Period headings, using the supplied time slots |
| Row 3 | `Regular` or `Extra` for each period |
| Row 4 | Classroom for each period |
| Column A, from row 5 | Serial number |
| Columns B–C | Student ID and name, once per student |
| Remaining cells | `P` or `A`; leave unused periods blank for every student |

4. To add another date, copy the relevant period columns to the right and enter that date in row 1. You may merge date cells across a group; the importer carries the date to the following period columns.
5. Upload and inspect the preview. **Add / update draft attendance** retains other dates; **Replace the entire draft** uses only the uploaded file. Validation errors preserve the saved draft.
6. Submit the checked batch. Afterward, tutors retain the receipt, not access to submitted student entries.
7. Later in the same semester, choose **+ Add attendance** for that module. Upload additional dates, or the complete register with earlier data unchanged. No module form needs to be filled again. Keep your original spreadsheet for future additions.

Limits: CSV UTF-8 or XLSX, 10 MB, up to 400 period columns, 200,000 XLSX cells per file, and 5,000 student/date/class-type entries in a module. Use values, not formulas. Supporting PDFs are also limited to 10 MB. If the server still has a 2 MB PHP upload limit, that lower limit must be increased separately.

## 6. Review, correct and verify

- Exam Cell/DAA opens **Attendance review**, filters by tutor/module, department, programme or academic year, and selects **View / edit**. Lists show 10 modules per page.
- The register has dates across the top. Scroll sideways for further dates. The overview shows DPD hours, present/absent hours, percentage and eligibility.
- **Download full register** exports the matrix and an Eligibility sheet.
- To correct entries, edit the full downloaded register, upload it under **Correct submitted attendance**, and enter a reason. This replaces the full register. A DPD-only correction does not require a spreadsheet upload.
- For the 80–<90% band, select the student, upload the supporting PDF, enter notes, and approve or reject verification. The decision and document are available only to Exam Cell/DAA. Any later attendance/DPD change requires verification again.

## Validation performed

Local tests use fictional records in isolated SQLite databases; no real mail is sent. Tests cover matrix and Excel round-trips, merged date headings, repeated/partial uploads, invalid cells, role restrictions, email-code attempt limits, deadlines and legacy-route bypass attempts, verification PDFs, boundary percentages, corrections and audit history. Desktop and 390-pixel phone layouts were checked in Chrome. Existing marks/re-assessment, public forms, substitution approvals and Exam Cell tests passed. Production MySQL and actual email delivery still need the normal post-install check on your server.

## Recovery if installation fails

Keep Apache and the mail cron paused until code and database versions agree. The backup directory contains the old application and database. Ask ICT to restore them together before reopening; do not restore an old database after new real submissions without first preserving those submissions. Keep the backup until the update is confirmed working.
