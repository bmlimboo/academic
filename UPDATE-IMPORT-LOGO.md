# Update: historical import save confirmation and CNR logo

This cumulative update uses the supplied captiveportal-cnr-logo.png as the header logo. It fixes misleading import-preview success messages and adds actual database counts on Import Historical Data. It also includes the earlier staff, approval, timetable and mobile changes. It does not contain your server database or import the CSV automatically.

## 1. Copy the update from your Mac

In the Mac Terminal (not the Ubuntu terminal), replace YOUR_USERNAME with your SSH username. Use root only if SSH root access is already enabled.

```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-academic-import-logo-update.zip" YOUR_USERNAME@10.10.140.137:/tmp/
ssh YOUR_USERNAME@10.10.140.137
```

## 2. Back up on Ubuntu

```bash
sudo -i
ls -lh /tmp/cnr-academic-import-logo-update.zip
ls /var/www/academic-system/public/index.php
systemctl stop apache2
tar -czf "/root/cnr-before-import-logo-$(date +%Y%m%d-%H%M%S).tar.gz" -C /var/www academic-system
mysqldump --single-transaction cnr_academic > "/root/cnr-before-import-logo-$(date +%Y%m%d-%H%M%S).sql"
ls -lh /root/cnr-before-import-logo-*
```

Check that both backups succeeded before continuing. If either fails, restart Apache with `systemctl start apache2` and resolve the backup first. The database command assumes the existing root socket authentication. Do not put your database password into the command line.

## 3. Install the update

PHP 8.2+ and the extensions used by the previous attendance release are required (mysql, gd, mbstring, xml, zip). The bundled vendor directory avoids needing Composer on the VM.

```bash
unzip -l /tmp/cnr-academic-import-logo-update.zip
unzip -o /tmp/cnr-academic-import-logo-update.zip -d /var/www/
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

## 4. Check and finish the historical import

1. Sign in as Admin and open Import Historical Data.
2. Read **Records actually saved**. This count is queried from the running application's database, not the upload preview.
3. If zero, select your corrected CSV saved as **CSV UTF-8 (Comma delimited)**. The previously supplied CSV had reversed travel dates on rows 164, 314 and 411; use your corrected copy.
4. Click **Step 1: Validate and preview**. The message explicitly says NOT saved yet.
5. Review the rows, then click **Step 2: Save these records to database**. This button is now above the preview as well as at the bottom, so it is easy to find with hundreds of rows.
6. Only after the transaction commits does the system show the saved count and take you directly to the substitution report. The dashboard also shows the saved records, 15 per page.

Old code could display Saved successfully after validation even though it had not inserted requests. It also allowed a missing/expired preview to report zero records imported. The update distinguishes both cases and retains the preview if the database save fails.

CSV files with invalid UTF-8 now receive a specific encoding error rather than proceeding toward invalid JSON. No characters are silently replaced. Identical previously imported rows are skipped. Do not change previously imported data just to bypass duplicate checking.

Approved/completed report totals now include Historical Approved records. Historical Pending does not enter the live approval queue or trigger emails.

## 5. If the application still shows no saved records

On Ubuntu:

```bash
cd /var/www/academic-system
php bin/check-import.php
```

This command only reads the application's configured database. It prints the database name, request counts, historical-import count and missing applicant links. It never prints passwords or individual staff details. Share this output for diagnosis if counts still disagree. If Apache has environment-variable configuration overrides, the CLI database can differ; the Import Historical Data page's stored count reflects the web application connection.

Alternatively, in phpMyAdmin, select the database used by the academic system and run these read-only SQL queries:

```sql
SELECT DATABASE();
SELECT module, status, COUNT(*) AS records
FROM requests
GROUP BY module, status;
SELECT COUNT(*) AS historical_substitutions
FROM requests
WHERE module = 'substitution' AND import_key IS NOT NULL;
```

If both the dashboard and the application's stored count are zero, the connected database has no saved substitution records. Re-run the two-step import using the corrected file. If database rows exist but do not display, retain them and share the diagnostic counts before attempting another import or changing configuration.

## 6. Logo and verification

The header references a new logo filename, so the old cached image is not reused. Refresh after updating. Existing uploaded files and config/config.php are excluded from the archive.

Local tests cover a real multipart CSV upload, preview without persistence, commit, redirect to report, public dashboard visibility and empty-preview rejection. Existing workflow, staff management and responsive browser checks are retained. No live VM connection, MySQL migration or database recovery was performed from this laptop.

If rollback is necessary, use the matched pre-update code/database backups and preserve new submissions before restoring any SQL backup.
