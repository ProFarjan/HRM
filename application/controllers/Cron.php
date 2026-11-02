<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Command line only (`php index.php cron <job>`). First statement of the constructor refuses every web request.
 * Jobs loop over the configured tenants (all of them, or just TENANT_DB). Later steps add attendance_close,
 * leave_carry_forward and device_health here.
 */
class Cron extends CI_Controller
{
    use Default_deny;

    protected $permission_map = array(
        'ping'         => '@cli',
        'migrate'      => '@cli',
        'housekeeping' => '@cli',
    );

    public function __construct()
    {
        if ( ! is_cli()) { show_404(); }
        parent::__construct();
    }

    public function _remap($method, $params = array())
    {
        $method = strtolower((string) $method);
        if ( ! $this->is_routable($method)) {
            show_404();
        }
        return call_user_func_array(array($this, $method), $params);
    }

    /** php index.php cron ping  - boots the framework and prints ok (health check; also how the test suite boots CI) */
    public function ping()
    {
        echo "ok\n";
    }

    /**
     * php index.php migrate                  -> $config['migration_version'] for every tenant
     * php index.php migrate version 12       -> a specific version (down() must work)
     * php index.php migrate status           -> current / expected version of every tenant
     * TENANT_DB=hrm_client_xyz php index.php migrate
     */
    public function migrate($action = NULL, $version = NULL)
    {
        $target = NULL;
        if ($action === 'status') {
            $latest = Tenant::latest_version();
            foreach (Tenant::names() as $database) {
                try {
                    $current = Tenant::version($database);
                    echo sprintf("%s: version %d of %d %s\n", $database, $current, $latest, $current === $latest ? 'up to date' : 'PENDING');
                } catch (RuntimeException $e) {
                    echo $database . ': ' . $e->getMessage() . "\n";
                }
            }
            exit(0);
        }
        if ($action !== NULL) {
            if ($action !== 'version' || $version === NULL || ! ctype_digit((string) $version)) {
                fwrite(STDERR, "Usage: php index.php migrate [version N | status]\n");
                exit(2);
            }
            $target = (int) $version;
        }
        $failed = 0;
        foreach (Tenant::names() as $database) {
            try {
                list($ok, $message, $from, $to) = Tenant::migrate($database, $target);
            } catch (RuntimeException $e) {
                list($ok, $message, $from, $to) = array(FALSE, $e->getMessage(), 0, 0);
            }
            echo sprintf("%s: %d -> %d %s%s\n", $database, $from, $to, $ok ? 'OK' : 'FAILED', $ok ? '' : ' (' . $message . ')');
            $failed += $ok ? 0 : 1;
        }
        exit($failed ? 1 : 0);
    }

    /** php index.php cron housekeeping - purge device_logs > 30 d, idempotency_keys > 7 d, login_attempts, rate_limits */
    public function housekeeping()
    {
        foreach (Tenant::names() as $database) {
            try {
                Tenant::switch_to($database);
            } catch (RuntimeException $e) {
                echo $database . ': ' . $e->getMessage() . "\n";
                continue;
            }
            $this->load->model('Housekeeping_model');
            $deleted = $this->Housekeeping_model->purge();
            $parts = array();
            foreach ($deleted as $table => $n) {
                $parts[] = $table . '=' . $n;
            }
            echo $database . ': ' . implode(' ', $parts) . "\n";
        }
    }
}
