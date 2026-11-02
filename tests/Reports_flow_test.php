<?php
/** The attendance reports over HTTP: permissions, content, validation, scope (own / department head) and audited Excel exports. */
class Reports_flow_test extends Hrm_test_case
{
    protected $use_transaction = FALSE;
    /** @var Http_server */
    protected static $server;
    protected static $roles = array();
    protected static $users = array();

    const PAGES = array('inout', 'employee', 'summary', 'late', 'absent', 'overtime', 'missing');

    public static function setUpBeforeClass(): void
    {
        self::$server = new Http_server();
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        self::wipe();
        $db = get_instance()->db;
        $db->query('UPDATE users SET employee_id = NULL WHERE employee_id IS NOT NULL');
        if (self::$users) {
            $db->where_in('id', self::$users)->delete('users');
        }
        if (self::$roles) {
            $db->where_in('role_id', self::$roles)->delete('role_permissions');
            $db->where_in('id', self::$roles)->delete('roles');
        }
        $db->empty_table('ci_sessions');
        $db->empty_table('login_attempts');
    }

    protected static function wipe()
    {
        $db = get_instance()->db;
        foreach (array('attendance', 'attendance_punches', 'audit_log') as $t) {
            $db->query('DELETE FROM ' . $t);
        }
        $db->query('UPDATE departments SET head_employee_id = NULL');
        $db->query('DELETE FROM employees');
        $db->query('DELETE FROM departments');
    }

