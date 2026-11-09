# Update CNR Academic System — Tutor Attendance release

This is an update to the existing PHP/MySQL application at 10.10.140.137, not a separate application. It adds Tutor Attendance, consultation timetable CSV import/manual consultation requests, and complete library choice fields. Your public forms remain public.

The ZIP includes compatible PHP libraries and an additive database migration. It excludes config/config.php and storage, so it does not overwrite your password, uploaded documents or database. Unlike the previous update, **this release requires running bin/migrate.php**.

## 1. Copy from the Mac

Run on the Mac. Replace YOUR_USERNAME with your existing Ubuntu SSH user:

```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-academic-attendance-update.zip" YOUR_USERNAME@10.10.140.137:/tmp/
ssh YOUR_USERNAME@10.10.140.137
```

## 2. Back up on Ubuntu

Become root (skip if the prompt already says root):

```bash
sudo -i
```

Use a maintenance period without active submissions:

```bash
systemctl stop apache2
tar -czf "/root/cnr-before-attendance-$(date +%Y%m%d-%H%M%S).tar.gz" -C /var/www academic-system
mysqldump --single-transaction cnr_academic > "/root/cnr-before-attendance-$(date +%Y%m%d-%H%M%S).sql"
ls -lh /root/cnr-before-attendance-*
```

If a backup fails, stop the update, run `systemctl start apache2`, and resolve the backup first. Root MySQL socket authentication is assumed as in the earlier installation.

## 3. Check PHP requirements and install extensions

PHP 8.2 or newer is required. On a supported Ubuntu installation:

```bash
php -v
apt update
apt install -y php-gd php-mbstring php-xml php-zip php-mysql unzip
```

Your previous Ubuntu 24.10 installation is end-of-life. The archived repositories may restore package access, but move to supported Ubuntu before live use. No operating-system upgrade is performed by this application update.

## 4. Extract and migrate

```bash
unzip -l /tmp/cnr-academic-attendance-update.zip
unzip -o /tmp/cnr-academic-attendance-update.zip -d /var/www/
cd /var/www/academic-system
php vendor/composer/platform_check.php
php bin/migrate.php
```

Expected migration message:

```text
Attendance and consultation migration complete. Existing records and passwords preserved.
```

The migration is repeatable. It creates new tables and indexes, seeds attendance window settings, and copies existing consultation booking times into the new conflict-checking table. It does not recreate user accounts or modify existing passwords. MySQL DDL auto-commits; if a migration step fails, keep the site offline, correct the error and rerun the migration. Do not assume a failed DDL migration rolled back automatically.

Set code permissions, preserving upload ownership:

```bash
chown -R root:www-data src public vendor migrations bin
find src public vendor migrations bin -type d -exec chmod 750 {} \;
find src public vendor migrations bin -type f -exec chmod 640 {} \;
php -l public/index.php
apache2ctl configtest
systemctl start apache2
```

Do NOT rerun demo.php. Your existing mail configuration and mail-worker cron remain unchanged.

## 5. Enable HTTPS before devices

Public forms can be checked on the existing HTTP address, but board registration, phone registration and QR confirmation intentionally refuse HTTP. Ask ICT to configure a trusted HTTPS certificate for the college hostname or internal IP, including the hostname/IP in the certificate SAN. Both the phone and board must trust its issuing CA. For an internal IP, use the college's internal CA; a public certificate service cannot normally issue a certificate for a private IP address.

For Apache, enable SSL and create an HTTPS virtual host using your ICT-issued certificate and private key, retaining the existing document root:

```apache
<VirtualHost *:443>
    ServerName 10.10.140.137
    DocumentRoot /var/www/academic-system/public
    DirectoryIndex index.php
    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/cnr-academic-fullchain.pem
    SSLCertificateKeyFile /etc/ssl/private/cnr-academic.key
    <Directory /var/www/academic-system/public>
        Options -Indexes
        AllowOverride None
        Require all granted
    </Directory>
    php_value upload_max_filesize 10M
    php_value post_max_size 25M
    php_value max_input_vars 5000
    php_flag display_errors Off
    php_flag log_errors On
    ErrorLog ${APACHE_LOG_DIR}/cnr-academic-error.log
    # %U logs the path without query strings, keeping QR tokens out of access logs.
    LogFormat "%h %l %u %t \"%m %U %H\" %>s %b" cnr_private
    CustomLog ${APACHE_LOG_DIR}/cnr-academic-access.log cnr_private
</VirtualHost>
```

Save as `/etc/apache2/sites-available/cnr-academic-ssl.conf` after installing the actual certificate files, then:

```bash
a2enmod ssl
a2ensite cnr-academic-ssl.conf
apache2ctl configtest
systemctl restart apache2
```

If UFW is active, allow `443/tcp`. Use the actual DNS hostname instead of the IP in ServerName if the certificate is issued for a hostname. Change `url` in `config/config.php` to that exact HTTPS URL. Configure any reverse proxy to omit QR query strings from access logs too. Once HTTPS works, redirect the HTTP virtual host to the fixed HTTPS URL and use HTTPS for every login.

No certificate is bundled, because it must identify and be trusted for your actual server. Do not share the server private key or SMTP password in chat.

## 6. Set up tutor access

1. Log in as System Admin using the existing credentials.
2. Make sure tutors already exist in Teaching Staff; bulk staff upload is still supported.
3. Open Tutor Attendance → Devices & Tutor Access.
4. Select the existing staff member, enable attendance and assign an initial password of at least 12 characters.
5. Give that password to the tutor through your normal secure channel. The tutor can change it under My Account.

This permission is independent of the user's role: a HoD or PL can also be a teaching tutor. Students cannot be granted attendance through this screen. Merely having a college email address is not sufficient. Existing staff who have not been enabled continue to use public service forms without a password.

