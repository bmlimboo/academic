# Academic table formatting update

Apply after the Admin access update. No database migration is required. Saved attendance and marks are unchanged.

Changes: bordered Excel attendance template with 30 blank writing rows, clearer header spacing and alternating row shading; Download Excel register template button; bordered marks/report tables; component headings such as Class test / 100 (30%). CSV cannot contain visual formatting; use Excel for borders.

Upload cnr-academic-table-format-update.zip to /tmp on the Ubuntu server, then run:

```bash
cd /var/www/academic-system
backup_file="/root/cnr-before-table-format-$(date +%Y%m%d-%H%M%S).tar.gz"
tar -czf "$backup_file" src public
chmod 600 "$backup_file"
unzip -o /tmp/cnr-academic-table-format-update.zip -d /var/www/academic-system
php -l src/academic/student-attendance.php
php -l src/academic/download.php
systemctl reload apache2
```

Refresh your browser with Cmd+Shift+R and download a fresh Excel register template. Previously downloaded spreadsheets retain their old appearance. Existing upload formats remain supported.
