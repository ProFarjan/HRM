<?php
/**
 * P17: hashed, revocable, per-branch / per-device keys; ADMS accepts only approved serial numbers, remembers unknown ones in a
 * capped list without storing anything else, can be limited to addresses, and is rate limited.
 */
class Device_auth_test extends Device_test_case
{
    public function test_a_key_is_stored_as_a_hash_only()
    {
        $key = $this->make_key();
        $row = $this->db->get('device_keys')->row_array();
        $this->assertSame(hash('sha256', $key), $row['key_hash']);
        $this->assertSame(substr($key, -4), $row['key_hint']);
        $this->assertStringStartsWith('hrmk_', $key);
        $this->assertSame(45, strlen($key));
        $dump = json_encode($this->db->get('device_keys')->result_array());
        $this->assertStringNotContainsString($key, $dump, 'the key itself is nowhere in the table');
        $this->assertNotSame(Device_auth::generate_key(), Device_auth::generate_key());
    }

    public function test_a_revoked_key_is_refused_at_once()
    {
        $key = $this->make_key();
        list($status) = $this->service($key, array('test' => TRUE));
        $this->assertSame(200, $status);
        $this->db->update('device_keys', array('revoked_at' => date('Y-m-d H:i:s')));
        list($status, $body) = $this->service($key, array('test' => TRUE));
        $this->assertSame(401, $status);
        $this->assertSame('error', $body['status']);
        $log = $this->db->order_by('id', 'DESC')->get('device_logs')->row_array();
        $this->assertSame(401, (int) $log['http_code']);
        $this->assertNull($log['device_id'], 'a refused call is logged without a device');
    }

    public function test_a_key_of_an_inactive_branch_is_refused()
    {
        $b = $this->second_branch();
        $key = $this->make_key($b);
        $this->db->update('branches', array('status' => 'Inactive'), array('id' => $b));
        list($status) = $this->service($key, array('test' => TRUE));
        $this->assertSame(403, $status);
    }

    public function test_last_used_at_is_kept_up()
    {
        $key = $this->make_key();
        $this->assertNull($this->db->get('device_keys')->row()->last_used_at);
        $this->service($key, array('test' => TRUE));
        $this->assertNotNull($this->db->get('device_keys')->row()->last_used_at);
    }

    public function test_repeated_wrong_keys_are_throttled_per_address()
    {
        $good = $this->make_key();
        for ($i = 0; $i < Device_auth::FAIL_LIMIT; $i++) {
            list($status) = $this->service('hrmk_wrong' . $i, array('test' => TRUE));
            $this->assertSame(401, $status);
        }
        list($status, $body) = $this->service('hrmk_wrong_again', array('test' => TRUE));
        $this->assertSame(429, $status);
        list($status) = $this->service($good, array('test' => TRUE));
        $this->assertSame(429, $status, 'the lock holds for this address until the window ends');
    }

    // ---------------------------------------------------------------------------------------------- ADMS

