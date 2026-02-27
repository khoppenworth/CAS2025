# Deployment Guide — EPSS Self-Assessment (HRassessv300)

This document describes everything needed to deploy and operate the EPSS Self-Assessment web application in production.  The stack is a classic LAMP application written in PHP, backed by MySQL, and styled with static assets bundled in this repository.  Follow the steps below from server preparation through post-launch operations.

---

## 1. Architecture Overview

| Component            | Description                                                                 |
|----------------------|-----------------------------------------------------------------------------|
| Web server           | Apache 2.4+ or Nginx 1.20+ with PHP-FPM 8.1+.                               |
| Application runtime  | PHP 8.1+ (uses `password_hash`, JSON handling, PDO).                        |
| Database             | MySQL 8 / MariaDB 10.6+ (UTF-8 MB4).                                        |
| Background tasks     | Optional cron for database backups and log rotation.                        |
| External interfaces  | FHIR-compliant endpoints (`/fhir/*.php`) exposing questionnaire data.       |
| Optional analytics   | Export CSV download, Looker Studio / BI tools connecting to MySQL.          |

The application stores configuration, users, questionnaires, and responses in MySQL.  Branding values are editable in the UI and persisted to the `site_config` table.  When the table is missing (for a first run) the code now auto-creates it with sensible defaults, so the login page renders before running migrations.

---

## 2. Prerequisites

* DNS record pointing to the server.
* Ubuntu 22.04 LTS (or another Linux distribution with LAMP support).
* Non-root sudo user (`adduser deploy && usermod -aG sudo deploy`).
* Firewall configured to allow SSH, HTTP (80), and HTTPS (443).

---

## 3. Install System Packages

```bash
sudo apt update && sudo apt upgrade -y

# Web server + PHP runtime
sudo apt install apache2 libapache2-mod-php -y
sudo apt install php php-cli php-mysql php-xml php-mbstring php-curl php-zip php-gd -y

# Database
sudo apt install mysql-server -y

# Utilities
sudo apt install unzip git fail2ban ufw -y
```

Enable UFW firewall (optional but recommended):

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Apache Full'
sudo ufw enable
```

---

## 4. Secure & Configure MySQL

```bash
sudo mysql_secure_installation
```

Create the application database and user (adjust credentials as needed):

```sql
CREATE DATABASE epss_v300 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'epss_user'@'localhost' IDENTIFIED BY 'ChangeMe!'
  REQUIRE SSL;
GRANT ALL PRIVILEGES ON epss_v300.* TO 'epss_user'@'localhost';
FLUSH PRIVILEGES;
```

Import the schema and seed data:

```bash
mysql -u epss_user -p epss_v300 < init.sql

# Optional: load realistic demo data (populates every work function with five years of scores)
mysql -u epss_user -p epss_v300 < dummy_data.sql
```

Upgrading from releases prior to v3.0.0? Run the consolidated upgrade helper to add the questionnaire work-function mapping and SMTP settings columns:

```bash
mysql -u epss_user -p epss_v300 < upgrade_to_v3.sql
```

Additional SQL utilities:

* `migration.sql` — structural changes to apply after the initial import.
* `dummy_data.sql` — populate extra demo data (optional).
* `dummy_data_cleanup.sql` — remove demo content.

> **Tip:** Keep SQL files under version control on the server so you can re-run them when new releases ship.

---

## 5. Deploy the Application Code

1. **Clone the repository** into the Apache document root:
   ```bash
   cd /var/www
   sudo git clone https://github.com/your-org/HRassessv300.git epss-self-assessment
   ```

2. **Set ownership and permissions**:
   ```bash
   sudo chown -R www-data:www-data /var/www/epss-self-assessment
   sudo find /var/www/epss-self-assessment -type d -exec chmod 755 {} \;
   sudo find /var/www/epss-self-assessment -type f -exec chmod 644 {} \;
   sudo chmod 750 /var/www/epss-self-assessment/assets/uploads
   ```

3. **Configure database credentials** in `config.php` or via environment variables if they differ from the defaults:
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_NAME', 'epss_v300');
   define('DB_USER', 'epss_user');
   define('DB_PASS', 'ChangeMe!');
   define('BASE_URL', '/');
   ```

   The application now tolerates a missing `site_config` table on first boot and will create it with default branding values. If
   your database listens on a non-standard port, set the `DB_PORT` environment variable (or define the constant) so the
   application, upgrade script, and CLI utilities can connect successfully.

4. **Optional assets**: If you use AdminLTE dashboards, unzip the AdminLTE package into `assets/adminlte/`.

---

## 6. Apache Virtual Host & HTTPS

Create a site definition:

```bash
sudo tee /etc/apache2/sites-available/epss.conf <<'APACHE'
<VirtualHost *:80>
    ServerName epss.example.org
    DocumentRoot /var/www/epss-self-assessment

    <Directory /var/www/epss-self-assessment>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/epss_error.log
    CustomLog ${APACHE_LOG_DIR}/epss_access.log combined
</VirtualHost>
APACHE
```

Enable the site and required modules:

```bash
sudo a2enmod rewrite headers
sudo a2ensite epss.conf
sudo systemctl reload apache2
```

Issue HTTPS certificates with Let’s Encrypt:

```bash
sudo apt install certbot python3-certbot-apache -y
sudo certbot --apache -d epss.example.org
```

Certbot automatically configures the HTTPS virtual host and renewals (`systemctl status certbot.timer`).

---

## 7. Post-Deployment Checklist

