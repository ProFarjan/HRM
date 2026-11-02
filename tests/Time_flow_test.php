<?php
/** Step 3 over HTTP: roster grid + save, xlsx/csv import (all-or-nothing), permission split (view vs schedule), default shift setting. */
class Time_flow_test extends Hrm_test_case
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
        $db->query('UPDATE hr_settings SET default_shift_id = NULL WHERE id = 1');
        foreach (array('shift_rosters', 'employee_shifts', 'holidays', 'audit_log') as $t) {
            $db->query('DELETE FROM ' . $t);
        }
        $db->query('DELETE FROM employees');
        $db->query('DELETE FROM shifts');
        if (self::$users) {
            $db->where_in('id', self::$users)->delete('users');
        }
        if (self::$roles) {
            $db->where_in('id', self::$roles)->delete('roles');
        }
        $db->empty_table('ci_sessions');
        $db->empty_table('login_attempts');
    }

    protected function make_user($username, array $permissions)
    {
        $role_id = $this->insert_role('role_' . $username, $permissions);
        self::$roles[] = $role_id;
        self::$users[] = $this->insert_user($username, $role_id, NULL, 'Passw0rd!x');
    }

    protected function client_as($username)
    {
        $http = new Http_client(self::$server->port);
        $http->login($username, 'Passw0rd!x');
        return $http;
    }

    protected function shift($code)
    {
        $this->db->insert('shifts', array('branch_id' => $this->first_branch_id(), 'shift_name' => 'Shift ' . $code, 'shift_code' => $code, 'start_time' => '08:00:00',
            'end_time' => '17:00:00', 'status' => 'Active', 'created_at' => date('Y-m-d H:i:s')));
        return (int) $this->db->insert_id();
    }

    protected function employee($code)
    {
        $this->db->insert('employees', array('branch_id' => $this->first_branch_id(), 'employee_code' => $code, 'first_name' => 'Worker ' . $code,
            'employment_type' => 'Permanent', 'join_date' => '2025-01-01', 'status' => 'Active', 'created_at' => date('Y-m-d H:i:s')));
        return (int) $this->db->insert_id();
    }

    public function test_roster_grid_save_and_import()
    {
        $this->make_user('tf_sched', array('rosters_manage', 'shifts_view'));
        $mor = $this->shift('MOR');
        $e1 = $this->employee('T1');
        $this->employee('T2');
        $http = $this->client_as('tf_sched');

        list($status, $body) = $http->post('rosters/save', array('cells' => array("$e1|2026-05-04|$mor", "$e1|2026-05-05|OFF")), array('X-Requested-With: XMLHttpRequest'));
        $this->assertSame('success<<<###>>>2', $body, (string) $status);
        list(, $body) = $http->post('rosters/grid', array('from_date' => '04-05-2026', 'view' => 'week'));
        $grid = json_decode($body, TRUE);
        $this->assertCount(7, $grid['days']);
        $row = $grid['rows'][array_search('T1', array_column($grid['rows'], 'code'), TRUE)];
        $this->assertSame((string) $mor, $row['cells']['2026-05-04']['value']);
        $this->assertSame('OFF', $row['cells']['2026-05-05']['value']);
        $this->assertSame('weekly_off', $row['cells']['2026-05-05']['type']);
        list(, $body) = $http->post('rosters/grid', array('from_date' => '15-02-2026', 'view' => 'month'));
        $this->assertCount(28, json_decode($body, TRUE)['days']);

        $dir = HRM_ROOT . '/tests/_output/';
        // csv with one bad row: nothing is written
        file_put_contents($dir . 'roster.csv', "employee_code,date,shift\nT2,11-05-2026,MOR\nT9,12-05-2026,MOR\nT2,31-02-2026,OFF\nT2,13-05-2026,NIGHT\n");
        list(, $body) = $http->post_multipart('rosters/import', array(), array('roster_file' => $dir . 'roster.csv'));
        $this->assertStringContainsString('Row 3', $body);
        $this->assertStringContainsString('Row 4', $body);
        $this->assertStringContainsString('Row 5', $body);
        $this->assertSame(0, $this->db->query("SELECT COUNT(*) AS c FROM shift_rosters WHERE roster_date >= '2026-05-11'")->row()->c + 0);

        // xlsx with an Excel date, a UI date, OFF and a clear
        $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $sheet->getActiveSheet();
        $ws->fromArray(array(array('employee_code', 'date', 'shift'), array('t2', NULL, 'mor'), array('T2', '12-05-2026', 'off'), array('T1', '04-05-2026', '')), NULL, 'A1');
        $ws->setCellValue('B2', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTime('2026-05-11')));
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($dir . 'roster.xlsx');
        list(, $body) = $http->post_multipart('rosters/import', array(), array('roster_file' => $dir . 'roster.xlsx'));
        $this->assertSame('success<<<###>>>3', $body);
        $t2 = (int) $this->db->get_where('employees', array('employee_code' => 'T2'))->row()->id;
        $this->assertSame((string) $mor, $this->db->get_where('shift_rosters', array('employee_id' => $t2, 'roster_date' => '2026-05-11'))->row()->shift_id);
        $this->assertSame('0', $this->db->get_where('shift_rosters', array('employee_id' => $t2, 'roster_date' => '2026-05-12'))->row()->is_working_day);
        $this->assertSame(0, $this->db->get_where('shift_rosters', array('employee_id' => $e1, 'roster_date' => '2026-05-04'))->num_rows(), 'empty cell cleared');

        file_put_contents($dir . 'roster.xlsx.txt', 'just text');
        rename($dir . 'roster.xlsx.txt', $dir . 'fake.xlsx');
        list(, $body) = $http->post_multipart('rosters/import', array(), array('roster_file' => $dir . 'fake.xlsx'));
        $this->assertStringContainsString('.xlsx or .csv', $body);
        foreach (array('roster.csv', 'roster.xlsx', 'fake.xlsx') as $f) {
            @unlink($dir . $f);
        }
    }

    public function test_viewing_shifts_is_not_scheduling_people()
    {
        $this->make_user('tf_viewer', array('shifts_view', 'holidays_view'));
        $shift = $this->shift('VW');
        $emp = $this->employee('V1');
        $http = $this->client_as('tf_viewer');
        list($status) = $http->get('employee_shifts');
        $this->assertSame(200, $status);
        list($status) = $http->get('employee_shifts/employee/' . $emp);
        $this->assertSame(200, $status);
        foreach (array('employee_shifts/assign/' . $emp, 'employee_shifts/bulk', 'rosters/save', 'rosters/grid', 'holidays/save', 'holidays/copy_year') as $url) {
            list($status) = $http->post($url, array('shift_id' => (string) $shift, 'effective_from' => '01-06-2026'));
            $this->assertSame(403, $status, $url);
        }
        list($status) = $http->get('rosters');
        $this->assertSame(403, $status);
        list($status) = $http->get('holidays');
        $this->assertSame(200, $status);
        $this->assertSame(0, $this->db->get_where('employee_shifts', array('employee_id' => $emp))->num_rows());
    }

    public function test_default_shift_setting()
    {
        $this->make_user('tf_settings', array('settings_edit'));
        $shift = $this->shift('DFT');
        $this->db->update('shifts', array('status' => 'Inactive'), array('shift_code' => 'MOR'));
        $inactive = (int) $this->db->get_where('shifts', array('shift_code' => 'MOR'))->row()->id;
        $http = $this->client_as('tf_settings');
        $this->CI->load->model('Settings_model');
        hr_setting_reset_cache();
        $form = settings_form($this->CI->Settings_model->current());
        list(, $body) = $http->post_multipart('settings/save', array_merge($form, array('default_shift_id' => (string) $inactive)));
        $this->assertStringContainsString('default shift', $body);
        list(, $body) = $http->post_multipart('settings/save', array_merge($form, array('default_shift_id' => (string) $shift)));
        $this->assertSame('success', $body);
        $this->assertSame((string) $shift, $this->db->get_where('hr_settings', array('id' => 1))->row()->default_shift_id);
        list(, $page) = $http->get('settings');
        $this->assertMatchesRegularExpression('/<option value="' . $shift . '" selected>/', $page);
    }
}