    protected function adms_device($sn = 'ADMS000001', array $extra = array())
    {
        $this->db->insert('devices', array_merge(array('branch_id' => $this->first_branch_id(), 'name' => 'ADMS ' . $sn, 'serial_number' => $sn, 'comm_mode' => 'adms_push', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')), $extra));
        return (int) $this->db->insert_id();
    }

    public function test_an_unknown_serial_number_is_remembered_and_nothing_it_sends_is_stored()
    {
        $this->dv_employee('A', '101');
        list($status, $text) = $this->http->raw('GET', 'iclock/cdata?SN=UNKNOWN0001&options=all&pushver=2.4.1');
        $this->assertSame(200, $status, 'the device gets the normal handshake text so it behaves');
        $this->assertStringStartsWith('GET OPTION FROM: UNKNOWN0001', $text);

        list($status, $text) = $this->http->raw('POST', 'iclock/cdata?SN=UNKNOWN0001&table=ATTLOG', "101\t2026-01-20 08:30:00\t0\t1\t0\n");
        $this->assertSame(403, $status, 'refused, so the device keeps its data until it is approved');
        $this->assertStringStartsWith('ERROR', $text);
        $this->assertSame(0, $this->punch_count());
        $this->assertSame(0, $this->db->count_all('devices'));
        $pending = $this->db->get('devices_pending')->row_array();
        $this->assertSame('UNKNOWN0001', $pending['serial_number']);
        $this->assertGreaterThanOrEqual(2, (int) $pending['hits']);

        list($status, $text) = $this->http->raw('GET', 'iclock/getrequest?SN=UNKNOWN0001');
        $this->assertSame('OK', $text, 'no commands for a device nobody approved');
    }

    public function test_the_pending_list_cannot_be_flooded()
    {
        for ($i = 1; $i <= Device_auth::PENDING_CAP + 20; $i++) {
            $this->http->raw('GET', 'iclock/getrequest?SN=FLOOD' . str_pad((string) $i, 5, '0', STR_PAD_LEFT));
        }
        $this->assertSame(Device_auth::PENDING_CAP, $this->db->count_all('devices_pending'));
        $this->assertSame(0, $this->db->count_all('devices'));
        $this->assertSame(0, $this->punch_count());
    }

    public function test_a_malformed_serial_number_touches_nothing()
    {
        foreach (array('a', "x%27%3B--", 'with%20space', str_repeat('Z', 41)) as $sn) {
            list($status) = $this->http->raw('GET', 'iclock/getrequest?SN=' . $sn);
            $this->assertSame(400, $status, $sn);
        }
        list($status) = $this->http->raw('GET', 'iclock/getrequest');
        $this->assertSame(400, $status, 'no serial number');
        $this->assertSame(0, $this->db->count_all('devices_pending'));
    }

    public function test_a_known_device_gets_the_handshake_and_uploads()
    {
        $this->adms_device();
        $emp = $this->dv_employee('A', '101');
        list($status, $text) = $this->http->raw('GET', 'iclock/cdata?SN=ADMS000001&options=all');
        $this->assertSame(200, $status);
        $this->assertStringContainsString("TransFlag=TransData AttLog\tOpLog", $text);
        $this->assertStringContainsString("Realtime=1\n", $text);
        list($status, $text) = $this->http->raw('POST', 'iclock/cdata?SN=ADMS000001&table=ATTLOG', "101\t2026-01-20 08:30:00\t0\t1\t0\n101\t2026-01-20 17:40:00\t1\t1\t0\n");
        $this->assertSame(array(200, 'OK'), array($status, $text));
        $this->assertSame(2, $this->punch_count(array('employee_id' => $emp)));
        $day = $this->db->get_where('attendance', array('employee_id' => $emp))->row_array();
        $this->assertSame(array('08:30:00', '17:40:00'), array($day['check_in'], $day['check_out']));
        $dev = $this->db->get('devices')->row_array();
        $this->assertSame('127.0.0.1', $dev['last_ip']);
        $this->assertNotNull($dev['last_upload_at']);
        // other tables are acknowledged and not stored
        list($status, $text) = $this->http->raw('POST', 'iclock/cdata?SN=ADMS000001&table=OPERLOG', "OPLOG 4\t0\t2026-01-20 08:00:00\n");
        $this->assertSame(array(200, 'OK'), array($status, $text));
        $this->assertSame(2, $this->punch_count());
    }

    public function test_a_disabled_device_and_a_service_device_are_refused_on_adms()
    {
        $this->adms_device('ADMS000002', array('status' => 'disabled'));
        list($status) = $this->http->raw('POST', 'iclock/cdata?SN=ADMS000002&table=ATTLOG', "101\t2026-01-20 08:30:00\t0\t1\t0\n");
        $this->assertSame(403, $status);
        list($status) = $this->http->raw('GET', 'iclock/cdata?SN=ADMS000002&options=all');
        $this->assertSame(403, $status);
        $this->db->insert('devices', array('branch_id' => $this->first_branch_id(), 'name' => 'Svc', 'serial_number' => 'SVC0000001', 'comm_mode' => 'windows_service', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')));
        list($status) = $this->http->raw('GET', 'iclock/getrequest?SN=SVC0000001');
        $this->assertSame(403, $status, 'a device registered for the Windows Service does not talk ADMS');
        $this->assertSame(0, $this->punch_count());
    }

    public function test_allowed_addresses_and_the_confirmation_of_a_changed_one()
    {
        $id = $this->adms_device('ADMS000003', array('allowed_ips' => '10.9.9.9'));
        list($status, $text) = $this->http->raw('GET', 'iclock/getrequest?SN=ADMS000003');
        $this->assertSame(403, $status);
        $this->assertStringContainsString('address not allowed', $text);
        $dev = $this->db->get_where('devices', array('id' => $id))->row_array();
        $this->assertSame('127.0.0.1', $dev['blocked_ip'], 'HR sees where it called from');
        $this->assertNull($dev['last_seen_at']);

        // HR confirms the new address
        set_current_branch_id($this->first_branch_id());
        $this->act_as_new_user('dv_hr', array('devices_manage'));
        $this->CI->load->model('Devices_model');
        $this->assertSame('success', $this->CI->Devices_model->allow_blocked_ip($id));
        $dev = $this->db->get_where('devices', array('id' => $id))->row_array();
        $this->assertSame('10.9.9.9,127.0.0.1', $dev['allowed_ips']);
        $this->assertNull($dev['blocked_ip']);
        list($status, $text) = $this->http->raw('GET', 'iclock/getrequest?SN=ADMS000003');
        $this->assertSame(array(200, 'OK'), array($status, $text));
    }

    public function test_the_address_rules()
    {
        $this->assertTrue(Device_auth::ip_allowed('1.2.3.4', ''));
        $this->assertTrue(Device_auth::ip_allowed('1.2.3.4', NULL));
        $this->assertTrue(Device_auth::ip_allowed('1.2.3.4', '9.9.9.9, 1.2.3.4'));
        $this->assertFalse(Device_auth::ip_allowed('1.2.3.5', '9.9.9.9, 1.2.3.4'));
        $this->assertTrue(Device_auth::ip_allowed('192.168.1.77', '192.168.1.0/24'));
        $this->assertFalse(Device_auth::ip_allowed('192.168.2.77', '192.168.1.0/24'));
        $this->assertTrue(Device_auth::ip_allowed('10.1.2.3', '10.0.0.0/8'));
        $this->assertTrue(Device_auth::ip_allowed('::1', '::1'));
        $this->assertFalse(Device_auth::ip_allowed('::1', '192.168.1.0/24'));
    }

    public function test_an_adms_serial_number_is_rate_limited()
    {
        $this->adms_device('ADMS000004');
        $last = 200;
        for ($i = 0; $i < Device_auth::ADMS_SN_LIMIT + 3; $i++) {
            list($last) = $this->http->raw('GET', 'iclock/getrequest?SN=ADMS000004');
        }
        $this->assertSame(429, $last);
    }

    public function test_adms_commands_round_trip()
    {
        $id = $this->adms_device('ADMS000005');
        $device = $this->db->get_where('devices', array('id' => $id))->row_array();
        $create = Device_command_queue::enqueue($device, 'create_user', array('user_id' => '101', 'name' => 'Rahim'), NULL);
        $reboot = Device_command_queue::enqueue($device, 'reboot', array(), NULL);
        list(, $text) = $this->http->raw('GET', 'iclock/getrequest?SN=ADMS000005&INFO=Ver%206.60,1,2,3,4');
        $this->assertSame("C:$create:DATA UPDATE USERINFO PIN=101\tName=Rahim\tPri=0\tPasswd=\tCard=\nC:$reboot:REBOOT\n", $text);
        $this->assertSame('Ver 6.60', $this->db->get_where('devices', array('id' => $id))->row()->firmware);
        list(, $text) = $this->http->raw('GET', 'iclock/getrequest?SN=ADMS000005');
        $this->assertSame('OK', $text);
        $this->http->raw('POST', 'iclock/devicecmd?SN=ADMS000005', "ID=$create&Return=0&CMD=DATA\nID=$reboot&Return=-1&CMD=REBOOT\n");
        $this->assertSame('done', $this->db->get_where('device_commands', array('id' => $create))->row()->status);
        $this->assertSame('failed', $this->db->get_where('device_commands', array('id' => $reboot))->row()->status);
    }

    public function test_a_command_that_was_never_answered_fails_instead_of_jamming_the_queue()
    {
        $id = $this->adms_device('ADMS000006');
        $device = $this->db->get_where('devices', array('id' => $id))->row_array();
        $cmd = Device_command_queue::enqueue($device, 'list_users', array(), NULL);
        Device_command_queue::deliver($id);
        $this->db->update('device_commands', array('sent_at' => date('Y-m-d H:i:s', time() - 3600)), array('id' => $cmd));
        Device_command_queue::deliver($id);
        $row = $this->db->get_where('device_commands', array('id' => $cmd))->row_array();
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('No answer', $row['result']);
    }

    public function test_every_call_is_logged()
    {
        $this->adms_device('ADMS000007');
        $this->http->raw('GET', 'iclock/getrequest?SN=ADMS000007');
        $this->http->raw('GET', 'iclock/getrequest?SN=NOTKNOWN001');
        $this->http->raw('GET', 'iclock/getrequest?SN=a');
        $codes = array_column($this->db->order_by('id')->get('device_logs')->result_array(), 'http_code');
        $this->assertSame(array('200', '200', '400'), $codes);
        $this->assertSame('heartbeat', $this->db->order_by('id')->get('device_logs')->row()->message);
    }
}
