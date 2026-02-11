# HRassess

Performance assessment portal built with PHP and MySQL.

## LAMP deployment guide

The application runs on a traditional Linux + Apache + MySQL/MariaDB + PHP stack. The steps below assume Ubuntu 22.04 with
`sudo` privileges—adapt the package manager and service commands as needed for your distribution.

### 1. Install system packages

```sh
sudo apt update
sudo apt install apache2 mysql-server php php-cli php-mysql php-xml php-curl php-zip php-gd php-intl php-mbstring unzip git
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Ensure the PHP CLI and Apache modules use the same PHP version. Enable any additional extensions required by your local
policy (e.g., `php-ldap` for LDAP integrations).

### 2. Deploy the application code

```sh
sudo mkdir -p /var/www/hrassess
sudo chown "$USER":"$USER" /var/www/hrassess
git clone https://github.com/your-org/HRassessv300.git /var/www/hrassess
cp /var/www/hrassess/.env.example /var/www/hrassess/.env
```

Populate the `.env` file with the production database connection, base URL, and SMTP settings. When deploying behind a
reverse proxy or subdirectory, adjust `BASE_URL` accordingly. Ensure writable directories exist for uploads:

```sh
mkdir -p /var/www/hrassess/assets/uploads/branding
chmod 775 /var/www/hrassess/assets/uploads /var/www/hrassess/assets/uploads/branding
```

If Apache runs under a different user (e.g., `www-data`), adjust ownership:

```sh
sudo chown -R www-data:www-data /var/www/hrassess/assets/uploads
```

### 3. Configure the virtual host

Create `/etc/apache2/sites-available/hrassess.conf`:

```apache
<VirtualHost *:80>
    ServerName hrassess.example.com
    DocumentRoot /var/www/hrassess

    <Directory /var/www/hrassess>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/hrassess_error.log
    CustomLog ${APACHE_LOG_DIR}/hrassess_access.log combined
</VirtualHost>
```

Enable the site and reload Apache:

```sh
sudo a2ensite hrassess.conf
sudo systemctl reload apache2
```

For HTTPS deployments, enable `certbot` or your preferred TLS tooling and update the virtual host accordingly.

### 4. Provision the database

Secure the MySQL installation and create a database/user:

```sh
sudo mysql_secure_installation
mysql -u root -p
```

Within the MySQL shell:

```sql
CREATE DATABASE hrassess CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hrassess'@'localhost' IDENTIFIED BY 'strong-password';
GRANT ALL PRIVILEGES ON hrassess.* TO 'hrassess'@'localhost';
FLUSH PRIVILEGES;
```

Import the schema and (optionally) sample content from the application directory:

```sh
mysql -u hrassess -p hrassess < /var/www/hrassess/init.sql
# Optional migrations if upgrading from an older release
mysql -u hrassess -p hrassess < /var/www/hrassess/migration.sql
# Optional demo data for onboarding/training environments
mysql -u hrassess -p hrassess < /var/www/hrassess/dummy_data.sql
```

If you are upgrading from an installation that predates v3.0.0, apply the consolidated upgrade script to provision the questionnaire work-function mapping and SMTP configuration columns:

```sh
mysql -u hrassess -p hrassess < /var/www/hrassess/upgrade_to_v3.sql
```

Seed an administrator account so you can sign in:

```sh
cd /var/www/hrassess
php scripts/seed_admin.php
```

Store the generated password securely. Additional users can be invited through the web interface once the admin account is
active.

### 5. Final verification

1. Restart Apache and MySQL to ensure configuration changes are loaded:
   ```sh
   sudo systemctl restart apache2 mysql
   ```
2. Browse to `http://hrassess.example.com` (or your virtual host) and sign in with the seeded administrator credentials.
3. Use **Administration → Settings** to configure SMTP, branding, and SSO providers.
4. Run `bin/check-upload-env.php` and verify no errors are reported.

Keep system packages and the application up to date. The included `scripts/system_upgrade.php` command automates code updates
and backups for future releases.

## Configuration

Environment variables are read directly via `getenv`. Set them in your shell or a `.env` file (loaded by your web server):

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `BASE_URL` (defaults to `/`)
- `APP_DEBUG` (`1` to show errors in development, otherwise disable display of errors)
- `RATE_LIMIT_REQUESTS` (optional; requests allowed per IP during the window, defaults to 240)
- `RATE_LIMIT_WINDOW_SECONDS` (optional; rolling window length in seconds, defaults to 60)

### Single sign-on

