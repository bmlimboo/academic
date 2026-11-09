# Academic student attendance and assessment update — 19 October 2026

This is a cumulative application update, including the earlier Exam Cell programme/filter changes. It has been tested locally, not installed on the Ubuntu server. Configuration, uploaded documents, existing passwords and existing records are excluded from the ZIP and preserved by the migration. Do not install an older update ZIP afterwards.

## What is included

- Academic menu: student attendance, CA/ESE marks, re-assessment, private drafts and reviewer reports. This is separate from tutor QR attendance.
- Authenticated access for existing active teaching staff. Staff can set a password using Forgot password and their registered email. Existing official roles and approval powers are unchanged. No passwords are reset by installation.
- Twelve programmes under the six teaching departments. Tutors select the programme they teach, including another department's programme. Year I–IV, Semester I–II, Section A by default, academic year, classroom, module code/name and DPD contact hours.
- CSV/XLSX templates generated for each module. XLSX instructions are on a second sheet. Upload at most 5,000 rows and 10 MB per file; the first sheet contains data, with values rather than formulas. Keep student IDs as text to preserve leading zeroes.
- Draft uploads can merge or replace. Merge replaces matching Student ID rows for marks, or Student ID + date + class type rows for attendance. A failed validation preserves the saved draft. Manual row editing supports up to 200 rows; larger drafts can be downloaded, corrected in Excel and uploaded again.
- Draft metadata and weights can be corrected before submission. With marks already uploaded, retain the same component count and order; discard/recreate a draft if its structure is wrong. Download a copy first if needed.
- Submission validates every row and locks it. Tutors retain a receipt but cannot view or edit the submitted student entries. PL can view their department; DAA can view all departments. Admin sees metadata and can correct DPD hours with an audited reason, but cannot read submitted student entries. Exam Cell manages re-assessment windows and expected module lists, not student marks.
- Submitted records cannot be reopened or deleted by this update. Deliberately check a draft before submitting it. Drafts can be discarded by their owner.
- Reports filter programme, department, study year, semester, section and academic year. Module lists use 10 rows per page. Select programme, academic year, year and semester before loading student details or exporting; this avoids loading the entire college’s student entries into one page. Consolidated marks and attendance review lists can be exported to Excel. Student IDs join module records; missing marks are not counted as zero.
- Help & Manuals includes the Academic workflow.

## Calculation rules

### Attendance

Each template period is one actual teaching hour; breaks are excluded. Use P/A only for taught periods. Leave unused periods blank. No automatic attendance/absence is created for assembly or seminars. Retrospective dates are supported; future dates are rejected.

**Attendance % = min(100, (regular present hours + extra present hours) / DPD contact hours × 100).** Extra classes count as make-up attendance towards the fixed DPD denominator. They do not increase that denominator. Extra hours remain identifiable. Twelve credits are not converted into contact hours.

All students listed in the module must have P or A for each recorded class before final submission. Duplicate overlapping Regular/Extra periods are rejected. Every cohort student should be included in the file; the system does not yet import an independent student enrolment list.

Below 80% is marked red for review. From 80% to below 90% requires supporting absence documents. At least 90% has no shortage flag. Supporting-document collection/approval is not added by this update; the report identifies who needs it. When recorded teaching hours are below DPD hours, coverage is explicitly provisional; verify missing uploads before determining eligibility. A secondary recorded-session rate is shown for comparison. Admin contact-hour corrections recalculate the DPD percentage immediately and are audited.

### Original marks

Each contribution is `raw mark / maximum mark × weight`. CA may contain 1–20 components in total with an optional single ESE component. Weights together must equal 100%. CA-only modules use CA weight 100. Blank marks block final submission; zero is a valid entered mark.

A pass requires:

- CA contribution at least 40% of the CA weight;
- ESE contribution at least 40% of the ESE weight, when present;
- combined total at least 50/100.

For CA60/ESE40, minimum component contributions are 24 and 16, but 24+16=40 still fails. A high total does not override a failed component. Calculations use unrounded contributions, then display two decimal places. No letter-grade scale is invented.

### Re-assessment

1. Exam Cell/Admin records the expected module codes for a programme/year/semester/academic-year/section. Do not omit a module. The list can be updated with an audit trail.
2. Every student's original marks must be submitted for every expected module before the system confirms eligibility. Missing results are not passes.
3. One or two failed modules allow re-assessment; three or more show **Repeat semester** and block re-assessment entry.
4. Exam Cell/Admin creates an opening and closing date/time (Bhutan time), for all programmes or a particular programme. Tutors can save and submit RA marks only inside the matching enabled window. A window can be disabled without deleting its records. Disabled/expired windows do not erase drafts.
5. The tutor selects their original submitted module. Module/cohort information and assessment weights are inherited. They enter the new **ESE raw mark only**; original CA remains unchanged. A student who already passed the original module cannot be entered for that module's RA.
6. The same component and total pass checks apply. A successful RA has final mark **50**, even when its calculated result is higher. Raw marks, calculated total and capped final mark remain available to PL/DAA. A failed RA remains Fail even if its numerical total exceeds 50 because CA failed.
7. The latest submitted RA updates that module's consolidated result and average. Original submissions remain unchanged in history.