    protected function tearDown(): void
    {
        self::wipe();
        $this->db->query('UPDATE users SET employee_id = NULL WHERE employee_id IS NOT NULL');
        if (self::$users) {
            $this->db->where_in('id', self::$users)->delete('users');
        }
        if (self::$roles) {
            $this->db->where_in('role_id', self::$roles)->delete('role_permissions');
            $this->db->where_in('id', self::$roles)->delete('roles');
        }
        self::$users = array();
        self::$roles = array();
        parent::tearDown();
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

    protected function emp($code, array $extra = array())
    {
        $this->db->insert('employees', array_merge(array('branch_id' => $this->first_branch_id(), 'employee_code' => $code, 'first_name' => 'Emp', 'last_name' => $code,
            'employment_type' => 'Permanent', 'join_date' => '2026-01-01', 'status' => 'Active', 'created_at' => date('Y-m-d H:i:s')), $extra));
        return (int) $this->db->insert_id();
    }

    protected function att($employee, $date, $status, array $extra = array())
    {
        $this->db->insert('attendance', array_merge(array('branch_id' => $this->first_branch_id(), 'employee_id' => $employee, 'attendance_date' => $date, 'status' => $status,
            'approval_status' => 'Approved', 'source' => 'punch', 'created_at' => date('Y-m-d H:i:s')), $extra));
        return (int) $this->db->insert_id();
    }

    /** two people over 6-9 April 2026 */
    protected function seed()
    {
        $a = $this->emp('RF1');
        $b = $this->emp('RF2');
        $this->att($a, '2026-04-06', 'Present', array('check_in' => '09:20:00', 'check_out' => '18:00:00', 'late_minutes' => 20, 'working_hours' => 8.67, 'overtime_hours' => 0.5, 'break_minutes' => 45));
        $this->att($a, '2026-04-07', 'Present', array('check_in' => '08:55:00', 'check_out' => NULL, 'working_hours' => 0));
        $this->att($a, '2026-04-08', 'Absent');
        $this->att($a, '2026-04-09', 'Absent');
        $this->att($b, '2026-04-06', 'Present', array('check_in' => '09:00:00', 'check_out' => '18:30:00', 'working_hours' => 9.5, 'overtime_hours' => 1.5));
        $this->att($b, '2026-04-07', 'Leave');
        return array($a, $b);
    }

    protected function sheet($bytes)
    {
        $file = HRM_ROOT . '/tests/_output/rep_export.xlsx';
        file_put_contents($file, $bytes);
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet()->toArray();
        @unlink($file);
        return $rows;
    }

    public function test_every_report_needs_attendance_report_and_every_export_needs_export_hr_too()
    {
        list($a) = $this->seed();
        $this->make_user('rf_nobody', array('employees_view'));
        $this->make_user('rf_viewer', array('attendance_report', 'show_all_attendance'));
        $this->make_user('rf_export_only', array('export_hr'));
        $nobody = $this->client_as('rf_nobody');
        $viewer = $this->client_as('rf_viewer');
        $export_only = $this->client_as('rf_export_only');
        $q = '?from_date=06-04-2026&to_date=09-04-2026&employee_id=' . $a . '&mode=range&month=4&year=2026';
        foreach (self::PAGES as $page) {
            list($status) = $nobody->get('reports/' . $page . $q);
            $this->assertSame(403, $status, $page . ' without attendance_report');
            list($status) = $viewer->get('reports/' . $page . $q);
            $this->assertSame(200, $status, $page . ' with attendance_report');
            list($status) = $viewer->get('reports/' . $page . '_xlsx' . $q);
            $this->assertSame(403, $status, $page . '_xlsx without export_hr');
            list($status) = $export_only->get('reports/' . $page . '_xlsx' . $q);
            $this->assertSame(403, $status, $page . '_xlsx: export_hr alone does not open the report');
            list(, $html) = $viewer->get('reports/' . $page . $q);
            $this->assertStringNotContainsString($page . '-xlsx', $html, 'no Excel button without export_hr');
        }
    }

    public function test_the_in_out_sheet_shows_times_late_and_missing_check_outs()
    {
        $this->seed();
        $this->make_user('rf_viewer', array('attendance_report', 'show_all_attendance'));
        list(, $html) = $this->client_as('rf_viewer')->get('reports/inout?month=4&year=2026');
        $this->assertStringContainsString('09:20', $html);
        $this->assertStringContainsString('18:30', $html);
        $this->assertStringContainsString('io-missing', $html, 'a day with no check-out is marked');
        $this->assertStringContainsString('att-late', $html);
        $this->assertStringContainsString('att-a', $html, 'absent days keep their letter');
        $this->assertStringContainsString('reports/employee?employee_id=', $html, 'a name opens that person\'s report');
    }

    public function test_the_employee_report_by_month_and_by_date_range()
    {
        list($a) = $this->seed();
        $this->make_user('rf_viewer', array('attendance_report', 'show_all_attendance'));
        $c = $this->client_as('rf_viewer');
        list(, $html) = $c->get('reports/employee');
        $this->assertStringContainsString('r_employee', $html, 'the picker is shown first');
        $this->assertStringNotContainsString('employee-table', $html);

        list($status, $html) = $c->get('reports/employee?employee_id=' . $a . '&mode=month&month=4&year=2026');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('employee-table', $html);
        $this->assertSame(30, substr_count($html, 'class="punch-times"'), 'one row per day of April');
        $this->assertStringContainsString('09:20', $html);
        $this->assertStringContainsString('Weekly off', $html, 'days without a row come from the plan');
        $this->assertStringContainsString('insights', $html);

        list(, $html) = $c->get('reports/employee?employee_id=' . $a . '&mode=range&from_date=06-04-2026&to_date=09-04-2026');
        $this->assertSame(4, substr_count($html, 'class="punch-times"'), 'four days');
        $this->assertStringContainsString('insight-warning', $html);
        $this->assertStringContainsString('Late on 1 day(s), 20 minutes', $html);
        $this->assertStringContainsString('Longest absence in a row: 2 working day(s)', $html);
    }

    public function test_validation_never_breaks_a_report()
    {
        list($a) = $this->seed();
        $this->make_user('rf_viewer', array('attendance_report', 'show_all_attendance'));
        $c = $this->client_as('rf_viewer');
        list($status, $html) = $c->get('reports/summary?from_date=01-01-2026&to_date=31-12-2026');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('92', $html, 'a range over 92 days is explained');
        list($status, $html) = $c->get('reports/late?from_date=09-04-2026&to_date=01-04-2026');
        $this->assertSame(200, $status);
        $this->assertStringContainsString(t('audit_range_order'), $html);
        list($status) = $c->get('reports/summary?from_date=garbage&department_id=99999');
        $this->assertSame(200, $status);
        list($status, $html) = $c->get('reports/employee?employee_id=999999&mode=month&month=4&year=2026');
        $this->assertStringNotContainsString('employee-table', $html, 'an unknown employee shows no data');
        list($status, $html) = $c->get('reports/employee?employee_id=' . $a . '&mode=range&from_date=01-01-2025&to_date=31-12-2026');
        $this->assertStringContainsString('366', $html);
        $this->assertStringNotContainsString('employee-table', $html);
        list($status) = $c->get('reports/inout?month=13&year=2026');
        $this->assertSame(200, $status, 'a bad month falls back to the current one');
    }

    public function test_summary_late_absent_overtime_and_missing_content()
    {
        $this->seed();
        $this->make_user('rf_viewer', array('attendance_report', 'show_all_attendance', 'attendance_edit'));
        $c = $this->client_as('rf_viewer');
        $q = '?from_date=06-04-2026&to_date=09-04-2026';
        list(, $html) = $c->get('reports/summary' . $q);
        $this->assertStringContainsString('trend-chart', $html);
        $this->assertStringContainsString('Overall attendance is', $html);
        $this->assertStringContainsString('Most absent days: Emp RF1', $html);
        $this->assertStringContainsString('Chart.min.js', $html, 'the chart library loads on this page only');
        list(, $html) = $c->get('reports/late' . $q);
        $this->assertStringContainsString('late-ranking', $html);
        $this->assertStringContainsString('Emp RF1', $html);
        list(, $html) = $c->get('reports/late' . $q . '&min_minutes=30');
        $this->assertStringContainsString(t('rep_nothing'), $html, 'a 20 minute delay is under the 30 minute filter');
        list(, $html) = $c->get('reports/absent' . $q);
        $this->assertStringContainsString('08-04-2026', $html);
        $this->assertStringContainsString('09-04-2026', $html);
        list(, $html) = $c->get('reports/overtime' . $q);
        $this->assertStringContainsString('ot-ranking', $html);
        $this->assertStringContainsString('1.50', $html);
        list(, $html) = $c->get('reports/missing' . $q);
        $this->assertStringContainsString('07-04-2026', $html);
        $this->assertStringContainsString('attendance/edit/', $html, 'with attendance_edit: a link to fix it');
        list(, $html) = $this->client_as('rf_viewer')->get('reports/missing' . $q);
        $this->assertStringNotContainsString('06-04-2026 Mon', $html, 'a complete day is not listed');
    }

    public function test_excel_exports_are_audited_and_hold_the_same_numbers()
    {
        list($a) = $this->seed();
        $this->make_user('rf_exporter', array('attendance_report', 'show_all_attendance', 'export_hr'));
        $c = $this->client_as('rf_exporter');
        $period = '?from_date=06-04-2026&to_date=09-04-2026';

        list($status, $bytes, $headers) = $c->get('reports/inout_xlsx?month=4&year=2026');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('spreadsheetml', $headers);
        $line = NULL;
        foreach ($this->sheet($bytes) as $r) {
            $line = $r[0] === 'RF1' ? $r : $line;
        }
        $this->assertSame('09:20-18:00', $line[2 + 5], '6 April');
        $this->assertSame('08:55-?', $line[2 + 6], '7 April: no check-out');
        $this->assertSame('A', $line[2 + 7]);

        list(, $bytes) = $c->get('reports/employee_xlsx?employee_id=' . $a . '&mode=range&from_date=06-04-2026&to_date=09-04-2026');
        $rows = $this->sheet($bytes);
        $this->assertCount(5, $rows, 'header + 4 days');
        $this->assertSame('Present', $rows[1][3]);
        $this->assertSame('09:20', $rows[1][4]);
        $this->assertEquals(45, $rows[1][6]);
        $this->assertSame('Absent', $rows[3][3]);

        list(, $bytes) = $c->get('reports/summary_xlsx' . $period);
        $this->assertCount(3, $this->sheet($bytes));
        list(, $bytes) = $c->get('reports/late_xlsx' . $period);
        $this->assertCount(2, $this->sheet($bytes), 'header + the one late arrival');
        list(, $bytes) = $c->get('reports/absent_xlsx' . $period);
        $this->assertStringContainsString('08-04-2026', $this->sheet($bytes)[1][5]);
        list(, $bytes) = $c->get('reports/overtime_xlsx' . $period);
        $this->assertCount(3, $this->sheet($bytes));
        list(, $bytes) = $c->get('reports/missing_xlsx' . $period);
        $this->assertCount(2, $this->sheet($bytes));

        $audit = $this->db->get_where('audit_log', array('module' => 'reports', 'action' => 'export'))->result_array();
        $this->assertCount(7, $audit, 'every export is audited');
        $this->assertEqualsCanonicalizing(array('inout', 'employee', 'summary', 'late', 'absent', 'overtime', 'missing'), array_map(function ($r) { return json_decode($r['new_values'], TRUE)['report']; }, $audit));
        list($status) = $c->get('reports/summary_xlsx?from_date=01-01-2026&to_date=31-12-2026');
        $this->assertSame(422, $status, 'an export of a range that is too long is refused, not run');
        list($status) = $c->get('reports/employee_xlsx');
        $this->assertSame(422, $status);
    }

    public function test_scope_own_records_and_department_head()
    {
        $dept = $this->db->insert('departments', array('branch_id' => $this->first_branch_id(), 'department_name' => 'Cutting', 'department_code' => 'CUTT', 'status' => 'Active', 'created_at' => date('Y-m-d H:i:s'))) ? (int) $this->db->insert_id() : 0;
        $head = $this->emp('HD1', array('department_id' => $dept));
        $this->db->update('departments', array('head_employee_id' => $head), array('id' => $dept));
        $team = $this->emp('TM1', array('department_id' => $dept));
        $other = $this->emp('OT1');
        foreach (array($head, $team, $other) as $id) {
            $this->att($id, '2026-04-06', 'Present', array('check_in' => '09:00:00', 'check_out' => '18:00:00'));
        }
        $this->make_user('rf_head', array('attendance_report'), $head);
        $this->make_user('rf_self', array('attendance_report'), $other);
        $q = '&mode=month&month=4&year=2026';

        $h = $this->client_as('rf_head');
        list($status) = $h->get('reports/employee?employee_id=' . $team . $q);
        $this->assertSame(200, $status, 'a department head reports on the team');
        list($status) = $h->get('reports/employee?employee_id=' . $other . $q);
        $this->assertSame(403, $status, 'but not on other departments');
        list(, $html) = $h->get('reports/inout?month=4&year=2026');
        $this->assertStringContainsString('TM1', $html);
        $this->assertStringNotContainsString('OT1', $html);

        $s = $this->client_as('rf_self');
        list($status) = $s->get('reports/employee?employee_id=' . $other . $q);
        $this->assertSame(200, $status, 'yourself');
        list($status) = $s->get('reports/employee?employee_id=' . $team . $q);
        $this->assertSame(403, $status);
        list(, $html) = $s->get('reports/employee');
        $this->assertStringNotContainsString('TM1', $html, 'the picker lists only people you may see');
        list(, $html) = $s->get('reports/summary?from_date=01-04-2026&to_date=30-04-2026');
        $this->assertStringNotContainsString('TM1', $html);
    }

    public function test_the_menu_has_a_reports_group_and_pages_carry_the_report_switcher()
    {
        $this->seed();
        $this->make_user('rf_viewer', array('attendance_report', 'show_all_attendance'));
        list(, $html) = $this->client_as('rf_viewer')->get('reports/summary');
        foreach (array('reports/inout', 'reports/employee', 'reports/late', 'reports/absent', 'reports/overtime', 'reports/missing', 'reports/daily', 'reports/monthly') as $link) {
            $this->assertStringContainsString($link, $html, $link);
        }
        $this->assertStringContainsString('report-nav', $html);
    }
}
