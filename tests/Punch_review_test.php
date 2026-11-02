<?php
/**
 * Unmatched punches (P5: the PIN is linked, then every stored punch is processed) and punches for closed days (P16: a late upload
 * never changes a closed day, but HR can see it).
 */
class Punch_review_test extends Device_test_case
{
    protected function make_user($username, array $permissions)
    {
        $role = $this->insert_role('role_' . $username, $permissions);
        $this->insert_user($username, $role);
        $client = new Http_client(self::$server->port);
        $client->login($username, 'Passw0rd!x');
        return $client;
    }

    protected function table(Http_client $c, $url, array $extra = array())
    {
        list($status, $body) = $c->post($url, array_merge(array('draw' => '1', 'start' => '0', 'length' => '50'), $extra), array('X-Requested-With: XMLHttpRequest'));
        return array($status, json_decode($body, TRUE), $body);
    }

    protected function ajax(Http_client $c, $url, array $post = array())
    {
        return $c->post($url, $post, array('X-Requested-With: XMLHttpRequest'));
    }

    /** punches through the real ingestor, as a device would deliver them */
    protected function deliver(array $rows, $device_id = NULL)
    {
        $device_id = $device_id !== NULL ? $device_id : $this->insert_device('Gate ' . count($this->db->get('devices')->result_array()));
        $punches = array();
        foreach ($rows as $row) {
            $punches[] = array('device_pin' => $row[0], 'punch_at' => $row[1], 'punch_state' => isset($row[2]) ? $row[2] : 255);
        }
        $r = (new Punch_ingestor())->ingest($punches, array('branch_id' => $this->first_branch_id(), 'device_id' => $device_id, 'source' => 'device_service'));
        $this->assertTrue($r['ok'], $r['message']);
        return $device_id;
    }

    public function test_the_unmatched_list_groups_by_pin()
    {
        $this->dv_employee('K', '100');
        set_current_branch_id($this->first_branch_id());
        $d = $this->deliver(array(array('100', '2026-01-20 08:00:00'), array('777', '2026-01-20 08:01:00'), array('777', '2026-01-20 17:01:00'), array('888', '2026-01-21 09:00:00'), array('777', '2026-01-21 09:00:20')));
        $hr = $this->make_user('dv_review', array('attendance_punches'));
        list($status, $json) = $this->table($hr, 'attendance/unmatched_list', array('from_date' => '01-01-2026', 'to_date' => '31-01-2026'));
        $this->assertSame(200, $status);
        $this->assertSame(2, $json['recordsFiltered'], 'PINs 777 and 888; 100 belongs to an employee');
        $by = array();
        foreach ($json['data'] as $r) {
            $by[$r['raw_pin']] = $r;
        }
        $this->assertSame(3, $by['777']['count']);
        $this->assertSame(1, $by['888']['count']);
        $this->assertStringContainsString('Gate', $by['777']['devices']);

        list(, $json) = $this->table($hr, 'attendance/unmatched_list', array('from_date' => '01-03-2026', 'to_date' => '31-03-2026'));
        $this->assertSame(0, $json['recordsFiltered'], 'the date range applies');
        list($status, $body) = $hr->post('attendance/unmatched_list', array('from_date' => '01-01-2026', 'to_date' => '01-12-2026'), array('X-Requested-With: XMLHttpRequest'));
        $this->assertSame(422, $status, 'at most 92 days');
    }

    public function test_linking_a_pin_gives_the_punches_their_owner_and_builds_the_days()
    {
        $emp = $this->dv_employee('L', NULL);
        $this->db->update('employees', array('join_date' => '2026-01-21'), array('id' => $emp));
        set_current_branch_id($this->first_branch_id());
        $this->deliver(array(array('777', '2026-01-20 09:00:00'), array('777', '2026-01-21 09:00:00'), array('777', '2026-01-21 18:00:00')));
        $this->assertSame(3, $this->punch_count(array('employee_id' => NULL)));
        $hr = $this->make_user('dv_link', array('attendance_punches', 'employees_edit'));

        list(, $body) = $this->ajax($hr, 'attendance/link_pin', array('device_pin' => '777', 'employee_id' => (string) $emp));
        $this->assertSame('success<<<###>>>2', $body, 'two punches linked; the one before the join date stays unmatched');
        $e = $this->db->get_where('employees', array('id' => $emp))->row_array();
        $this->assertSame(array('777', '2026-01-21'), array($e['device_pin'], $e['device_pin_from']), 'the PIN starts at the join date (later than the first punch)');
        $this->assertSame(1, $this->punch_count(array('employee_id' => NULL)));
        $day = $this->db->get_where('attendance', array('employee_id' => $emp, 'attendance_date' => '2026-01-21'))->row_array();
        $this->assertSame(array('09:00:00', '18:00:00'), array($day['check_in'], $day['check_out']));
        $this->assertNull($this->db->get_where('attendance', array('employee_id' => $emp, 'attendance_date' => '2026-01-20'))->row_array());
        $this->assertCount(1, $this->audit_rows('attendance', $emp, 'link_pin'));
        $this->assertCount(1, $this->audit_rows('employees', $emp, 'pin_change'));

        list(, $body) = $this->ajax($hr, 'attendance/link_pin', array('device_pin' => '777', 'employee_id' => (string) $emp));
        $this->assertSame('success<<<###>>>0', $body, 'a second link changes nothing: the early punch still belongs to nobody');
        $this->assertSame(1, $this->punch_count(array('employee_id' => NULL)));
    }

