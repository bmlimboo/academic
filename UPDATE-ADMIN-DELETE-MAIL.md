# Admin deletion and substitution notification update

## Behaviour

- Administrator: open View request, then Administrator controls. Delete mistaken / duplicate request requires a reason and confirmation. It removes the record from active lists and reports but preserves the original request, attachments and activity history for audit.
- On the Academic Services home page, administrators can select View deleted requests (audit). Other roles cannot open deleted requests or their attachments.
- Deletion cancels queued/failed messages for that request. Already sent or currently being delivered emails cannot be recalled.
- DAA substitution notifications now go to daa.cnr@rub.edu.bt, independently of the email used on Tshotsho's DAA login account. Login/password reset addresses are not changed.
- The applicant still receives notifications at the email saved in their staff-directory account. When DAA is the applicant, their receipt goes to their own registered address and approval goes to President.
- Administrator controls show the applicant address, recipient history, delivery status and attempts. Resend notifications queues a reminder to the current recipients and supersedes unsent earlier messages for the request. Wait at least one minute between retries.
- No existing requests are automatically deleted or resent by installing this update.

## Copy from the Mac

```bash
scp /Users/budhimanlimboo/Documents/myproject/deliverables/cnr-admin-delete-mail-update.zip root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

## Back up and install on Ubuntu

Use a quiet period. Stop if any backup or validation command fails.

```bash
cd /var/www/academic-system
backup_dir="/root/cnr-before-delete-mail-$(date +%Y%m%d-%H%M%S)"
mkdir -m 700 "$backup_dir"
systemctl stop apache2
tar -czf "$backup_dir/application.tar.gz" public src bin migrations config
mysqldump --single-transaction cnr_academic > "$backup_dir/database.sql"
chmod 600 "$backup_dir/database.sql"
unzip -o /tmp/cnr-admin-delete-mail-update.zip -d /var/www/academic-system
php -l src/request-admin.php
php -l src/request-admin-view.php
php -l src/bootstrap.php
php -l public/index.php
php -l bin/mail-worker.php
php bin/migrate.php
chown -R root:www-data public src bin migrations
find public src bin migrations -type d -exec chmod 755 {} \;
find public src bin migrations -type f -exec chmod 644 {} \;
systemctl start apache2
```

If root database authentication requires a password, use `mysqldump -u root -p --single-transaction cnr_academic` and redirect to the backup file above. Do not put the password directly in the command. Use your actual database name if different. This cumulative ZIP excludes config, storage and credentials. It uses your existing mail transport and worker schedule. This feature needs no new database columns; the migration preserves existing data.

## Resolve the duplicated request shown in the screenshot

1. Sign in as administrator. Open each Mr. Ugyen request with View request and compare their details and activity histories to identify which should remain.
2. On the mistaken record, expand Delete mistaken / duplicate request, enter a reason such as Duplicate submission, and click Delete request. Confirm.
3. Open the remaining request. Under Administrator controls, check the displayed applicant email. If incorrect, correct the existing staff-directory entry first.
4. Inspect Email delivery for this request. This shows where earlier notifications were addressed; it can confirm whether Tshotsho's personal account was used.
5. Click Resend notifications on the retained request. The new entries should include the applicant and daa.cnr@rub.edu.bt for Faculty/HoD routes. Do not repeatedly press the button.
6. Wait for the existing mail worker, then check the status and ask the recipients to check inbox/spam.

## If neither recipient receives mail

Queued means the worker has not processed the message. Failed means PHP's mail transport did not accept it. Sent only means the server transport accepted it, not that Gmail placed it in the inbox.

Check the current schedule and Postfix queue:

```bash
sudo grep -R -n 'mail-worker.php' /etc/crontab /etc/cron.d/
sudo crontab -u www-data -l
sudo postqueue -p
sudo tail -n 80 /var/log/mail.log
```

Do not add a duplicate cron job. A cron entry under /etc/cron.d may exist even when www-data's crontab is empty. The application queues the applicant email already; failure to receive it must be checked against these live delivery records. No live server logs were inspected while preparing this update.

The worker now rechecks each queued item under a database row lock so a message cancelled after its batch was selected is skipped. A message already handed to the mail server may still arrive.
