# College calendar: vertical Excel-style layout

This update changes presentation only. January to December appear vertically. Each month has its date grid and holiday notes on the left, with Meetings, Assessment and Others on the right. Every event is visible immediately. On phones, these same sections stack beneath each month for readability. Monthly view, editing permissions, publication status and saved events are unchanged.

Requires the College Calendar update to be installed first. No migration, import or mail-worker change is needed for this layout patch.

## Mac Terminal
```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-calendar-layout-update.zip" root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

## Ubuntu
```bash
cd /var/www/academic-system
unzip -t /tmp/cnr-calendar-layout-update.zip
backup_file="/root/cnr-before-calendar-layout-$(date +%Y%m%d-%H%M%S).tar.gz"
tar -czf "$backup_file" src/college-calendar/view.php public/assets/college-calendar.css
chmod 600 "$backup_file"
tar -tzf "$backup_file" >/dev/null && echo "Backup verified"
echo "$backup_file"
```
Proceed only if archive and backup checks succeed.
```bash
unzip -o /tmp/cnr-calendar-layout-update.zip -d /var/www/academic-system
php -l src/college-calendar/view.php
chown root:www-data src/college-calendar/view.php public/assets/college-calendar.css
chmod 644 src/college-calendar/view.php public/assets/college-calendar.css
apache2ctl configtest
```
If PHP reports no syntax errors and Apache reports Syntax OK:
```bash
systemctl reload apache2
systemctl is-active apache2
```
Open https://cas.cnr.edu.bt/calendar.php?year=2026 and press Cmd+Shift+R. Select Full year. Check January–December vertically and the Meetings, Assessment and Others columns. The calendar retains its existing data and holiday entries.

## If the original calendar update is not installed yet

Use the refreshed `cnr-college-calendar-update.zip` instead. It now includes this vertical layout. Follow INSTALL-COLLEGE-CALENDAR.md, including its one-time migration/import. Do not run the import again just to change the layout.

## Rollback in the same SSH session
```bash
tar -xzf "$backup_file" -C /var/www/academic-system
systemctl reload apache2
```