## 7. Create classrooms and import teaching timetable

1. DAA/Admin opens Tutor Attendance → Classrooms.
2. Add room code, name, building and floor (for example CR-101).
3. Open Upload Timetable and download the Excel template.
4. Fill Academic Year, Semester, Department, Programme, Year, Section, Subject Code, Subject Name, Tutor Email, Day, Start Time, End Time and Classroom.
5. Tutor Name is informational. Tutor Email maps to existing staff. Use the exact programme/department values used by the application.
6. Class Date is optional for a one-off class; its weekday must match Day.
7. For recurring classes, supply default term start/end dates on the upload form, or Valid From/Valid Until per row. Term ranges are limited to one year.
8. Upload XLSX, XLS or CSV (up to 5,000 rows / 10 MB). Preview shows valid/error counts and each row's result.
9. Download the error report if needed. Import Valid Records explicitly imports only rows marked Valid. Correct and upload error rows separately.
10. Existing/within-file duplicates and tutor/classroom overlaps are errors. All accepted rows are revalidated at commit; concurrent conflicts roll back the accepted batch.

Use Timetable to filter, edit, disable or export. Schedules with recorded attendance cannot be edited; disable future occurrences and add the replacement schedule. Deletion is limited to future schedules with no recorded attendance. Disabling keeps past attendance evidence.

## 8. Register the tutor's phone

1. Open the trusted HTTPS system URL on the actual phone, using the same browser that will open scanned QR links.
2. Sign in using the existing tutor email and assigned password.
3. Open My Registered Device.
4. Enter a device name and current password; press Register This Device.
5. The secure HttpOnly cookie recognizes this browser on future scans, even after the normal login session expires.

Only one browser/device registration is active. Registration on a replacement phone revokes the previous one. Clearing browser cookies requires re-registration. This identifies a browser, not hardware IMEI/MAC/SIM. System Admin can revoke devices. Both staff active status and attendance permission are rechecked during scans.

## 9. Register each classroom board

1. On the actual board browser, open the HTTPS system URL.
2. Sign in as System Admin.
3. Open Devices & Tutor Access → Classroom board registration.
4. Select the room, enter the board name and press Register This Classroom Browser.
5. Sign out of the admin session.
6. Open Classroom Display and bookmark it as the board's start page.
7. Press Full screen once if desired, or configure the board's browser kiosk mode. Browsers require a user gesture for ordinary full-screen requests; the page cannot force it silently.

The display automatically refreshes a QR every 45 seconds by default and shows a countdown and current class in the attendance window. Merely opening the room/display URL in another browser cannot create a valid QR. A registered board cookie is required.

## 10. Daily attendance and reports

Tutor scans with the registered phone → checks the displayed class → presses Confirm Attendance. Default windows:

- Opens 10 minutes before start.
- Present through 5 minutes after start.
- Late after that, through 15 minutes after start.
- Later scans are rejected; DAA can record an audited exception.

System Admin can change these windows and set QR refresh to 30–60 seconds. Expired/used QR codes are rejected. Unique timetable/date records prevent double attendance. An approved substitution's affected class can authorize the assigned substitute when original tutor, module code, date and times match exactly. Other substitutions can be recorded as audited DAA exceptions.

Dashboard supports date range, department, programme, tutor, classroom and status filters, daily/monthly views, tutor/department/programme summaries and Excel export. Room utilization is scheduled hours plus confirmation counts, not measured occupancy duration. My Attendance includes the tutor's classes and registered substitutions. HoD/PL reports are department-scoped; DAA/Admin can view all. Missed is derived for scheduled occurrences without confirmation after the closing window. QR confirmation records check-in evidence; it cannot independently prove a whole class was taught or completely prevent real-time QR relay.

## 11. Consultation timetable

DAA/Admin opens Consultation Timetable → Upload Consultation Timetable. Download the CSV template:

```text
Tutor Email,Day,Start Time,End Time,Location
```

Use full weekday names Monday–Friday, HH:MM times, and existing tutor emails. Upload → validate/preview → import. Overlapping slots are rejected. Students see published slots in the consultation form.

If a tutor has no published slots, the student chooses manual booking and supplies tutor, date and start/end time. Existing consultations and teaching timetable overlaps are checked. Manual mode cannot bypass a tutor's published consultation timetable. The originally mentioned departmental timetable files were not received, so no real department timetables are preloaded.

## 12. Library and email checks

Library service forms now include the requested checkboxes, dropdowns, optional bibliography fields, participant counts, repository upload requirement and reading-list fields. No personal resource passwords are requested.

All four service submissions/status changes retain the existing applicant/DAA/responsible-staff notification queue. Importing teaching/consultation timetables does not email applicants. Email delivery still requires your configured PHP mail transport and cron worker; no live SMTP test was performed on the VM.

## 13. Acceptance checks and rollback

- Check all four public forms, old records, imports, downloads and existing official logins.
- Test a real phone and board over HTTPS; reject a second unregistered phone and a copied expired QR.
- Test wrong tutor/room/time, duplicate confirmation, revoked devices and the next scheduled class.
- Verify HoD department restrictions and audited DAA corrections.
- Check email delivery using real test recipients.
- Review `/var/log/apache2/cnr-academic-error.log` if an action fails. Do not paste passwords or active QR/device tokens.

If you must roll back, stop Apache, restore the backed-up application files and restart Apache. The added tables can remain unused by the old version; do not drop them or restore an old database over new live records without reviewing what would be lost. Keep the original full backup until acceptance testing is complete.

Local automated validation covers PHP syntax, existing/public workflows, Excel readers, conflict checks, QR/device security and attendance/consultation/library functions. Target-host MySQL behavior, trusted certificates, board startup, phone camera scanning and actual mail delivery require on-site verification.