Administrators can configure Google Workspace or Microsoft Entra ID (Azure AD) authentication under **Administration → Settings**.
Provide the client ID, client secret, and (for Microsoft) the tenant identifier. The OAuth redirect URL is
`<BASE_URL>/oauth.php?provider=<google|microsoft>&action=callback`; ensure this is registered with each provider.

When a user signs in with SSO and no account exists, the platform auto-provisions a pending profile that is limited to the
Profile page until a supervisor approves it. Supervisors (and admins) can review and approve accounts at
**Team & Reviews → Pending Approvals**. A banner on the profile and login pages explains the pending status to the end user.
Supervisors can also set the user's next assessment date during approval.

### Email delivery

Enable SMTP notifications from **Administration → Settings**. The system sends approval and assessment scheduling emails using the
connection details provided. Required fields are the host, port, authentication credentials (if needed), and the "From" address.
Test the configuration by approving a pending account or scheduling an assessment for a staff member.

### Branding & Logo

Administrators can update the site identity from **Administration → Branding & Landing**. Logo uploads are stored under
`assets/uploads/branding` inside the project directory. Ensure this folder (and `assets/uploads/`) is writable by the web server
user. Supported formats include PNG, JPEG, GIF, SVG, and WebP. The application validates uploads using PHP's Fileinfo extension
and rejects unsupported MIME types.

Uploaded files are saved with randomized filenames and served via relative web paths so deployments under a subdirectory continue
to work. If no custom logo is configured, a theme-driven logo generated by `logo.php` renders automatically.

Run `bin/check-upload-env.php` to verify the upload environment. The script reports the resolved base path, upload directory
permissions, and relevant PHP configuration such as `upload_max_filesize`, `post_max_size`, and Fileinfo availability.

### API documentation

A built-in Swagger UI is available to administrators at `/swagger.php` (linked under **Administration → API Documentation**).
The page renders the OpenAPI definition stored at `docs/openapi.json`, covering FHIR resources and internal helper endpoints.

## Internationalisation

Translations live in `lang/*.json`. Users can switch between English, French, and Amharic via the language selector. Preferences
persist in the session and a cookie. Administrators can enable or disable interface languages from **Administration → Settings**
while keeping at least English or French active. To add a language:

1. Create `lang/<code>.json` with translation keys.
2. Add the language code to `SUPPORTED_LOCALES` in `i18n.php`, deploy the translation file, and enable it from the Settings page.

## Database migrations & data

- `init.sql` – bootstrap schema.
- `migration.sql` – incremental changes when upgrading existing databases.
- `dummy_data.sql` – optional demo users and responses. Remove with `dummy_data_cleanup.sql` if needed.
- `scripts/purge_questionnaires_and_submissions.php --yes` – removes all questionnaires and linked submissions.
- `scripts/seed_dummy_data_from_questionnaires.php` – creates dummy users/responses based on existing questionnaires.
- `docs/dummy-data-seeding.md` – details questionnaire eligibility and analytics implications for seeded data.

### Data Export

Administrators can download a CSV of assessment responses from **Administration → Export Data**. When stakeholders prefer
live dashboards, connect Google Looker Studio directly to the MySQL database and reuse the same export query:

1. In Looker Studio, create a **MySQL** data source that points to the reporting replica (or a production database user with
   read-only privileges).
2. Paste the script below into Looker Studio's custom query editor, or run it ahead of time to create a reusable view.
3. Disable the "Use default date range" option in Looker Studio if you want to control filtering through report parameters,
   such as performance period or reviewer.

```sql
-- Create a reusable view for analytics tooling
CREATE OR REPLACE VIEW analytics_assessment_exports AS
SELECT
    qr.id AS response_id,
    u.username,
    u.full_name,
    u.email,
    u.role,
    u.work_function,
    u.account_status,
    qr.questionnaire_id,
    q.title AS questionnaire_title,
    qr.status,
    qr.score AS score_percent,
    pp.label AS performance_period,
    qr.created_at,
    qr.reviewed_at,
    reviewer.username AS reviewer_username,
    reviewer.full_name AS reviewer_full_name,
    qr.review_comment
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
LEFT JOIN questionnaire q ON q.id = qr.questionnaire_id
LEFT JOIN users reviewer ON reviewer.id = qr.reviewed_by
LEFT JOIN performance_period pp ON pp.id = qr.performance_period_id;
```

Grant the reporting user `SELECT` access to the view (and underlying tables when using a custom query). The resulting dataset
mirrors the downloadable CSV and is ready for charts, scorecards, and calculated fields in Looker Studio.

