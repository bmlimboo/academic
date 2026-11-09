# Consolidated marks update installation

Requires the previous Admin access update. Includes the table-format update. No database migration; existing records and calculation rules remain unchanged.

## On your Mac
```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-consolidated-marks-update.zip" root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

## On Ubuntu
Check the archive, then back up before extracting:
```bash
unzip -t /tmp/cnr-consolidated-marks-update.zip
cd /var/www/academic-system
backup_file="/root/cnr-before-consolidated-marks-$(date +%Y%m%d-%H%M%S).tar.gz"
tar -czf "$backup_file" src public
chmod 600 "$backup_file"
tar -tzf "$backup_file" >/dev/null && echo "Backup verified"
echo "$backup_file"
```
Proceed only after archive and backup checks succeed.
```bash
unzip -o /tmp/cnr-consolidated-marks-update.zip -d /var/www/academic-system
php -l src/academic/core.php
php -l src/academic/view.php
php -l src/academic/download.php
php -l src/academic/reports-view.php
php -l src/academic/marks-view.php
php -l src/academic/student-attendance.php
php -l src/academic/student-attendance-view.php
```
Every PHP check must say no syntax errors. Then:
```bash
chown root:www-data src/academic/core.php src/academic/view.php src/academic/download.php src/academic/reports-view.php src/academic/marks-view.php src/academic/student-attendance.php src/academic/student-attendance-view.php public/assets/style.css
chmod 644 src/academic/core.php src/academic/view.php src/academic/download.php src/academic/reports-view.php src/academic/marks-view.php src/academic/student-attendance.php src/academic/student-attendance-view.php public/assets/style.css
apache2ctl configtest
```
If Syntax OK:
```bash
systemctl reload apache2
systemctl is-active apache2
```
Keep the mail worker running. No migration command is needed.

## Verify
Refresh with Cmd+Shift+R. Sign in as Admin, Exam Cell, PL or DAA and open Academic reports. Select programme, academic year, study year and semester, then View reports. The consolidated table uses module codes only. Click Download consolidated marks (Excel); the workbook has grouped module headings, CA/ESE/Total/Result subheadings and bordered rows. PL remains department-scoped; Exam Cell, DAA and Admin can export across departments. Admin retains its existing management permissions. The selection filters identify which cohort to export, rather than restricting Admin permissions.

## Rollback
In the same SSH session:
```bash
tar -xzf "$backup_file" -C /var/www/academic-system
systemctl reload apache2
```
If reconnected, locate the exact backup with `ls -lt /root/cnr-before-consolidated-marks-*.tar.gz` and use its full path instead of `$backup_file`.
