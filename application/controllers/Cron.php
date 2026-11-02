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
        'attendance_close' => '@cli',
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

    /**
     * php index.php cron attendance_close [Y-m-d] [dry-run]  - day close (P1) for every tenant and every active branch.
     * Default day = yesterday in each branch's timezone; today or a future day is refused (never closed).
     */
    public function attendance_close($date = NULL, $dry = NULL)
    {
        if ($date !== NULL && parse_strict_date($date, 'Y-m-d') === FALSE) {
            fwrite(STDERR, "Usage: php index.php cron attendance_close [Y-m-d] [dry-run]\n");
            exit(2);
        }
        $dry_run = ($dry === 'dry-run');
        $failed = 0;
        foreach (Tenant::names() as $database) {
            try {
                Tenant::switch_to($database);
            } catch (RuntimeException $e) {
                echo $database . ': ' . $e->getMessage() . "\n";
                $failed++;
                continue;
            }
            $this->load->model('Day_close_model');
            foreach ($this->Day_close_model->active_branches() as $b) {
                $day = $date !== NULL ? $date : date('Y-m-d', strtotime(Day_close_model::today_in($b['timezone']) . ' -1 day'));
                set_current_branch_id((int) $b['id']);
                $r = $this->Day_close_model->close_day((int) $b['id'], $day, $dry_run);
                $parts = array();
                foreach ($r['created'] as $status => $n) {
                    $parts[] = $status . '=' . $n;
                }
                echo sprintf("%s %s %s: %s%s, already %d, missing check-out %d%s\n", $database, $b['code'], $day,
                    $r['ok'] ? 'created ' . ($parts ? implode(' ', $parts) : 'none') : 'FAILED (' . $r['message'] . ')',
                    $dry_run ? ' (dry run)' : '', $r['already'], $r['missing_checkout'], '');
                $failed += $r['ok'] ? 0 : 1;
            }
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