## Questionnaire import

Administrators can upload FHIR `Questionnaire` resources (JSON or XML) from
**Administration → Manage Questionnaires**. The importer accepts standalone
Questionnaire resources or Bundles that contain them. Items with
`<type>group</type>` (or child `item` nodes) become sections in the application,
and every nested item is imported as a question in the same order as the source
file. An Excel planning sheet that demonstrates the grouping structure is
available at `scripts/download_questionnaire_template.php`.
It ships with the full Warehouse &amp; Inventory Management (WIM)
technical assessment so new deployments have a ready-to-use
questionnaire aligned with EPSA requirements.

## Development tooling

- `make lint` – run `php -l` across all PHP files.
- `php scripts/check_database_integrity.php` – confirm the deployed database
  tables and columns match the expectations in the PHP application (e.g.,
  `config.php`, `index.php`) and that default roles/site configuration exist.
- GitHub Actions workflow `.github/workflows/ci.yml` lints PHP and checks MySQL availability.

## System upgrades

Use the [`scripts/system_upgrade.php`](scripts/system_upgrade.php) CLI to deploy
new releases safely. The tool backs up the application directory and database
before applying updates from GitHub and provides downgrade capabilities if an
upgrade fails. During deployment the script also runs bundled SQL migrations
such as `migration.sql` so database changes are applied automatically. Refer to
[`docs/system-upgrade.md`](docs/system-upgrade.md) for usage examples and
options.

## Quality and compliance

Refer to [`docs/quality_assurance.md`](docs/quality_assurance.md) for the
project's quality management and compliance framework. The checklist aligns the
delivery workflow with ISO/IEC 12207 (software life cycle), ISO/IEC 25010
(product quality), ISO/IEC 27001 (information security), and related standards.

## Default navigation

- `/index.php` – login
- `/my_performance.php` – personal performance hub (default landing page after login)
- `/submit_assessment.php` – assessment submission
- `/admin/*` – administration

## Progressive Web App assets

`manifest.php` and `service-worker.js` are referenced with the configured base URL to ensure they resolve when deployed
in a subdirectory.

## Quick start ONLY for localhost testing

1. Verify PHP extension requirements via Composer:
   ```sh
   composer check-platform-reqs
   ```
   The application requires PHP 8.1+ with the `curl`, `gd`, `json`, `mbstring`, `pdo_mysql`, `simplexml`, and `zip` extensions enabled.
2. Copy `.env.example` to `.env` and adjust values as needed.
3. Create the database schema:
   ```sh
   mysql -u root -p < init.sql
   ```
   The placeholder admin, supervisor, and staff accounts created by the script remain disabled until you assign new passwords.
4. (Optional) Load demo content:
   ```sh
   mysql -u root -p < dummy_data.sql
   ```
   Demo users share the temporary password `DemoUserPass123!` and must choose a new one on their first login.
5. Seed a default administrator account:
   ```sh
   make seed-admin
   ```
   The command prints the generated password to the console.
   All seeded accounts are flagged to require a password change at first sign-in.
6. (Optional) Rebuild the database from scratch, loading migrations, demo data, and an admin user:
   ```sh
   make rebuild
   ```
   Pass `ARGS="--no-dummy"` or `ARGS="--no-admin"` to the make target (or run `php scripts/rebuild_app.php` directly) if you want to exclude demo rows or the admin seeding step.
7. Launch the development web server:
   ```sh
   make run
   ```
8. Visit [http://localhost:8080](http://localhost:8080) and sign in with the seeded administrator credentials.

## Testing

The repository includes lightweight smoke tests that exercise the questionnaire
weighting helpers, work-function defaults, and analytics snapshot builder. Run
them individually from the project root:

```sh
php tests/work_function_assignments_test.php
php tests/questionnaire_scoring_test.php
php tests/analytics_report_snapshot_test.php
```

These scripts create in-memory SQLite schemas so they do not require the MySQL
server. Extend them or convert to PHPUnit tests as you grow coverage around the
questionnaire lifecycle and analytics endpoints.

## Scheduled analytics reports

Administrators can configure recurring analytics emails from the **Analytics → Scheduled analytics reports** card. Once a schedule is active, add the CLI helper to cron so PDFs are generated and emailed on time:

```sh
php scripts/send_scheduled_reports.php
```

The command checks for all due schedules, renders the latest analytics snapshot, and emails recipients using the SMTP configuration stored in **Settings → Notifications**. Review the script output (or cron logs) periodically to confirm reports are delivered successfully.
