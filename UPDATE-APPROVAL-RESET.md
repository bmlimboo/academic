# Update approval routes and password recovery

This update is for the existing installation at /var/www/academic-system. It includes the Help & Manuals page, department selector, and revised illustrated guides.

## What changes

- Faculty -> department HoD -> DAA.
- HoD applicant -> DAA directly; HoD Approval shows Not applicable.
- DAA applicant -> President; HoD and DAA Approval show Not applicable.
- No self-approval. President decisions are available only for the DAA route.
- New production notification and reset links use https://cas.cnr.edu.bt. Production URL is fixed centrally in src/bootstrap.php; your config/config.php and mail settings are preserved.
- Forgot password? on Official Login emails a single-use link valid for 30 minutes to an active official's registered email. Only the newest link works. Resetting a password invalidates existing official login sessions. Faculty directory entries still do not need a login.
- The upgrade script repairs existing pending requests according to the applicant's current role, then stores the route. Future role changes do not silently change a stored route. Completed and historical requests are preserved.
- Existing official sessions will need to sign in again once after installing this update.

## 1. Copy from the Mac

```bash
scp /Users/budhimanlimboo/Documents/myproject/deliverables/cnr-approval-password-reset-update.zip root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

## 2. Back up before changing files

Run in the Ubuntu container. Choose a quiet period; the website will be briefly unavailable. Stop if a command fails.

```bash
cd /var/www/academic-system
backup_dir="/root/cnr-before-approval-reset-$(date +%Y%m%d-%H%M%S)"
mkdir -m 700 "$backup_dir"
systemctl stop apache2
tar -czf "$backup_dir/application.tar.gz" public src bin migrations config
mysqldump --single-transaction cnr_academic > "$backup_dir/database.sql"
chmod 600 "$backup_dir/database.sql"
```

The dump command assumes the database is named cnr_academic and root uses local database authentication. If it fails, use your database administrator credentials with `mysqldump -u root -p --single-transaction cnr_academic` and redirect into the same backup file. Never put a password directly on the command line. Do not continue without a successful database backup.

## 3. Install files and database table

```bash
unzip -o /tmp/cnr-approval-password-reset-update.zip -d /var/www/academic-system
php -l public/index.php
php -l src/bootstrap.php
php -l src/password-reset.php
php bin/migrate.php
```

The migration adds the reset-token table and preserves existing accounts and passwords. No config or storage files are included in the ZIP.

## 4. Preview and repair pending routes

```bash
php bin/update-approval-routes.php
```

This prints request references and their proposed routes without saving. Check that HoD requests show Pending DAA and DAA requests show Pending President. Check the applicants have the correct roles in the account directory before applying. If a role is wrong, restore website access with `systemctl start apache2`, correct it as administrator, then stop Apache again before continuing.

```bash
php bin/update-approval-routes.php --apply
```

This is repeatable. It queues routing notifications for affected HoD/DAA requests, supersedes their unsent old routing messages, and replaces legacy internal-IP/localhost links in remaining unsent emails. Already-sent emails cannot be changed. The existing mail worker sends queued messages.

## 5. Set permissions and start the website

```bash
chown -R root:www-data src bin migrations public
find src bin migrations public -type d -exec chmod 755 {} \;
find src bin migrations public -type f -exec chmod 644 {} \;
systemctl start apache2
```

Your config and storage permissions remain unchanged.

## 6. Check official accounts and password recovery

1. Sign in as administrator at https://cas.cnr.edu.bt.
2. Under Users & Notifications, confirm the President's existing staff entry has role President, the correct email and Active status. Do not create a duplicate person. Confirm HoD and DAA roles are also correct.
3. Open Official Login in a private browser window and choose Forgot password?. Enter your own registered official email.
4. Wait for the existing mail worker, then open the newest email link. Set and confirm a new password (12 to 72 bytes) and sign in. Check spam if needed.
5. Check Help & Manuals: Faculty, HoD, DAA and President PDFs should open.
6. Verify real pending requests show the intended stage and correct officials have the decision controls. Do not approve a real request merely to test the system.

Password-reset emails use the same Postfix/Gmail transport and queue as substitution emails. The existing worker must run regularly. Check without exposing credentials:

```bash
sudo grep -R -n 'mail-worker.php' /etc/crontab /etc/cron.d/
sudo crontab -u www-data -l
```

Do not add a duplicate cron job. If recovery emails remain queued, inspect Users & Notifications and the existing worker configuration. The system deliberately gives the same reset-request message for unknown, inactive and valid emails.

## Recovery

If an installation check fails, stop and retain the error output. Restart Apache if no incompatible files were installed. Otherwise restore the application backup while Apache is stopped and then start it. If routing was already migrated, restoring old code alone is insufficient: restore the matching database backup as well, only before accepting new requests, because restoration discards changes made since backup. The backup path is printed by `echo "$backup_dir"` in the original SSH session.

## Local validation

Approval routes, authorization, rejection reasons, self-approval, migration preview/idempotency, password expiry/replay/rate limiting, public HTTP screens and session revocation were tested using an isolated SQLite database. Production MySQL and email delivery must be checked after installation using the steps above.
