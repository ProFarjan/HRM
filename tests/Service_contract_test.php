<?php
/**
 * The contract the ZKTeco Windows Service depends on (CLAUDE.md "Attendance devices" A) and the server side of P16: a failure to
 * store is HTTP 500 so the service keeps its batch, a resend is harmless, the test ping and heartbeat answer as documented, and
 * the command round trip works.
 */
class Service_contract_test extends Device_test_case
{
    protected function fixture($name)
    {
        return file_get_contents(HRM_ROOT . '/tests/fixtures/service/' . $name);
    }

    public function test_the_connection_test_and_authentication()
    {
        $key = $this->make_key();
        list($status, $body) = $this->service($key, $this->fixture('test.json'));
        $this->assertSame(200, $status);
        $this->assertSame(array('status' => 'success', 'message' => 'Connection OK'), $body);

        list($status, $body) = $this->service(NULL, $this->fixture('test.json'));
        $this->assertSame(401, $status, 'no key');
        $this->assertSame('error', $body['status']);
        list($status) = $this->service('hrmk_' . str_repeat('0', 40), $this->fixture('test.json'));
        $this->assertSame(401, $status, 'wrong key');

        // the three ways of carrying the key
        list($status) = $this->service(NULL, $this->fixture('test.json'), array('Authorization: Bearer ' . $key));
        $this->assertSame(200, $status, 'Authorization: Bearer');
        list($status) = $this->http->raw('POST', 'api/v1/pull_attendance?api_key=' . $key, $this->fixture('test.json'));
        $this->assertSame(200, $status, '?api_key=');
    }

    public function test_only_post_with_valid_json_is_accepted()
    {
        $key = $this->make_key();
        list($status) = $this->http->raw('GET', 'api/v1/pull_attendance', '', array('X-API-Key: ' . $key));
        $this->assertSame(405, $status);
        list($status, $body) = $this->service($key, 'this is not json');
        $this->assertSame(400, $status);
        $this->assertSame('error', $body['status']);
    }

    public function test_heartbeat_is_success_with_zero_records_and_marks_the_device_seen()
    {
        $key = $this->make_key();
        list($status, $body) = $this->service($key, $this->fixture('heartbeat.json'));
        $this->assertSame(200, $status);
        $this->assertSame(0, $body['processed']);
        $this->assertSame(0, $body['total']);
        $this->assertSame(array(), $body['commands']);
        $device = $this->db->get_where('devices', array('name' => 'Main Gate'))->row_array();
        $this->assertNotNull($device, 'the device is registered on first sight');
        $this->assertSame('windows_service', $device['comm_mode']);
        $this->assertNotNull($device['last_seen_at']);
        $this->assertNull($device['last_upload_at'], 'a heartbeat uploads no punches');
        $this->assertSame(1, (int) $this->db->get_where('device_logs', array('endpoint' => 'api/v1/pull_attendance', 'http_code' => 200))->num_rows());
    }

    public function test_a_batch_is_stored_and_builds_the_day()
    {
        $e101 = $this->dv_employee('A', '101');
        $this->dv_employee('B', '102');
        $key = $this->make_key();
        list($status, $body) = $this->service($key, $this->fixture('batch.json'));
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(array('status' => 'success', 'processed' => 3, 'total' => 3, 'accepted' => 3), array_intersect_key($body, array_flip(array('status', 'processed', 'total', 'accepted'))));
        $this->assertSame(3, $this->punch_count());
        $row = $this->db->get_where('attendance', array('employee_id' => $e101, 'attendance_date' => '2026-01-20'))->row_array();
        $this->assertSame('08:30:15', $row['check_in']);
        $this->assertSame('17:45:00', $row['check_out']);
        $this->assertSame('punch', $row['source']);
        $device = $this->db->get_where('devices', array('name' => 'Main Gate'))->row_array();
        $this->assertSame('2026-01-20 17:45:00', $device['last_punch_at']);
        $this->assertNotNull($device['last_upload_at']);
        $this->assertSame('device_service', $this->db->query('SELECT source FROM attendance_punches LIMIT 1')->row()->source);
    }

