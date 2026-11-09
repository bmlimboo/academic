# Update: teaching in other departments

This cumulative update permits timetable Department to differ from the tutor's home department in the staff directory. The shared validator covers manual entry, editing, file preview and import. No staff department or existing timetable is automatically changed by installation.

## 1. Copy the update from your Mac

In the Mac Terminal (not the Ubuntu terminal), replace YOUR_USERNAME with your SSH username. Use root only if SSH root access is already enabled.

```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-academic-cross-department-update.zip" YOUR_USERNAME@10.10.140.137:/tmp/
ssh YOUR_USERNAME@10.10.140.137
```

## 2. Back up on Ubuntu

```bash
sudo -i
ls -lh /tmp/cnr-academic-cross-department-update.zip
ls /var/www/academic-system/public/index.php
systemctl stop apache2
tar -czf "/root/cnr-before-cross-department-$(date +%Y%m%d-%H%M%S).tar.gz" -C /var/www academic-system
mysqldump --single-transaction cnr_academic > "/root/cnr-before-cross-department-$(date +%Y%m%d-%H%M%S).sql"
ls -lh /root/cnr-before-cross-department-*
```

Check that both backups succeeded before continuing. If either fails, restart Apache with `systemctl start apache2` and resolve the backup first. The database command assumes the existing root socket authentication. Do not put your database password into the command line.

## 3. Install the update

PHP 8.2+ and the extensions used by the previous attendance release are required (mysql, gd, mbstring, xml, zip). The bundled vendor directory avoids needing Composer on the VM.

```bash
unzip -l /tmp/cnr-academic-cross-department-update.zip
unzip -o /tmp/cnr-academic-cross-department-update.zip -d /var/www/
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

## 4. Upload the timetable again

In the file's Department column, use the department receiving the class. Example: a tutor whose home department is Agriculture may have one timetable row for Forest Science and another for Sustainable Development. Keep the same Tutor Email for that person. Do not change their staff-directory department to make an upload pass.

After installing, open Upload Timetable and upload the file again to generate a fresh preview. Old preview errors are not recomputed automatically. The Department differs from the staff directory error is removed; department names must still match the system's department list.

If you already imported the valid rows, you can upload only the previously failed rows. Re-uploading the entire file will flag existing overlapping/duplicate classes and let you import only the newly valid rows after review. Do not delete existing timetables just to clear duplicate errors.

## 5. What stays separate

- Staff directory department: home department, used for substitution approval routing.
- Timetable Department: department receiving the class, used by teaching timetable/attendance department filters and host-department HoD/PL visibility.
- Tutor identity: the existing staff ID matched by Tutor Email. No duplicate account is created.

Tutor and room clashes remain checked across all departments. A tutor cannot be scheduled for overlapping classes simply because the classes belong to different departments.

The error Tutor email not found or attendance permission is not enabled is separate. For those rows, check the email matches an active staff entry, then use Account directory's bulk Enable attendance control if required. Cross-department flexibility does not enable missing/inactive staff or bypass attendance permissions.

## 6. Verification

51 attendance/import checks and 35 workflow checks passed, including cross-department saving, preserving home department, host-department visibility, and conflict protection. 75 HTTP checks passed. PHP syntax checks passed. Tests use an isolated local SQLite database; the VM has not been updated or its 505-row upload inspected directly.

Existing configuration and storage are excluded from the archive. Retain the pre-update backups and preserve new submissions before any database rollback.
