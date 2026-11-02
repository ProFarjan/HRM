<?php
/**
 * Definition of done: migrate up, down one version, up again - and all the way down and up. Also checks the
 * storage rules (every table InnoDB utf8mb4, foreign keys declared) and that the seed is idempotent.
 */
class Migration_roundtrip_test extends Hrm_test_case
{
    protected $use_transaction = FALSE;

    /** version number of the migration that seeds the admin (NNN_seed_admin.php) */
    protected function seed_version()
    {
        $files = glob(APPPATH . 'migrations/*_seed_admin.php');
        $this->assertCount(1, $files);
        return (int) substr(basename($files[0]), 0, 3);
    }

    protected function version()
    {
        return (int) get_instance()->db->query('SELECT version FROM migrations')->row()->version;
    }

    protected function tables()
    {
        $names = array();
        foreach ($this->CI->db->query('SHOW TABLES')->result_array() as $row) {
            $names[] = reset($row);
        }
        sort($names);
        return $names;
    }

    protected function app_tables()
    {
        return array_values(array_diff($this->tables(), array('tx_probe')));
    }

    public function test_down_one_version_and_up_again()
    {
        $latest = Tenant::latest_version();
        $this->assertSame($latest, $this->version());
        $before = $this->app_tables();

        list($ok, $message, $from, $to) = Tenant::migrate('hrm_test', $latest - 1);
        $this->assertTrue($ok, $message);
        $this->assertSame(array($latest, $latest - 1), array($from, $to));

        list($ok, $message, $from, $to) = Tenant::migrate('hrm_test');
        $this->assertTrue($ok, $message);
        $this->assertSame(array($latest - 1, $latest), array($from, $to));
        $this->assertSame($before, $this->app_tables());
    }

    public function test_down_below_the_seed_removes_the_admin_and_up_restores_it()
    {
        $seed = $this->seed_version();
        list($ok, $message) = Tenant::migrate('hrm_test', $seed - 1);
        $this->assertTrue($ok, $message);
        $this->assertSame(0, (int) get_instance()->db->query("SELECT COUNT(*) AS c FROM users WHERE username = 'admin'")->row()->c, 'down() removed the seeded admin');
        list($ok, $message) = Tenant::migrate('hrm_test');
        $this->assertTrue($ok, $message);
        $this->assertSame(1, (int) get_instance()->db->query("SELECT COUNT(*) AS c FROM users WHERE username = 'admin'")->row()->c, 'the seed ran again');
    }

    public function test_down_to_zero_and_up_again()
    {
        $latest = Tenant::latest_version();
        $before = $this->app_tables();

        list($ok, $message) = Tenant::migrate('hrm_test', 0);
        $this->assertTrue($ok, $message);
        $this->assertSame(array('migrations', 'tx_probe'), $this->tables(), 'every table of ours was dropped, down() works for all versions');

        list($ok, $message, , $to) = Tenant::migrate('hrm_test');
        $this->assertTrue($ok, $message);
        $this->assertSame($latest, $to);
        $this->assertSame($before, $this->app_tables());
    }

    public function test_running_up_twice_is_idempotent()
    {
        $latest = Tenant::latest_version();
        list($ok) = Tenant::migrate('hrm_test');
        $this->assertTrue($ok);
        // re-run every migration's up() against an already complete schema: guards must make it a no-op
        get_instance()->db->update('migrations', array('version' => 0));
        list($ok, $message, , $to) = Tenant::migrate('hrm_test');
        $this->assertTrue($ok, $message);
        $this->assertSame($latest, $to);
        $this->assertSame(1, (int) get_instance()->db->query('SELECT COUNT(*) AS c FROM branches')->row()->c, 'no second branch');
        $this->assertSame(1, (int) get_instance()->db->query('SELECT COUNT(*) AS c FROM roles WHERE is_system = 1')->row()->c, 'no second Admin role');
        $this->assertSame(1, (int) get_instance()->db->query("SELECT COUNT(*) AS c FROM users WHERE username = 'admin'")->row()->c, 'no second admin');
        $this->assertSame(1, (int) get_instance()->db->query('SELECT COUNT(*) AS c FROM hr_settings')->row()->c);
    }

    public function test_every_table_is_innodb_utf8mb4_with_declared_foreign_keys()
    {
        $db = get_instance()->db;
        $rows = $db->query("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->result_array();
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertSame('InnoDB', $r['ENGINE'], $r['TABLE_NAME']);
            $this->assertStringStartsWith('utf8mb4_', $r['TABLE_COLLATION'], $r['TABLE_NAME']);
        }
        $expected = array(
            'users' => array('roles', 'branches', 'employees'), 'role_permissions' => array('roles'), 'audit_log' => array('branches'), 'sequences' => array('branches'),
            'departments' => array('branches', 'users', 'employees'), 'designations' => array('branches', 'users'),
            'employees' => array('branches', 'departments', 'designations', 'employees', 'users', 'users'),
            'employee_pin_history' => array('branches', 'employees'), 'employee_documents' => array('employees', 'users'),
            'salary_structures' => array('employees', 'users'),
            'shifts' => array('branches', 'users'), 'employee_shifts' => array('employees', 'shifts', 'users'),
            'shift_rosters' => array('employees', 'shifts', 'users'), 'holidays' => array('branches', 'users'),
            'attendance_punches' => array('branches', 'employees', 'users'), 'attendance' => array('branches', 'employees', 'shifts', 'users', 'users'),
        );
        foreach ($expected as $table => $parents) {
            $found = array();
            foreach ($db->query('SELECT REFERENCED_TABLE_NAME AS p FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL', array($table))->result_array() as $r) {
                $found[] = $r['p'];
            }
            sort($found);
            sort($parents);
            $this->assertSame($parents, $found, "foreign keys of $table");
        }
    }

