# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A standalone **HRM & Payroll** web application (employees, departments, designations, shifts and rosters, holidays and weekly
offs, attendance from ZKTeco biometric devices, leave, overtime, salary advances, employee loans, payroll with salary payments,
payslips, reports and an HR dashboard) built on **CodeIgniter 3.1.x**, AdminLTE 2 + jQuery views, DataTables (server side),
MySQL 5.7+/MariaDB 10.3+ (InnoDB, utf8mb4).

It re-implements, as its own product, the HRM module of the Nano Sync POS/ERP (`ProFarjan/pos-retails`, CodeIgniter 3) with the
same features, and integrates the same attendance hardware paths: the **ZKTeco Windows Service**
(`ProFarjan/ZKTeco-Windows-Service`: pulls from the device over TCP/UDP 4370, then JSON POST) and the **ZKTeco ADMS push
protocol** (`/iclock/*`: the device calls the server itself).

The feature behaviour below is copied from pos-retails on purpose. The **defects** found in pos-retails are not: each one has a
numbered entry (P1-P20) in **"Known pos-retails defects and how this project resolves them"**, with the design that removes it, the
rule that keeps it removed, and the test that proves it. Read that section before touching payroll, attendance, devices or
permissions, and build the guardrails (build order step 0) before any feature.

Target runtime: PHP 7.4 - 8.3. Write code that runs on both: no `match`, no named arguments, no union/typed properties, no
`str_contains`, no dynamic properties on your own classes (declare them). Locally under WAMP/XAMPP at `http://localhost/hrm/`.

## Commands

There is no build step. Verify PHP with `php -l` and by exercising the real pages; keep the test suite and the static checks green.

```bash
php -l application/models/Payroll_model.php              # syntax check
php index.php migrate                                     # DB to $config['migration_version'] (every configured tenant)
php index.php migrate version 12                          # to a specific version (down() must work)
php index.php migrate status                              # current / expected schema version of every tenant
TENANT_DB=hrm_client_xyz php index.php migrate            # one tenant only
php index.php cron attendance_close [Y-m-d] [dry-run]     # day close for a day (default yesterday) - run 00:30
php index.php cron leave_carry_forward [year]             # year-end carry forward - run 1 Jan 00:10
php index.php cron device_health                          # stale devices, lost-punch gaps, clock drift - every 15 min
php index.php cron housekeeping                           # purge device_logs > 30 d, idempotency_keys > 7 d, login_attempts, rate_limits
php index.php cron ping                                   # boots the framework, prints ok (health check; the test bootstrap boots CI through it)
php tests/static_checks.php                               # guardrail scan (must print "0 violations")
composer install                                          # mpdf (payslip PDF, Bangla), phpoffice/phpspreadsheet (Excel)
composer lint                                             # php -l on every PHP file
vendor/bin/phpunit                                        # model + guardrail tests against the hrm_test database
```

## Architecture

### Stack and folders (CodeIgniter 3 standard layout)
```
application/
  config/        config.php, database.php (gitignored; ship database.example.php), routes.php, autoload.php,
                 migration.php, hrm.php (setting defaults), permissions.php (permission catalog), menu.php (sidebar catalog)
  core/          MY_Controller.php (default-deny access), MY_Model.php (scope + audit), Api_Controller.php (device JSON),
                 Ess_Controller.php (self service), Default_deny.php (trait: the one `is_routable()` rule), hrm_autoload.php
                 (class autoloader for libraries/ and core/, loaded through composer's `autoload.files`)
  controllers/   one per module (list below); Api.php (device JSON), Iclock.php (ADMS), Cron.php (CLI only), Auth.php (login)
  models/        <Module>_model.php - all SQL lives here
  helpers/       hrm_helper.php (settings, dates, money, month ranges), permission_helper.php, sequence_helper.php
  libraries/     Input_validator.php, Datatable_request.php (server-side list parameters), Tx.php, Idempotency.php,
                 Tenant.php (per-tenant switch, migrate, version), Hrm_migration.php,
                 Payroll_calculator.php, Shift_calculator.php, Punch_ingestor.php, Adms_protocol.php, Device_command_queue.php, Pdf.php
  migrations/    NNN_name.php, sequential
  views/         <module>/<page>.php, layout/ (head, sidebar, header, footer, scripts), partials/ (modals)
  language/      english/, bangla/ (every label through $this->lang->line())
theme/           AdminLTE 2, plugins (DataTables 1.10, select2, datepicker, iCheck, Chart.js 2, fullcalendar 3), js/<module>.js
uploads/         outside the web root if possible; otherwise denied by .htaccess and served only through a controller
tests/           phpunit bootstrap booting CI3 against hrm_test; static_checks.php; fixtures/
```

### Tenancy and scoping
- One database per client (same model as pos-retails): `config/database.php` maps `HTTP_HOST` to a database
  (`get_database_domain_map()`), CLI selects it with `TENANT_DB=...`, `get_all_configured_database_names()` is what `Migrate`
  and `Cron` loop over. **Never hard-code a tenant or branch id anywhere** (P3).
- Inside a database every business row carries `branch_id`. `current_branch_id()` comes from the login's branch (web) or from the
  device key / device row (device endpoints). Only the system Admin role may work in another branch: the header switcher posts to
  `branches/switch_branch` (`@system`), which stores `branch_id` in the session; `load_global()` re-checks that the branch is active
  on every request (otherwise back to the home branch). List and report queries filter on it through `MY_Model::scope_branch($alias)`.
- "Own records only" is a permission per module (`show_all_<module>`), applied with `MY_Model::scope_own($alias)`.
  A department head additionally sees their department (`scope_department`). Employees with a login use the self-service area
  (`Ess_Controller`), which can only ever read/write rows of `current_employee_id()`.

### Request lifecycle and conventions
- Every web controller `extends MY_Controller`; the constructor calls `load_global()`: login check, idle logout, branch/user
  active check, timezone (`date_default_timezone_set` from the branch), `load_info()` fills `$this->data`.
- Models read input only through `Input_validator` (below), use Query Builder or bound `?` parameters, wrap multi-row writes in
  `Tx::run()`, and return `"success"`, `"success<<<###>>>$id"` or a human-readable error string that the page JS shows with toastr.
