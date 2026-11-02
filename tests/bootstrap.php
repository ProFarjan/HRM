<?php
/**
 * PHPUnit bootstrap: boots CodeIgniter 3 once against the hrm_test database (never a real tenant), migrates it from
 * zero, and makes the CI instance available to the tests through get_instance().
 *
 * CI is started the way production starts it: through index.php, routed to `cron ping` (CLI-only, prints "ok").
 */
define('HRM_ROOT', dirname(__DIR__));
chdir(HRM_ROOT);

putenv('CI_ENV=testing');
$_SERVER['CI_ENV'] = 'testing';
putenv('TENANT_DB=hrm_test');                      // the only database the tests may touch
putenv('HRM_ADMIN_USER=admin');
// A fresh random admin password for every test run (nothing secret is committed); child processes inherit it.
define('HRM_TEST_ADMIN_PASSWORD', 'T' . bin2hex(random_bytes(8)) . '!a');
putenv('HRM_ADMIN_PASSWORD=' . HRM_TEST_ADMIN_PASSWORD);
$_SERVER['argv'] = array('index.php', 'cron', 'ping');
$_SERVER['argc'] = 3;

if ( ! is_file(HRM_ROOT . '/application/config/database.php')) {
    fwrite(STDERR, "application/config/database.php is missing: copy database.example.php and set the credentials (see README).\n");
    exit(1);
}

ob_start();
require HRM_ROOT . '/index.php';                   // boots CI and runs Cron::ping
ob_end_clean();

ini_set('display_errors', '1');                    // index.php hides errors in the testing environment; a fatal in a test must be visible
load_class('Model', 'core');                       // CI_Model + MY_Model, so test files can declare model subclasses
require_once HRM_ROOT . '/tests/support/Fake_input.php';
require_once HRM_ROOT . '/tests/support/Hrm_test_case.php';
require_once HRM_ROOT . '/tests/support/Http_client.php';
require_once HRM_ROOT . '/tests/support/settings_form.php';

$CI =& get_instance();
$database = $CI->db->database;
if (substr($database, -5) !== '_test') {
    fwrite(STDERR, "Refusing to run: the test database must end in _test, got '$database'.\n");
    exit(1);
}

// migrate from zero: drop every table, then run all migrations
$CI->db->db_debug = FALSE;
$CI->db->query('SET FOREIGN_KEY_CHECKS = 0');
foreach ($CI->db->query('SHOW TABLES')->result_array() as $row) {
    $CI->db->query('DROP TABLE IF EXISTS `' . str_replace('`', '', reset($row)) . '`');
}
$CI->db->query('SET FOREIGN_KEY_CHECKS = 1');

list($ok, $message, $from, $to) = Tenant::migrate($database);
if ( ! $ok) {
    fwrite(STDERR, "Test database migration failed ($from -> $to): $message\n");
    exit(1);
}
$CI->db->db_debug = FALSE;                         // tests assert on failed queries instead of dying

// scratch table for the Tx / Idempotency / validator tests (not part of the application schema)
$CI->db->query('DROP TABLE IF EXISTS tx_probe');
$CI->db->query('CREATE TABLE tx_probe (id INT NOT NULL AUTO_INCREMENT, val VARCHAR(255) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
