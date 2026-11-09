# Admin deletion of attendance and marks

This update adds an Admin-only delete option for attendance, CA/ESE and re-assessment records. No database migration is needed on the existing academic system. No records are deleted by installing it.

## How to delete dummy submissions after installation

1. Sign in through Official login as Admin.
2. Open Academic · Attendance & Marks → My workspace.
3. For CA/ESE, choose View / delete beside the submitted module. For attendance, open its Add attendance link, or open Submitted attendance registers → View / edit.
4. Find Administrator: delete this record.
5. Verify the module code and receipt number, enter a reason such as “Dummy data used for testing”, tick the confirmation and click Delete record.
6. The module disappears from active lists and reports. Its module/cohort identity is freed so a correct replacement can be created. Consolidated results update from the remaining records.

If an original CA/ESE record has linked re-assessment records, the message lists their receipt numbers. Delete those records first, then delete the original. Deleting one module does not delete other modules, the tutor account or submission periods. Expected-module lists are not changed automatically.

A full snapshot, reason, actor and timestamp remain in academic_audit. Attendance verification metadata is included in that snapshot; supporting PDF files remain stored for audit and are no longer downloadable through the deleted record. There is no one-click restore. This is deletion from active academic records, not permanent erasure of audit evidence.

## Install from your Mac

```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-academic-delete-update.zip" root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

## On Ubuntu — create the backup variable in this session

```bash
cd /var/www/academic-system
unzip -t /tmp/cnr-academic-delete-update.zip
backup_file="/root/cnr-before-academic-delete-$(date +%Y%m%d-%H%M%S).tar.gz"
tar -czf "$backup_file" src public bin migrations
chmod 600 "$backup_file"
tar -tzf "$backup_file" >/dev/null && echo "Backup verified"
echo "$backup_file"
```

Proceed only after archive and backup checks pass. This backup is for the code update, not a database backup. Before deleting records, use your normal database backup procedure if you need a separate recoverable copy of the data.

```bash
unzip -o /tmp/cnr-academic-delete-update.zip -d /var/www/academic-system
php -l public/index.php
php -l src/academic/core.php
php -l src/academic/view.php
php -l src/academic/student-attendance-view.php
```

All PHP checks must report no syntax errors.

```bash
chown -R root:www-data public src bin migrations
find public src bin migrations -type d -exec chmod 755 {} \;
find public src bin migrations -type f -exec chmod 644 {} \;
apache2ctl configtest
```

If Syntax OK:

```bash
systemctl reload apache2
systemctl is-active apache2
```

Refresh with Cmd+Shift+R. Keep the mail-worker cron running. The ZIP includes the latest calendar layout and academic formatting; it excludes configuration, storage and uploaded files. Do not run a calendar import as part of this deletion update.

## Roll back the code update

In the same terminal:

```bash
tar -xzf "$backup_file" -C /var/www/academic-system
systemctl reload apache2
```

Code rollback does not restore deleted database records. The deletion audit retains their snapshots.

Validation: 16 isolated deletion checks and 39 existing academic HTTP checks passed. No production records were deleted or emails sent during development.