**Policy boundary:** CA is retained for this exam re-sit workflow. A CA failure cannot be repaired by increasing ESE alone. CA-only module reassessment is blocked until the college supplies a separate rule; the system does not invent CA replacement rules. Repeat-semester is an academic report/entry restriction, not an automatic student enrolment change.

Consolidated averages are equal-weight averages of the available submitted modules, not an official final programme award. Missing student marks yield an incomplete average. Original failure counts use the confirmed expected module list. Re-assessment results are shown with receipt references.

## 1. Copy the update from your Mac

```bash
scp /Users/budhimanlimboo/Documents/myproject/deliverables/cnr-academic-records-update.zip root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

## 2. Pause, then back up on Ubuntu

Use the same terminal throughout. Stop if any command fails. Do not continue with a failed backup.

```bash
cd /var/www/academic-system
backup_dir="/root/cnr-before-academic-records-$(date +%Y%m%d-%H%M%S)"
mkdir -m 700 "$backup_dir"
cp /etc/cron.d/cnr-academic-mail "$backup_dir/cnr-academic-mail"
mv /etc/cron.d/cnr-academic-mail "$backup_dir/cnr-academic-mail.paused"
systemctl stop apache2
```

Wait at least one minute. Then:

```bash
pgrep -af '[p]hp.*mail-worker.php'
```

No output means no worker is running. If it shows a worker, let it finish before proceeding. If the cron file was already paused, locate its existing backup instead of creating a second schedule.

```bash
tar -czf "$backup_dir/application.tar.gz" public src bin migrations config storage
mysqldump --single-transaction cnr_academic > "$backup_dir/database.sql"
chmod 600 "$backup_dir/database.sql"
ls -lh "$backup_dir/application.tar.gz" "$backup_dir/database.sql"
```

Both backups must be non-empty and commands must finish without errors. If MySQL requires authentication, use `mysqldump -u root -p --single-transaction cnr_academic > "$backup_dir/database.sql"`. Use your actual database name if different.

## 3. Install and migrate

```bash
unzip -o /tmp/cnr-academic-records-update.zip -d /var/www/academic-system
php -l public/index.php
php -l src/academic/core.php
php -l src/academic/view.php
php -l src/academic/download.php
php bin/migrate.php
```

The last message should be:

```text
Academic student attendance and assessment migration complete.
```

Migration creates the Academic record, audit, cohort and re-assessment-window tables. Existing data is preserved; repeat runs are supported. The app uses the existing PhpSpreadsheet dependency already used by teaching timetable uploads. Verify it is available:

```bash
php -r 'require "vendor/autoload.php"; echo class_exists("PhpOffice\\PhpSpreadsheet\\Spreadsheet") ? "Excel support OK\n" : "Excel support missing\n";'
```

If this reports missing support or errors, keep the app paused and resolve the existing Composer dependency before using Excel functions.

```bash
chown -R root:www-data public src bin migrations
find public src bin migrations -type d -exec chmod 755 {} \;
find public src bin migrations -type f -exec chmod 644 {} \;
systemctl start apache2
```

## 4. Restore the mail worker

```bash
mv "$backup_dir/cnr-academic-mail.paused" /etc/cron.d/cnr-academic-mail
chown root:root /etc/cron.d/cnr-academic-mail
chmod 644 /etc/cron.d/cnr-academic-mail
systemctl is-active apache2 cron
```

After a minute:

```bash
journalctl -t cnr-academic-mail --since "5 minutes ago" --no-pager
```

The existing mail worker delivers requested password-reset emails. Installing this update does not announce the module or send student marks by email.

## 5. Check before inviting tutors

- Sign in as Admin. Confirm **Academic · Attendance & Marks** and **Re-assessment entry dates** are available.
- Use a designated teaching test account. If it has no known password, use Forgot password with its registered email, or set its password in Edit Account.
- Create a clearly named draft test module. Download the template, enter fictional students and upload. Confirm the preview and edit functions, then discard the test draft. Do not final-submit production test student records: final submissions are deliberately locked.
- Confirm the correct PL account department. A PL sees only submissions for that teaching department. DAA sees all departments. Admin can correct DPD hours but does not see student marks.
- Configure the expected real module codes and RA entry dates only when the college is ready to open re-assessment entry.
- Refresh the browser to load the updated CSS and JavaScript.

Keep the backup protected. If installation fails before reopening the site, restore the matching application and database backup together using your normal recovery procedure. Do not restore an old database over new submissions made after reopening.

## Verification performed locally

PHP syntax checks; isolated SQLite tests of access control, draft/final lifecycle, extra attendance and fixed-DPD calculations, weighted marks, CA/ESE pass requirements, module-count eligibility, window gates, RA cap and consolidated results; real HTTP CSV/XLSX/download and password-login tests; desktop and 390px mobile browser checks; regression checks for existing substitution and Exam Cell features. Production MySQL and the college server have not been exercised by this local test run.
