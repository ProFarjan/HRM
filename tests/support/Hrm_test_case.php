<?php
use PHPUnit\Framework\TestCase;

/**
 * Base class: each test runs inside a transaction that is rolled back (set $use_transaction = FALSE for tests that
 * need real commits - Tx, Idempotency, concurrency - and clean up themselves).
 */
abstract class Hrm_test_case extends TestCase
{
    /** @var bool */
    protected $use_transaction = TRUE;
    /** @var object CI instance */
    protected $CI;
    /** @var object */
    protected $db;

    protected function setUp(): void
    {
        $this->CI =& get_instance();
        $this->db = $this->CI->db;
        $this->db->db_debug = FALSE;
        set_current_user(NULL);
        hr_setting_reset_cache();
        has_permission_reset();
        date_default_timezone_set('Asia/Dhaka');
        if ($this->use_transaction) {
            $this->db->trans_begin();
            Tx::adopt_open_transaction();
        }
    }

    protected function tearDown(): void
    {
        $_POST = array();
        if ($this->use_transaction) {
            $this->db->trans_rollback();
            Tx::release_adopted();
        }
        set_current_user(NULL);
        hr_setting_reset_cache();
        has_permission_reset();
    }

    /** the id of the first (seeded) branch */
    protected function first_branch_id()
    {
        return (int) $this->db->query('SELECT id FROM branches ORDER BY id LIMIT 1')->row()->id;
    }

    protected function insert_branch($code)
    {
        $this->db->insert('branches', array('name' => 'Branch ' . $code, 'code' => $code, 'timezone' => 'Asia/Dhaka', 'status' => 'Active', 'created_at' => date('Y-m-d H:i:s')));
        return (int) $this->db->insert_id();
    }

    /** a role holding exactly $permissions (is_system = 0 unless $system) */
    protected function insert_role($name, array $permissions = array(), $system = FALSE)
    {
        $this->db->insert('roles', array('role_name' => $name, 'is_system' => $system ? 1 : 0, 'created_at' => date('Y-m-d H:i:s')));
        $role_id = (int) $this->db->insert_id();
        foreach ($permissions as $key) {
            $this->db->insert('role_permissions', array('role_id' => $role_id, 'permission_key' => $key));
        }
        return $role_id;
    }

    protected function insert_user($username, $role_id, $branch_id = NULL, $password = 'Passw0rd!x', $extra = array())
    {
        $this->db->insert('users', array_merge(array(
            'username'             => $username,
            'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
            'role_id'              => $role_id,
            'branch_id'            => $branch_id === NULL ? $this->first_branch_id() : $branch_id,
            'status'               => 'Active',
            'must_change_password' => 0,
            'created_at'           => date('Y-m-d H:i:s'),
        ), $extra));
        return (int) $this->db->insert_id();
    }

    /** log in (in-process) as an existing user id */
    protected function act_as($user_id)
    {
        $this->CI->load->model('Auth_model');
        $user = $this->CI->Auth_model->session_user($user_id);
        $this->assertNotNull($user, 'user exists');
        set_current_user($user);
        return $user;
    }

    protected function act_as_new_user($username, array $permissions = array(), $system = FALSE, $branch_id = NULL)
    {
        $role_id = $this->insert_role('role_' . $username, $permissions, $system);
        return $this->act_as($this->insert_user($username, $role_id, $branch_id));
    }

    /** replace the request body for in-process model calls (CI_Input reads $_POST live) */
    protected function post(array $fields)
    {
        $_POST = $fields;
    }

    /** a role with these keys and a login holding it, made the current user */
    protected function act_with_permissions($username, array $permissions, $branch_id = NULL)
    {
        return $this->act_as_new_user($username, $permissions, FALSE, $branch_id);
    }

    protected function audit_rows($module, $record_id = NULL, $action = NULL)
    {
        $this->db->from('audit_log')->where('module', $module);
        if ($record_id !== NULL) {
            $this->db->where('record_id', (string) $record_id);
        }
        if ($action !== NULL) {
            $this->db->where('action', $action);
        }
        return $this->db->order_by('id')->get()->result_array();
    }

    /** an employee row inserted directly (fixtures); returns the id */
    protected function insert_employee($code, array $extra = array())
    {
        $this->db->insert('employees', array_merge(array(
            'branch_id' => current_branch_id() ? current_branch_id() : $this->first_branch_id(), 'employee_code' => $code, 'first_name' => 'Emp ' . $code,
            'employment_type' => 'Permanent', 'join_date' => '2024-01-01', 'status' => 'Active', 'created_at' => date('Y-m-d H:i:s'),
        ), $extra));
        return (int) $this->db->insert_id();
    }

    /** a shift of the current branch; returns the id */
    protected function insert_shift($code, $start, $end, array $extra = array())
    {
        $this->db->insert('shifts', array_merge(array('branch_id' => current_branch_id(), 'shift_name' => 'Shift ' . $code, 'shift_code' => $code,
            'start_time' => $start, 'end_time' => $end, 'break_duration' => 0, 'working_hours' => 0, 'grace_time' => 0,
            'is_night' => Shift_calculator::minutes($end) < Shift_calculator::minutes($start) ? 1 : 0, 'status' => 'Active', 'created_at' => date('Y-m-d H:i:s')), $extra));
        return (int) $this->db->insert_id();
    }

    protected function assign_shift($employee_id, $shift_id, $from = '2020-01-01')
    {
        $this->db->insert('employee_shifts', array('employee_id' => $employee_id, 'shift_id' => $shift_id, 'effective_from' => $from, 'created_at' => date('Y-m-d H:i:s')));
    }

    protected function attendance_row($employee_id, $date)
    {
        return $this->db->get_where('attendance', array('employee_id' => $employee_id, 'attendance_date' => $date))->row_array();
    }

    protected function id_of_result($result)
    {
        $this->assertStringStartsWith('success<<<###>>>', (string) $result, (string) $result);
        return (int) substr($result, strlen('success<<<###>>>'));
    }

    protected function queries_before()
    {
        return (int) $this->db->total_queries();
    }

    /** run $php (a script) in a separate CLI process booted like the tests; returns array(stdout, exit code) */
    protected function php_process($script, array $args = array())
    {
        $cmd = array_merge(array(PHP_BINARY, HRM_ROOT . '/tests/support/' . $script), $args);
        $proc = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, HRM_ROOT);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return array($out . $err, $code);
    }
}
