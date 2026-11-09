# Update: combined and research teaching sessions

This cumulative update adds multiple participating student groups to one teaching session and supports occasional research classes on their actual dates.

## 1. Copy the update from your Mac

In the Mac Terminal (not the Ubuntu terminal), replace YOUR_USERNAME with your SSH username. Use root only if SSH root access is already enabled.

```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-academic-flexible-teaching-update.zip" YOUR_USERNAME@10.10.140.137:/tmp/
ssh YOUR_USERNAME@10.10.140.137
```

## 2. Back up on Ubuntu

```bash
sudo -i
ls -lh /tmp/cnr-academic-flexible-teaching-update.zip
ls /var/www/academic-system/public/index.php
systemctl stop apache2
tar -czf "/root/cnr-before-flexible-teaching-$(date +%Y%m%d-%H%M%S).tar.gz" -C /var/www academic-system
mysqldump --single-transaction cnr_academic > "/root/cnr-before-flexible-teaching-$(date +%Y%m%d-%H%M%S).sql"
ls -lh /root/cnr-before-flexible-teaching-*
```

Check that both backups succeeded before continuing. If either fails, restart Apache with `systemctl start apache2` and resolve the backup first. The database command assumes the existing root socket authentication. Do not put your database password into the command line.

## 3. Install the update

PHP 8.2+ and the extensions used by the previous attendance release are required (mysql, gd, mbstring, xml, zip). The bundled vendor directory avoids needing Composer on the VM.

```bash
unzip -l /tmp/cnr-academic-flexible-teaching-update.zip
unzip -o /tmp/cnr-academic-flexible-teaching-update.zip -d /var/www/
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

## 4. Configure delivery and exemptions

Download the new timetable template. New optional columns: Delivery Mode (In person/Online), Attendance Required (Yes/No), Flexible (Yes/No). Old files default to In person, Yes, No. The manual entry/edit screen has the same settings.

EDS701: choose Online and Attendance Required No; leave Classroom blank. The class remains visible in the timetable but reserves no physical room and generates no QR or missed-attendance count. Online + attendance Yes is rejected because this system uses classroom QR attendance. Exempt classes are excluded from attendance reports, and their settings remain visible in the teaching timetable.

STS601 and RES601 can remain combined classes. For adjustable research teaching, create specific-date sessions and set Flexible Yes. Admin/DAA retain control of modules, participants, delivery and exemption settings. Existing classes with attendance cannot be edited; preserve them and create future sessions instead.

## 5. Staff editing without login

On the staff member's enrolled attendance phone, open your configured HTTPS site followed by /my-research-timetable.php. Example: https://YOUR-EXISTING-HOST/my-research-timetable.php. Use the same host and browser used for attendance enrolment; an HTTP IP URL will not authenticate the secure phone cookie.

Staff can change only their own enabled, future, specific-date sessions marked Flexible. They may change date, start/end time and physical classroom. Dates must fall within an active matching college calendar. Combined student groups move together. The system rejects tutor/room overlaps and records an audit trail. They cannot change exemption settings or recorded attendance. Inactive/revoked phones have no access.

Recurring schedules must first be replaced by specific-date sessions by Admin/DAA for this workflow. This update does not allow staff to rewrite past classes or blanket-edit a recurring semester timetable. Admin-configured time slots remain available for manual entry; flexible phone edits accept actual start/end times within the college calendar.

## 6. Verification

71 attendance/import checks and 75 HTTP checks passed locally. PHP syntax checked. The live server has not been updated. Staff need an existing HTTPS phone enrolment; no new staff login account is required.
