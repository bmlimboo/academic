# Update: combined and research teaching sessions

This cumulative update adds multiple participating student groups to one teaching session and supports occasional research classes on their actual dates.

## 1. Copy the update from your Mac

In the Mac Terminal (not the Ubuntu terminal), replace YOUR_USERNAME with your SSH username. Use root only if SSH root access is already enabled.

```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-academic-combined-sessions-update.zip" YOUR_USERNAME@10.10.140.137:/tmp/
ssh YOUR_USERNAME@10.10.140.137
```

## 2. Back up on Ubuntu

```bash
sudo -i
ls -lh /tmp/cnr-academic-combined-sessions-update.zip
ls /var/www/academic-system/public/index.php
systemctl stop apache2
tar -czf "/root/cnr-before-combined-sessions-$(date +%Y%m%d-%H%M%S).tar.gz" -C /var/www academic-system
mysqldump --single-transaction cnr_academic > "/root/cnr-before-combined-sessions-$(date +%Y%m%d-%H%M%S).sql"
ls -lh /root/cnr-before-combined-sessions-*
```

Check that both backups succeeded before continuing. If either fails, restart Apache with `systemctl start apache2` and resolve the backup first. The database command assumes the existing root socket authentication. Do not put your database password into the command line.

## 3. Install the update

PHP 8.2+ and the extensions used by the previous attendance release are required (mysql, gd, mbstring, xml, zip). The bundled vendor directory avoids needing Composer on the VM.

```bash
unzip -l /tmp/cnr-academic-combined-sessions-update.zip
unzip -o /tmp/cnr-academic-combined-sessions-update.zip -d /var/www/
cd /var/www/academic-system
php vendor/composer/platform_check.php
php bin/migrate.php
```

The migration ends with `Combined teaching sessions migration complete.` It retains existing requests, accounts, passwords, documents and attendance. Do not run setup.php or demo.php on the existing server. If migration fails, keep the site offline, correct the error and rerun migrate.php; MySQL schema changes are not automatically rolled back.

```bash
chown -R root:www-data src public vendor migrations bin
find src public vendor migrations bin -type d -exec chmod 750 {} \;
find src public vendor migrations bin -type f -exec chmod 640 {} \;
php -l public/index.php
apache2ctl configtest
systemctl start apache2
```

## 4. Enter a combined class

Open Tutor Attendance → Upload Timetable → Enter class timetable manually.
Select the college calendar, tutor, module, agreed time and classroom. Enter the first department/programme/year/section, then click Add participating group for the other groups.
For DZG101, add Food Science & Technology, Forest Science and Environment and Climate Studies groups with their actual programmes and years. Save once. One QR confirmation records that session and the timetable appears for each group.

## 5. Upload combined classes

Download the NEW Excel or CSV template after installation. For one DZG101 session, enter three rows, one for each participating group. Set Combined Session ID to DZG101-MON-0900 on all three rows. Tutor, module, academic year, semester, day/date, start/end, room, valid dates and optional total Student Count must match exactly. Only department, programme, year and section differ.
Use another ID for a Wednesday session. Do not assign one ID to sessions at different times. The preview shows source row numbers together and imports one session. A blank ID imports a normal individual session. The Excel Instructions sheet explains this too.

## 6. Research classes

For PhD or MSc research teaching, choose Specific date / research class in manual entry, select the actual Class date and matching weekday, then an Admin-configured time. Add other participating groups if the students attend together. In uploads, supply Class Date; use another row/ID for each actual date. Leave it blank only for weekly recurring teaching.
Admin can add the needed calendar/timing under Calendar and timings. Only actual scheduled dates generate attendance expectations; no placeholder weekly classes are needed. Total students is optional and records the planned group size, not individual student attendance.

## 7. Existing records and checks

Existing classes retain their original group. No automatic merging occurs. For future duplicate rows with no attendance, edit one session to include all groups and remove the other unused future schedules. If they have attendance history, disable future occurrences and create a new combined session starting after the old occurrences. Keep old attendance history.
Reports count a combined session once globally and include it when filtering by any participating department/programme. Summary labels show shared department/programme combinations together. Tutor home department and substitution approval routing remain separate. Overlapping separate classes are still rejected.

After updating, discard old upload previews and validate the file again. Test a combined session and a date-specific research session, then check each participant department filter. Existing configuration and storage are excluded from this archive. This package has been tested locally; it has not been deployed to the VM.
