# Staff roles and mobile layout update

The update shows all existing non-student staff in Account directory, including imported faculty, and allows Admin to assign an official role to the same entry. DAA, HoD and PL remain in the teaching directory. Teaching attendance is independently enabled; teachers continue to use phone QR confirmation without login.

## 1. Copy the update from your Mac

In the Mac Terminal (not the Ubuntu terminal), replace YOUR_USERNAME with your SSH username. Use root only if SSH root access is already enabled.

```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-academic-roles-mobile-update.zip" YOUR_USERNAME@10.10.140.137:/tmp/
ssh YOUR_USERNAME@10.10.140.137
```

## 2. Back up on Ubuntu

```bash
sudo -i
ls -lh /tmp/cnr-academic-roles-mobile-update.zip
ls /var/www/academic-system/public/index.php
systemctl stop apache2
tar -czf "/root/cnr-before-roles-mobile-$(date +%Y%m%d-%H%M%S).tar.gz" -C /var/www academic-system
mysqldump --single-transaction cnr_academic > "/root/cnr-before-roles-mobile-$(date +%Y%m%d-%H%M%S).sql"
ls -lh /root/cnr-before-roles-mobile-*
```

Check that both backups succeeded before continuing. If either fails, restart Apache with `systemctl start apache2` and resolve the backup first. The database command assumes the existing root socket authentication. Do not put your database password into the command line.

## 3. Install the update

PHP 8.2+ and the extensions used by the previous attendance release are required (mysql, gd, mbstring, xml, zip). The bundled vendor directory avoids needing Composer on the VM.

```bash
unzip -l /tmp/cnr-academic-roles-mobile-update.zip
unzip -o /tmp/cnr-academic-roles-mobile-update.zip -d /var/www/
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

## 4. Assign a role to an existing teacher

1. Sign in as Admin → Users & Notifications → Account directory — all staff.
2. Find the existing person and click Edit. Do not create another account with the same email.
3. Select daa, hod, pl or the required official role. Choose a department for HoD/PL approval routing.
4. When promoting faculty to an official role, enter an official login password of at least 12 characters. For existing official accounts, leave password blank to retain it.
5. Tick Teaching attendance enabled if the person also teaches. Existing enabled attendance is shown checked.
6. Keep Active account checked and Save account.

The same user ID is retained, so requests, schedules and historical records stay linked. Official role controls administrative login and approvals; the teaching checkbox controls attendance eligibility. Phone registration and QR confirmation continue without staff login. One official role is assigned per person; teaching eligibility is additional to that role.

All faculty and DAA/HoD/PL entries appear in Teaching Staff; other officials appear there when teaching attendance is enabled. To withdraw official access, change the role back to faculty. Another administrator must change your own permissions.

## 5. Phone layout

At phone width, tap Menu to open navigation. Forms stack into one column, controls remain readable, and wide reports scroll within the table rather than widening the page. Existing colours and desktop layout are retained. Refresh the browser after installing to load version 3.2 assets.

This update also fixes an existing substitution form rendering error after Purpose that browser testing uncovered.

## 6. Validation and recovery

Local verification: 14 role-management checks, 35 workflow checks, 45 attendance/import checks, 58 HTTP checks and 28 responsive browser checks. Browser checks cover registration forms at 320, 390, 768 and 1440 pixels and selected official screens. Database tests use SQLite; run the migration and confirm role assignment on your MySQL server after deployment. No live server changes were made from the laptop.

Use the pre-update application/database backups together if rollback is required. Stop Apache first and preserve a backup of the current state. Restoring a database backup removes subsequent submissions, so schedule updates during a maintenance window.
