# Install Help & Manuals

This update adds a public Help & Manuals menu and three illustrated PDF guides: Faculty (3 pages), HoD (2 pages), and DAA (2 pages). It includes the preceding consultation department selector. No database migration is needed. No configuration or uploaded files are replaced.

## 1. Copy from your Mac

Run in your Mac Terminal:

```bash
scp /Users/budhimanlimboo/Documents/myproject/deliverables/cnr-help-manuals-update.zip root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

## 2. Back up the current application files

Run on the Ubuntu server. Stop if the backup reports an error.

```bash
cd /var/www/academic-system
tar -czf /root/cnr-before-help-$(date +%Y%m%d-%H%M%S).tar.gz public src
```

## 3. Install

```bash
unzip -o /tmp/cnr-help-manuals-update.zip -d /var/www/academic-system
php -l /var/www/academic-system/public/index.php
php -l /var/www/academic-system/src/views.php
php -l /var/www/academic-system/src/help.php
php -l /var/www/academic-system/src/sample-timetables.php
chown root:www-data public/index.php src/views.php src/help.php src/sample-timetables.php
chmod 640 public/index.php src/views.php src/help.php src/sample-timetables.php
chown -R root:www-data public/manuals
chmod 755 public/manuals
chmod 644 public/manuals/*-substitution-manual.pdf
systemctl reload apache2
```

## 4. Verify

Open https://cas.cnr.edu.bt/?page=help and refresh the page.

- Select Help & Manuals in the sidebar. On a phone, first tap Menu.
- Open and download each of the three guides.
- Check the page in a private browser window: no login is needed to read manuals.
- Confirm official approval actions still require HoD or DAA login.

The screenshots use fictional local demonstration accounts and requests. They contain no live approval records. They describe the workflow; the manuals do not grant any approval permissions.