    public function test_linking_follows_the_pin_rules()
    {
        $holder = $this->dv_employee('H', '555');
        $other = $this->dv_employee('O', NULL);
        $changer = $this->dv_employee('C', '601');
        set_current_branch_id($this->first_branch_id());
        $this->deliver(array(array('555', '2026-02-01 09:00:00'), array('666', '2026-02-01 09:00:00'), array('777', '2026-02-02 09:00:00')));
        $this->db->update('attendance_punches', array('employee_id' => NULL), array('device_pin' => '555'));        // as if unmatched
        $hr = $this->make_user('dv_link', array('attendance_punches', 'employees_edit'));

        list(, $body) = $this->ajax($hr, 'attendance/link_pin', array('device_pin' => '555', 'employee_id' => (string) $other));
        $this->assertStringContainsString($this->db->get_where('employees', array('id' => $holder))->row()->employee_code, $body, 'a PIN held by someone else is refused');
        $this->assertNull($this->db->get_where('employees', array('id' => $other))->row()->device_pin);

        list(, $body) = $this->ajax($hr, 'attendance/link_pin', array('device_pin' => '666', 'employee_id' => (string) $changer));
        $this->assertStringStartsWith('success', $body);
        $hist = $this->db->get_where('employee_pin_history', array('employee_id' => $changer))->row_array();
        $this->assertSame('601', $hist['device_pin'], 'the former PIN moved to the history');
        $this->assertSame('666', $this->db->get_where('employees', array('id' => $changer))->row()->device_pin);

        list(, $body) = $this->ajax($hr, 'attendance/link_pin', array('device_pin' => '12345', 'employee_id' => (string) $other));
        $this->assertSame(t('att_unmatched_none'), $body);
        list(, $body) = $this->ajax($hr, 'attendance/link_pin', array('device_pin' => 'x1', 'employee_id' => (string) $other));
        $this->assertStringContainsString(t('emp_pin_rule'), $body);
        list(, $body) = $this->ajax($hr, 'attendance/link_pin', array('device_pin' => '777', 'employee_id' => '999999'));
        $this->assertStringNotContainsString('success', $body, 'an employee that does not exist');
    }

    public function test_linking_needs_the_employee_permission()
    {
        $emp = $this->dv_employee('L', NULL);
        set_current_branch_id($this->first_branch_id());
        $this->deliver(array(array('777', '2026-02-02 09:00:00')));
        $viewer = $this->make_user('dv_review', array('attendance_punches'));
        list($status) = $this->ajax($viewer, 'attendance/link_pin', array('device_pin' => '777', 'employee_id' => (string) $emp));
        $this->assertSame(403, $status, 'attendance_punches alone cannot give a PIN to an employee');
        $this->assertNull($this->db->get_where('employees', array('id' => $emp))->row()->device_pin);
        list(, $page) = $viewer->get('attendance/unmatched');
        $this->assertStringNotContainsString('link-modal', $page, 'no link form without the permission');
    }

    public function test_punches_for_closed_days_are_listed_and_do_not_change_the_day()
    {
        $emp = $this->dv_employee('C', '401');
        set_current_branch_id($this->first_branch_id());
        $device = $this->deliver(array(array('401', '2026-01-20 09:00:00'), array('401', '2026-01-20 18:00:00')));
        $this->db->update('attendance', array('locked' => 1), array('employee_id' => $emp));
        $before = $this->db->get_where('attendance', array('employee_id' => $emp))->row_array();

        // a late upload: a punch after the closed day's last one, one inside its span, and one on an open day
        $this->deliver(array(array('401', '2026-01-20 19:30:00'), array('401', '2026-01-20 12:00:00'), array('401', '2026-01-21 09:00:00')), $device);
        $after = $this->db->get_where('attendance', array('employee_id' => $emp, 'attendance_date' => '2026-01-20'))->row_array();
        $this->assertSame($before['check_out'], $after['check_out'], 'a closed day never changes');
        $this->assertSame('18:00:00', $after['check_out']);

        $hr = $this->make_user('dv_review', array('attendance_punches'));
        list(, $json) = $this->table($hr, 'attendance/closed_list', array('from_date' => '01-01-2026', 'to_date' => '31-01-2026'));
        $times = array_map(function ($r) { return substr($r['when'], -8); }, $json['data']);
        sort($times);
        $this->assertSame(array('19:30:00'), $times, 'only the punch the locked day does not show (12:00 lies inside it; 21 January is open)');
        $this->assertStringContainsString('401', $json['data'][0]['pin']);
    }
}
