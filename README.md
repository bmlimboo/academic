# Current update: cross-department teaching

Follow UPDATE-CROSS-DEPARTMENT.md. Timetable Department is the teaching department and may differ from the staff home department.

# Current update: bulk teaching attendance

Follow UPDATE-BULK-ATTENDANCE.md to deploy the cumulative update and enable/disable attendance for selected staff.

# Current release: teaching calendar and simpler timetable upload

Follow UPDATE-TEACHING-TIMETABLE.md. This supersedes older grid-upload/manual-entry instructions.

# Current update: historical import saving and supplied CNR logo

Follow UPDATE-IMPORT-LOGO.md. Upload validation is a preview only; use Step 2 to save records. The import page shows actual stored counts.

# Current release: staff status and HoD/DAA approvals

Use UPDATE-STAFF-APPROVALS.md for the latest cumulative server update. This release makes the first substitution approval stage HoD-only, with DAA as final approver; it supersedes older HoD/PL approval instructions.

# Current release: staff roles and mobile layout

Follow UPDATE-ROLES-MOBILE.md for the latest SCP/server update and instructions for assigning official roles to existing teaching staff.

# Latest update: timetable samples and password-free attendance

Use UPDATE-TIMETABLES.md for the current server update. Teaching staff enrol their phone once through an Admin-issued link; they do not log in. Older password-based attendance instructions below are superseded.

# Current release: Tutor Attendance and service updates

Use **UPDATE-ATTENDANCE.md** for installation on the existing VM. This release requires the additive `php bin/migrate.php` migration and includes PHP dependencies for XLS/XLSX and QR generation. Earlier statements below about no migration, no tutor login, or attendance not being implemented describe prior releases and are superseded by this section and the update guide.

Tutor Attendance is integrated with the existing users, session, roles and PDO connection. Public service forms stay public; System Admin can enable a tutor's attendance permission and initial password without creating another user. Board/phone registration and QR confirmation require trusted HTTPS. Consultation CSV upload, manual booking for tutors without published slots, and complete library choice controls are included. See IMPLEMENTATION-PHASES.md for implementation and validation details.

# CNR Academic Administration & Reporting System

## Bulk teaching staff upload

Admin → Teaching Staff now supports CSV/XLSX upload with Name, Email and Department headers. Validate and preview, then import. Existing emails are skipped without changing roles or passwords; duplicate emails inside one file must be removed before upload. Staff entries do not grant login access and no emails are sent. Historical substitutions match these directory entries by email; no separate login account is needed. Add the directory first, then import history.

## Public forms update

Forms and the latest 15 service summaries are now available without login. The homepage combines all four modules; module record pages use the same simple pagination without search or filter controls. Only the official Reports screen retains filters and exports. Public summaries include reference, service, staff applicant, submitted time and exact status. Student identities, consultation purposes, detailed payloads and attachments are not published.

Teaching staff select their name and enter the matching official email. Consultation applicants enter their name and email. This is contact matching, not identity verification; public submissions are marked as unauthenticated in the activity history. Submission never starts a staff or student login session. Existing faculty/student passwords no longer grant login. Officials continue to use their existing login accounts.

Administrators manage password-free faculty directory entries under Teaching Staff. Deleting a member marks them inactive and retires future timetable selections while preserving historical requests. Restore is available from the complete staff list. DAA can manage consultation outcomes on behalf of tutors who no longer sign in. Designated official roles remain managed in Users & Notifications.

Substitution Purpose has one category selector. Other requires a specification. Transportation Other also requires details; personal transport can retain optional mileage details. Validation is enforced in PHP and in the form.

This update requires no database migration and does not change existing approval states. The update ZIP excludes config/config.php and storage, preserving the server database password and uploads. See UPDATE-UBUNTU.md for deployment.

A plain PHP application with MySQL persistence. The supplied interface follows the provided CNR sample. This is a first implementation for review and institutional acceptance testing; it has not been deployed to a college server.

## Implemented

- Official account login, password changes, account creation/editing/deactivation, role and department assignment, login throttling, CSRF checks and server-side record permissions.
- Staff substitutions with unlimited affected-class and compensation-session rows, travel dates, calculated inclusive calendar days, supporting files and sequential HoD/PL → DAA approval. Self-approval is blocked. Either a department HoD or PL can make the first decision; it is not two separate approval stages.
- Consultation timetable with recurring weekday/time slots maintained by DAA/admin and designated officials. Students select the tutor's slot and an appointment date. One booking per slot/date; confirmation, completion, no-show and cancellation are handled by DAA or the assigned tutor when designated as an official. Retiring a timetable slot preserves existing bookings.
- Exam Cell requisitions and all nine library service categories with conditional questions. These are processed rather than approved.
- Programme document uploads by librarians, title, programme, description, version, uploader, upload time and hard-copy location. DAA, PL, librarians and admins can download. Earlier files remain available; librarians explicitly mark superseded versions.
- Searchable records, status/date filters, pagination, purpose/service counts, distinct applicants, approved staff-days and affected teaching hours, CSV export.
- CSV/XLSX historical substitution import with validation and preview, repeat-import deduplication, original-column retention and no notification replay. XLSX requires the first worksheet. Use the template headers; dates must be ISO text. Extra columns retain old repeated date/time and approval values but are not converted into structured class-session rows automatically.
- Private attachments served only after access checks. Supported types: PDF, DOCX, XLSX, PNG and JPEG, up to 10 MB each.
- Email outbox and cron worker. All request submissions/status changes notify the applicant and DAA; additionally the tutor, exam staff or librarians as appropriate. Substitutions notify the department HoD/PL and assigned substitutes; President and Administrative Officer receive email only at final approval. Emails link to authenticated records and do not include attachments.