1. **Login** with the seeded admin account (`admin / Admin123`) and immediately change the password from the Profile page.
2. **Complete the admin profile** so that other pages are accessible (profile completion is required).
3. **Update branding** under **Admin → Branding & Landing** to set the landing text, address, contact, and logo.
4. **Configure questionnaires** and performance periods as needed.
5. **Create additional users** via **Admin → Manage Users**.
6. **Verify FHIR endpoints** by hitting `https://epss.example.org/fhir/metadata.php` (should return JSON bundle).
7. **Check browser offline support** (service worker) by loading the dashboard and toggling offline mode.
8. **Review the performance trend chart** on **My Performance** to ensure Chart.js renders and the Likert-derived scores plot as expected (use the demo data for a quick smoke test).

---

## 8. Operations & Maintenance

| Task | Command / Notes |
|------|-----------------|
| Monitor Apache | `sudo tail -f /var/log/apache2/epss_error.log` |
| Monitor application logs | PHP errors are written to the Apache error log.  The app also records some actions in the `logs` table. |
| Backup database | `mysqldump -u epss_user -p epss_v300 > /backups/epss-$(date +%F).sql` (schedule via cron). |
| Backup uploads | `/var/www/epss-self-assessment/assets/uploads` contains uploaded branding assets. |
| Apply updates | `cd /var/www/epss-self-assessment && sudo -u www-data git pull` (then re-run SQL migrations if provided). |
| Security updates | `sudo apt update && sudo apt upgrade` weekly. |
| Rotate cron backups | Use `logrotate` or a simple cron script to prune older dumps. |

**Automated backups example (cron):**

```bash
sudo tee /etc/cron.d/epss-db-backup <<'CRON'
0 2 * * * root mysqldump -u epss_user -p'ChangeMe!' epss_v300 \
  | gzip > /var/backups/epss-$(date +\%F).sql.gz
CRON
```

Ensure `/var/backups` exists and is secured (`chmod 700`).

---

## 9. Scaling Considerations

* **Session storage:** PHP’s default file-based sessions are adequate for a single server.  For multi-node deployments, store sessions in Redis or the database.
* **Load balancing:** Terminate SSL at a load balancer and forward to multiple web servers each connected to a replicated MySQL cluster.
* **Caching:** Static assets are cacheable.  Consider enabling `mod_expires` and `mod_deflate` for additional performance.
* **Database tuning:** Adjust InnoDB buffer pool and connection limits based on concurrent users.

---

## 10. Disaster Recovery

1. Restore the latest database dump: `mysql -u epss_user -p epss_v300 < backup.sql`.
2. Re-deploy code from Git (matching the tagged release).
3. Restore `/assets/uploads` from backup to recover custom logos.
4. Update DNS cutover or load balancer to point to the recovered node.

Keep a tested recovery runbook to minimise downtime.

---

## 11. Local Development Quickstart

```bash
# Install PHP & MySQL locally (macOS example via Homebrew)
brew install php mysql
brew services start mysql

# Clone repository
git clone https://github.com/your-org/HRassessv300.git
cd HRassessv300

# Create database and import schema
mysql -u root -p -e "CREATE DATABASE epss_v300 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p epss_v300 < init.sql

# Run PHP's built-in server for testing
php -S 127.0.0.1:8000
```

Visit http://127.0.0.1:8000/index.php, log in with the seeded credentials, and explore the workflow.

---

## 12. Reference Accounts & Defaults

* Admin login: `admin` / `Admin123`.
* Supervisor login: `super` / same placeholder hash.
* Staff login: `staff` / same placeholder hash.
* Default branding: “My Performance” with a theme-derived EPSS logo served by `logo.php`.
* Translations: English (`lang/en.json`) is loaded by default; add additional JSON files to `lang/` for other locales.

---

## 13. Change Log for this Release

* Hardened `get_site_config()` to auto-create the configuration table when missing, preventing fatal errors on first boot.
* Rewrote this deployment guide with end-to-end instructions, operations guidance, and local development tips.

Keep this document with the release artifacts so administrators always have the latest operational instructions.

-- 1) Verify current state
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'questionnaire_item'
  AND COLUMN_NAME IN ('condition_source_linkid', 'condition_operator', 'condition_value')
ORDER BY COLUMN_NAME;

-- 2) Add missing columns safely (MySQL/MariaDB-compatible pattern)
SET @qi_condition_source_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_item'
    AND COLUMN_NAME = 'condition_source_linkid'
);
SET @qi_condition_source_sql = IF(
  @qi_condition_source_exists = 0,
  'ALTER TABLE questionnaire_item ADD COLUMN condition_source_linkid VARCHAR(255) NULL AFTER requires_correct',
  'DO 1'
);
PREPARE stmt FROM @qi_condition_source_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @qi_condition_operator_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_item'
    AND COLUMN_NAME = 'condition_operator'
);
SET @qi_condition_operator_sql = IF(
  @qi_condition_operator_exists = 0,
  'ALTER TABLE questionnaire_item ADD COLUMN condition_operator VARCHAR(20) NULL AFTER condition_source_linkid',
  'DO 1'
);
PREPARE stmt FROM @qi_condition_operator_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @qi_condition_value_exists = (
  SELECT COUNT(1)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_item'
    AND COLUMN_NAME = 'condition_value'
);
SET @qi_condition_value_sql = IF(
  @qi_condition_value_exists = 0,
  'ALTER TABLE questionnaire_item ADD COLUMN condition_value VARCHAR(500) NULL AFTER condition_operator',
  'DO 1'
);
PREPARE stmt FROM @qi_condition_value_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) Re-verify
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'questionnaire_item'
  AND COLUMN_NAME IN ('condition_source_linkid', 'condition_operator', 'condition_value')
ORDER BY COLUMN_NAME;
