# HRM & Payroll

A standalone **HRM & Payroll** web application: employees, departments, designations, shifts and rosters, holidays,
attendance from ZKTeco biometric devices (Windows Service JSON push and ADMS push), leave, overtime, salary advances,
employee loans, payroll with salary payments and payslips, reports and an HR dashboard.

Built on **CodeIgniter 3.1.13** (MVC, Query Builder, CI3 migrations), AdminLTE 2 + Bootstrap 3 + jQuery,
server-side DataTables, MySQL 5.7+ / MariaDB 10.3+ (InnoDB, utf8mb4). One database per client.

**[CLAUDE.md](CLAUDE.md) is the specification** (architecture, guardrails G1-G8, domain model, business rules, device
integration, permissions, the defect register P1-P20 and the build order). Read it before changing anything.

Status: build steps 0-1 (guardrails, application shell, login, users, roles, branches, settings, audit log). Business modules arrive
step by step, see "Build order" in CLAUDE.md.

## Requirements

- PHP **7.4 to 8.3** with `mysqli`, `mbstring`, `intl`, `xml`, `zip`, `gd` (and `curl` to run the test suite)
- MySQL 5.7+ or MariaDB 10.3+
- [Composer](https://getcomposer.org/) 2 (mPDF, PhpSpreadsheet; PHPUnit for development)
- Apache with `mod_rewrite` (`.htaccess` is included), or nginx with a `try_files ... /index.php` rule.
  Locally under WAMP/XAMPP at `http://localhost/hrm/`, or `php -S 127.0.0.1:8000 tests/support/router.php`.
- No Node/npm: the front-end libraries are plain files under `theme/`.

Code must run on every PHP version in that range: no `match`, named arguments, typed/union properties, nullsafe
operator, `str_contains()` & co.; declare every class property. `php tests/static_checks.php` enforces it.

## Install

```bash
composer install
cp application/config/database.example.php application/config/database.php   # edit the host map (and see below)
php index.php migrate                                                          # every configured tenant database
```

1. Create the database(s) (utf8mb4) and a MySQL user. `database.php` is gitignored; it maps `HTTP_HOST` to a database in
   `get_database_domain_map()`. Credentials come from the environment (`HRM_DB_HOST`, `HRM_DB_USER`, `HRM_DB_PASS`,
   `HRM_DB_PORT`) or edit `hrm_db_connection()`. An unknown host is refused (404): there is no fallback tenant.
2. `php index.php migrate` creates the schema and seeds the system **Admin** role and an `admin` user. The password is
   `$HRM_ADMIN_PASSWORD` when set, otherwise a random one **printed once** on the console (you must change it at first login).
   `HRM_ADMIN_USER` overrides the username. Nothing secret is committed.
   One tenant only: `TENANT_DB=hrm_client_xyz php index.php migrate`. A specific version: `php index.php migrate version 6`.
   Where every tenant stands: `php index.php migrate status`.
3. Open the site and log in. Under **Administration**: users, roles (permission grid), branches, settings (six tabs) and the audit log.
   Set `HRM_ENCRYPTION_KEY` (environment) for signed links in later steps.
4. Make sure `application/logs`, `application/cache` and `uploads` are writable by the web server. `uploads/` is denied by
   `.htaccess`; files are served only through a controller.

### Cron (server crontab)

```cron
30 0  * * *    php /path/to/hrm/index.php cron attendance_close       # day close, yesterday          (build step 4)
10 0  1 1 *    php /path/to/hrm/index.php cron leave_carry_forward    # year-end carry forward         (build step 6)
*/15 * * * *   php /path/to/hrm/index.php cron device_health          # stale devices, lost punches    (build step 5)
15 3  * * *    php /path/to/hrm/index.php cron housekeeping           # purge technical rows           (available now)
```

`cron` and `migrate` are command-line only: over HTTP they answer 404. Each job loops over the configured tenants
(or only `TENANT_DB`). Jobs marked with a build step are added by that step.

## Tests and static checks

```bash
mysql -e "CREATE DATABASE hrm_test CHARACTER SET utf8mb4"      # plus a user with rights on it (same credentials as database.php)
php tests/static_checks.php        # guardrail scan, must print "0 violations"
vendor/bin/phpunit                 # migrates hrm_test from zero, then runs every test
git config core.hooksPath tools/git-hooks      # run the scan (and php -l) before every commit
composer lint                      # php -l on every PHP file
```

Browser checks (Playwright with the bundled Chromium; screenshots land in `docs/step-N/`):

```bash
TENANT_DB=hrm_dev HRM_E2E_PASSWORD='E2e#Passw0rd' php tests/e2e/seed_users.php      # users nopriv / viewer
TENANT_DB=hrm_dev php -S 127.0.0.1:8099 tests/support/router.php &
HRM_ADMIN_PASSWORD=... node tests/e2e/shell_smoke.js
HRM_ADMIN_PASSWORD=... node tests/e2e/admin_pages.js
```

The test bootstrap boots CodeIgniter against **`hrm_test`** (it refuses any database whose name does not end in `_test`),
drops all its tables and runs every migration. Guardrail tests run first: `Guardrail_static_checks_test`,
`Guardrail_permission_coverage_test` (reflection over all controllers plus an HTTP smoke test: every mapped route as a
user without permissions is 403), `Input_validator_test`. The HTTP tests start `php -S` on a random port.

## Layout

```
application/config      config, database(.example), hrm (setting defaults), permissions (catalog), menu (sidebar)
application/core        MY_Controller (default-deny _remap), Ess_Controller, Api_Controller, MY_Model, Default_deny, hrm_autoload
application/controllers Auth, Cron, Dashboard, Users, Roles, Branches, Settings, Audit (each declares $permission_map)
application/models      all SQL lives here
application/libraries   Input_validator, Datatable_request, Tx, Idempotency, Tenant, Hrm_migration
application/helpers     hrm (settings, dates, money), permission, sequence
application/migrations  NNN_name.php, sequential, idempotent, working down()
theme/                  AdminLTE 2 and plugins as plain files, theme/js/app.js (CSRF, iCheck, idle logout)
tests/                  phpunit bootstrap, static_checks.php, support/ (HTTP client, router, workers), e2e/
```