## Production requirements

- PHP 8.2+ with PDO MySQL, mbstring, fileinfo, ZIP, SimpleXML, DOM and session support.
- MySQL 8+ (MariaDB compatibility requires testing on the target version).
- HTTPS-enabled Apache or Nginx with its document root pointing to **academic-system/public**. Never expose the repository root: configuration, uploads and database files must stay outside the web root.
- A MySQL database and a dedicated database user; a writable `storage/uploads` directory.
- A working PHP `mail()` transport on the host (normally the host's authenticated SMTP relay) and an authorized sender address. This version does not collect or directly configure SMTP usernames/passwords in the UI.
- Cron support for queued delivery. Back up MySQL and `storage/uploads` together. Agree record retention and access policies before use.

Create the database through your hosting control panel or MySQL administration tool, then configure these environment variables for both PHP and CLI/cron:

```sh
APP_DSN='mysql:host=127.0.0.1;dbname=cnr_academic;charset=utf8mb4'
DB_USER='cnr_academic'
DB_PASS='your-database-password'
APP_URL='https://your-college-domain.example'
MAIL_FROM='academic-services@your-college-domain.example'
MAIL_ENABLED='0'
APP_DEMO='0'
```

Set `ADMIN_EMAIL` and a unique `ADMIN_PASSWORD` of at least 12 characters for the initial setup, then run:

```sh
php bin/setup.php
```

Remove the bootstrap admin password from your environment after account creation. The schema installer converts the portable schema to MySQL auto-increment syntax. If your hosting does not support environment variables, configure the same values in `config/config.php`, which must remain outside the document root. Do not commit credentials.

Sign in as admin and create the real DAA, department HoD/PL, exam officers, librarians, President and Administrative Officer. Add faculty under Teaching Staff; students submit without accounts. Assign departments consistently. Change your password from My Account. The current model assigns one primary role per account; multi-role delegation and institutional single sign-on are not included.

After testing the server mail transport, set `MAIL_ENABLED=1` and schedule:

```cron
* * * * * /path/to/php /path/to/academic-system/bin/mail-worker.php
```

Ensure cron receives the same environment configuration. Failed messages retry up to five attempts and remain visible in Users & Notifications. “Sent” means accepted by the local mail transport, not confirmed inbox delivery. Actual email delivery has not been tested in this local environment. Do not enable email against the demo database.

## Local demonstration

SQLite is used only as a convenient local preview; the application defaults to MySQL in production. Create a separate demo database:

```sh
export APP_DSN="sqlite:$(pwd)/storage/demo.sqlite"
export APP_DEMO=1
export ADMIN_EMAIL=admin@example.test
export ADMIN_PASSWORD='CnrPreview2026!'
php bin/setup.php
php bin/demo.php
php -S 127.0.0.1:8086 -t public
```

Open http://127.0.0.1:8086. Demo accounts: admin, daa, hod, pl, faculty, student, exam, librarian, president, administration followed by `@example.test`. All demo accounts use `CnrPreview2026!`. They are fictional and must not be used in production. PHP's built-in server is for local review only.

## Importing the existing workbook

1. Create the staff accounts using the emails that will appear in the import.
2. Download the template from Import Historical Data.
3. Copy/map the old columns to Name, Email, Department, Travel Start Date, Travel End Date, Purpose and Status. Optional reporting columns are Purpose Category and Place.
4. Use Approved, Rejected, Pending or Cancelled. These become explicitly historical statuses, preserving their meaning without claiming that the new application performed those approvals.
5. Use ISO text dates (YYYY-MM-DD), not Excel serial values. Retain all extra columns, including original timestamp, HoD/Dean decisions and old repeated class dates.
6. Upload, validate, review and import. Identical source rows are skipped on repeat import.

A sample of the real workbook is still needed to implement and verify automatic mapping of its exact repeated session columns. Changes to an already-imported source row are treated as a new row, so use the original unchanged file when retrying a batch.

## Reporting definitions and limits

Travel days are inclusive calendar days; return date is stored separately. Approved staff-day totals deduplicate overlapping dates for one staff member. Filters select requests by travel start date and reports then total their entire travel periods; they are not a daily occupancy report. Affected hours come only from structured affected-class sessions. Historical imports without structured sessions cannot supply those hours. Consultation totals distinguish requested, confirmed, completed and no-show appointments.

Exports contain only records visible to the signed-in account and neutralize spreadsheet formula prefixes. Consultation reasons currently follow the supplied Research/Academic/Monitoring categories. The system does not store private counselling notes.

## Validation

Run `php tests/workflows.php` for isolated SQLite workflow checks. PHP syntax checks and local HTTP route checks are also performed during development. MySQL integration and real email delivery still require validation on the intended host.

Teaching attendance/VLAN validation from the earlier proposal is not implemented in this release. It needs a separate classroom-network pilot. The latest detailed request covers the four service modules and programme repository.

## Decisions still needed from CNR

- Actual account roster, official email addresses, department HoD/PL assignments, and the faculty consultation timetable.
- Hosting/database details and mail transport configuration.
- Original substitution workbook to verify column mapping and historical reports.
- Whether officials require multi-role accounts/delegated approvals, working-day calculations or half-days; the current implementation uses a single role and inclusive calendar days.
