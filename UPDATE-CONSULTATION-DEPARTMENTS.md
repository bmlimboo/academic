# Consultation timetable department selection

Adds a public Department dropdown and View timetable button. Both the faculty grid and recurring slots show only the selected department. Slots without department metadata use the tutor’s home department. Existing booking and management actions remain available. No database migration is required.

1. On your Mac, copy the update:

```bash
scp /Users/budhimanlimboo/Documents/myproject/deliverables/cnr-consultation-department-update.zip root@10.10.140.137:/tmp/
```

2. Connect to the server:

```bash
ssh root@10.10.140.137
```

3. Back up the two files (stop if this fails):

```bash
cd /var/www/academic-system
tar -czf /root/consultation-before-update-$(date +%Y%m%d-%H%M%S).tar.gz src/views.php src/sample-timetables.php
```

4. Apply and check the update:

```bash
unzip -o /tmp/cnr-consultation-department-update.zip -d /var/www/academic-system
php -l /var/www/academic-system/src/views.php
php -l /var/www/academic-system/src/sample-timetables.php
chown root:www-data /var/www/academic-system/src/views.php /var/www/academic-system/src/sample-timetables.php
chmod 640 /var/www/academic-system/src/views.php /var/www/academic-system/src/sample-timetables.php
systemctl reload apache2
```

5. Open https://cas.cnr.edu.bt/?page=timetable, select a department and click View timetable. Verify that both tables display only that department. Also check in a private browser window without login. A department without published slots shows an empty-state message.

This patch contains the two PHP files and this guide. It does not modify configuration, uploaded files or database records.
