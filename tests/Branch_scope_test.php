<?php
/**
 * P3: the branch of a punch comes only from the authenticated key or the registered device - never from the request body.
 * (pos-retails forced store 2, and let a store id inside each record override the payload's.)
 */
class Branch_scope_test extends Device_test_case
{
    public function test_a_record_with_another_branch_id_is_saved_to_the_keys_branch()
    {
        $a = $this->first_branch_id();
        $b = $this->second_branch();
        $emp_a = $this->dv_employee('A', '501', $a);
        $emp_b = $this->dv_employee('B', '501', $b);                         // the same PIN in the other branch
        $key_b = $this->make_key($b, NULL, 'branch B');

        list($status, $body) = $this->service($key_b, array(
            'device_name' => 'B gate', 'branch_id' => $a, 'store_id' => $a,
            'records' => array(array('user_id' => '501', 'attendance_datetime' => '2026-01-20 08:30:00', 'attendance_type' => '0', 'branch_id' => $a, 'store_id' => $a)),
        ));
        $this->assertSame(200, $status);
        $this->assertSame(1, $body['accepted']);
        $punch = $this->db->get('attendance_punches')->row_array();
        $this->assertSame($b, (int) $punch['branch_id'], 'the key\'s branch, not the claimed one');
        $this->assertSame($emp_b, (int) $punch['employee_id'], 'the PIN is resolved inside the key\'s branch');
        $this->assertSame(0, $this->punch_count(array('branch_id' => $a)));
        $this->assertSame(0, (int) $this->db->get_where('attendance', array('employee_id' => $emp_a))->num_rows());
        $this->assertSame($b, (int) $this->db->get_where('devices', array('name' => 'B gate'))->row()->branch_id, 'the auto-registered device belongs to the key\'s branch too');
    }

    public function test_the_disagreement_is_logged()
    {
        $a = $this->first_branch_id();
        $b = $this->second_branch();
        $this->service($this->make_key($b), $this->read_fixture('foreign_branch.json'));
        $log = $this->db->order_by('id', 'DESC')->get('device_logs')->row_array();
        $this->assertSame($b, (int) $log['branch_id']);
        $this->assertStringContainsString('payload branch/store 999,2 ignored', $log['message']);
        $this->assertSame(1, $this->punch_count(array('branch_id' => $b)));
        $this->assertSame(0, $this->punch_count(array('branch_id' => $a)));
    }

    public function test_agreeing_ids_are_not_logged_as_disagreement()
    {
        $a = $this->first_branch_id();
        $this->service($this->make_key($a), array('device_name' => 'G', 'branch_id' => $a, 'records' => array(array('user_id' => '5', 'attendance_datetime' => '2026-01-20 08:30:00', 'store_id' => $a))));
        $this->assertStringNotContainsString('ignored', (string) $this->db->order_by('id', 'DESC')->get('device_logs')->row()->message);
    }

    public function test_an_adms_device_stores_in_its_own_branch()
    {
        $a = $this->first_branch_id();
        $b = $this->second_branch();
        $emp_b = $this->dv_employee('B', '601', $b);
        $this->db->insert('devices', array('branch_id' => $b, 'name' => 'B adms', 'serial_number' => 'BRANCHB0001', 'comm_mode' => 'adms_push', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')));
        list($status, $text) = $this->http->raw('POST', 'iclock/cdata?SN=BRANCHB0001&table=ATTLOG&branch_id=' . $a, "601\t2026-01-20 08:30:00\t0\t1\t0\n");
        $this->assertSame(200, $status, $text);
        $punch = $this->db->get('attendance_punches')->row_array();
        $this->assertSame($b, (int) $punch['branch_id']);
        $this->assertSame($emp_b, (int) $punch['employee_id']);
        $this->assertSame('device_push', $punch['source']);
    }

    public function test_a_key_of_one_branch_cannot_use_a_device_of_another()
    {
        $a = $this->first_branch_id();
        $b = $this->second_branch();
        $device_b = $this->insert_device('B only', array(), $b);
        $key_a = $this->make_key($a, $device_b, 'wrongly bound');          // a key row that names a device of another branch
        list($status) = $this->service($key_a, array('records' => array(array('user_id' => '5', 'attendance_datetime' => '2026-01-20 08:30:00'))));
        $this->assertSame(403, $status);
        $this->assertSame(0, $this->punch_count());
    }

    public function test_the_ingestor_ignores_a_branch_inside_a_punch()
    {
        $a = $this->first_branch_id();
        $b = $this->second_branch();
        $r = (new Punch_ingestor())->ingest(array(array('device_pin' => '9', 'punch_at' => '2026-01-20 08:30:00', 'branch_id' => $b, 'store_id' => $b)), array('branch_id' => $a, 'device_id' => $this->insert_device('Ctx'), 'source' => 'device_push'));
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $this->punch_count(array('branch_id' => $a)));
        $this->assertSame(0, $this->punch_count(array('branch_id' => $b)));
    }

    protected function read_fixture($name)
    {
        return file_get_contents(HRM_ROOT . '/tests/fixtures/service/' . $name);
    }
}