- Text is stored as entered (UTF-8) and escaped **on output** with `e()` (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`). Never store
  HTML-escaped text (P11). User-supplied HTML is not allowed anywhere.
- Money: `DECIMAL(14,2)`, `round($x, 2)` at each persisted step, compare with `abs($a - $b) < 0.005`, never `==`. Durations: minutes
  as INT, hours as `DECIMAL(6,2)`.
- Dates: stored `DATE` / `DATETIME` / `TIME` in the **branch's local time** (devices send local time; there is no UTC conversion
  anywhere). UI format from settings (`date_format`, default `d-m-Y`); convert with `to_db_date()` / `show_date()`.
  Business dates come from PHP in the branch timezone, never from MySQL `NOW()` / `CURDATE()`.
- Views: AdminLTE pages include `layout/head.php`, `layout/sidebar.php`, `layout/footer.php`, `layout/scripts.php`.
  `scripts.php` hands the token to `theme/js/app.js`, which adds it to every non-GET `$.ajax` with `$.ajaxPrefilter` (CI3 reads the
  token from the POST body only, and `$.ajaxSetup({data})` cannot merge into a request's own data), applies iCheck to inputs (bind `ifChanged`, not only
  `change`) and runs the idle-logout timer. Page JS lives in `theme/js/<module>.js`, loaded with `?v=<?= filemtime(...) ?>`.
  Lists are server-side DataTables (`ajax_list` + `count_filtered()` using `COUNT(*)`, never `num_rows()` of a full select).
- Sessions: database driver or Redis. `config/autoload.php` does **not** start the session for requests whose first segment is
  `api`, `iclock` or `cron`. `csrf_exclude_uris` = `api/.*`, `iclock/.*` only. `db_debug` = `(ENVIRONMENT === 'development')`.
- Read-only AJAX endpoints that can be slow call `release_session_lock()` after the permission check so parallel dashboard
  widgets do not queue behind the session lock.

### Settings
- Single row `hr_settings` (id = 1) edited at `/settings` (tabs: General, Attendance, Payroll, Leave, Devices, Notifications; permission
  `settings_edit`). Each key has its own validation rule; a save writes and audits only the keys that changed, then resets the cache.
  The logo is uploaded through `Input_validator::image()`, stored as `uploads/settings/<random>.png|jpg` and served by `Settings::logo`.
  `default_shift_id` gets its control when shifts exist (step 3).
  Read through `hr_setting('key', $default)` (static cache, falls back to `config/hrm.php`), so a new setting never breaks an
  un-migrated tenant. Branch settings (timezone, weekly offs, payslip address = `branches.address`) live on `branches`.

### Migrations
- `application/migrations/NNN_name.php`, sequential; bump `$config['migration_version']`. `php index.php migrate` is routed to
  `Cron::migrate` (Cron is CLI-only); it loops over every tenant and prints one line per database.
- Idempotent with live-schema guards (`SHOW TABLES LIKE ?`, and `information_schema.COLUMNS` / `.STATISTICS` with bound parameters
  instead of `SHOW COLUMNS` / `SHOW INDEX`, because those cannot take a bound table name) - never `table_exists()`, which caches; every
  migration extends `Hrm_migration` and has a working `down()`. **Every table is created `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci`, explicitly** (`Tenant::migrate()` pre-creates CI's `migrations` table the same way). JSON columns are
  `LONGTEXT` (portable between MySQL 5.7 and MariaDB 10.3).
- Seed lists (permissions, default leave types, default settings) with `INSERT ... WHERE NOT EXISTS`.

## Engineering guardrails (these make the pos-retails defects structurally impossible)

Build these first (build order step 0). Everything else uses them. `tests/static_checks.php` and the guardrail tests fail the
build when someone bypasses them.

### G1. Default-deny access, enforced in one place (fixes P6, P7, P14, P18)
CodeIgniter 3 routes **every public method** of the controller class chain (it blocks only names starting with `_` and methods of
`CI_Controller`). pos-retails therefore exposed its base-class helpers as URLs and, in seven controllers, left `ajax_list` and nine
`save`/`update` endpoints without any permission check. Here access is decided by a map, once, in `_remap`:

```php
abstract class MY_Controller extends CI_Controller
{
    /** method => permission key | '@any' (any logged-in user) | '@system' (system Admin role only)
     *  | '@self' (Ess_Controller only; the model scopes to the employee) */
    protected $permission_map = array();
    /** methods that change data: POST only */
    protected $post_only = array();

    public function __construct() { parent::__construct(); $this->load_global(); }

    public function _remap($method, $params = array())
    {
        $method = strtolower($method);                           // map keys are lower case (static check)
        $map = $this->permission_map;
        if (!$this->is_routable($method)) {                      // Default_deny trait: in the map + exists + public + not static
            $this->deny(403);                                    // not in the map = no access (default deny)
        }
        if (in_array($method, $this->post_only, true) && $this->input->method() !== 'post') {
            $this->deny(405);
        }
        $key = $map[$method];
        if ($key === '@system' && !current_user()['is_system']) { $this->deny(403); }   // not the same as holding every key
        if ($key !== '@any' && $key !== '@self' && $key !== '@system' && !has_permission($key)) {
            $this->deny(403, $this->input->is_ajax_request() ? 'text' : 'page');   // AJAX gets a plain text message
        }
        return call_user_func_array(array($this, $method), $params);
    }

    protected function deny($code, $mode = 'page') { /* log (user, route), then show_error / echo + exit */ }
    protected function load_global() { /* login, idle logout, active checks, timezone, load_info() */ }
    protected function belong_to($table, $id) { /* branch + own-records check; 403 on failure */ }
    protected function release_session_lock() { /* session_write_close() */ }
}
```
Rules:
- **All helpers on `MY_Controller` are `protected`** (a public one is a routable URL).
- Every controller declares its `$permission_map` and `$post_only` at the top; a new public method without a map entry is a 403
  on its first request and a failing test (below).
- A by-id endpoint calls `belong_to()` **after** the permission check (a user without permission must not learn whether an id exists).
- The only classes allowed not to extend `MY_Controller`: `Api` and `Iclock` (device endpoints, own authentication; they extend
  `Api_Controller`, which refuses every request until Step 5 implements `authenticate_device()`), `Cron` (first statement
  `if (!is_cli()) { show_404(); }`), `Auth` (login). They use the same `Default_deny` trait and declare a `$permission_map` too, with the
  tokens `@device`, `@cli`, `@public`, `@login`. `Ess` extends `Ess_Controller` and is the only user of `@self`.
- Permission lookups (`has_permission`) and the settings row use raw bound `$db->query()`, never Query Builder's `get()`: a lookup in the
  middle of a model's half-built `->from()->where()` chain would otherwise reset that chain.
- **Roles:** a role is a set of permission keys (`role_permissions`); the system Admin role has `roles.is_system = 1` and passes every
  check. Never infer "manager" from holding some edit/delete permission (P14) - managers hold `attendance_approve`,
  `show_all_attendance`, `leaves_approve`, ... explicitly.

### G2. Typed input, no `extract()`, no interpolated SQL (fixes P8, P11)
```php
$v = new Input_validator($this->input);          // reads POST (or GET for list filters) only
$employee_id = $v->ref('employee_id', 'employees');            // exists, same branch, inside the user's own-records scope
$from        = $v->date('from_date');                          // UI format or Y-m-d -> 'Y-m-d' (DateTime::createFromFormat, strict)
$type        = $v->enum('payment_type', array('Cash', 'Bank', 'Mobile Banking'));
$amount      = $v->money('amount', 0.01, 99999999.99);
$reason      = $v->text('reason', 255, false);               // trimmed, length checked against the column, no tags
if (!$v->ok()) { return $v->message(); }                       // all errors at once, one per line
```
Other getters: `flag` (checkbox -> 0/1), `secret` (password: not trimmed or filtered, length only), `token` (the `request_token` uuid),
`int_list` (id arrays), `enum_list` (checkbox groups of strings, e.g. permission keys), `email`, `image` (upload: type from the file
contents via `getimagesize`, size cap, `is_uploaded_file`), `document` (PDF by its `%PDF-` signature, or PNG/JPEG; original name cleaned),
`spreadsheet` (import: `.xlsx` by its ZIP signature or UTF-8 `.csv`; returns the PhpSpreadsheet reader type). DataTables parameters are read only by `Datatable_request` (sort column by
index from a whitelist, page size clamped to the screen cap, search text <= 100 characters); list cells are escaped with `e()` in the model. `text()` refuses anything that looks like an HTML tag and control characters; everything else (quotes, `&`, SQL-looking
strings, Bangla) is accepted and stored verbatim.
Rules: no `extract()`; no `$_POST` access in models; no request value inside an SQL string (Query Builder, or `?` bindings with an
array); enums, statuses and sort columns are whitelisted; DataTables `order[0][column]` is mapped through a whitelist array;
`limit` is clamped; ids from the request are always re-checked against the branch (`ref()`), never trusted.

### G3. Transactions and idempotency for every money or multi-row action (fixes P9, P10)
```php
list($ok, $message) = Tx::run(function () use ($payroll_id, $amount, $account_id, $token) {
    Idempotency::claim($token, 'payroll_pay');                 // INSERT into idempotency_keys; duplicate = return the stored result
    $payroll = $this->payroll->lock($payroll_id);              // SELECT ... FOR UPDATE, then re-check status / balance
    // ... lock the account, insert account_transactions + payroll_payments, update payment_status, apply deductions when Paid
    return true;                                               // or an error string -> rollback, message shown to the user
});
```
- `Tx::run()` begins a transaction, runs the closure, **rolls back on exception, on any failed query (`trans_status()`), or when the
  closure returns a string**, commits otherwise, and returns `array(bool $ok, string $message)`.
- Nested `Tx::run()` calls join the outermost transaction (no savepoints); a failed inner run makes the outer one roll back. `db_debug`
  is switched off inside `Tx::run()` so a failed query becomes a rollback and a message instead of ending the request.
- Money forms carry a hidden `request_token` (uuid created when the page is rendered). `idempotency_keys(token PK, action,
  user_id, result_json, created_at)`: a double click or a browser retry returns the first result instead of paying twice
  (the claim row is part of the transaction: a failed attempt does not burn the token; duplicates read the stored result with a shared
  locking read, `FOR UPDATE` would deadlock concurrent duplicates).
- Lock order (avoids deadlocks): payroll -> account -> advance -> loan -> loan_schedules (ascending id) -> leave_allocations.
  Re-read and re-check the row's status **after** locking it (approve twice, pay twice, edit after pay).
- Anything that writes several tables (pay salary, approve leave, disburse advance, pay installment, close month, ingest a
  punch batch) is inside one `Tx::run()`.

### G4. Sequential codes (fixes P10)
`sequences(branch_id, name, year, next_value)` unique on the first three (`year` = reset period: 0 never, 2026 yearly, 202611 monthly).
`next_code('leave')` runs one atomic `INSERT ... VALUES (..., LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE next_value = LAST_INSERT_ID(next_value + 1)`
and reads `SELECT LAST_INSERT_ID()` inside the caller's transaction (concurrent callers serialise on the row lock). The earlier
`INSERT IGNORE ... 0` + `UPDATE` pair deadlocks when several processes meet a brand-new row (shared-lock upgrade); `Sequences_test` proves the fix. Codes: `LV-2026-0001`, `LN-0001`, `PS-202611-0001`, `ADV-0001`. Never
`rand()`, never `MAX(id)+1`.

### G5. Query rules for lists and reports (fixes P15)
- Dates are range predicates: `attendance_date BETWEEN ? AND ?` using `month_range($month, $year)`. Never `MONTH(col)` / `YEAR(col)`
  / `DATE(col)` on a column in a `WHERE` (they defeat the index).
- A report is **at most three queries** (employees, the month's rows, the leave/holiday map) assembled in PHP. No query inside a
  per-employee or per-day loop. Punch-level detail is loaded only for one employee.
- Every list endpoint has a hard row cap (export 20 000, screen 1 000) and a default date range.
- Required indexes are part of the migrations (see Domain model); `tests/Report_query_budget_test` asserts a monthly report for
  500 employees x 30 days runs in <= 6 queries (`$this->db->total_queries()`).

### G6. Authentication and sensitive data (fixes P17)
- Passwords with `password_hash(PASSWORD_DEFAULT)` / `password_verify`; session id regenerated on login; `login_attempts` throttle
  (5 failures in 15 minutes per username+IP locks that pair for 15 minutes); forced password change for new users.
- Device and API keys are stored as `hash('sha256', $key)`, shown **once** when created, compared with `hash_equals`, and bound to
  a branch (and optionally a device). Keys can be revoked; `last_used_at` is updated.
- Salary figures need `employees_salary`; `bank_account_number`, `national_id`, `tin` are masked (last 4 digits) in lists and
  exports unless the user has `employees_sensitive`. Every export needs `export_hr` and is written to `audit_log`.
- Payslip PDFs and employee documents are served through a controller that checks ownership or permission; emailed links are
  signed (HMAC + expiry). Uploads: type from the file contents (`Input_validator::image`), random file names, size cap (PHP's own size errors give the same
  message), never served from `uploads/` directly.

### G7. Audit (fixes P8)
`MY_Model::audit($module, $record_id, $action, $old, $new)` writes `audit_log` for approve, reject, pay, delete, close/reopen,
settings change, salary-structure change, role change, device key change, and **every manual override of a calculated payroll value**
(`old` = calculated, `new` = saved, with the user and reason).

### G8. Static checks (`php tests/static_checks.php`, also a phpunit test and the git pre-commit hook)
Fails (exit 1) when it finds, outside `vendor/` and `system/`:
| rule | catches |
|---|---|
| `extract(` anywhere in `application/` | P11 |
| `->query()` / single-argument `->where()` built with `$` interpolation, `sprintf` or `.` joining anything but literals (use `?` bindings) | P11 |
| a class in `controllers/` that does not extend `MY_Controller` and is not in `{Api, Iclock, Cron, Auth}` | P7 |
| a `public function` in a controller (excluding `__construct`, `_remap`) with no key in `$permission_map` | P6, P18 |
| `public function` in `core/MY_Controller.php` other than `__construct` and `_remap` | P18 |
| `MONTH(` / `YEAR(` / `DATE(` applied to a column inside `where`/`->query` | P15 |
| `rand(` / `mt_rand(` / `uniqid(` anywhere (codes come from `next_code()`, random names from `random_bytes`) | P10 |
| `ENGINE=MyISAM` or a `CREATE TABLE` without `ENGINE=InnoDB` in migrations | storage |
| `'hr_advances'`, `'hr_payroll'`, `'db_` or other legacy table names | P12 |
| literal `208` or `1.5` near `overtime` in `libraries/Payroll_calculator.php` | P13 |
| `store_id` / `branch_id` read from `$_POST`/`$record[...]` inside `Api` / `Iclock` / `Punch_ingestor` | P3 |
| `$permission_map` key not lower case, value not in `config/permissions.php` (or an allowed `@token`); `@self` outside `Ess_Controller` subclasses | P6, G1 |
| `$_POST` / `$_GET` / `$_REQUEST` inside `models/` | G2 |
| PHP 8-only syntax or functions (`match`, `?->`, `str_contains` & co, typed/union properties, named arguments, promotion, enum, readonly, attributes, `mixed`/`static` types) | runtime 7.4 |
| `$this->x = ...` where `x` is not a declared property of the class (PHP 8.2 dynamic property deprecation) | runtime 8.2+ |

## Domain model (tables)

All tables InnoDB + utf8mb4, foreign keys declared (`ON DELETE RESTRICT` unless stated), `created_at`/`updated_at` where useful.
`*` = not in pos-retails, added on purpose. Dead and duplicate pos-retails tables are **not** recreated (P12).

### Organisation
- `branches` (id, name, code, address, phone, timezone, weekly_offs* `VARCHAR(20)` CSV ISO weekday 1-7 or NULL = global, status)
- `departments` (id, branch_id, department_name, department_code, head_employee_id, description, status, created_by, created_at)
- `designations` (id, branch_id, designation_name, designation_code, level, description, status, created_by, created_at)
- `employees`* (separate from logins; pos-retails stored employees inside `db_users`): id, branch_id, employee_code (unique per
  branch), first_name, last_name, gender, dob, mobile, email, photo, address, department_id, designation_id,
  reporting_manager_id, employment_type ENUM('Permanent','Contract','Temporary','Part-time','Probation'), join_date,
  confirmation_date*, exit_date*, exit_reason*, status ENUM('Active','On Leave','Resigned','Terminated'), blood_group,
  emergency_contact_name, emergency_contact_number, bank_name, bank_account_number, bank_branch, national_id*, tin*,
  **device_pin*** (ZKTeco user id, digits 1-9; `UNIQUE (branch_id, device_pin)`; NULL for employees without a card/finger),
  device_pin_from* (date the current PIN started), user_id* (nullable link to `users`, unique; `users.employee_id` is the other side,
  both written together), created_by, created_at, updated_at.
- `employee_pin_history`* (branch_id*, employee_id, device_pin, valid_from, valid_to; index `(branch_id, device_pin, valid_from)`): on exit/re-hire/PIN change the old PIN moves here and
  `employees.device_pin` is cleared, so a PIN can be reused later and late punches of a leaver still find their owner by date (P5).
- `employee_documents`* (id, employee_id, title, file (random name), original_name, expires_on, created_by, created_at; deleted with the employee).
- `users` (id, username unique, password_hash, employee_id nullable, role_id, branch_id, status, must_change_password, last_login_at),
  `roles` (id, role_name, is_system), `role_permissions` (role_id, permission_key; unique pair), `login_attempts`*.

### Time
- `shifts` (id, branch_id, shift_name, shift_code, start_time, end_time, break_duration (min), working_hours DECIMAL(5,2) (0 = end -
  start), grace_time (min), shift_allowance, is_night* (end < start), status).
- `employee_shifts` (id, employee_id, shift_id, effective_from, effective_to NULL, is_active, created_by, created_at) - `UNIQUE (employee_id,
  effective_from)`; ranges of one employee never overlap.
- `shift_rosters` (id, employee_id, shift_id NULL, roster_date, is_working_day, remarks) - `UNIQUE (employee_id, roster_date)`; wins over
  `employee_shifts` for that day; `is_working_day = 0` makes the day a weekly off for that employee (shift_id may then be NULL).
- `holidays` (id, branch_id, holiday_name, holiday_date, year, is_optional, is_fixed_date*, description, status) - `UNIQUE (branch_id,
  holiday_date)`. `is_fixed_date` = same day every year (copied by "copy last year"); `is_optional` holidays are not days off.
- Weekly offs: `hr_settings.weekly_offs` (default `5` = Friday for Bangladesh; pos-retails default was `7`), overridden by
  `branches.weekly_offs`, overridden per day by a roster row.

### Attendance
- `attendance_punches`* - every raw punch, **never edited** (P4): id, branch_id, employee_id NULL (unmatched PIN), device_pin, device_id,
  punch_at DATETIME, punch_state (0 in, 1 out, 4 OT-in, 5 OT-out, 255 unknown), verify_mode, source ENUM('device_push',
  'device_service','manual','import','mobile'), work_code, ignored TINYINT (debounced duplicate tap), received_at.
  `UNIQUE (device_id, device_pin, punch_at)`; indexes `(branch_id, punch_at)`, `(employee_id, punch_at)`.
- `attendance` (one row per employee per day): id, branch_id, employee_id, attendance_date, shift_id, check_in TIME, check_out TIME,
  first_punch_at DATETIME*, last_punch_at DATETIME* (night shifts), status ENUM('Present','Absent','Half-day','Leave','Holiday',
  'Weekly Off'*), leave_id* NULL, approval_status ENUM('Pending','Approved','Rejected'), approval_note, working_hours,
  break_minutes*, late_minutes, early_leave_minutes*, overtime_hours, source*, locked*, remarks, created_by, approved_by,
  approved_at, created_at, updated_at. `UNIQUE (employee_id, attendance_date)`; indexes `(branch_id, attendance_date, status)`,
  `(branch_id, approval_status)`.

### Leave
- `leave_types` (id, branch_id, leave_type_name, leave_code, default_days, is_paid* (unpaid = LWP in payroll), count_weekly_offs*
  (0 = weekly offs/holidays inside a leave are not counted), carry_forward, max_carry_forward_days, max_consecutive_days*,
  requires_document*, description, status).
- `leave_allocations` (id, branch_id, employee_id, leave_type_id, year, allocated_days, used_days, remaining_days, carry_forward_days)
  - `UNIQUE (employee_id, leave_type_id, year)`.
- `leaves` (id, branch_id, leave_code `LV-YYYY-nnnn`, employee_id, leave_type_id, from_date, to_date, half_day* ENUM('','first','second'),
  total_days DECIMAL(5,2), reason, attachment*, approval_status ENUM('Pending','Approved','Rejected','Cancelled'), remarks, approved_by,
  approved_at, created_by, created_at); indexes `(branch_id, approval_status, from_date)`, `(employee_id, from_date, to_date)`.
- `leave_allocation_log`* (leave_id, leave_type_id, year, days) - which year's balance each approved leave consumed (a range crossing
  New Year is split), so cancel restores exactly what approve deducted (P10).

### Overtime
- `overtime` (id, branch_id, employee_id, overtime_date, start_time, end_time, overtime_hours, overtime_rate, overtime_amount, reason,
  status ENUM('Pending','Approved','Rejected'), approved_by, approved_at, rejection_reason, payroll_id, source* ENUM('attendance','manual')).

### Pay
- `salary_structures` (id, employee_id, basic_salary, house_rent, medical_allowance, transport_allowance, other_allowances,
  gross_salary, pf_deduction, esi_deduction, tax_deduction, other_deductions, net_salary, effective_from, effective_to, is_active,
  created_by, created_at; `UNIQUE (employee_id, effective_from)`). Saving a new one closes the previous (`effective_to = new.effective_from - 1 day`). **Payroll never reads `is_active`;
  it reads the structure whose dates cover the month (P20).**
- `payroll` (id, branch_id, employee_id, payroll_month, payroll_year, salary_structure_id*, basic_salary, hra, medical_allowance,
  transport_allowance, other_allowances, overtime_amount, bonus, incentive, gross_salary, pf_deduction, esi_deduction, tax_deduction,
  lwp_deduction, advance_deduction, loan_emi, other_deductions, total_deductions, net_salary, **calendar_days***, **working_days**
  (calendar days - weekly offs - holidays of that employee, P19), present_days, absent_days, leave_days, paid_leave_days*,
  unpaid_leave_days*, holiday_days*, weekly_off_days*, non_employed_days*, late_days*, late_converted_absent*, overtime_hours,
  calc_snapshot* JSON (calculated values + inputs + hints at generation), overrides* JSON (what HR changed, who, why),
  payment_status ENUM('Pending','Partial'*,'Paid','Hold'), payment_date, remarks, processed_by, processed_at, locked*).
  `UNIQUE (employee_id, payroll_year, payroll_month)`; index `(branch_id, payroll_year, payroll_month, payment_status)`.
- `payroll_payments` (id, payroll_id, employee_id, amount, payment_date, payment_type, account_id, account_txn_id*, note, branch_id,
  created_by, created_at); index `(payroll_id)`.
- `payslips`* (id, payroll_id, payslip_number `PS-YYYYMM-nnnn`, file, emailed_at) - optional PDF archive.

### Advances and loans (employees only)
- `advances` (id, branch_id, advance_code, employee_id, amount, request_date, installments, start_month `YYYY-MM`, reason, status
  ENUM('Pending','Approved','Rejected','Completed'), approved_by, approved_date, disbursed_account_id*, disbursed_at*, paid_amount,
  remaining_amount, remarks, created_by).
- `advance_payments` (id, advance_id, payment_amount, payment_date, payment_method ENUM('Salary Deduction','Cash','Bank'), payroll_id*
  (FK, **not** a note string, P9), account_id, notes, created_by); `UNIQUE (advance_id, payroll_id)` (NULL payroll_id = manual repayment).
- `loans` (id, branch_id, loan_code `LN-nnnn`, employee_id, principal_amount, interest_type ENUM('none','flat','reducing'), interest_rate,
  loan_date, first_due_date, tenure_months, installments, account_id, loan_status ENUM('Draft','Approved','Active','Closed','Cancelled',
  'Defaulted'), total_interest, total_payable, principal_paid, interest_paid, total_paid, remaining_amount, note, created_by).
- `loan_schedules` (id, loan_id, installment_no, due_date, opening_principal, principal_due, interest_due, total_due, paid_amount,
  principal_paid, interest_paid, status ENUM('Pending','Partial','Paid','Overdue'), last_payment_date); indexes `(loan_id, due_date)`,
  `(due_date, status)`.
- `loan_payments` (id, loan_id, schedule_id, payment_code, payment_nature ENUM('DISBURSEMENT','INSTALLMENT','ADJUSTMENT'), payment_date,
  amount, principal_component, interest_component, payroll_id*, payment_type, account_id, note, created_by); `UNIQUE (loan_id, payroll_id,
  schedule_id)` for payroll rows.

### Money accounts (minimal, replaces the POS accounting module)
- `accounts` (id, branch_id, account_name, account_type ENUM('Cash','Bank','Mobile Banking'), opening_balance, status).
- `account_transactions` (id, branch_id, account_id, txn_date, direction ENUM('in','out'), amount, ref_type ENUM('salary',
  'advance_disbursement','advance_repayment','loan_disbursement','loan_installment','adjustment'), ref_id, note, created_by);
  index `(account_id, txn_date)`. Balance = opening + SUM(in) - SUM(out), computed under the account row lock when paying.
  Salary, advance and loan cash movements write here directly (pos-retails wrote an Expense row plus an accounting transaction);
  exportable for an external accounting system.

### Devices
- `devices`* (id, branch_id, name, serial_number (ADMS SN, unique), ip, port, protocol ENUM('UDP','TCP'), comm_mode
  ENUM('adms_push','windows_service'), timezone, allowed_ips, last_ip, last_seen_at, last_punch_at, clock_offset_seconds, firmware,
  status ENUM('active','disabled'); added in step 5: `last_upload_at` (when the server last received a batch with punches),
  `lost_punch_alert`, `health_checked_at`, `blocked_ip` + `blocked_at` (an address that was refused). `serial_number` is NULL for
  Windows Service devices; unique `(branch_id, name)`).
- `device_keys`* (id, branch_id, device_id NULL, label, key_hash, created_by, created_at, last_used_at, revoked_at; step 5 adds
  `key_hint` = last 4 characters and `revoked_by`) - a key is bound to a branch (and optionally one device); the branch of a punch
  comes **only** from the key / device (P3, P17).
- `devices_pending`* (serial_number PK, ip, first_seen_at, last_seen_at, hits) - unknown ADMS serial numbers; capped at 50 rows, purged
  after 30 days; HR approves one into `devices` (choosing the branch). No punches are stored for a pending SN.
- `device_commands`* (id, device_id, command_type ENUM('create_user','update_user','delete_user','list_users','sync_time','clear_attlog',
  'reboot'), payload JSON, status ENUM('queued','sent','done','failed'), result JSON, created_by, created_at, sent_at, done_at).
- `device_logs`* (id, device_id, direction, endpoint, http_code, records, message, created_at; step 5 adds `branch_id` and `ip`; `device_id`
  and `branch_id` are NULL for calls that failed authentication) - 30 days, purged by cron.

### Cross-cutting
`audit_log` (id, branch_id, user_id, module, record_id, action, old_values JSON, new_values JSON, ip, created_at; indexes
`(module, record_id)`, `(branch_id, created_at)`), `sequences`, `idempotency_keys`, `rate_limits` (key, window_start, hits).

## Business rules (copy these exactly, except where a P-number says otherwise)

### Shift resolution and daily metrics (`libraries/Shift_calculator.php`)
- Shift of an employee on a date: roster row for that date, else the active `employee_shifts` row with
  `effective_from <= date AND (effective_to IS NULL OR effective_to >= date)` (latest `effective_from` wins), else `default_shift_id`, else none.
- With a shift and both check-in and check-out:
  - `working_hours` = (out - in) / 60 minutes, +1440 when out < in (night shift); minus `break_duration` only when `deduct_break` = 1.
  - `late_minutes` = max(0, in - (shift.start_time + grace_time)).
  - `early_leave_minutes` = max(0, shift.end_time - out) (setting `early_leave_counts_as_late`).
  - expected hours = shift.working_hours, or (end - start) when it is 0 (+1440 when end < start).
  - `overtime_hours` = max(0, working_hours - expected), 2 decimals, then floored to `ot_min_minutes` blocks.
- Without a shift: working_hours = out - in; late = 0; overtime = 0.
- Worked on a holiday or weekly off (Present/Half-day): the **whole** working_hours is overtime and late = 0.
- Interpretations fixed in step 3 (`Shift_calculator_test` holds each of them):
  - times across midnight: late and early leave use the short distance (normalised to +-12 h), so a 00:30 check-in on a 22:00 shift is
    150 min late (minus grace), not 21 h early; worked time adds 1440 when out < in.
  - `early_leave_counts_as_late` = 1 adds the early-leave minutes to `late_minutes` (both are still returned separately).
  - overtime is computed in minutes (worked - expected) and then floored to `ot_min_minutes` blocks; off-day overtime (all hours) is
    not floored, and it applies with or without a shift.
  - only a check-in: late is known, working/overtime hours are 0 until the check-out.
  - the default shift applies only to employees of the shift's branch.
- `Shift_calculator::metrics()` is pure (explicit options = no database). `plan($employees, $from, $to)` returns shift + day type for
  every employee/day in 6 queries (employees, branches, rosters, assignments, holidays, shifts): shift = roster > assignment > default;
  day type = active non-optional holiday > roster row > branch weekly offs > global weekly offs; plus `employed` (join/exit).

### Punch to day (`libraries/Punch_ingestor.php`, used by devices, import, manual punch) (fixes P4, P5)
1. `INSERT IGNORE` into `attendance_punches` (unique key) - a re-sent punch is a no-op.
2. Debounce: a punch within `punch_debounce_seconds` (default 60) of the previous kept punch of the same PIN is stored with
   `ignored = 1` (a double tap must not become a fake break).
3. Map `device_pin` to an employee: `employees.device_pin` of the device's branch, else `employee_pin_history` valid on that date.
   Unmatched punches stay with `employee_id = NULL` and appear on **Attendance > Unmatched punches**, where HR links the PIN to an
   employee (all its punches are then processed).
4. Rebuild each touched employee/day from all non-ignored punches (`rebuild_day($employee_id, $date)`): earliest = check-in, latest =
   check-out (one punch = check-in only, status Present, no check-out yet), pairs OUT -> next IN give `break_minutes`, then the
   shift metrics. Night shift (`is_night`): a punch before `shift.end_time + 4h` on the next calendar day belongs to the previous day.
5. Device/service punches are `Approved`. A row HR edited by hand has `source = 'manual'` and is not overwritten by later punches
   unless HR clicks **Rebuild from punches**. Locked rows (month closed) are never changed; late punches for them go to the
   **Punches for closed days** list.
6. The whole batch is one `Tx::run()`; any failure => the endpoint answers **HTTP 500** so the device/service keeps the data.

### Manual and bulk marking
- Permissions: `attendance_mark_self` (own day, row is `Pending`), `attendance_add` (any employee, `Approved`), `attendance_edit`,
  `attendance_approve`, `attendance_delete`, `show_all_attendance` (P14). `bulk_mark` = date + department, a row per employee, existing
  rows skipped (or updated with *overwrite* ticked). Absent/Leave/Holiday/Weekly Off clear check_in/out.

### Day close (cron `attendance_close`) (fixes P1)
For every active employee (join_date <= day, no exit_date before it) and every **closed** day (never today) without an attendance row,
create one: `Holiday` (holidays) > `Weekly Off` (roster / branch / global) > `Leave` (approved leave covering the day, `leave_id` set) >
`Absent`. Re-running fills gaps only. Payroll generation first runs the close for every day of the month up to yesterday, so a missed
cron run can never under-deduct. A Present row with only a check-in at day close gets status Present, remark "missing check-out",
and appears in the **Missing punch** list (it is not silently treated as a full day or as absent).

### Leave (fixes P10)
- Apply: validated through `Input_validator` (employee and leave type belong to the branch); `from <= to`; `total_days` = days in the
  range **excluding weekly offs and holidays when `count_weekly_offs = 0`**, 0.5 for a half day; refuse overlap with another Pending/
  Approved leave of the same employee (checked inside the transaction); check `remaining_days` per year (a range crossing New Year is
  split per year and recorded in `leave_allocation_log`); `max_consecutive_days`; attachment when `requires_document`.
- Users without `leaves_approve` can only apply for themselves (Pending). Nobody approves their own leave unless the system Admin
  role or setting `self_approve_leave = 1`.
- **Approve** (one `Tx::run`): lock the leave row, re-check it is still Pending, lock the allocation rows, deduct (`used_days +=`,
  `remaining_days -=`), write `leave_allocation_log`, upsert `Leave` attendance rows for the days that have no row or an `Absent` row.
  If a day already has real punches, the approval is refused with the list of conflicting dates (HR decides: shorten the leave or
  delete the punches' day).
- **Reject / Cancel of an Approved leave** (one transaction): restore exactly what `leave_allocation_log` recorded, turn the `Leave`
  rows back to `Absent` (or `Present` when punches exist), set the status.
- Allocation: single or bulk (department / all, from `leave_types.default_days`); editing recomputes `remaining_days`. Carry forward
  (cron, 1 Jan): `carry_forward_days = min(last remaining, max_carry_forward_days)` added to allocated and remaining.

### Overtime - one source of truth (fixes P2, P13)
- Setting `overtime_source`: **`attendance`** (default; payroll sums `attendance.overtime_hours` of Present/Half-day days) or
  **`approved_requests`** (payroll sums Approved `overtime` rows of the month, `overtime.payroll_id` set when used). Never both for the
  same month. With `approved_requests`, a request is only creatable for a day that has attendance, hours <= worked-beyond-shift, and
  needs `overtime_approve`; the OT module's list shows the payroll it was paid in.
- Rate per hour = (basis salary / `ot_divisor_hours`) x `ot_multiplier`; basis = basic (default) or gross (`overtime_basis`);
  `ot_divisor_hours` default **208** and `ot_multiplier` default **1.5** are settings, not literals. The basis comes from the
  employee's salary structure in force that month (never from a user-table column).
- `overtime_mode`: `auto` puts the amount into payroll; `manual` shows "Suggested: x (h hrs x rate/hr x 1.5, based on Basic)" and
  leaves the amount for HR (override rules below).

### Payroll calculation (`libraries/Payroll_calculator.php`, one employee, one month) (fixes P1, P2, P13, P19, P20)
Inputs: the salary structure(s) whose dates cover the month, the month's attendance rows (after the day close), HR settings,
advances, loan schedules. **Pure function: the same inputs always give the same output; no browser values go in.**
```
calendar_days     = days in month                                       (date('t'), no calendar extension needed)
non_employed_days = days before join_date + days after exit_date within the month
weekly_off_days, holiday_days  = from the employee's roster / branch / global weekly offs and holidays
working_days      = calendar_days - weekly_off_days - holiday_days      (stored; shown on the payslip; NOT calendar days, P19)
payable_days      = Present x 1 + Half-day x 0.5 + Holiday + Weekly Off + paid Leave days
absent_days       = Absent days + Half-day x 0.5 + unpaid Leave days
late_days         = Present days with late_minutes > 0
late_absent       = late_days_per_absent > 0 ? floor(late_days / late_days_per_absent) : 0
effective_absent  = absent_days + (late_deduction_mode == 'auto' ? late_absent : 0)
components        = sum over salary-structure segments of (segment_days / calendar_days x component)    (a mid-month raise is pro-rated)
gross_base        = basic + hra + medical + transport + other_allowances            (from `components`)
ot_amount         = overtime_mode == 'auto' ? round(rate_per_hour x ot_hours, 2) : 0
gross             = gross_base + ot_amount + bonus + incentive
per_day           = (absent_deduction_basis == 'basic_salary' ? basic : gross_base) / calendar_days
lwp               = absent_deduction_mode == 'auto' ? round(per_day x (effective_absent + non_employed_days), 2) : 0
advance_deduction = for each Approved advance with remaining > 0 and start_month <= this month (oldest first): min(remaining, round(amount / installments, 2))
loan_emi          = for each Active loan: unpaid (total_due - paid) of schedule rows with due_date <= month end, capped at remaining_amount
                    (no schedule rows: total_payable / installments once first_due_date is reached)
total_deductions  = pf + esi + tax + other_deductions + lwp + advance_deduction + loan_emi
net               = gross - total_deductions        (refuse to save a negative net unless setting allow_negative_net = 1; say why)
```
The calculator also returns hints (`overtime_hint`, `lwp_hint` "Suggested: x (n absent day(s) x y/day based on Gross Salary). Includes
k day(s) from late attendance.") and writes the whole input/output set into `calc_snapshot`.

### Payroll lifecycle (fixes P8, P9, P20)
- **Generate** (single) and **Bulk Generate** (department / all, month) post **only** `employee_id(s)`, `month`, `year`, `request_token`
  and the optional override fields below. The server runs the calculator, applies the permitted overrides, validates, and saves.
  Totals, days and deductions in the request are ignored (pos-retails saved the totals the browser sent, in the single and the bulk path).
- **Permitted overrides** (need `payroll_override`; without it they are ignored): `bonus` >= 0; `other_deductions` >= 0;
  `overtime_amount` >= 0 only when `overtime_mode = manual`; `lwp_deduction` between 0 and gross_base only when `absent_deduction_mode =
  manual`; `incentive` >= 0 when enabled; `remarks`. Everything else (basic, allowances, pf/esi/tax, advance, loan, days) changes only by
  changing its source data (salary structure, attendance, advance, loan) and regenerating. Each override is stored in `overrides` and
  `audit_log` (calculated value, saved value, user, reason - reason required).
- One row per employee and month (unique key); a second generate returns "already processed" with a link, never a duplicate.
- Edit allowed while `payment_status = 'Pending'` and not `locked`; it reruns the calculator. Delete only Pending without payments.
- **Pay** (one `Tx::run`, idempotent by `request_token`): lock payroll, then the account; refuse more than the balance and an account
  without enough balance (unless `allow_negative_account`); insert `account_transactions` (out) and `payroll_payments`; set `Partial` or
  `Paid` (paid >= net - 0.01). Nothing is written if any step fails.
- **When a payroll becomes Paid** (same transaction as the payment that completes it): advance deductions become `advance_payments` rows
  (method Salary Deduction, `payroll_id` set, unique with the advance, so they can never apply twice; oldest advance first; Completed at
  zero); loan EMI becomes `loan_payments` INSTALLMENT rows with `payroll_id`, allocated to the oldest due schedule rows; `locked = 1`.
- Payslip (`payroll/payslip/<id>`: print + PDF via mPDF with a Bangla font; shows calendar days, working days, present, absent, leave,
  late, OT, every earning and deduction), bank cheque print, bulk payslip PDF, optional email (signed link). Salary sheet for the bank.
- **Month close**: `payroll/close_month` locks every payroll and attendance row of the month (reopen needs `payroll_reopen`, audited).

### Advances (fixes P9)
- Request (HR for any employee, or the employee in self-service): amount, installments, start month, reason -> Pending.
- **Approve = disburse** (one transaction, idempotent): pick payment type + account, amount must equal the request, account balance
  checked under lock, `account_transactions` out, `disbursed_*`; status Approved, remaining = amount. Reject keeps a reason.
- Manual repayment (cash/bank, `account_transactions` in) any time; payroll deductions as above; Completed when remaining reaches 0.

### Loans (employees) (fixes P9)
- Create with principal, interest type/rate, installments, first due date, tenure; disbursement from an account (balance checked).
- Schedule: due dates = same day each month from first_due_date, clamped to the month's last day (never `strtotime('+1 month')`);
  principal_each = principal / n (last row takes the rounding rest); flat: total interest = principal x rate% x (max(tenure, n) / 12),
  spread evenly (last row takes the rest); reducing: interest = opening_principal x rate% / 12 per row; none: 0.
- Manual installment payments allocate to the oldest unpaid rows (interest first, then principal); payroll EMI as above;
  Closed when remaining = 0; overdue rows flagged daily by cron.

## Attendance devices (ZKTeco) (fixes P3, P4, P5, P16, P17)

Two ways in; both end in `Punch_ingestor::ingest(array $punches, $device)` (the punch-to-day steps above). **The branch of every punch
comes from the authenticated key or the registered device, never from the request body.**

### A. ZKTeco Windows Service (pull, `ProFarjan/ZKTeco-Windows-Service`)
A Windows service (NSSM + portable PHP 8.2, `service_worker.php`) on a PC in the device's LAN connects to each device on TCP/UDP 4370
(ZK protocol; optional comm password), every `sync_interval` seconds reads the attendance log (`uid`, `id` = PIN, `state` 1 fingerprint /
3 password / 4 card, `timestamp`, `type` 0 in / 1 out / 4 OT-in / 5 OT-out), then POSTs JSON in batches of `batch_size`:
```
POST /api/v1/pull_attendance          (route -> Api::pull_from_machine)
X-API-Key: <key>                       (or Authorization: Bearer <key>, or ?api_key=)
{ "device_name": "Main Gate", "timestamp": "2026-01-20 13:48:44", "batch_number": 1, "total_batches": 1,
  "records": [ { "user_id": "101", "attendance_datetime": "2026-01-20 08:30:15",
                 "auth_method": "1", "auth_method_name": "Finger Print", "attendance_type": "0", "attendance_type_name": "Check-In" } ],
  "command_results": [ { "id": 7, "success": true, "message": "User created.", "data": { "uid": 12 } } ] }
```
(The service's `custom_params` may add `branch_id`/`store_id`; **it is ignored and logged if it disagrees with the key's branch**.)
Server contract (the service depends on it):
- `{"test": true}` without records -> `{"status":"success","message":"Connection OK"}` (service's `test_api.php`).
- Empty `records` = heartbeat -> 200 `{"status":"success","processed":0,"total":0}`; still update `devices.last_seen_at` and return queued commands.
- Success -> 200 `{"status":"success","processed":n,"total":n,"accepted":n,"commands":[...]}`.
- **Any storage failure -> HTTP 500** (the service saves failed batches in its `failed_requests` file and merges them into the next
  cycle; a 200 would make it discard them).
- `commands`: the service executes `create_user` / `update_user` (`payload: user_id (PIN), name (24 chars), password (8), role (0 user / 14
  admin), card_no`), `delete_user` (`user_id`), `list_users` on the open connection and returns results in the next call's
  `command_results`; the server marks `device_commands` sent -> done/failed. "Add employee -> enrol on device" needs no visit to the PC.
- Accept both `user_id`/`id`/`uid` and `attendance_datetime`/`timestamp`. Times are device local time = the device's timezone.

**Known service hazard and the server-side compensation (P16).** `service_worker.php` clears the device's attendance memory
(`clear_device_after_sync`) whenever the API is *enabled*, **even if the API call failed**; the only protection is its local
failed-request file. This project cannot change that program (separate repository), so the server makes loss detectable and
recoverable: (1) ingestion is idempotent, so any resend is harmless; (2) `cron device_health` raises **"Possible lost punches"** when a
device sent nothing for `device_gap_hours` (default 12) on a day when employees of its branch had a shift; (3) the **Missing check-out**
and **Employees with a single punch** lists; (4) **Attendance > Import punches** accepts the USB export of the device (`.dat`, tab-separated
`PIN \t YYYY-MM-DD HH:MM:SS \t ...`) and CSV, idempotent, with a preview and an "unmatched PIN" report; (5) the Devices page recommends
`save_failed_requests: true`, `retry_on_failure: true`, `max_retry_attempts >= 3` and keeping the service's `logs/` and failed-request
folders on a disk that is not wiped. *Optional hardening of the service, in a fork of that repository (not part of this project):*
clear device memory only when every batch returned 2xx, write the failed batch to disk before clearing, honour the `accepted` count.

### B. ADMS push (device calls the server; "Cloud server" setting on the device)
Routes: `iclock/cdata`, `iclock/getrequest`, `iclock/devicecmd` -> `Iclock` controller. Plain-text protocol, no session, no CSRF.
- Handshake `GET /iclock/cdata?SN=XXXX&options=all&pushver=...` -> look up `devices.serial_number`. **Unknown SN:** upsert
  `devices_pending` (cap 50, rate limited), reply the normal handshake text so the device behaves, store nothing. Known and
  `active`: reply
  ```
  GET OPTION FROM: XXXX
  ErrorDelay=60
  Delay=30
  TransTimes=00:00;14:05
  TransInterval=1
  TransFlag=TransData AttLog	OpLog
  Realtime=1
  Encrypt=0
  ```
- Punches `POST /iclock/cdata?SN=XXXX&table=ATTLOG&Stamp=...`, body one punch per line, tab separated:
  `PIN<TAB>YYYY-MM-DD HH:MM:SS<TAB>status<TAB>verify<TAB>workcode...` (some firmware: spaces, or date and time in separate columns). Skip
  lines without a numeric PIN and a valid datetime (count them in `device_logs`). Reply `OK` on success, **HTTP 500** `ERROR: ...`
  on failure so the device resends. `table=OPERLOG` / `USERINFO` / `BIOPHOTO`: reply `OK` (optionally keep user info).
- `GET /iclock/getrequest?SN=XXXX` -> heartbeat (`last_seen_at`, `last_ip`) and command delivery: `OK` when nothing is queued, otherwise
  one command per line `C:<id>:<COMMAND>`, e.g. `C:7:DATA UPDATE USERINFO PIN=101\tName=Rahim\tPri=0\tPasswd=\tCard=` /
  `C:8:DATA DELETE USERINFO PIN=101` / `C:9:CHECK` / `C:10:REBOOT` / `C:11:CLEAR LOG`.
- `POST /iclock/devicecmd?SN=XXXX` body `ID=7&Return=0&CMD=DATA` -> mark the command done (Return 0) or failed.
- The branch is `devices.branch_id` (pos-retails hard-coded store 2). The device clock may drift: the Devices page shows
  `clock_offset_seconds` (device time vs server time from the last handshake) and offers a **sync time** command.
- ADMS cannot carry a secret, and a serial number is not secret. Compensating controls: only approved SNs are accepted; optional
  `devices.allowed_ips` (default: remember the first approved IP and require HR to confirm a change); `rate_limits` per IP and per SN;
  recommend serving `/iclock/*` on a separate vhost/port restricted by firewall/VPN to the offices' addresses.

### Security for device endpoints (fixes P17)
- Keys in `device_keys` (hashed, shown once, revocable, per branch/device); the company key of pos-retails (`db_sitesettings.unique_code`,
  stored in plain text and shared by all devices) does not exist here. Every call is logged in `device_logs` (endpoint, code, records, reason).

## HR settings (`hr_settings`, Settings page tabs)
| key | default | used by |
|---|---|---|
| company_name, logo, address, phone, email | | payslip header, reports |
| timezone, date_format, currency_symbol, currency_position, decimals | Asia/Dhaka, d-m-Y, ৳, left, 2 | everywhere |
| weekly_offs | `5` (Fri) | day close, leave days, holiday OT (pos-retails: `weekly_holidays`, default `7`) |
| default_shift_id | NULL | employees without a shift |
| deduct_break, ot_min_minutes, early_leave_counts_as_late | 0, 0, 0 | shift metrics |
| punch_debounce_seconds | 60 | punch ingestion |
| device_gap_hours | 12 | lost-punch detection |
| overtime_source | attendance | payroll OT (attendance / approved_requests) |
| overtime_basis | basic_salary | OT rate (basic_salary / gross_salary) |
| ot_divisor_hours, ot_multiplier | 208, 1.5 | OT rate |
| overtime_mode | auto | auto / manual (suggestion only) |
| late_days_per_absent | 3 | late -> absent conversion (0 = off) |
| late_deduction_mode | auto | add converted days to LWP automatically or only suggest |
| absent_deduction_basis | gross_salary | LWP per-day basis |
| absent_deduction_mode | auto | auto / manual |
| allow_negative_net, allow_negative_account | 0, 0 | payroll save, payments |
| self_approve_leave | 0 | leave approval |
| payslip_footer, payslip_show_attendance | | payslip |
| notify_leave_sms, notify_payslip_email, notify_absent_sms | 0 | notifications |
| idle_logout_minutes | 0 | session |

## Permissions (`config/permissions.php` catalog + `role_permissions`; nothing is auto-granted except to the system Admin role)
`employees_view/add/edit/delete`, `employees_salary`, `employees_sensitive`, `departments_*`, `designations_*`, `shifts_view/add/edit/delete`,
`rosters_manage`, `holidays_*`, `attendance_view/mark_self/add/bulk/edit/approve/delete/report`, `show_all_attendance`, `attendance_punches`,
`attendance_import`, `leave_types_*`, `leave_allocations_view/add/edit/delete`, `leaves_view/add/edit/delete/approve/report`, `show_all_leaves`,
`overtime_view/add/edit/delete/approve`, `advances_view/add/edit/delete/approve/pay`, `loans_view/add/edit/delete/pay`,
`payroll_view/add/edit/delete/process/pay/override/report/reopen`, `payslip_email`, `accounts_view/add/edit`, `devices_view/manage/commands/keys`,
`reports_hr`, `dashboard_hr`, `export_hr`, `settings_edit`, `users_view/add/edit/delete`, `roles_manage`, `audit_view`.
Self-service needs only a login linked to an employee (`@self` endpoints in `Ess`).

### Users, roles and branches (build step 1)
- **Branches** have no `branches_*` keys: the pages use `settings_edit` (branches are company settings). Branches are deactivated, never
  deleted; you cannot deactivate the branch you work in, your login's branch, or the last active branch.
- **No escalation through administration.** Someone who is not the system Admin may only assign, edit or delete roles whose permission
  set is a subset of their own, only grant keys they hold themselves, and only manage logins whose role is within their own permissions.
  Only the Admin assigns the Admin role or manages an Admin login. The Admin role itself is never edited or deleted.
- **Never yourself:** nobody changes their own role, branch or status or deletes their own login (`Users_test`).
- **Last Admin:** the last active Admin login cannot be deactivated, demoted or deleted; the check locks the other Admin rows
  (`SELECT ... FOR UPDATE`), so two Admins cannot remove each other concurrently.
- Users are listed per branch; non-Admins always create logins in their own branch. A role in use cannot be deleted; a login with records
  (foreign keys from later modules) cannot be deleted - deactivate it. Every create / change / role change / password reset /
  (de)activation / delete is audited, never with a password.
- After login, `Dashboard::home` (`@any`, also the site root) sends a user to the dashboard (`dashboard_hr`), else to the first menu entry
  they may open, else to a welcome page.
- **Audit viewer** (`audit_view`): rows of the working branch (rows without a branch only for the Admin), date range default 7 days and
  at most 366, whitelisted sort, three queries per page (`Audit_test`).

### Organisation (build step 2)
- Departments and designations: code unique per branch (upper case), deactivate via status, delete only while no employee uses
  them. A department head is a current employee of the branch (`scope_department` reads it).
- Employees: code unique per branch; left empty it becomes `next_code('employee')` = `EMP-nnnn`. Department, designation and manager
  are `ref()`-checked against the branch; a reporting line may not loop. Status `Active` / `On Leave` on the form; `Resigned` /
  `Terminated` only through **exit**. Delete only an employee without other records (salary, PIN history, login, subordinates, head of
  a department, and later attendance/payroll) - otherwise record an exit.
- **Sensitive data:** `bank_account_number`, `national_id`, `tin` are masked to the last 4 characters (pages, lists, audit) unless
  `employees_sensitive`; without it the form shows the masked text and a save keeps the stored values (`Employees_test`).
- **Salary** (`employees_salary` to see it, plus `employees_edit` to change it): gross and net are computed on the server; a new
  structure must start after the latest one and closes it (`effective_to = new.effective_from - 1 day`); only the latest can be removed
  (the previous one reopens; payroll use will block it from step 9). `Salary_model::covering($employee, $from, $to)` returns the
  structures in force with their segment days - payroll reads that, never `is_active` (P20; `Salary_structure_test`).
- **Device PIN** (groundwork for P5, `Employee_pin_test`): a new PIN starts on the join date or the chosen date; a change moves the old
  PIN to `employee_pin_history` (valid_to = day before the new one); a change dated on/before the current PIN's start is a correction
  (no history). A PIN may not start on a date that a previous owner's history still covers. `Employees_model::pin_owner($branch, $pin,
  $date)` finds the owner on a date (current holder from `device_pin_from`, else the history) - the punch ingestor uses it in step 4/5.
- **Exit** (date >= join and >= PIN start, Resigned/Terminated, reason): the PIN moves to the history with valid_to = exit date, the
  linked login is deactivated (never your own, never the last Admin). **Re-hire**: date after the exit becomes the new join date.
- Documents and photo: `Input_validator::document()` / `image()`, random names under `uploads/employees/`, served by
  `Employees::document` (attachment) and `Employees::photo` after the permission and branch checks.
- Login link: an active, unlinked login of the branch, within the actor's permissions (`Users_model::check_manageable`), needs
  `users_edit` as well; both `users.employee_id` and `employees.user_id` are written in one transaction.
- Not in step 2 (later steps): **Enrol on device** / `delete_user` command queue (step 5); the profile's attendance, leave balance,
  advances, loans and payroll sections (steps 4-9).

### Time (build step 3)
- Shifts (`shifts_*`): code unique per branch, `is_night` derived from end < start, break shorter than the shift, working hours not
  longer than it; delete only when no assignment, roster row or default setting uses the shift (deactivate otherwise).
- **Permissions without their own keys:** shift assignments are seen with `shifts_view`; assigning people (single, bulk by department,
  removing the latest) is scheduling and needs `rosters_manage` (the catalog has no `employee_shifts_*` keys).
- Assignments: a new one must start on/after the join date and after the latest one, and closes it (`effective_to` = day before); only the
  latest can be removed (the previous reopens). Bulk assignment runs in one transaction and skips employees already on that shift or with
  a later assignment. Only active shifts of the branch and current employees.
- Roster (`rosters_manage`): week/month grid per department; a cell is a shift, `OFF` or empty (= no row: normal rules). Save posts only
  changed cells (max 5000, at most one year). Copy week copies 7 days of rows (keep or overwrite). Import `.xlsx`/`.csv` with columns
  employee_code, date (UI format, Y-m-d or an Excel date), shift_code | OFF | empty (clear); every row is checked first and with any error
  nothing is written.
- Holidays (`holidays_*`): one per branch and date; year view; copy last year's `is_fixed_date` holidays (29 Feb skipped in a common year,
  taken dates skipped).
- Settings: `default_shift_id` on the Attendance tab (any active shift; it only applies in its own branch).

### Attendance (build step 4)
- **Punches** (`attendance_punches`, migration 017): `device_id` stays without a foreign key until `devices` exists (step 5) and is NULL
  for manual punches; NULL never collides in a unique key, so a manual punch is refused as a duplicate by (employee, `punch_at`) inside
  the ingestor. `device_pin` is NULL for manual punches of an employee without a PIN.
- **`Punch_ingestor::ingest($punches, $device)`** (`$device` = `branch_id`, `device_id`, `source`, `created_by`; the branch comes only
  from it) returns counts (stored, duplicates, ignored, unmatched, invalid, rebuilt, kept_manual, locked). One `Tx::run()` per batch.
  Owner of a PIN on the punch date: `employees.device_pin` from `device_pin_from`, else `employee_pin_history` (set-based, one query).
  `reprocess_unmatched($branch, $pin)` attaches stored unmatched punches after HR sets the PIN (the page arrives in step 5).
- **Breaks:** when the punches carry explicit states (0 in / 1 out) an OUT followed by an IN is a break; with unknown states (255)
  the punches alternate in/out from the first one; the last punch never opens a break. Night shift: a punch up to
  `shift.end_time + 240 min` on the next calendar day belongs to the previous day (`Punch_ingestor::NIGHT_TAIL_MINUTES`).
- **`attendance.source`**: `punch` (built from punches), `manual` (HR mark or edit), `self` (self-marked, Pending), `day_close`.
  `rebuild_day()` overwrites only `punch` / `day_close` rows; `manual` / `self` rows are kept until HR clicks **Rebuild from punches**
  (forced); locked rows are never touched (counted as `locked`; the **Punches for closed days** list arrives with the device pages in
  step 5). A day whose punches are all gone loses its `punch` row.
- **Mark** (`attendance_add`): Approved; not in the future; it may replace a `day_close` row (e.g. an Absent filled by the cron), any
  other existing row is edited instead. **Add punch** on the mark page goes through the ingestor (source `manual`).
  **My attendance today** (`attendance_mark_self`): the login's own employee, today only, Pending.
- **Approve / reject** (`attendance_approve`): only Pending rows, re-checked under the row lock; a rejection needs a note; nobody decides
  their own row except the system Admin. Seeing a row: your own, `show_all_attendance`, or being its department head (`scope_department`);
  `Access_model` (by-id `belong_to`) applies the same own + headed-department rule, so a department head can approve their team.
- **Bulk mark** (`attendance_bulk`): date + department (or all), active employees of that day; existing rows are skipped, or updated when
  *overwrite* is ticked (never locked rows); the answer is "n created, n updated, n skipped".
- **Day close** (`Day_close_model`, cron `attendance_close [Y-m-d] [dry-run]`): per tenant and active branch; "yesterday" and "never today"
  are computed in the **branch's** timezone. Leave rows need the leave tables, so until step 6 a covered day is Absent (step 6 fills
  `approved_leaves()`, approval turns those rows into Leave). Step 6 note: when punches arrive for a `Leave` day the rebuild currently
  makes it Present - leave approval must check punches (see "Leave").
- **Reports** (`Reports`): daily and monthly grid need `attendance_report` (rows of the branch, plus `scope_department` without
  `show_all_attendance`); the Excel downloads need `export_hr` as well and are written to `audit_log` (module `reports`, action
  `export`). At most 1000 employees per report, three queries (G5).
- Layout: a page passes `$page_vendor_css` / `$page_vendor_js` (theme-relative paths) to load a plugin only where it is used
  (fullcalendar + moment on the calendar).
- Deferred on purpose: `Payroll_missing_rows_test` (step 9, it needs the payroll calculator; the day-close half of P1 is `Day_close_test`).
  (The unmatched-punches page, the closed-day list, the import and the device pages came in step 5.)

### Devices (build step 5)
- **Migrations 019 / 020.** 019 creates the five device tables; 020 makes `attendance_punches.device_id` a foreign key (`fk_punches_device`).
  Nothing but the demo seed could write a device id before this step, so 020 first clears a `device_id` that has no `devices` row.
- **Who is calling (`Device_auth`).** Windows Service: key from `X-API-Key`, `Authorization: Bearer` or `?api_key=`; the lookup is by
  `sha256(key)` confirmed with `hash_equals`; revoked key / inactive branch refused; `last_used_at` at most once a minute; **20 failed
  checks per IP and minute lock that IP for the rest of the minute (429, even for a valid key)**. Device of a call: the one the key is
  bound to, else the `windows_service` device of the key's branch with the payload's `device_name` (created on first sight, at most 50
  per branch, so a branch-wide key needs no setup per PC); a name that belongs to an ADMS device is 409, a disabled device 403.
  ADMS: serial number `[A-Za-z0-9_-]{3,40}` (else 400, nothing touched); 600 calls per IP and 120 per SN and minute (429); only approved
  serial numbers are served, **an unknown one gets the normal handshake text and `OK` on getrequest but HTTP 403 on every upload**
  (the device keeps its data until HR approves it) and is remembered in `devices_pending` (capped at 50, purged after 30 days).
  Approving a pending device (`devices_manage`; only the system Admin may pick another branch) can restrict it to the address it called
  from (`allowed_ips`, default on); a call from another address is refused 403 and shown on the Devices page as `blocked_ip`, where HR
  confirms it ("allow this address" adds it to `allowed_ips`). `allowed_ips` also applies to Windows Service devices when set.
- **`Api::pull_from_machine`** (route `api/v1/pull_attendance`): POST only (405), body <= 8 MB (413), valid JSON (400), at most 10 000
  records. `{"test": true}` -> `Connection OK` without registering a device. Answer: `processed` = records - invalid, `accepted` =
  stored + already stored, plus `invalid`, `unmatched` and `commands`. Every storage failure or exception is **HTTP 500 and nothing is
  stored**; `last_upload_at` moves only on success. `Service_payload` is the pure parser; ids a payload claims for itself are read **only**
  by `Service_payload::claimed_branches()` (a library outside the P3 static scan) and only to write "ignored" into `device_logs`.
- **`Iclock`**: `cdata` (GET handshake; POST `table=ATTLOG` stores punches, any other table is acknowledged `OK` and dropped; body <= 2 MB),
  `getrequest` (heartbeat, `INFO=` gives the firmware, commands as `C:<id>:<COMMAND>` lines), `devicecmd` (results). `Adms_protocol` is
  the pure parser: PIN 1-9 digits, date and time in one or two columns, tabs or spaces, junk lines counted in `device_logs`.
- **Time zones.** A device without a timezone delivers branch local time. With `devices.timezone` set, punch times are converted to the
  branch's timezone before they are stored (the file import too).
- **Clock drift** is an estimate: a push of at most 3 punches (realtime) compares its latest punch time with the server clock and keeps the
  difference when it is within 6 h; a backlog says nothing. The Windows Service reports its PC's time, not the device's, so it gives none.
  `sync_time` is sent as `SET OPTIONS DateTime=...`, which is firmware dependent: check it on your model (the result is recorded).
- **Command queue** (`Device_command_queue`): Windows Service devices take `create_user`, `update_user`, `delete_user`, `list_users`; ADMS
  devices also `sync_time`, `clear_attlog`, `reboot`. `create/update/delete` need a numeric PIN; the name is cut to 24 characters, the device
  password to 8 digits, `role` is 0 or 14. An identical queued command is not queued twice. queued -> sent (when delivered, inside one
  transaction) -> done / failed (the result must come from the same device); a command **sent but unanswered for 30 minutes becomes
  failed**. HR queues by hand (`devices_commands`: PIN and name come from the employee, never from the form), **Enrol on device** is on the
  employee's PIN tab (`devices_commands`), and **recording an exit queues `delete_user` on every active device of the branch**. The device
  password is never shown in the command list or the audit trail.
- **Keys** (`devices_keys`): created for the working branch (optionally one device of it), `hrmk_` + 40 hex characters shown **once** in the
  answer of the create call, only the hash and the last 4 characters are stored, revocable (`audit_log`: create / revoke, never the key).
- **Health** (`Devices_model::health`): green < 10 min since the last call, yellow < 60 min, red; drift > 5 min; `cron device_health`
  (every 15 min) sets **`lost_punch_alert` when the device uploaded nothing for `device_gap_hours` and a shift of an employee of its branch
  began in that silence** (uses `Shift_calculator::plan` over the last 3 days; a device that never uploaded counts from its creation; a
  disabled device is skipped); a batch with punches clears the flag at once. "Employees with a single punch" is the existing
  **Missing check-out** filter (same rows), not a second list.
- **Unmatched punches** (`attendance_punches`): punches without an employee grouped by PIN (last 30 days by default, at most 92), with
  **Link PIN** (`attendance_punches` **and** `employees_edit`): the employee gets the PIN with the normal PIN rules (`Employees_model::assign_pin`)
  starting at the later of the join date and the first unmatched punch, then `reprocess_unmatched` gives the stored punches their owner and
  rebuilds the days; punches before the PIN's start stay unmatched.
- **Punches for closed days** (`attendance_punches`): non-ignored punches inside a **locked** day that the day does not show (before its first or
  after its last punch, or the day has no punches); night shifts that cross midnight are not matched. Read only - nothing changes a closed day.
- **Import punches** (`attendance_import`): `.dat` / `.txt` (the USB export, same line format as ADMS) or `.csv` (pin, date and time in one column or
  two, optional state and verify mode, header optional, `;` `,` or tab; UI date format or `Y-m-d`), UTF-8 without NUL bytes, <= 8 MB, <= 50 000
  rows. The form needs the **device the file came from** (its id makes a punch unique, so importing twice, or a file whose punches the service
  already delivered, changes nothing). Step 1 parses and shows counts, period, PINs that match nobody and a sample, writing only a random-named
  file under `uploads/import/` (1 hour, only for the same user and branch); step 2 ingests it in chunks of 5000 with source `import` and audits it.
- **Permissions.** `devices_view` list + call log; `devices_manage` add / edit / delete / approve / allow address; `devices_keys` keys;
  `devices_commands` queue + enrol; `attendance_punches` unmatched + closed-day lists; `attendance_import` import. `Api` / `Iclock` map to `@device`.

## Modules, pages and endpoints (controllers; each declares its `$permission_map`)
- `Employees` - list (filters branch, department, designation, status, type), add/edit (tabs: personal, job, bank, salary structure
  history, documents, device PIN + **Enrol on device** -> queues `create_user`), profile (attendance, leave balance, advances, loans,
  last payrolls), exit (exit date, reason; PIN moves to history; queues `delete_user`).
- `Departments`, `Designations` - CRUD, status toggle. `Shifts`, `Employee_shifts` (assign, bulk by department, history), `Rosters`
  (week/month grid, copy week, Excel import). `Holidays` - CRUD, year view, copy last year's fixed-date holidays.
- `Attendance` - mark, bulk mark, list (server DataTables; date range / department / status / approval), approve/reject, calendar
  (per employee), punches (raw), unmatched punches, missing check-out, rebuild day, **import punches**, reports.
- `Leave_types`, `Leave_allocations` (single + bulk), `Leaves` (apply, list, approve/reject/cancel, balance, who-is-away calendar).
- `Overtime` - request/list/approve/reject, report. `Advances`, `Loans` - as above.
- `Payroll` - generate, bulk generate, list, edit, pay (partial), payslip, cheque, bulk payslips, close month, reports.
- `Accounts` - accounts and transactions. `Devices` - list with health, approve pending ADMS device, keys, command queue, logs.
- `Reports`, `Dashboard`, `Ess` (my attendance, my leave + apply, my payslips, my advances/loans + request), `Settings`, `Users`,
  `Roles`, `Audit`. `Api` (device JSON), `Iclock` (ADMS), `Cron` (CLI only), `Auth` (login, password change).

## Reports (`Reports` controller; filters, print, Excel, PDF; all set-based per G5)
Daily attendance, monthly attendance grid (P/A/L/H/W/½, late colouring), employee monthly detail (punch pairs, break minutes from
`attendance_punches`), late, absent, missing check-out, overtime, leave (type / employee / period) and balance, payroll register (month,
department), bank salary sheet (account number, amount), payslips (bulk), advance and loan statements, salary payments by account,
headcount (joiners/leavers, department/designation), staff performance (attendance %, late count, OT hours, leaves - **HR only**;
pos-retails' version also mixed in sales figures).

## HR Dashboard (`Dashboard::index`, permission `dashboard_hr`; widgets load by AJAX, each endpoint calls `release_session_lock()`)
Filters: branch (if allowed), department, date (default today), month (payroll widgets). Each widget cached 60 s (`cache` keyed by
tenant + branch + filters) and bounded by branch + date range (indexes above).
1. **KPI cards (today)** - active employees; Present (Present + Half-day, % of expected); Absent (expected - present - on leave; before the
   day close it reads "not yet punched"); On leave; Late arrivals (+ average late minutes); Overtime hours; Pending approvals
   (attendance + leaves + overtime + advances; click opens the queue). Expected = active employees whose day is a working day.
2. **Live punch feed** - last 20 punches (photo, name, time, device, in/out), refreshed every 30 s; unmatched PINs highlighted with
   "Link employee".
3. **Attendance trend** (line) - last 30 days present % and late %.
4. **Department attendance today** (horizontal bars) - present / expected.
5. **Late arrivals today** - employee, shift start, check-in, late minutes, late days this month (red at `late_days_per_absent`).
6. **Who is away** - today's and next 7 days' approved leaves + today's absentees.
7. **Upcoming** - holidays (30 days), birthdays and anniversaries (7 days), probation/contract ends, document expiries.
8. **Payroll this month** (doughnut + figures) - generated / not generated, gross, deductions, net, paid / partial / pending, **Generate missing**.
9. **Salary cost trend** (stacked bars) - last 12 months net salary + OT.
10. **Advances & loans** - outstanding advances, outstanding loans, EMI due this month, overdue instalments.
11. **Leave alerts** - paid-leave balance < 2 days; requests pending > 2 days.
12. **Device health** - per device: green last_seen < 10 min / amber < 60 min / red, last punch, punches today, clock drift > 5 min,
    **Possible lost punches** flag.
13. **Headcount** - by department (doughnut), joiners vs leavers (6 months), gender split.
14. **Data quality** - unmatched punches, missing check-outs, employees without a device PIN, employees without a salary structure,
    employees without a shift (each a link to the fix screen).

Role variants: HR/Admin see all; a department head sees widgets 1, 3-6 for their department; an employee lands on the **ESS dashboard**
(today's punch status and shift, this month's present/absent/late/OT, leave balances + Apply, last payslip, advance/loan balance, holidays).

## Notifications (optional module)
SMS (pluggable gateway) and email (CI Email) with templates and placeholders (`{{employee_name}} {{date}} {{leave_type}} {{from}} {{to}}
{{net_salary}} {{month}}`): leave approved/rejected, payslip ready, absent today, late warning at the threshold, advance approved.
Sent **after** the transaction commits; a failed message never fails the business action; every send is logged.

## Known pos-retails defects and how this project resolves them

Each entry: what pos-retails does (verified in its source), the design here, and the test that proves it. Do not weaken a rule without
changing its test deliberately. (Paths are pos-retails paths, for reference only.)

**P1. Absence and leave days are not counted.** `Payroll_model::calculate_employee_salary` counts only rows with `status='Absent'` and stores
`leave_days = 0`; a working day without a row costs nothing, and approved leave is never recognised.
*Here:* day-close cron creates every missing day (Holiday > Weekly Off > Leave > Absent); payroll runs the close first; paid and unpaid leave
are separate (`leave_types.is_paid`); formulas in "Payroll calculation". *Test:* `Day_close_test`, `Payroll_missing_rows_test` (month with no
rows for 5 working days = 5 absent days deducted; approved paid leave not deducted; unpaid leave deducted).

**P2. Overtime module disconnected; rate reads a column that does not exist.** `Overtime_model::get_employee_ot_rate` selects `db_users.salary`
(no such column => rate always 0), and nothing in payroll reads `db_overtime`; payroll sums attendance OT instead.
*Here:* `overtime_source` setting, one source per month, rate from the salary structure in force, `overtime.payroll_id` on use. *Test:*
`Overtime_source_test` (attendance mode ignores requests; requests mode ignores attendance OT; no double counting; rate > 0).

**P3. Branch hard-coded / taken from the request.** `Api::push_sdk_zkteco` forces `store_id = 2`; `Api::save_attendance_records` lets a
`store_id` inside each record override the payload's, so anyone with the (single, shared) key can write to any store.
*Here:* branch from `device_keys` / `devices` only; payload branch ignored and logged; static check forbids reading it. *Test:*
`Branch_scope_test` (record with another branch id is saved to the key's branch), `Guardrail_static_checks_test`.

**P4. Raw punches are not stored; the break report reads an empty table.** Only first/last punch survive in `db_attendance`; nothing ever
inserts into `db_attendance_log`, which `Attendance_reports_model::get_break_info` reads.
*Here:* `attendance_punches` is written first and is the source for break minutes and rebuilds. *Test:* `Punch_ingestion_test` (4 punches
=> 1 break of the right length; rebuild after manual edit; re-send is a no-op).

**P5. Device PIN = login user id.** Punches are written with the PIN as `db_attendance.user_id`.
*Here:* `employees.device_pin`, `employee_pin_history`, unmatched-punch workflow, PIN reuse after exit. *Test:* `Punch_ingestion_test`
(unmatched PIN kept and later linked; reused PIN does not attach new punches to the leaver).

**P6. Missing permission checks.** No check at all on `ajax_list` in Shifts, Employee_shifts, Holidays, Departments, Designations,
Leave_types, Leave_allocations (employee/shift data readable by any logged-in user), nor on `Shifts::newshift/update_shift`,
`Employee_shifts::save`, `Holidays::update_holiday`, `Departments::update_department`, `Designations::update_designation`,
`Leave_types::update_leave_type`, `Leave_allocations::newallocation/update_allocation` (any logged-in user can create or change them).
*Here:* G1 default-deny map. *Test:* `Guardrail_permission_coverage_test` (reflection over every controller: each public method has a
map entry; an HTTP smoke test calls every mapped route as a user with no permissions and expects 403).

**P7. Controller without login, loading models that do not exist.** `Leave_requests extends CI_Controller` (no `load_global()`) and loads
`Leave_type_model` and `User_model`, which are not in the codebase.
*Here:* only `Api`, `Iclock`, `Cron`, `Auth` may skip `MY_Controller` (static check); leave requests are the `Leaves` module. *Test:*
`Guardrail_static_checks_test`.

**P8. Payroll trusts the browser.** `Payroll_model::save_payroll` stores `gross_salary`, `net_salary`, deductions and days exactly as posted
(through `extract()` of `$_POST`), and the bulk save takes the posted `salary_data` JSON; a forged request can set any salary.
*Here:* server-side recalculation for single and bulk, permitted-override whitelist with bounds and audit (see "Payroll lifecycle"). *Test:*
`Payroll_tamper_test` (post inflated net/gross/advance => stored values equal the calculator's; override without `payroll_override` ignored;
override outside bounds refused; override audited).

**P9. Salary payment not atomic; advance deductions de-duplicated by a note string.** `save_salary_payment` writes an expense, an account
transaction, a payment row and the deductions without a transaction; `apply_payroll_advance_deduction` finds earlier work by matching
`notes = 'Payroll auto deduction PAY-...'`. A failure half-way leaves money out and no payment (or the reverse); a double click pays twice.
*Here:* G3 (`Tx::run`, row locks, `request_token`), `advance_payments.payroll_id` / `loan_payments.payroll_id` with unique keys.
*Test:* `Payroll_payment_atomicity_test` (forced failure at each step leaves no rows; double submit pays once; deductions apply exactly once
when the paying payment completes the payroll; partial payments).

**P10. Leave workflow.** Approve is not in a transaction (double approve deducts twice), there is no overlap check, days are calendar days
including weekly offs, the balance check/deduction uses the year of `from_date` only, the leave code is `rand(1000,9999)`, and ids
from the request are not checked against the branch.
*Here:* "Leave" rules, `leave_allocation_log`, `sequences`, `Input_validator::ref`. *Test:* `Leave_workflow_test` (overlap refused; weekly
off inside a leave not counted; year split; approve twice deducts once; cancel restores exactly; codes unique under concurrency).

**P11. SQL built by string interpolation, `extract($_POST)`, text stored HTML-escaped.** e.g. `WHERE id='$id' AND store_id=...`, many models
start with `extract($this->security->xss_clean(html_escape(array_merge($this->data, $_POST))))`, and stored text shows `&amp;#039;`.
*Here:* G2 (validator, bound queries), escape on output only, static checks. *Test:* `Guardrail_static_checks_test`; `Input_validator_test`
(injection strings are stored verbatim and rendered escaped).

**P12. Dead and duplicate tables.** Never read by code in pos-retails: `hr_advances` (one view only), `hr_advance_repayments`, `hr_allowances`,
`hr_payroll`, `db_employee_advances`, `db_employee_loans`, `db_loan_emis`, `db_advance_recoveries`, `db_payslips`, `db_overtime_rates`,
`vw_employee_leave_balance`, `vw_leave_summary`, `vw_monthly_attendance_summary`, `db_worker_payments`; advances exist three times, payroll twice.
*Here:* one table per concept (Domain model); `db_loans` is employee-only. *Test:* `Guardrail_static_checks_test` (legacy names banned).

**P13. Magic numbers.** Overtime uses literal `/ 208` and `* 1.5` inside the calculation.
*Here:* `ot_divisor_hours`, `ot_multiplier` settings. *Test:* `Overtime_source_test` (changing the settings changes the amount), static check.

**P14. "Manager" inferred from having edit or delete permission.** `Attendance_model::can_manage_all_attendance` treats anyone with
`attendance_edit` or `attendance_delete` as allowed to mark and auto-approve for everybody.
*Here:* explicit `attendance_add`, `attendance_approve`, `show_all_attendance`, `attendance_mark_self`. *Test:* `Attendance_permission_test`.

**P15. Slow reports.** The monthly/daily/detail reports run queries inside per-employee loops (and per day for breaks) and filter with
`MONTH(attendance_date)` / `YEAR(attendance_date)`, which cannot use the date index; payroll does the same.
*Here:* G5 (range predicates, three queries per report, caps). *Test:* `Report_query_budget_test`, static check.

**P16. Windows Service clears the device after a failed upload.** `service_worker.php` (line ~278) clears device memory when the API is merely
enabled, whether or not `sendAttendanceBatch` succeeded.
*Here:* idempotent ingestion, HTTP 500 on any failure, gap detection, missing-check-out lists, USB `.dat`/CSV import, recommended
service settings (see "Attendance devices"). *Test:* `Service_contract_test` (500 on DB error; resend accepted twice = one row), `Device_health_test`.

**P17. Shared plain-text API key; open ADMS endpoint.** One key in `db_sitesettings.unique_code`, stored unhashed and shared by every device and
branch; ADMS accepts any serial number.
*Here:* hashed, per-branch/device, revocable keys; approved-SN-only ADMS, pending list with caps, IP controls, rate limits; login throttling
and `password_hash`. *Test:* `Auth_test`, `Device_auth_test` (unknown SN stores nothing and cannot flood the table; revoked key => 401).

**P18. Base-class helpers are web-routable.** In CodeIgniter 3 any public method is a URL; pos-retails' `MY_Controller` has public `load_info`,
`permission_check`, `belong_to`, `currency`, `release_session_lock`, ... callable by URL.
*Here:* G1 (`_remap` default deny, helpers `protected`, static check). *Test:* `Guardrail_permission_coverage_test`.

**P19. `working_days` is stored as calendar days.** `calculate_employee_salary` returns `'working_days' => $total_days` (days in the month).
*Here:* `calendar_days` and `working_days` (minus weekly offs and holidays) are separate columns and both appear on the payslip. *Test:*
`Payroll_day_semantics_test`.

**P20. Payroll uses today's salary structure for any month.** The employee query joins `db_salary_structures ... AND ss.is_active = 1`, so
regenerating or editing a past month uses the current structure.
*Here:* structure(s) covering the month by dates, pro-rated across a mid-month change, id stored in `salary_structure_id`. *Test:*
`Payroll_structure_asof_test`.

## Build order (each step shippable, with migrations and tests)
0. **Guardrails first** *(done)*: CI3 skeleton, `MY_Controller` (default-deny `_remap`), `Input_validator`, `Tx`, `Idempotency`, `sequences`,
   `MY_Model` scope/audit, `tests/static_checks.php`, the guardrail tests (P6, P7, P11, P12, P13, P15, P18), phpunit bootstrap on `hrm_test`.
1. Auth (login, `password_hash`, throttle, idle logout), roles/permissions, branches, settings, layout, audit log, migration runner per tenant. *(done)*
2. Organisation: departments, designations, employees (documents, salary structure history, PIN + PIN history), users linked to employees. *(done)*
3. Time: shifts, employee shifts, rosters, holidays, weekly offs, `Shift_calculator` + unit tests. *(done)*
4. Attendance: punches table, `Punch_ingestor`, manual/bulk mark, approval, rebuild day, **day close cron**, calendar, daily/monthly reports (P1, P4, P5, P14). *(done)*
5. Devices: `Api::pull_from_machine` (Windows Service contract) and `Iclock` (ADMS), device keys, devices page, pending SNs, unmatched punches,
   command queue, health + lost-punch detection, USB/CSV import (P3, P16, P17). *(done)*
6. Leave: types, allocations (bulk), apply/approve/cancel, balance, carry-forward cron, reports (P10).
7. Overtime source setting + requests/approval (P2, P13).
8. Money accounts; advances (disburse, repay); loans (schedule, pay) (P9).
9. Payroll: `Payroll_calculator` + tests for every rule, generate, bulk generate, overrides, pay, payslip/cheque/PDF, month close, reports (P8, P9, P19, P20).
10. Dashboard widgets, ESS portal, notifications, Excel import/export.

## Testing
- `tests/` boots CI3 against `hrm_test` (migrated from zero in the bootstrap; also run `migrate version <n-1>` then back to prove `down()`),
  seeds fixtures per test, and wraps each test in a transaction. The bootstrap removes CodeIgniter's error/exception handlers after
  booting: PHPUnit 9 does not replace an existing handler, so otherwise a warning in application code would be printed, not fail
  the test (`Helpers_test::test_warnings_in_application_code_fail_tests`).
- **Guardrail tests** (run first; a failure blocks everything else): `Guardrail_static_checks_test`, `Guardrail_permission_coverage_test`
  (also the 403 smoke test with a no-permission user), `Input_validator_test`, `Report_query_budget_test`.
- **Administration tests (step 1):** `Users_test`, `Roles_test`, `Branches_test`, `Settings_test`, `Audit_test`, `Datatable_request_test`,
  `Admin_flow_test` (HTTP: landing page, branch switch, logo upload, CSRF on lists, by-id 403).
- **Time tests (step 3):** `Shift_calculator_test` (metrics + plan precedence + query budget), `Shifts_test` (incl. assignments),
  `Rosters_test`, `Holidays_test`, `Time_flow_test` (HTTP: roster grid/save/import, view vs schedule permissions, default shift).
- **Device tests (step 5):** `Adms_parser_test` (tabs, spaces, split date/time, junk lines, handshake, command lines, service JSON, USB/CSV files),
  `Service_contract_test` (test ping, heartbeat, batch, resend = one row, **HTTP 500 and nothing stored on a DB error**, commands round trip, which
  device a key means), `Branch_scope_test` (P3: a record with another branch id is saved to the key's branch, ADMS in the device's branch),
  `Device_auth_test` (P17: hashed keys, revoked key 401, throttle, unknown serial stores nothing and cannot flood the list, allowed addresses,
  rate limit, ADMS command round trip), `Device_health_test` (colours, drift, lost-punch rule, cron output), `Devices_flow_test` (HTTP: permissions,
  CRUD, keys shown once, approve pending, commands, enrol, exit queues delete_user, call log, branch scope), `Punch_review_test` (unmatched list,
  link PIN, closed-day list), `Punch_import_test` (preview writes nothing, import idempotent, CSV, tokens).
- **Attendance tests (step 4):** `Punch_ingestion_test` (P4, P5: breaks, debounce, re-send no-op, unmatched then linked, reused PIN,
  night shift, manual row kept until rebuild), `Punch_ingestion_atomicity_test` (a forced failure stores nothing), `Day_close_test`
  (P1: Holiday > Weekly Off > Absent, gaps only, join/exit, never today, missing check-out), `Attendance_test` (mark, bulk, approve,
  locked rows), `Attendance_permission_test` (P14, HTTP), `Attendance_flow_test` (cron CLI, Excel export audited, calendar),
  `Report_query_budget_test` (500 employees x 30 days <= 6 queries).
- **Organisation tests (step 2):** `Organisation_units_test`, `Employees_test` (incl. masking and a real `scope_department`),
  `Employee_pin_test`, `Salary_structure_test`, `Organisation_flow_test` (HTTP: salary/sensitive permissions, documents, photo, branch 403).
- **Behaviour tests:** `Shift_calculator_test` (normal, grace, night shift, holiday work); `Punch_ingestion_test`; `Day_close_test`;
  `Leave_workflow_test`; `Overtime_source_test`; `Payroll_calculator_test` (every formula line, auto vs manual, late conversion, joiner/leaver,
  mid-month raise, advance + loan); `Payroll_missing_rows_test`; `Payroll_tamper_test`; `Payroll_payment_atomicity_test`;
  `Payroll_day_semantics_test`; `Payroll_structure_asof_test`; `Attendance_permission_test`; `Branch_scope_test`; `Auth_test`;
  `Device_auth_test`; `Adms_parser_test` (tabs, spaces, split date/time, junk lines); `Service_contract_test` (test ping, heartbeat, 500 on DB
  error, commands round trip); `Device_health_test`.
- Device work without hardware: replay `tests/fixtures/adms/*.txt` and `tests/fixtures/service/*.json` with curl or the phpunit HTTP helper.
- A change that touches a P-numbered rule must include the test named in its entry.

## Gotchas
- Device endpoints are hit every few seconds per device: session-less, cheap (one batch insert + one rebuild per touched employee/day),
  and never answer 200 when punches were not stored.
- ZKTeco device time is local and has no timezone; interpret it in the device's (or branch's) timezone, never the server's.
- iCheck replaces checkboxes: bind `ifChanged` for logic, read `:checked` at submit.
- bootstrap-datepicker re-reads its field only on `keyup`; `app.js` also updates it on `input`/`paste`, otherwise a pasted or
  autofilled date is wiped when the field loses focus.
- Bangla names: use `mb_*` string functions (`ucfirst()` on UTF-8 produced invalid JSON for DataTables in pos-retails).
- Keep `config/database.php` and any tenant routing JSON out of git; ship `database.example.php`.
- CodeIgniter 3 on PHP 8.2+ emits "dynamic property" deprecations from its own core; declare properties in your classes and keep
  `error_reporting` at `E_ALL & ~E_DEPRECATED` for CI's core only if needed - never hide errors in your own code.
