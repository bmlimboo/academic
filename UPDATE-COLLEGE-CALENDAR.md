# College Calendar — installation and user guide

Prepared 19 October 2026. This update is tested locally, not yet installed on your live Ubuntu server. It includes the recent Admin-access, academic table formatting and consolidated marks updates. Configuration, uploaded files, passwords and existing academic records are not included in or overwritten by the ZIP.

## Features

- Public page: `https://cas.cnr.edu.bt/calendar.php` — no system banner, logo or sidebar; just College Calendar and the selected year.
- Automatic Gregorian dates and weekdays for 2000–2100, including leap years. Full-year and monthly views, category filter, today marker, mobile layout and printing.
- Blue meetings, purple assessments, green other college events, red national holidays, amber local observances. Every category also has a text label.
- Exam Cell, DAA and Admin can add, edit, publish and delete all calendar events. Other roles and visitors have read-only access. Admin retains its existing management rights.
- Event fields: title, category, start/end date (inclusive), optional start/end time, location, details and source. All times use Bhutan time.
- Draft events are visible only in the editor view. Published events are public. Deletion removes an event from public/editor listings while keeping its database audit history.
- Concurrent edits are protected: a stale editor must reload before saving over someone else's changes.
- The optional import includes 122 events from the supplied College Calendar 2026.xlsx. The repeated November–December marking event is imported once. Explicit dates in event text are used, not the spreadsheet row in which the notes happen to appear. Cross-month events remain one event. Source cell references are retained for editors.
- This calendar is informational. Publishing an event does not change submission deadlines, attendance requirements, room bookings or send emails.

## Holidays

2026 holiday entries are taken from your supplied college workbook. Punakha observances are labelled local; RUB Foundation Day and College Foundation Day are college events rather than national holidays.