    public function test_a_resent_batch_is_accepted_again_and_stored_once()
    {
        $this->dv_employee('A', '101');
        $this->dv_employee('B', '102');
        $key = $this->make_key();
        $this->service($key, $this->fixture('batch.json'));
        list($status, $body) = $this->service($key, $this->fixture('batch.json'));
        $this->assertSame(200, $status);
        $this->assertSame(3, $body['accepted'], 'the service may clear the device: everything is stored');
        $this->assertSame(3, $this->punch_count(), 'one row per punch');
    }

    public function test_a_storage_failure_is_http_500_and_the_resend_succeeds()
    {
        $this->dv_employee('A', '101');
        $this->dv_employee('B', '102');
        $key = $this->make_key();
        $this->db->query("CREATE TRIGGER test_attendance_fail BEFORE INSERT ON attendance FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'");
        list($status, $body) = $this->service($key, $this->fixture('batch.json'));
        $this->assertSame(500, $status, 'never 200 when punches were not stored (P16)');
        $this->assertSame('error', $body['status']);
        $this->assertSame(0, $this->punch_count(), 'nothing half stored: the service keeps the whole batch');
        $device = $this->db->get_where('devices', array('name' => 'Main Gate'))->row_array();
        $this->assertNull($device['last_upload_at'], 'a failed upload is not an upload');
        $this->assertSame(500, (int) $this->db->order_by('id', 'DESC')->get('device_logs')->row()->http_code);

        $this->db->query('DROP TRIGGER test_attendance_fail');
        list($status, $body) = $this->service($key, $this->fixture('batch.json'));
        $this->assertSame(200, $status);
        $this->assertSame(3, $body['accepted']);
        $this->assertSame(3, $this->punch_count());
    }

    public function test_unmatched_and_invalid_records_are_reported_not_fatal()
    {
        $key = $this->make_key();
        list($status, $body) = $this->service($key, array('device_name' => 'Gate', 'records' => array(
            array('user_id' => '777', 'attendance_datetime' => '2026-01-20 09:00:00'), array('user_id' => 'zz', 'attendance_datetime' => 'x'),
        )));
        $this->assertSame(200, $status);
        $this->assertSame(array(2, 1, 1, 1), array($body['total'], $body['processed'], $body['invalid'], $body['unmatched']));
        $this->assertNull($this->db->get_where('attendance_punches', array('device_pin' => '777'))->row()->employee_id);
    }

    public function test_commands_round_trip()
    {
        $this->service($this->make_key(), $this->fixture('heartbeat.json'));
        $device = $this->db->get_where('devices', array('name' => 'Main Gate'))->row_array();
        $key = $this->make_key();
        $id = Device_command_queue::enqueue($device, 'create_user', array('user_id' => '101', 'name' => 'Rahim Uddin Ahmed Chowdhury Junior', 'password' => '12ab34', 'role' => 14, 'card_no' => '5551'), NULL);
        $this->assertIsInt($id);
        $same = Device_command_queue::enqueue($device, 'create_user', array('user_id' => '101', 'name' => 'Rahim Uddin Ahmed Chowdhury Junior', 'password' => '12ab34', 'role' => 14, 'card_no' => '5551'), NULL);
        $this->assertSame($id, $same, 'an identical queued command is not queued twice');

        list(, $body) = $this->service($key, $this->fixture('heartbeat.json'));
        $this->assertCount(1, $body['commands']);
        $cmd = $body['commands'][0];
        $this->assertSame($id, $cmd['id']);
        $this->assertSame('create_user', $cmd['type']);
        $this->assertSame('101', $cmd['payload']['user_id']);
        $this->assertSame(24, mb_strlen($cmd['payload']['name']), 'name cut to the 24 characters the device takes');
        $this->assertSame('1234', $cmd['payload']['password'], 'digits only');
        $this->assertSame(14, $cmd['payload']['role']);
        $this->assertSame('sent', $this->db->get_where('device_commands', array('id' => $id))->row()->status);

        list(, $body) = $this->service($key, $this->fixture('heartbeat.json'));
        $this->assertSame(array(), $body['commands'], 'delivered once');

        $results = array('device_name' => 'Main Gate', 'records' => array(), 'command_results' => array(array('id' => $id, 'success' => TRUE, 'message' => 'User created.', 'data' => array('uid' => 12))));
        $this->service($key, $results);
        $row = $this->db->get_where('device_commands', array('id' => $id))->row_array();
        $this->assertSame('done', $row['status']);
        $this->assertSame('User created.', json_decode($row['result'], TRUE)['message']);

        // a failed command, and a result for a command that is not this device's is ignored
        $other = $this->insert_device('Other gate');
        $foreign = Device_command_queue::enqueue($this->db->get_where('devices', array('id' => $other))->row_array(), 'delete_user', array('user_id' => '5'), NULL);
        $failed = Device_command_queue::enqueue($device, 'delete_user', array('user_id' => '101'), NULL);
        $this->service($key, $this->fixture('heartbeat.json'));
        $this->service($key, array('device_name' => 'Main Gate', 'records' => array(), 'command_results' => array(
            array('id' => $failed, 'success' => FALSE, 'message' => 'No such user.'), array('id' => $foreign, 'success' => TRUE),
        )));
        $this->assertSame('failed', $this->db->get_where('device_commands', array('id' => $failed))->row()->status);
        $this->assertSame('queued', $this->db->get_where('device_commands', array('id' => $foreign))->row()->status, 'another device\'s command is untouched');
    }

