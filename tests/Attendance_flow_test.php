<?php
/** Step 4 over HTTP / CLI: cron attendance_close, Excel export (export_hr + audit), calendar events, reports pages. */
class Attendance_flow_test extends Hrm_test_case
{
    protected $use_transaction = FALSE;
    /** @var Http_server */
    protected static $server;
    protected static $roles = array();
    protected static $users = array();

    public static function setUpBeforeClass(): void
    {
        self::$server = new Http_server();
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        $db = get_instance()->db;
        foreach (array('attendance', 'attendance_punches', 'audit_log') as $t) {
            $db->query('DELETE FROM ' . $t);
        }
        $db->query('UPDATE users SET employee_id = NULL WHERE employee_id IS NOT NULL');       // else the employees below are protected by their foreign keys
        $db->query('DELETE FROM employees');
        if (self::$users) {
            $db->where_in('id', self::$users)->delete('users');
        }
        if (self::$roles) {
            $db->where_in('id', self::$roles)->delete('roles');
        }
        $db->empty_table('ci_sessions');
        $db->empty_table('login_attempts');
    }

    protected function make_user($username, array $permissions, $employee_id = NULL)
    {
        $role_id = $this->insert_role('role_' . $username, $permissions);
        self::$roles[] = $role_id;
        self::$users[] = $this->insert_user($username, $role_id, NULL, 'Passw0rd!x', array('employee_id' => $employee_id));
    }

    protected function client_as($username)
    {
        $http = new Http_client(self::$server->port);
        $http->login($username, 'Passw0rd!x');
        return $http;
    }

    protected function emp($code)
    {
        $this->db->insert('employees', array('branch_id' => $this->first_branch_id(), 'employee_code' => $code, 'first_name' => 'E ' . $code,
            'employment_type' => 'Permanent', 'join_date' => '2025-01-01', 'status' => 'Active', 'created_at' => date('Y-m-d H:i:s')));
        return (int) $this->db->insert_id();
    }

    protected function cron(array $args)
    {
        $env = array_filter(array_merge($_SERVER, array('CI_ENV' => 'testing', 'TENANT_DB' => 'hrm_test')), 'is_string');
        $proc = proc_open(array_merge(array(PHP_BINARY, HRM_ROOT . '/index.php', 'cron'), $args), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, HRM_ROOT, $env);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return array($out, proc_close($proc));
    }

    public function test_cron_attendance_close()
    {
        $emp = $this->emp('CR1');
        list($out, $code) = $this->cron(array('attendance_close', '2026-04-06', 'dry-run'));
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('HO 2026-04-06: created Absent=1 (dry run)', $out);
        $this->assertSame(0, $this->db->get_where('attendance', array('employee_id' => $emp))->num_rows());
        list($out, $code) = $this->cron(array('attendance_close', '2026-04-06'));
        $this->assertSame(0, $code, $out);
        $this->assertSame('Absent', $this->db->get_where('attendance', array('employee_id' => $emp))->row()->status);
        list($out) = $this->cron(array('attendance_close', '2026-04-06'));
        $this->assertStringContainsString('created none, already', $out, 'second run fills nothing');
        list($out, $code) = $this->cron(array('attendance_close', '2999-01-01'));
        $this->assertSame(1, $code, 'the future is refused');
        list($out, $code) = $this->cron(array('attendance_close', 'garbage'));
        $this->assertSame(2, $code);
        list(, $status) = array(NULL, (new Http_client(self::$server->port))->get('cron/attendance_close')[0]);
        $this->assertSame(404, $status, 'not over HTTP');
    }

    public function test_reports_and_excel_export_with_audit()
    {
        $emp = $this->emp('XL1');
        $this->db->insert('attendance', array('branch_id' => $this->first_branch_id(), 'employee_id' => $emp, 'attendance_date' => '2026-04-07', 'status' => 'Present',
            'check_in' => '09:20:00', 'check_out' => '18:00:00', 'late_minutes' => 20, 'working_hours' => 8.67, 'approval_status' => 'Approved', 'source' => 'punch', 'created_at' => date('Y-m-d H:i:s')));
        $this->make_user('xl_viewer', array('attendance_report', 'show_all_attendance'));
        $this->make_user('xl_exporter', array('attendance_report', 'show_all_attendance', 'export_hr'));
        $this->make_user('xl_export_only', array('export_hr'));

        $viewer = $this->client_as('xl_viewer');
        list($status, $page) = $viewer->get('reports/monthly?month=4&year=2026');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('att-late', $page);
        $this->assertStringNotContainsString('monthly-xlsx', $page, 'no export button without export_hr');
        list($status) = $viewer->get('reports/monthly_xlsx?month=4&year=2026');
        $this->assertSame(403, $status);
        list($status, $page) = $viewer->get('reports/daily?date=07-04-2026');
        $this->assertStringContainsString('XL1', $page);

        list($status) = $this->client_as('xl_export_only')->get('reports/daily_xlsx?date=07-04-2026');
        $this->assertSame(403, $status, 'export_hr alone does not open attendance reports');

        list($status, $bytes, $headers) = $this->client_as('xl_exporter')->get('reports/monthly_xlsx?month=4&year=2026');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('spreadsheetml', $headers);
        $file = HRM_ROOT . '/tests/_output/export.xlsx';
        file_put_contents($file, $bytes);
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet()->toArray();
        @unlink($file);
        $line = NULL;
        foreach ($sheet as $r) {
            if ($r[0] === 'XL1') {
                $line = $r;
            }
        }
        $this->assertNotNull($line);
        $this->assertSame('P', $line[2 + 6], '7 April');
        $audit = $this->db->get_where('audit_log', array('module' => 'reports', 'action' => 'export'))->row_array();
        $this->assertNotEmpty($audit, 'every export is audited');
        $this->assertSame('monthly', json_decode($audit['new_values'], TRUE)['report']);
    }

    public function test_calendar_events()
    {
        $emp = $this->emp('CA1');
        $this->db->insert('attendance', array('branch_id' => $this->first_branch_id(), 'employee_id' => $emp, 'attendance_date' => '2026-04-08', 'status' => 'Present',
            'check_in' => '09:00:00', 'check_out' => '18:00:00', 'late_minutes' => 0, 'approval_status' => 'Pending', 'source' => 'self', 'created_at' => date('Y-m-d H:i:s')));
        $this->make_user('cal_user', array('attendance_view'), $emp);
        list(, $body) = $this->client_as('cal_user')->post('attendance/calendar_events/' . $emp, array('start' => '2026-04-01', 'end' => '2026-04-30'), array('X-Requested-With: XMLHttpRequest'));
        $events = json_decode($body, TRUE);
        $titles = array_column($events, 'title', 'start');
        $this->assertStringContainsString('09:00-18:00', $titles['2026-04-08']);
        $this->assertStringContainsString('Pending', $titles['2026-04-08']);
    }
}
