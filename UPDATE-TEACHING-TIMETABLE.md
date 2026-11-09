# Update: simpler teaching timetable entry and upload

This cumulative update adds Admin-configured academic calendars and class timing choices, replaces free-text manual timetable controls with dropdowns, and makes Upload Timetable a file-only form. Existing timetable and attendance records are retained. Run the migration to add the settings tables.

## 1. Copy the update from your Mac

In the Mac Terminal (not the Ubuntu terminal), replace YOUR_USERNAME with your SSH username. Use root only if SSH root access is already enabled.

```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-academic-teaching-timetable-update.zip" YOUR_USERNAME@10.10.140.137:/tmp/
ssh YOUR_USERNAME@10.10.140.137
```

## 2. Back up on Ubuntu

```bash
sudo -i
ls -lh /tmp/cnr-academic-teaching-timetable-update.zip
ls /var/www/academic-system/public/index.php
systemctl stop apache2
tar -czf "/root/cnr-before-teaching-timetable-$(date +%Y%m%d-%H%M%S).tar.gz" -C /var/www academic-system
mysqldump --single-transaction cnr_academic > "/root/cnr-before-teaching-timetable-$(date +%Y%m%d-%H%M%S).sql"
ls -lh /root/cnr-before-teaching-timetable-*
```

Check that both backups succeeded before continuing. If either fails, restart Apache with `systemctl start apache2` and resolve the backup first. The database command assumes the existing root socket authentication. Do not put your database password into the command line.

## 3. Install the update

PHP 8.2+ and the extensions used by the previous attendance release are required (mysql, gd, mbstring, xml, zip). The bundled vendor directory avoids needing Composer on the VM.

```bash
unzip -l /tmp/cnr-academic-teaching-timetable-update.zip
unzip -o /tmp/cnr-academic-teaching-timetable-update.zip -d /var/www/
cd /var/www/academic-system
php vendor/composer/platform_check.php
php bin/migrate.php
```

The migration ends with `Teaching calendar and timing settings migration complete.` It retains existing requests, accounts, passwords, documents and attendance. Do not run setup.php or demo.php on the existing server. If migration fails, keep the site offline, correct the error and rerun migrate.php; MySQL schema changes are not automatically rolled back.

```bash
chown -R root:www-data src public vendor migrations bin
find src public vendor migrations bin -type d -exec chmod 750 {} \;
find src public vendor migrations bin -type f -exec chmod 640 {} \;
php -l public/index.php
apache2ctl configtest
systemctl start apache2
```

## 4. Configure fixed calendar dates and class timings

Sign in as Admin → Tutor Attendance → Academic Calendar & Class Timings.

1. Add the academic year (for example 2026-2027), Semester I or II, and the college calendar's valid start/end dates. Use your actual calendar dates.
2. Add each approved start/end timing, for example 09:00–10:00 and 10:15–11:15. Add a double-period start/end pair if it is needed.
3. Manual entries select these settings. Academic year, valid dates and start/end times are obtained from the selected settings on the server, so form changes cannot override them.
4. To correct settings, retire the incorrect entry and add a replacement. Existing saved classes retain their original dates and times; retirement only affects new selections.

Only Admin can configure these settings. Existing authorized Admin/DAA users can enter or upload classes. No staff login is added.

## 5. Enter class timetable manually

Tutor Attendance → Upload Timetable → **Enter class timetable manually** (button).

- Select the academic calendar. Its semester is selected automatically and its fixed valid dates appear below.
- Select Year I, II, III or IV and Semester I or II. Year and Semester appear side by side on desktop and stack on phones.
- Select Department and Programme from dropdowns.
- Enter section and module code/name; select tutor, weekday, configured class timing and classroom.
- Leave Class Date blank for a repeating weekly session. For a one-off class, choose a date matching the selected weekday and within the calendar.
- Save. To add the same module on another day, enter another session with that weekday.

Tutors must be active and attendance-enabled; classrooms must exist and be active. The teaching department may differ from the tutor's home department. Retired calendar/timing choices are rejected on save. Editing older classes may require selecting a current configured calendar/timing.

## 6. Upload Excel or CSV

Upload Timetable now asks only for a file. There are buttons for the Excel template and CSV template. Use the new row template; the old weekly-grid upload layout is no longer used on this page.

All details belong in the file: Academic Year, Semester, Department, Programme, Year, Section, Subject Code, Subject Name, Tutor Email, optional Tutor Name, Day, optional Class Date, Start Time, End Time, Classroom, Valid From, Valid Until.

The Excel file contains:

- **Timetable:** the first sheet, for your real class rows. Year, semester and weekday have dropdown choices.
- **Instructions:** examples of the same tutor/module on different weekdays. These are examples only and are not imported.
- **Settings:** your currently configured calendar/timing choices plus department and programme names, for reference while completing the first sheet.

CSV contains the same import headers. Fill every row's valid dates and times; there are no default-date fields on the upload form. Use UTF-8 CSV, dates YYYY-MM-DD, times HH:MM, Year I–IV (1–4 also accepted), and Semester I or II. The uploaded row values determine its schedule; copying the configured calendar/timing values keeps them aligned with the college timetable.

### Same module taught on multiple days

Use one row per session, repeating the tutor, module and term details:

| Tutor | Module | Day | Start | End |
|---|---|---|---|---|
| Same tutor email | MODULE-A | Monday | 09:00 | 10:00 |
| Same tutor email | MODULE-A | Wednesday | 09:00 | 10:00 |
| Same tutor email | MODULE-A | Friday | 10:15 | 11:15 |

With Class Date blank, these repeat every matching weekday between Valid From and Valid Until. Do not put several weekdays into one Day cell. Two sessions on the same day also use two rows with different, non-overlapping timings. Tutor or room clashes and duplicate sessions remain blocked.

Choose the completed file → Validate and preview → Import valid records. Download the error report for invalid rows, correct those rows and upload them separately.

## 7. Verification

238 local checks passed, including Admin-only configuration, tampered timing rejection, missing upload dates, different weekdays for the same tutor/module, downloadable templates and phone layouts. Database tests use SQLite. Verify migration and real calendar values on your MySQL VM after updating. The server has not been changed from the development laptop.

Keep the pre-update application and database backups together. If rollback is needed, stop Apache and retain new submissions before restoring a database backup.