For each future year:
1. Select the year; the dates and weekday grid appear automatically.
2. In Manage calendar, expand National holidays.
3. Click Add fixed-date holiday drafts. This adds five entries covering the King's birthday period, Third King's birthday, Coronation Day, Fourth King's birthday / Constitution Day and National Day.
4. Check the annual government holiday notice, correct dates if needed, and publish each entry.
5. Add lunar holidays (Losar, Zhabdrung Kuchoe, Buddha observances, Guru Rinpoche's birthday, Blessed Rainy Day, Dassain) and special/local holidays using their announced dates.

The system does not invent lunar dates or automatically publish unverified dates. It does not require an external API, Google account, internet fetch or new cron job. Ministry of Home Affairs notices: https://www.moha.gov.bt/ . Repeating the fixed-date suggestion action will not create duplicates or restore previously deleted suggestions.

## 1. Copy the update from your Mac

Open Terminal on the Mac:

```bash
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-college-calendar-update.zip" root@10.10.140.137:/tmp/
scp "/Users/budhimanlimboo/Documents/myproject/deliverables/cnr-college-calendar-update.sha256" root@10.10.140.137:/tmp/
ssh root@10.10.140.137
```

Run all following server commands on Ubuntu. Use the same SSH session throughout. Stop if a command fails.

## 2. Verify the package

```bash
cd /tmp
sha256sum -c cnr-college-calendar-update.sha256
unzip -t cnr-college-calendar-update.zip
```

Expect `OK` and `No errors detected`. If unzip is missing, install it with `apt install unzip`.

## 3. Pause briefly and back up

Use a quiet time; the website will be unavailable until Apache is started again.

```bash
cd /var/www/academic-system
backup_dir="/root/cnr-before-college-calendar-$(date +%Y%m%d-%H%M%S)"
mkdir -m 700 "$backup_dir"
if [ -f /etc/cron.d/cnr-academic-mail ]; then
  mv /etc/cron.d/cnr-academic-mail "$backup_dir/cnr-academic-mail.paused"
fi
systemctl stop apache2
```

Wait for any existing mail worker to finish, then check:

```bash
pgrep -af '[p]hp.*mail-worker.php'
```

**No output is expected** and means no mail worker is running. If a process is listed, wait and check again.

```bash
tar -czf "$backup_dir/application.tar.gz" public src bin migrations config storage
chmod 600 "$backup_dir/application.tar.gz"
tar -tzf "$backup_dir/application.tar.gz" >/dev/null && echo "Application backup verified"
mysqldump --single-transaction cnr_academic > "$backup_dir/database.sql"
chmod 600 "$backup_dir/database.sql"
test -s "$backup_dir/database.sql" && echo "Database backup saved"
echo "Backup directory: $backup_dir"
```

The configured production database is expected to be `cnr_academic`. If yours differs, use its actual name. If MySQL requests authentication, use:

```bash
mysqldump -u root -p --single-transaction cnr_academic > "$backup_dir/database.sql"
```

Do not continue if mysqldump reports an error, even if the output file exists. Never share the backup: it contains private data and configuration.

## 4. Extract and check

```bash
unzip -o /tmp/cnr-college-calendar-update.zip -d /var/www/academic-system
cd /var/www/academic-system
php -l public/index.php
php -l public/calendar.php
php -l src/bootstrap.php
php -l src/college-calendar/core.php
php -l src/college-calendar/view.php
php -l bin/migrate-college-calendar.php
```

All checks must report `No syntax errors detected`.

## 5. Create calendar tables and import your 2026 calendar

```bash
php bin/migrate-college-calendar.php --import-2026
```

On the first installation expect:

```text
College calendar tables ready. Existing events preserved.
122 events imported from College Calendar 2026.xlsx. Repeat runs do not overwrite edits or restore deletions.
```

This creates only the new calendar event and audit tables. The 2026 workbook entries are imported as published events. Repeating the command skips previously imported events; it will not overwrite changes or restore deleted events. The import uses the existing active Admin as its audit actor. You do not need to upload the original Excel file to the server.

To install an empty calendar instead, omit `--import-2026`.

## 6. Permissions and restart

```bash
chown -R root:www-data public src bin migrations
find public src bin migrations -type d -exec chmod 755 {} \;
find public src bin migrations -type f -exec chmod 644 {} \;
apache2ctl configtest
```

If the configuration test says `Syntax OK`:

```bash
systemctl start apache2
if [ -f "$backup_dir/cnr-academic-mail.paused" ]; then
  mv "$backup_dir/cnr-academic-mail.paused" /etc/cron.d/cnr-academic-mail
  chown root:root /etc/cron.d/cnr-academic-mail
  chmod 644 /etc/cron.d/cnr-academic-mail
fi
systemctl is-active apache2 cron
```

Both services should be active. The calendar requires no additional scheduled job.

## 7. Check the public page and editing

Open https://cas.cnr.edu.bt/calendar.php?year=2026 and refresh with Cmd+Shift+R.

- Open in a private/incognito window: the calendar should appear without login and without Add/Edit/Delete controls.
- Select November: both November holidays on the 1st should appear, along with college events; Fireline preparation and the November–December marking event should span their dates.
- Sign in through Official login as Admin, DAA or Exam Cell. Click College Calendar in the sidebar, then Manage calendar.
- Add a test event as Draft. It should not appear to visitors.
- Edit it and choose Published, then Save event. It should appear to visitors.
- Delete the test event after checking it.
- Select 2027: dates generate immediately. Add the year's events and confirmed holidays as needed.
- Use Print calendar for a printable year or month; choose Save as PDF in the browser's print dialog if desired.

## 8. Add the link on the college website

Use the link label **College Calendar** and this URL:

```text
https://cas.cnr.edu.bt/calendar.php
```

It opens the current year automatically. Use `?year=2026` only for a link that should always open the 2026 archive.

Matching your existing website menu:

```html
<li>
  <i class="fa fa-check-circle" aria-hidden="true" style="color:#004e98"></i>
  <a style="text-decoration:none" href="https://cas.cnr.edu.bt/calendar.php">College Calendar</a>
</li>
```

## If installation fails

If PHP checks or migration fail, keep the site paused and copy the error message. To return to the previous application in the same SSH session:

```bash
tar -xzf "$backup_dir/application.tar.gz" -C /var/www/academic-system
systemctl start apache2
if [ -f "$backup_dir/cnr-academic-mail.paused" ]; then
  mv "$backup_dir/cnr-academic-mail.paused" /etc/cron.d/cnr-academic-mail
  chown root:root /etc/cron.d/cnr-academic-mail
  chmod 644 /etc/cron.d/cnr-academic-mail
fi
```

The new calendar tables can remain unused; do not drop or restore the production database just to roll back this additive feature. If you reconnect, locate your backup using `ls -dt /root/cnr-before-college-calendar-*` and set `backup_dir` to that exact directory before running rollback commands.

## Validation performed

44 isolated calendar HTTP checks: event import, idempotency, leap years, cross-month spans, role authorization, drafts, publishing, deletion, CSRF, stale revisions, escaped text and holiday suggestions. Desktop and mobile browser checks passed, including no horizontal page overflow. 106 existing public-access checks passed. Tests used an isolated SQLite database without sending emails. Production MySQL deployment is still to be performed on your server.
