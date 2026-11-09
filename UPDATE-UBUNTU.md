# Update: combined and research teaching sessions

This cumulative update adds multiple participating student groups to one teaching session and supports occasional research classes on their actual dates.

## 1. Copy the update from your Mac

In the Mac Terminal (not the Ubuntu terminal), replace YOUR_USERNAME with your SSH username. Use root only if SSH root access is already enabled.

```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-academic-approval-navigation-update.zip" YOUR_USERNAME@10.10.140.137:/tmp/
ssh YOUR_USERNAME@10.10.140.137
```

## 2. Back up on Ubuntu

```bash
sudo -i
ls -lh /tmp/cnr-academic-approval-navigation-update.zip
ls /var/www/academic-system/public/index.php
systemctl stop apache2
tar -czf "/root/cnr-before-approval-navigation-$(date +%Y%m%d-%H%M%S).tar.gz" -C /var/www academic-system
mysqldump --single-transaction cnr_academic > "/root/cnr-before-approval-navigation-$(date +%Y%m%d-%H%M%S).sql"
ls -lh /root/cnr-before-approval-navigation-*
```

Check that both backups succeeded before continuing. If either fails, restart Apache with `systemctl start apache2` and resolve the backup first. The database command assumes the existing root socket authentication. Do not put your database password into the command line.

## 3. Install the update

PHP 8.2+ and the extensions used by the previous attendance release are required (mysql, gd, mbstring, xml, zip). The bundled vendor directory avoids needing Composer on the VM.

```bash
unzip -l /tmp/cnr-academic-approval-navigation-update.zip
unzip -o /tmp/cnr-academic-approval-navigation-update.zip -d /var/www/
cd /var/www/academic-system
php vendor/composer/platform_check.php
php bin/migrate.php
```

The migration ends with `Flexible delivery settings migration complete.` It retains existing requests, accounts, passwords, documents and attendance. Do not run setup.php or demo.php on the existing server. If migration fails, keep the site offline, correct the error and rerun migrate.php; MySQL schema changes are not automatically rolled back.

```bash
chown -R root:www-data src public vendor migrations bin
find src public vendor migrations bin -type d -exec chmod 750 {} \;
find src public vendor migrations bin -type f -exec chmod 640 {} \;
php -l public/index.php
apache2ctl configtest
systemctl start apache2
```

## 4. Verify HoD approval access

Sign in as HoD. Staff Substitution in the sidebar now opens the report with View links. The home summary provides Review / approve for eligible requests, plus a link to substitution requests and approvals. Open the request, select Approve as HoD or Reject as HoD, then Save decision. Department matching and no-self-approval rules remain enforced.

New notification emails link directly to the individual request. If not signed in, opening that link and signing in returns to the request. Previously sent and already queued emails keep their original text; their summary page now has navigation to the request. Nothing is resent by installing this update.

HoD and faculty navigation hides Exam Cell Requisition and Library Services, including homepage form shortcuts. Public visitors still have all four forms. Faculty password login remains disabled as previously requested; this update does not enable new logins. Admin/DAA and other official menus retain their services.

For the reported request open your normal system address followed by /?page=detail&module=substitution&id=460. If approval is unavailable, verify the request department matches the HoD and it is not the HoD's own application.

80 local HTTP checks passed, including login return to approval, role menus, direct email links and public form availability. The VM has not been updated. Configuration, Gmail credentials, storage and cron are not included or overwritten by the ZIP.
