# Substitution form and Academic Services update

## Included changes

- Field-specific errors retain entered values and selected files in the open form when JavaScript is enabled. Correct the marked field and submit again. A failed network response keeps the form and asks the user to check records before retrying.
- Without JavaScript, a validation response preserves text and class rows, but browsers require attachment files to be selected again; the form explains this.
- Department dropdown checks the applicant's registered department to preserve correct HoD routing. Correct staff-directory data as administrator if someone has moved department.
- Return date removed for new submissions. Old records retain their stored data.
- Personal with mileage replaces the old label; no mileage-detail input.
- No substitution requires a written reason and disables class sessions. Stale sessions are ignored on the server.
- Each class has a substitute / teaching faculty email checked against the selected active staff record. A dedicated email lists their classes and explains that the request is awaiting approval.
- Academic Services home heading, coloured service buttons and Official login button.
- Public records: 10 per page, serial number, name, department, date and status. Substitutions show travel start/end; other services retain their relevant date. Consultation student identities remain masked on the public page.
- Substitution reports also use 10 rows per page and the new columns. Internal request references remain available in confirmation messages and official request detail links.
- Document/image uploads allow 20 MB per file. Video files are not enabled; use a video/resource link. Spreadsheet import limits remain separate.
- Updated Faculty PDF in Help & Manuals.

## 1. Copy from your Mac

```bash
scp /Users/budhimanlimboo/Documents/myproject/deliverables/cnr-substitution-refresh-update.zip root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

## 2. Back up and install on Ubuntu

Ask users to finish any open forms before updating. Stop if a backup command fails.

```bash
cd /var/www/academic-system
backup_dir="/root/cnr-before-form-refresh-$(date +%Y%m%d-%H%M%S)"
mkdir -m 700 "$backup_dir"
systemctl stop apache2
tar -czf "$backup_dir/application.tar.gz" public src bin migrations config
mysqldump --single-transaction cnr_academic > "$backup_dir/database.sql"
chmod 600 "$backup_dir/database.sql"
unzip -o /tmp/cnr-substitution-refresh-update.zip -d /var/www/academic-system
php -l public/index.php
php -l src/substitution-form.php
php -l src/substitution-view.php
php -l src/actions.php
php -l src/views.php
php bin/migrate.php
```

Use your actual database name if different. If mysqldump requires authentication, use `-u root -p` (prompt for the password), and do not proceed until backup succeeds. This ZIP is cumulative and excludes config, storage and credentials. The migration is repeatable; this form change adds no new database columns.

If you have NOT installed the preceding HoD/DAA/President approval update, also follow UPDATE-APPROVAL-RESET.md, included in this ZIP. Preview existing pending routes with `php bin/update-approval-routes.php`, check them, then apply using `php bin/update-approval-routes.php --apply`.

## 3. PHP upload configuration

In the PHP configuration actually used by the website, set:

```ini
upload_max_filesize = 20M
post_max_size = 50M
```

For Apache mod_php, locate the installed version with:

```bash
ls /etc/php/*/apache2/php.ini
```

Edit the listed file using nano. If Apache instead uses PHP-FPM, edit that version's `/etc/php/VERSION/fpm/php.ini` and restart its `phpVERSION-fpm` service as well. Changing only the CLI php.ini does not change website upload limits. The 50M total accommodates two 20 MB files plus form data.

## 4. Permissions and restart

```bash
chown -R root:www-data src public bin migrations
find src public bin migrations -type d -exec chmod 755 {} \;
find src public bin migrations -type f -exec chmod 644 {} \;
systemctl start apache2
```

## 5. Verify

- Refresh https://cas.cnr.edu.bt. Check Academic Services and the coloured buttons.
- Open Staff Substitution. Check Department is present and Return date is absent.
- Choose Personal with mileage: no additional mileage field should appear.
- Choose No: the reason field appears and Add class session is disabled/hidden.
- Choose Yes: add a session and enter the selected faculty's registered email.
- Check an invalid email highlights the field and preserves the other entries. Do not submit a real request merely for testing unless it is needed.
- Confirm a real submission's substitute notification arrives through your existing mail worker.
- Check the faculty manual and mobile layout.

The approval routes remain Faculty -> HoD -> DAA; HoD -> DAA; DAA -> President. Existing request history is preserved.
