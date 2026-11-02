<?php
/**
 * Class autoloader for our own non-CI-prefixed classes (loaded through composer's "autoload.files",
 * which CodeIgniter includes via $config['composer_autoload']).
 *
 * Libraries here are plain classes (Tx, Idempotency, Input_validator, ...) used as `Tx::run()` /
 * `new Input_validator($this->input)`, not through $this->load->library(). Base controllers that are not
 * MY_-prefixed (Ess_Controller, Api_Controller) and the Default_deny trait live in application/core.
 */
spl_autoload_register(function ($class) {
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $class) || strpos($class, 'CI_') === 0) {
        return;
    }
    $app = dirname(__DIR__);
    foreach (array('core', 'libraries') as $dir) {
        $file = $app . '/' . $dir . '/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});
