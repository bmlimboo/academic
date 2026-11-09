# Update: combined and research teaching sessions

This cumulative update adds multiple participating student groups to one teaching session and supports occasional research classes on their actual dates.

## 1. Copy the update from your Mac

In the Mac Terminal (not the Ubuntu terminal), replace YOUR_USERNAME with your SSH username. Use root only if SSH root access is already enabled.

```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-academic-import-results-update.zip" YOUR_USERNAME@10.10.140.137:/tmp/
ssh YOUR_USERNAME@10.10.140.137
```

## 2. Back up on Ubuntu

```bash
sudo -i
ls -lh /tmp/cnr-academic-import-results-update.zip
ls /var/www/academic-system/public/index.php
systemctl stop apache2
tar -czf "/root/cnr-before-import-results-$(date +%Y%m%d-%H%M%S).tar.gz" -C /var/www academic-system
mysqldump --single-transaction cnr_academic > "/root/cnr-before-import-results-$(date +%Y%m%d-%H%M%S).sql"
ls -lh /root/cnr-before-import-results-*
```

Check that both backups succeeded before continuing. If either fails, restart Apache with `systemctl start apache2` and resolve the backup first. The database command assumes the existing root socket authentication. Do not put your database password into the command line.

## 3. Install the update

PHP 8.2+ and the extensions used by the previous attendance release are required (mysql, gd, mbstring, xml, zip). The bundled vendor directory avoids needing Composer on the VM.

```bash
unzip -l /tmp/cnr-academic-import-results-update.zip
unzip -o /tmp/cnr-academic-import-results-update.zip -d /var/www/
cd /var/www/academic-system
php vendor/composer/platform_check.php
php bin/migrate.php
```

The migration ends with `Timetable import results migration complete.` It retains existing requests, accounts, passwords, documents and attendance. Do not run setup.php or demo.php on the existing server. If migration fails, keep the site offline, correct the error and rerun migrate.php; MySQL schema changes are not automatically rolled back.

```bash
chown -R root:www-data src public vendor migrations bin
find src public vendor migrations bin -type d -exec chmod 750 {} \;
find src public vendor migrations bin -type f -exec chmod 640 {} \;
php -l public/index.php
apache2ctl configtest
systemctl start apache2
```

## 4. Import and correct remaining rows

Open Tutor Attendance → Upload Timetable. Upload and validate. Click Import Valid Records to save the valid sessions. Rejected sessions are not saved.

Under Import history, click the new import. The saved result shows Uploaded and Not uploaded, source row numbers, tutor/module, day/time/room and reasons. Download not uploaded rows for correction, fix those rows in Excel, then upload that correction file and validate again. Keep the column headers. Original Row and Import Error are diagnostic columns and may be left in the file.

For 505 independent rows with 436 valid, importing saves 436 and leaves 69 for correction. Combined-session rows can produce fewer sessions than source rows; the history shows source-row totals. Conflict messages identify the existing timetable ID or earlier source row. Correct a real clash or deliberately combine a shared class; clashes are not bypassed.

Import history is retained in the database, including after sign-out. The most recent 30 imports are listed. History starts with imports made after this update; earlier imports cannot be reconstructed. Corrected uploads create a new history entry, leaving the previous result as a historical snapshot. Existing successfully imported classes are protected by duplicate/overlap checks.

This release passed 63 attendance/import checks and 75 HTTP checks locally. The server has not been updated. Preserve config and storage; do not rerun setup.