    public function test_the_service_cannot_be_given_adms_only_commands()
    {
        $device = $this->insert_device('Svc');
        $row = $this->db->get_where('devices', array('id' => $device))->row_array();
        $this->assertIsString(Device_command_queue::enqueue($row, 'reboot', array(), NULL), 'the service cannot reboot or clear a device');
        $this->assertIsString(Device_command_queue::enqueue($row, 'create_user', array('name' => 'x'), NULL), 'a PIN is required');
        $this->assertIsString(Device_command_queue::enqueue(array_merge($row, array('status' => 'disabled')), 'list_users', array(), NULL), 'not for a disabled device');
        $this->assertIsInt(Device_command_queue::enqueue($row, 'list_users', array(), NULL));
    }

    public function test_which_device_a_key_means()
    {
        $branch = $this->first_branch_id();
        $bound = $this->insert_device('Bound gate');
        $key = $this->make_key($branch, $bound);
        $this->service($key, array('device_name' => 'Anything else', 'records' => array()));
        $this->assertNotNull($this->db->get_where('devices', array('id' => $bound))->row()->last_seen_at, 'the bound device is used, whatever the name says');
        $this->assertSame(1, $this->db->count_all('devices'), 'no device was created');

        $any = $this->make_key();
        $this->service($any, array('device_name' => 'Back door', 'records' => array()));
        $this->service($any, array('device_name' => 'Back door', 'records' => array()));
        $this->assertSame(2, $this->db->count_all('devices'), 'a branch-wide key creates a device by name once');

        $this->db->update('devices', array('status' => 'disabled'), array('name' => 'Back door'));
        list($status, $body) = $this->service($any, array('device_name' => 'Back door', 'records' => array(array('user_id' => '1', 'attendance_datetime' => '2026-01-20 09:00:00'))));
        $this->assertSame(403, $status, 'a disabled device is refused');
        $this->assertSame(0, $this->punch_count());

        $this->db->insert('devices', array('branch_id' => $branch, 'name' => 'Adms gate', 'serial_number' => 'ADMS000001', 'comm_mode' => 'adms_push', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')));
        list($status) = $this->service($any, array('device_name' => 'Adms gate', 'records' => array()));
        $this->assertSame(409, $status, 'a device that talks ADMS is not served through the service endpoint');
    }

    public function test_a_branch_wide_key_cannot_create_unlimited_devices()
    {
        $any = $this->make_key();
        for ($i = 0; $i < Device_auth::AUTO_DEVICE_CAP; $i++) {
            $this->insert_device('Auto ' . $i);
        }
        list($status) = $this->service($any, array('device_name' => 'One too many', 'records' => array()));
        $this->assertSame(422, $status);
    }

    public function test_device_timezone_converts_punch_times_to_branch_time()
    {
        $this->dv_employee('A', '101');
        $device = $this->insert_device('Dubai gate', array('timezone' => 'Asia/Dubai'));          // 2 hours behind Asia/Dhaka
        $key = $this->make_key($this->first_branch_id(), $device);
        $this->service($key, array('records' => array(array('user_id' => '101', 'attendance_datetime' => '2026-01-20 07:00:00', 'attendance_type' => '0'))));
        $this->assertSame('2026-01-20 09:00:00', $this->db->get('attendance_punches')->row()->punch_at);
    }
}
