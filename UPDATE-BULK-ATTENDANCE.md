# Update: bulk teaching attendance controls

This cumulative update adds checkboxes and bulk attendance actions to the existing Account directory. Configuration, uploaded files and database contents are excluded from the ZIP. No staff permissions are changed automatically by installing the update.

## 1. Copy the update from your Mac

In the Mac Terminal (not the Ubuntu terminal), replace YOUR_USERNAME with your SSH username. Use root only if SSH root access is already enabled.

```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-academic-bulk-attendance-update.zip" YOUR_USERNAME@10.10.140.137:/tmp/
ssh YOUR_USERNAME@10.10.140.137
```

## 2. Back up on Ubuntu

```bash
sudo -i
ls -lh /tmp/cnr-academic-bulk-attendance-update.zip
ls /var/www/academic-system/public/index.php
systemctl stop apache2
tar -czf "/root/cnr-before-bulk-attendance-$(date +%Y%m%d-%H%M%S).tar.gz" -C /var/www academic-system
mysqldump --single-transaction cnr_academic > "/root/cnr-before-bulk-attendance-$(date +%Y%m%d-%H%M%S).sql"
ls -lh /root/cnr-before-bulk-attendance-*
```

Check that both backups succeeded before continuing. If either fails, restart Apache with `systemctl start apache2` and resolve the backup first. The database command assumes the existing root socket authentication. Do not put your database password into the command line.

## 3. Install the update

PHP 8.2+ and the extensions used by the previous attendance release are required (mysql, gd, mbstring, xml, zip). The bundled vendor directory avoids needing Composer on the VM.

```bash
unzip -l /tmp/cnr-academic-bulk-attendance-update.zip
unzip -o /tmp/cnr-academic-bulk-attendance-update.zip -d /var/www/
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

## 4. Enable attendance for many staff

1. Sign in as Admin → Users & Notifications → Account directory.
2. Optionally select a department or enter a name, then click Search staff.
3. Tick individual rows, or Select all shown to select active staff in the displayed filtered list.
4. Click Enable attendance for selected.
5. Check the saved count and the new Teaching attendance column.

Select all shown applies to the displayed search results, not hidden departments. Changing the filter clears the selection. Up to 500 staff can be changed per action. Inactive accounts cannot be selected; enable the account first if the person has returned.

To withdraw attendance access, select the staff and click Disable attendance for selected. Confirm the action. Their phone registrations and unused enrolment links are revoked, while official role, account Active/Inactive status and historical records remain unchanged. Re-enabling attendance after revocation requires a new phone enrolment link.

The existing Disable/Enable button in each row still controls the whole staff account; the bulk buttons specifically control teaching attendance. DAA/HoD/PL and adjunct faculty can be included in bulk attendance selections like other active staff.

## 5. Verification

This update passed 34 staff-management/approval checks, 75 HTTP checks and 32 responsive browser checks, including selecting all displayed staff and submitting the bulk form in a browser. PHP syntax checks passed. Tests use a temporary local SQLite database, not the live VM. The server has not been updated by the development laptop.

If recovery is needed, retain current submissions before restoring a matched code/database backup. Do not reset or recreate staff accounts.