    public function test_the_session_login_and_infrastructure_tables_exist_with_the_documented_unique_keys()
    {
        $db = get_instance()->db;
        $this->assertSame(array('branch_id', 'name', 'year'), $this->index_columns('sequences', 'PRIMARY'));
        $this->assertSame(array('token'), $this->index_columns('idempotency_keys', 'PRIMARY'));
        $this->assertSame(array('username'), $this->index_columns('users', 'uq_users_username'));
        $this->assertSame(array('module', 'record_id'), $this->index_columns('audit_log', 'idx_audit_module_record'));
        $this->assertSame(array('branch_id', 'created_at'), $this->index_columns('audit_log', 'idx_audit_branch_time'));
        $this->assertSame(array('key', 'window_start'), $this->index_columns('rate_limits', 'PRIMARY'));
        $this->assertSame(array('role_id', 'permission_key'), $this->index_columns('role_permissions', 'PRIMARY'));
    }

    protected function index_columns($table, $index)
    {
        $cols = array();
        foreach (get_instance()->db->query('SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX', array($table, $index))->result_array() as $r) {
            $cols[] = $r['COLUMN_NAME'];
        }
        return $cols;
    }

    public function test_the_seeded_admin_is_the_system_role_and_the_password_is_not_committed()
    {
        $db = get_instance()->db;
        $row = $db->query("SELECT u.password_hash, r.is_system FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = 'admin'")->row_array();
        $this->assertSame(1, (int) $row['is_system']);
        $this->assertTrue(password_verify(HRM_TEST_ADMIN_PASSWORD, $row['password_hash']), 'password came from HRM_ADMIN_PASSWORD');

        $migration = file_get_contents(HRM_ROOT . '/application/migrations/007_seed_admin.php');
        $this->assertStringNotContainsString("password_hash('", $migration, 'no literal password is hashed into the seed');
        $this->assertStringContainsString("getenv('HRM_ADMIN_PASSWORD')", $migration);
    }

    public function test_a_generated_password_is_printed_once_and_forces_a_change()
    {
        // Run the seed in a child process WITHOUT HRM_ADMIN_PASSWORD: a random password is printed on the console.
        // Done on hrm_test itself (no extra privileges needed): remove the seed, re-run it in the child, restore it afterwards.
        $seed = $this->seed_version();
        $env = array_filter(array_merge($_SERVER, array('CI_ENV' => 'testing', 'TENANT_DB' => 'hrm_test')), 'is_string');
        unset($env['HRM_ADMIN_PASSWORD'], $env['HRM_ADMIN_USER']);
        $run = function () use ($env) {
            $proc = proc_open(array(PHP_BINARY, HRM_ROOT . '/index.php', 'migrate'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, HRM_ROOT, $env);
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            return array($out, proc_close($proc));
        };
        try {
            list($ok, $message) = Tenant::migrate('hrm_test', $seed - 1);
            $this->assertTrue($ok, $message);

            list($out, $code) = $run();
            $this->assertSame(0, $code, $out);
            $this->assertMatchesRegularExpression('/username: admin\s+password: ([0-9a-f]{16})/', $out);
            preg_match('/password: ([0-9a-f]{16})/', $out, $m);
            $user = get_instance()->db->query("SELECT password_hash, must_change_password FROM users WHERE username = 'admin'")->row_array();
            $this->assertTrue(password_verify($m[1], $user['password_hash']), 'the printed password is the real one');
            $this->assertSame(1, (int) $user['must_change_password'], 'a generated password must be changed at first login');

            list($ok, $message) = Tenant::migrate('hrm_test', $seed - 1);           // remove it again and run twice
            $this->assertTrue($ok, $message);
            list($out, ) = $run();
            list($out_again, ) = $run();
            $this->assertStringContainsString('password:', $out);
            $this->assertStringNotContainsString('password:', $out_again, 'the password is shown once, never on a re-run');
        } finally {
            Tenant::migrate('hrm_test', $seed - 1);
            putenv('HRM_ADMIN_PASSWORD=' . HRM_TEST_ADMIN_PASSWORD);
            $result = Tenant::migrate('hrm_test');
            $this->assertTrue($result[0], $result[1]);
        }
    }

    public function test_migrate_status_reports_every_tenant()
    {
        $env = array_filter(array_merge($_SERVER, array('CI_ENV' => 'testing', 'TENANT_DB' => 'hrm_test')), 'is_string');
        $proc = proc_open(array(PHP_BINARY, HRM_ROOT . '/index.php', 'migrate', 'status'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, HRM_ROOT, $env);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($proc), $out);
        $latest = Tenant::latest_version();
        $this->assertSame("hrm_test: version $latest of $latest up to date", trim($out));

        $env['TENANT_DB'] = 'hrm_no_such_db';
        $proc = proc_open(array(PHP_BINARY, HRM_ROOT . '/index.php', 'migrate', 'status'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, HRM_ROOT, $env);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($proc);
        $this->assertStringContainsString('hrm_no_such_db: Cannot connect', $out);
    }
}
