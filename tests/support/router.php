<?php
/**
 * Router script for `php -S 127.0.0.1:PORT tests/support/router.php` (used by the HTTP smoke tests and the Playwright run):
 * serves theme/ files as they are and sends everything else through CodeIgniter's index.php, like Apache's rewrite rule.
 */
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($root . $path) && strpos($path, '/theme/') === 0) {
    return FALSE;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
chdir($root);
require $root . '/index.php';
