# Implementation record

1. Inspection: existing users, PDO helpers, PHP sessions/CSRF, roles, public forms, request history/outbox and consultation slots reused. No duplicate staff database or new application framework.
2. Database: migrations/002_attendance.sql and bin/migrate.php add attendance/device/room/settings/audit structures and consultation appointment intervals. Migration is repeatable and preserves old booking times. No changes to existing user table structure.
3. Imports: src/attendance/import.php and locked Composer PHP libraries implement XLS/XLSX/CSV readers, Excel template/export, row preview/error report, overlapping classroom/tutor validation, explicit partial import of valid rows and transactional revalidation.
4. Timetable: shared attendance views add filters, date-bounded recurrence, one-off dates, edit/disable/delete rules and exports. Existing programme/department lists remain authoritative.
5. Tutor device: src/attendance/core.php reuses staff/password/session authentication, independent teaching permission and one active hashed device token. Replacement requires current password.
6. Board: registered browser cookie authenticates QR issuance; raw device secrets never displayed. Dynamic local QR image, countdown, refresh and full-screen button.
7. Attendance: server-side QR expiry/replay/device/tutor/room/time validation, transaction mutex and unique class/date prevent duplicates. Existing approved substitution sessions can authorize a substitute.
8. Reports: scoped daily/monthly attendance and summaries, derived missed occurrences, classroom scheduled-hours utilization, audited exceptions and Excel export.
9. Validation: tests/attendance.php plus existing workflow and isolated HTTP tests. Dependency audit was clean at installation. Production MySQL, HTTPS trust, real scanning and actual email delivery still require VM acceptance checks.

Additional requested changes: src/consultation.php adds CSV slot import and manual appointment conflict checks; src/library.php supplies complete conditional service field specifications and validation. public/index.php and src/views.php integrate these into the existing navigation and forms. public/assets/app.js and style.css extend the existing design.
