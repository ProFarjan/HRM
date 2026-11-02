<?php
/**
 * P14: no "manager" by inference. Holding attendance_edit + attendance_delete does not allow marking others, bulk marking or
 * approving; attendance_mark_self gives a Pending row of your own day only; attendance_add marks anybody as Approved;
 * without show_all_attendance the list and calendar show your own rows (and a department head's department).
 */
class Attendance_permission_test extends Hrm_test_case
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
        $db->query('UPDATE users SET employee_id = NULL');
        foreach (array('attendance', 'attendance_punches', 'audit_log') as $t) {
            $db->query('DELETE FROM ' . $t);
        }
        $db->query('UPDATE departments SET head_employee_id = NULL');
        $db->query('DELETE FROM employees');
        $db->query('DELETE FROM departments');
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
        $id = $this->insert_user($username, $role_id, NULL, 'Passw0rd!x', array('employee_id' => $employee_id));
        self::$users[] = $id;
        return $id;
    }

    protected function client_as($username)
    {
        $http = new Http_client(self::$server->port);
        $http->login($username, 'Passw0rd!x');
        return $http;
    }

    protected function emp($code, array $extra = array())
    {
        $this->db->insert('employees', array_merge(array('branch_id' => $this->first_branch_id(), 'employee_code' => $code, 'first_name' => 'E ' . $code,
            'employment_type' => 'Permanent', 'join_date' => '2025-01-01', 'status' => 'Active', 'created_at' => date('Y-m-d H:i:s')), $extra));
        return (int) $this->db->insert_id();
    }

    public function test_edit_and_delete_do_not_make_a_manager()
    {
        $other = $this->emp('PX1');
        $this->make_user('pm_editor', array('attendance_view', 'attendance_edit', 'attendance_delete'));
        $http = $this->client_as('pm_editor');
        $day = date('d-m-Y', strtotime('-1 day'));
        foreach (array(
            'attendance/save_mark' => array('employee_id' => (string) $other, 'attendance_date' => $day, 'status' => 'Present', 'check_in' => '09:00'),
            'attendance/add_punch' => array('employee_id' => (string) $other, 'punch_date' => $day, 'punch_time' => '09:00', 'punch_state' => '0'),
            'attendance/save_bulk' => array('attendance_date' => $day, 'status' => 'Present', 'check_in' => '09:00'),
            'attendance/save_my_day' => array('check_in' => '09:00'),
        ) as $url => $fields) {
            list($status) = $http->post($url, $fields);
            $this->assertSame(403, $status, $url);
        }
        $this->assertSame(0, $this->db->count_all('attendance'));
        $this->assertSame(0, $this->db->count_all('attendance_punches'));
    }

    public function test_approval_needs_attendance_approve()
    {
        $emp = $this->emp('PX2');
        $this->db->insert('attendance', array('branch_id' => $this->first_branch_id(), 'employee_id' => $emp, 'attendance_date' => date('Y-m-d', strtotime('-2 days')),
            'status' => 'Present', 'check_in' => '09:00:00', 'approval_status' => 'Pending', 'source' => 'self', 'created_at' => date('Y-m-d H:i:s')));
        $id = (int) $this->db->insert_id();
        $this->make_user('pm_almost', array('attendance_view', 'attendance_add', 'attendance_edit', 'attendance_delete', 'show_all_attendance', 'attendance_bulk'));
        list($status) = $this->client_as('pm_almost')->post('attendance/approve/' . $id);
        $this->assertSame(403, $status);
        $this->assertSame('Pending', $this->db->get_where('attendance', array('id' => $id))->row()->approval_status);
        $this->make_user('pm_approver', array('attendance_approve', 'show_all_attendance'));     // approving another's row needs to see it
        list(, $body) = $this->client_as('pm_approver')->post('attendance/approve/' . $id);
        $this->assertSame('success', $body);
    }

    public function test_mark_self_is_pending_and_attendance_add_is_approved()
    {
        $mine = $this->emp('PX3');
        $other = $this->emp('PX4');
        $this->make_user('pm_self', array('attendance_mark_self'), $mine);
        list(, $body) = $this->client_as('pm_self')->post('attendance/save_my_day', array('check_in' => '00:01', 'employee_id' => (string) $other));
        $this->assertStringStartsWith('success', $body);
        $row = $this->db->get_where('attendance', array('employee_id' => $mine))->row_array();
        $this->assertSame('Pending', $row['approval_status']);
        $this->assertSame(0, $this->db->get_where('attendance', array('employee_id' => $other))->num_rows(), 'the posted employee_id is ignored');

        $this->make_user('pm_adder', array('attendance_add'));
        list(, $body) = $this->client_as('pm_adder')->post('attendance/save_mark', array('employee_id' => (string) $other, 'attendance_date' => date('d-m-Y', strtotime('-1 day')),
            'status' => 'Present', 'check_in' => '09:00'));
        $this->assertStringStartsWith('success', $body);
        $this->assertSame('Approved', $this->db->get_where('attendance', array('employee_id' => $other))->row()->approval_status);
    }

    public function test_own_records_only_without_show_all_attendance()
    {
        $dept_id = NULL;
        $this->db->insert('departments', array('branch_id' => $this->first_branch_id(), 'department_name' => 'QC', 'department_code' => 'QCP', 'status' => 'Active', 'created_at' => date('Y-m-d H:i:s')));
        $dept_id = (int) $this->db->insert_id();
        $head = $this->emp('HD1', array('department_id' => $dept_id));
        $member = $this->emp('MB1', array('department_id' => $dept_id));
        $outsider = $this->emp('OS1');
        $this->db->update('departments', array('head_employee_id' => $head), array('id' => $dept_id));
        $date = date('Y-m-d', strtotime('-3 days'));
        foreach (array($head, $member, $outsider) as $e) {
            $this->db->insert('attendance', array('branch_id' => $this->first_branch_id(), 'employee_id' => $e, 'attendance_date' => $date, 'status' => 'Present',
                'check_in' => '09:00:00', 'approval_status' => 'Approved', 'source' => 'punch', 'created_at' => date('Y-m-d H:i:s')));
        }
        $outsider_row = (int) $this->db->insert_id();
        $this->make_user('pm_head', array('attendance_view', 'attendance_edit'), $head);
        $http = $this->client_as('pm_head');
        list(, $body) = $http->post('attendance/ajax_list', array('draw' => '1', 'from_date' => date('d-m-Y', strtotime('-7 days')), 'to_date' => date('d-m-Y')));
        $codes = array_column(json_decode($body, TRUE)['data'], 'code');
        sort($codes);
        $this->assertSame(array('HD1', 'MB1'), $codes, 'own + the department they head');
        list($status) = $http->get('attendance/edit/' . $outsider_row);
        $this->assertSame(403, $status, 'by id too');
        list($status) = $http->get('attendance/calendar/' . $outsider);
        $this->assertSame(403, $status);
        list($status) = $http->get('attendance/calendar/' . $member);
        $this->assertSame(200, $status);
        list($status) = $http->get('attendance/calendar');
        $this->assertSame(200, $status, 'own calendar');
    }
}
