<?php
/**
 * Base of the device tests that talk HTTP to the real endpoints (Windows Service JSON, ADMS push): a php -S server on hrm_test,
 * keys / devices / employees as committed fixtures, everything removed again after each test.
 */
abstract class Device_test_case extends Hrm_test_case
{
    protected $use_transaction = FALSE;
    /** @var Http_server */
    protected static $server;
    /** @var Http_client */
    protected $http;

    public static function setUpBeforeClass(): void
    {
        self::$server = new Http_server();
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        self::wipe();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::wipe();
        $this->http = new Http_client(self::$server->port);
        $this->db->update('branches', array('weekly_offs' => ''), array('id' => $this->first_branch_id()));
        $this->db->query('UPDATE hr_settings SET punch_debounce_seconds = 60, device_gap_hours = 12 WHERE id = 1');
        hr_setting_reset_cache();
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TRIGGER IF EXISTS test_attendance_fail');
        self::wipe();
        parent::tearDown();
    }

    /** remove every fixture of the device tests (children first) */
    protected static function wipe()
    {
        $db = get_instance()->db;
        $db->db_debug = FALSE;
        $db->query('DROP TRIGGER IF EXISTS test_attendance_fail');
        foreach (array('attendance', 'attendance_punches', 'device_commands', 'device_logs', 'device_keys', 'devices_pending', 'rate_limits') as $table) {
            $db->query('DELETE FROM ' . $table);
        }
        $db->query('DELETE FROM employee_shifts');
        $db->query("DELETE FROM shifts WHERE shift_code LIKE 'DV%'");
        $db->query('DELETE FROM employee_pin_history');
        $db->query("DELETE FROM employees WHERE employee_code LIKE 'DV-%'");
        $db->query('DELETE FROM devices');
        $db->query("DELETE FROM audit_log WHERE module IN ('devices', 'device_keys', 'attendance', 'employees')");
        $db->query("DELETE FROM audit_log WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'dv\\_%')");
        $db->query('UPDATE users SET employee_id = NULL WHERE username LIKE \'dv\\_%\'');
        $db->query("DELETE FROM users WHERE username LIKE 'dv\\_%'");
        $db->query("DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE role_name LIKE 'role\\_dv\\_%')");
        $db->query("DELETE FROM roles WHERE role_name LIKE 'role\\_dv\\_%'");
        $db->query("DELETE FROM branches WHERE code LIKE 'DVB%'");
        $path = HRM_ROOT . '/uploads/import/';
        foreach ((array) glob($path . '*.json') as $f) {
            @unlink($f);
        }
    }

    /** an API key (plain text returned) for a branch, optionally bound to a device */
    protected function make_key($branch_id = NULL, $device_id = NULL, $label = 'test key')
    {
        $key = Device_auth::generate_key();
        $this->db->insert('device_keys', array('branch_id' => $branch_id !== NULL ? $branch_id : $this->first_branch_id(), 'device_id' => $device_id, 'label' => $label,
            'key_hash' => Device_auth::hash_key($key), 'key_hint' => substr($key, -4), 'created_at' => date('Y-m-d H:i:s')));
        return $key;
    }

    /** POST JSON to the Windows Service endpoint; returns array(status, decoded body) */
    protected function service($key, $payload, array $headers = array())
    {
        $body = is_string($payload) ? $payload : json_encode($payload);
        $headers = array_merge($key === NULL ? array() : array('X-API-Key: ' . $key), $headers);
        list($status, $text) = $this->http->raw('POST', 'api/v1/pull_attendance', $body, $headers);
        return array($status, json_decode($text, TRUE), $text);
    }

    /** an employee of the branch with a device PIN valid since 2024-01-01 */
    protected function dv_employee($code, $pin, $branch_id = NULL)
    {
        $branch_id = $branch_id !== NULL ? $branch_id : $this->first_branch_id();
        $this->db->insert('employees', array('branch_id' => $branch_id, 'employee_code' => 'DV-' . $code, 'first_name' => 'Dev ' . $code, 'employment_type' => 'Permanent',
            'join_date' => '2024-01-01', 'status' => 'Active', 'device_pin' => $pin, 'device_pin_from' => '2024-01-01', 'created_at' => date('Y-m-d H:i:s')));
        return (int) $this->db->insert_id();
    }

    protected function punch_count($where = array())
    {
        return (int) $this->db->where($where)->count_all_results('attendance_punches');
    }

    /** the committed branch row of the second branch (code DVB1) */
    protected function second_branch()
    {
        return $this->insert_branch('DVB1');
    }
}
