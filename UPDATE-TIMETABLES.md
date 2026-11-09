# Update: sample timetable layouts and attendance without staff login

This update is for the existing installation at 10.10.140.137. The archive preserves config/config.php and storage. It includes dependencies and requires the additive database migration. The following assumes your existing installation is /var/www/academic-system and uses Apache and database cnr_academic. If your installation path differs, use that actual path throughout.

## 1. Copy the update from your Mac

In the Mac Terminal (not the Ubuntu terminal), replace YOUR_USERNAME with your SSH username. Use root only if SSH root access is already enabled.

```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-academic-timetable-update.zip" YOUR_USERNAME@10.10.140.137:/tmp/
ssh YOUR_USERNAME@10.10.140.137
```

## 2. Back up on Ubuntu

```bash
sudo -i
ls -lh /tmp/cnr-academic-timetable-update.zip
ls /var/www/academic-system/public/index.php
systemctl stop apache2
tar -czf "/root/cnr-before-timetables-$(date +%Y%m%d-%H%M%S).tar.gz" -C /var/www academic-system
mysqldump --single-transaction cnr_academic > "/root/cnr-before-timetables-$(date +%Y%m%d-%H%M%S).sql"
ls -lh /root/cnr-before-timetables-*
```

Check that both backups succeeded before continuing. If either fails, restart Apache with `systemctl start apache2` and resolve the backup first. The database command assumes the existing root socket authentication. Do not put your database password into the command line.

## 3. Install the update

PHP 8.2+ and the extensions used by the previous attendance release are required (mysql, gd, mbstring, xml, zip). The bundled vendor directory avoids needing Composer on the VM.

```bash
unzip -l /tmp/cnr-academic-timetable-update.zip
unzip -o /tmp/cnr-academic-timetable-update.zip -d /var/www/
cd /var/www/academic-system
php vendor/composer/platform_check.php
php bin/migrate.php
```

The migration ends with `Timetable layouts and password-free enrolment migration complete.` It retains existing requests, accounts, passwords, documents and attendance. Do not run setup.php or demo.php on the existing server. If migration fails, keep the site offline, correct the error and rerun migrate.php; MySQL schema changes are not automatically rolled back.

```bash
chown -R root:www-data src public vendor migrations bin
find src public vendor migrations bin -type d -exec chmod 750 {} \;
find src public vendor migrations bin -type f -exec chmod 640 {} \;
php -l public/index.php
apache2ctl configtest
systemctl start apache2
```

## 4. Consultation timetables

Sign in as Admin or DAA and open Consultation Timetable. The manual entry and upload template use Faculty, Modules Taught, Free Days, Mentor-Mentee Day/Time, Academic-Related Day/Time, plus an optional Tutor Email to identify staff accurately. Select department, term and location. Upload CSV or XLSX, review the preview, then import.

Use 24-hour ranges such as 13:15-15:15, or explicit AM/PM at both endpoints. Leave both Mentor-Mentee fields blank where the sample has no mentor appointment. Identical mentor and academic slots become one shared bookable slot labelled for both purposes. Overlapping nonidentical slots are rejected. Existing active slots must be retired before importing replacements. Old bookings remain preserved.

The public timetable uses the seven sample columns and groups faculty by department/term. The samples are layout references; screenshots are not imported automatically and missing staff emails are not guessed.

## 5. Class timetables

Admin or DAA → Tutor Attendance → Upload Timetable:

- Choose **Enter a class manually** for individual sessions.
- Download **weekly grid Excel template** for the sample-style Mon–Fri grid with module/room columns and module/title/tutor legend.
- Fill each module's Tutor Email when the staff name does not match the directory exactly. Replace “New Recruit” with an actual enabled staff entry before importing.
- Provide academic year, semester, department, programme, section and term start/end on the upload screen. The year level comes from each grid block heading.
- Complete the legend beside each year block. Enter room codes that have already been added under Classrooms.
- Upload, review validation results, then import valid records. The old row-format template still works.

The timetable screen displays weekly grids and a module/tutor legend. Assembly, seminar, lunch and break labels do not create tutor attendance sessions through the grid importer. Enter a supervised seminar as a real module/session manually if it needs attendance tracking. PNG screenshots cannot provide editable schedule data; use the provided spreadsheet template.

## 6. Library services and programme documents

The library request form initially shows Current Awareness Service and its additional fields. Changing the service displays the relevant questions for electronic resources, document delivery, repository services, book recommendations, reading lists, orientation or space booking.

For programme documents, sign in as **Librarian**, open Programme Documents and click **Upload programme document**. Enter title/name, programme, description, version and hard-copy location, then choose the file. Uploader name and upload date/time are recorded automatically. Individual applicants cannot upload programme repository documents. Admin, DAA and PL retain their configured view/download access. An Admin account therefore sees guidance rather than an upload button.

## 7. Attendance without teaching staff login

Use the trusted HTTPS address configured for the application. Plain HTTP at 10.10.140.137 can show normal forms, but secure phone/board registration and confirmation require HTTPS. Retain the HTTPS setup from UPDATE-ATTENDANCE.md; set config/config.php's URL to your actual HTTPS address.

1. Admin adds or bulk-imports the staff directory entries.
2. Under Tutor Attendance → Devices & Tutor Access, enable attendance for each tutor. No tutor password is needed.
3. Select the tutor under Issue phone enrolment link. Copy the generated link and share it privately with that tutor. The link is shown once, expires in one hour and is single-use. Generating another invalidates the previous unused link.
4. The tutor opens the link in the phone browser, enters a phone name and confirms registration. No system login is involved. A replacement enrolment revokes the old phone registration.
5. On the actual classroom display browser, Admin registers that browser for its room and signs out.
6. Open the separate QR-only page: `https://10.10.140.137/qr-display.php` if this is your configured, trusted HTTPS address, otherwise use your configured hostname followed by `/qr-display.php`.
7. During a scheduled class's confirmation window, the tutor scans the rotating QR and taps **Confirm Attendance**. The phone opens a separate confirmation page, without the system menus or login.

Use the same enrolled browser when opening QR links. Clearing cookies or switching browser requires a fresh enrolment link. Official accounts such as Admin/DAA/Librarian still log in for their administrative duties. Ordinary faculty cannot log into the official panel, even when attendance is enabled.

QR confirmation records presence at the confirmation time; it does not measure entry/exit duration or prove a whole class was taught. This release retains registered-device checks and does not add VLAN enforcement.

## 8. Verify after updating

Check an anonymous public library form; the two department consultation grids after import; manual class entry and upload preview; Librarian upload followed by DAA/PL download; and phone enrolment followed by a real scheduled QR confirmation. Refresh cached pages if needed. No server deployment or live phone/certificate test has been performed from the development laptop.

Local validation: 35 workflow checks, 45 attendance/import/library checks, and 58 HTTP regression checks passed using SQLite. Verify the migration and these flows on your MySQL VM before normal use.

## Rollback

Stop Apache. Restore the matching pre-update application archive and SQL backup together, then restart Apache. Restoring SQL removes records created after that backup, so retain a fresh backup of the failed/current state first and use a maintenance window. Do not restore an old database over new submissions casually.
