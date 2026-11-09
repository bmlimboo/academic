# Compact attendance email verification form

Install this patch after the 19 October 2026 Student Attendance Portal update. It updates three application files; no database migration is needed.

The email form is now a compact card. Initially only the email input and **Send verification code to email** button appear. After a successful request, a six-digit input appears and receives focus. The email is retained and a Resend button is available. The layout adapts to phones. Existing verification expiry, attempt limits and mail delivery remain in place.

## On your Mac

```bash
scp /Users/budhimanlimboo/Documents/myproject/deliverables/cnr-attendance-email-form-update.zip root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

## On Ubuntu

Run these commands in the same terminal. Stop if any command fails.

```bash
cd /var/www/academic-system
backup_file="/root/cnr-before-email-form-$(date +%Y%m%d-%H%M%S).tar.gz"
tar -czf "$backup_file" src/academic/student-attendance.php src/academic/student-attendance-view.php public/assets/style.css
chmod 600 "$backup_file"
unzip -o /tmp/cnr-attendance-email-form-update.zip -d /var/www/academic-system
php -l src/academic/student-attendance.php
php -l src/academic/student-attendance-view.php
chown root:www-data src/academic/student-attendance.php src/academic/student-attendance-view.php public/assets/style.css
chmod 644 src/academic/student-attendance.php src/academic/student-attendance-view.php public/assets/style.css
systemctl reload apache2
```

No cron change or migration is required. Hard-refresh the browser (Command+Shift+R on your Mac) to load the new styling.

Open Student Attendance in a fresh/private browser window. Confirm that the code box is initially hidden. Enter your registered teaching email and click Send verification code to email; the code box should appear automatically. The existing mail worker delivers the requested code.

Local validation: PHP syntax checks, 49 attendance HTTP checks, and Chrome checks of the before/after form at desktop and phone widths. No real emails were sent during development.
