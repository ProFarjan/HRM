<?php
/**
 * The device pages over HTTP: permissions (view / manage / keys / commands), registering and editing devices, API keys shown once,
 * approving a pending ADMS serial number, the command queue and "enrol on device", the leaver's delete_user, the call log, and the
 * branch scope of all of it.
 */
class Devices_flow_test extends Device_test_case
{
    /** @var int[] */
    protected $user_ids = array();

    protected function make_user($username, array $permissions, $branch_id = NULL)
    {
        $role = $this->insert_role('role_' . $username, $permissions);
        $this->user_ids[$username] = $this->insert_user($username, $role, $branch_id);
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

    protected function device_form(array $over = array())
    {
        return array_merge(array('name' => 'Main entrance', 'comm_mode' => 'adms_push', 'serial_number' => 'FLOW0000001', 'ip' => '192.168.1.50', 'port' => '4370', 'protocol' => 'TCP',
            'status' => 'active', 'allowed_ips' => '', 'timezone' => ''), $over);
    }

    // ---------------------------------------------------------------------------------------------- permissions

    public function test_every_device_action_has_its_own_permission()
    {
        $viewer = $this->make_user('dv_viewer', array('devices_view'));
        list($status) = $viewer->get('devices');
        $this->assertSame(200, $status);
        list($status, $json) = $this->table($viewer, 'devices/ajax_list');
        $this->assertSame(200, $status);
        $this->assertSame(0, $json['recordsTotal']);
        list($status) = $viewer->get('devices/logs');
        $this->assertSame(200, $status, 'the call log needs devices_view');
        foreach (array('devices/add', 'devices/keys', 'devices/commands', 'devices/edit/1') as $url) {
            list($status) = $viewer->get($url);
            $this->assertSame(403, $status, $url);
        }
        foreach (array('devices/save', 'devices/create_key', 'devices/queue', 'devices/save_approve', 'devices/dismiss', 'devices/keys_list', 'devices/commands_list') as $url) {
            list($status) = $this->ajax($viewer, $url, $this->device_form());
            $this->assertSame(403, $status, $url);
        }
        $this->assertSame(0, $this->db->count_all('devices'));
        $this->assertSame(0, $this->db->count_all('device_keys'));

        $nobody = $this->make_user('dv_nobody', array('employees_view'));
        foreach (array('devices', 'devices/logs', 'attendance/unmatched', 'attendance/closed_punches', 'attendance/import') as $url) {
            list($status) = $nobody->get($url);
            $this->assertSame(403, $status, $url);
        }
        // the import is its own permission: punches_view does not give it
        $punches = $this->make_user('dv_punches', array('attendance_punches'));
        list($status) = $punches->get('attendance/unmatched');
        $this->assertSame(200, $status);
        list($status) = $punches->get('attendance/import');
        $this->assertSame(403, $status);
    }

    // ---------------------------------------------------------------------------------------------- devices

    public function test_register_edit_disable_and_delete_a_device()
    {
        $hr = $this->make_user('dv_hr', array('devices_view', 'devices_manage'));
        list($status, $body) = $this->ajax($hr, 'devices/save', $this->device_form());
        $this->assertStringStartsWith('success<<<###>>>', $body);
        $id = (int) substr($body, strlen('success<<<###>>>'));
        $row = $this->db->get_where('devices', array('id' => $id))->row_array();
        $this->assertSame(array($this->first_branch_id(), 'adms_push', 'FLOW0000001'), array((int) $row['branch_id'], $row['comm_mode'], $row['serial_number']));
        $this->assertCount(1, $this->audit_rows('devices', $id, 'create'));

        // rules
        list(, $body) = $this->ajax($hr, 'devices/save', $this->device_form());
        $this->assertStringContainsString(t('dev_name_taken'), $body);
        list(, $body) = $this->ajax($hr, 'devices/save', $this->device_form(array('name' => 'Other')));
        $this->assertStringContainsString(t('dev_serial_taken'), $body);
        list(, $body) = $this->ajax($hr, 'devices/save', $this->device_form(array('name' => 'No serial', 'serial_number' => '')));
        $this->assertStringContainsString(t('dev_serial_required'), $body, 'an ADMS device needs its serial number');
        list(, $body) = $this->ajax($hr, 'devices/save', $this->device_form(array('name' => 'Bad', 'serial_number' => 'x y', 'ip' => 'nope', 'timezone' => 'Mars/Base', 'allowed_ips' => '1.1.1.1, abc')));
        foreach (array('dev_serial_invalid', 'dev_ip_invalid', 'dev_timezone_invalid', 'dev_allowed_invalid') as $key) {
            $this->assertStringContainsString(t($key), $body, $key);
        }
        list(, $body) = $this->ajax($hr, 'devices/save', $this->device_form(array('name' => 'Service PC', 'comm_mode' => 'windows_service', 'serial_number' => '', 'allowed_ips' => '10.0.0.0/8, 192.168.1.5', 'timezone' => 'Asia/Dubai')));
        $this->assertStringStartsWith('success', $body);

        // edit + disable
        list(, $body) = $this->ajax($hr, 'devices/update/' . $id, $this->device_form(array('name' => 'Main entrance 2', 'status' => 'disabled')));
        $this->assertSame('success', $body);
        $this->assertSame('disabled', $this->db->get_where('devices', array('id' => $id))->row()->status);
        $audit = $this->audit_rows('devices', $id, 'update');
        $this->assertCount(1, $audit);
        $this->assertSame(array('name' => 'Main entrance 2', 'status' => 'disabled'), json_decode($audit[0]['new_values'], TRUE));

        list(, $json) = $this->table($hr, 'devices/ajax_list');
        $this->assertSame(2, $json['recordsTotal']);
        $this->assertStringContainsString('Main entrance 2', $json['data'][0]['name'] . $json['data'][1]['name']);

        // delete: not with punches, otherwise yes
        $this->db->insert('attendance_punches', array('branch_id' => $this->first_branch_id(), 'device_pin' => '1', 'device_id' => $id, 'punch_at' => '2026-01-20 08:00:00', 'source' => 'device_push', 'received_at' => date('Y-m-d H:i:s')));
        list(, $body) = $this->ajax($hr, 'devices/delete/' . $id);
        $this->assertSame(t('dev_has_punches'), $body);
        $this->db->query('DELETE FROM attendance_punches');
        list(, $body) = $this->ajax($hr, 'devices/delete/' . $id);
        $this->assertSame('success', $body);
        $this->assertNull($this->db->get_where('devices', array('id' => $id))->row());
        $this->assertCount(1, $this->audit_rows('devices', $id, 'delete'));
    }

    public function test_the_list_and_every_by_id_action_stay_inside_the_branch()
    {
        $other = $this->second_branch();
        $foreign = $this->insert_device('Elsewhere', array(), $other);
        $mine = $this->insert_device('Here');
        $hr = $this->make_user('dv_hr', array('devices_view', 'devices_manage', 'devices_keys'));
        list(, $json) = $this->table($hr, 'devices/ajax_list');
        $this->assertSame(1, $json['recordsTotal']);
        $this->assertStringContainsString('Here', $json['data'][0]['name']);
        foreach (array('devices/update/', 'devices/delete/', 'devices/allow_ip/') as $url) {
            list($status) = $this->ajax($hr, $url . $foreign, $this->device_form());
            $this->assertSame(403, $status, $url);
        }
        list($status) = $hr->get('devices/edit/' . $foreign);
        $this->assertSame(403, $status);
        list($status, $body) = $this->ajax($hr, 'devices/create_key', array('label' => 'x', 'device_id' => (string) $foreign));
        $this->assertStringNotContainsString('success', $body, 'a key cannot be bound to another branch\'s device');
        $this->assertNotNull($this->db->get_where('devices', array('id' => $foreign))->row());
    }

    // ---------------------------------------------------------------------------------------------- keys

    public function test_a_key_is_shown_once_works_and_can_be_revoked()
    {
        $hr = $this->make_user('dv_keys', array('devices_view', 'devices_keys'));
        list(, $body) = $this->ajax($hr, 'devices/create_key', array('label' => 'Factory PC', 'device_id' => ''));
        $this->assertStringStartsWith('success<<<###>>>hrmk_', $body);
        $key = substr($body, strlen('success<<<###>>>'));
        $row = $this->db->get('device_keys')->row_array();
        $this->assertSame(hash('sha256', $key), $row['key_hash']);
        $this->assertSame($this->first_branch_id(), (int) $row['branch_id'], 'the key belongs to the working branch');
        $this->assertNull($row['device_id']);

        list($status) = $this->service($key, array('test' => TRUE));
        $this->assertSame(200, $status);

        list(, $json, $raw) = $this->table($hr, 'devices/keys_list');
        $this->assertSame(1, $json['recordsTotal']);
        $this->assertStringNotContainsString($key, $raw, 'never shown again');
        $this->assertStringContainsString('…' . substr($key, -4), $json['data'][0]['hint']);

        $audit = json_encode($this->db->get('audit_log')->result_array());
        $this->assertStringNotContainsString($key, $audit, 'the audit trail never holds a key');
        $this->assertCount(1, $this->audit_rows('device_keys', $row['id'], 'create'));

        list(, $body) = $this->ajax($hr, 'devices/revoke_key/' . $row['id']);
        $this->assertSame('success', $body);
        list($status) = $this->service($key, array('test' => TRUE));
        $this->assertSame(401, $status);
        $this->assertCount(1, $this->audit_rows('device_keys', $row['id'], 'revoke'));
        list(, $json) = $this->table($hr, 'devices/keys_list');
        $this->assertTrue($json['data'][0]['revoked']);

        list(, $body) = $this->ajax($hr, 'devices/create_key', array('label' => ''));
        $this->assertStringNotContainsString('success', $body, 'a label is required');
    }

    public function test_keys_of_another_branch_cannot_be_revoked()
    {
        $other = $this->second_branch();
        $this->make_key($other, NULL, 'foreign');
        $id = (int) $this->db->get('device_keys')->row()->id;
        $hr = $this->make_user('dv_keys', array('devices_keys'));
        list($status) = $this->ajax($hr, 'devices/revoke_key/' . $id);
        $this->assertSame(403, $status);
        $this->assertNull($this->db->get('device_keys')->row()->revoked_at);
    }

    // ---------------------------------------------------------------------------------------------- pending

    public function test_a_pending_serial_number_is_approved_into_a_branch()
    {
        $this->http->raw('GET', 'iclock/cdata?SN=NEWDEVICE001&options=all');
        $this->assertSame(1, $this->db->count_all('devices_pending'));
        $hr = $this->make_user('dv_hr', array('devices_view', 'devices_manage'));
        list(, $page) = $hr->get('devices');
        $this->assertStringContainsString('NEWDEVICE001', $page);
        list($status, $page) = $hr->get('devices/approve/NEWDEVICE001');
        $this->assertSame(200, $status);

        list(, $body) = $this->ajax($hr, 'devices/save_approve', array('serial_number' => 'NEWDEVICE001', 'name' => 'Gate 2', 'branch_id' => '999999', 'restrict_ip' => '1', 'timezone' => ''));
        $this->assertStringContainsString(t('dev_branch_invalid'), $body, 'only the listed branches');
        $other = $this->second_branch();
        list(, $body) = $this->ajax($hr, 'devices/save_approve', array('serial_number' => 'NEWDEVICE001', 'name' => 'Gate 2', 'branch_id' => (string) $other, 'restrict_ip' => '1', 'timezone' => ''));
        $this->assertStringContainsString(t('dev_branch_invalid'), $body, 'a login that is not the system Admin cannot approve into another branch');
        $this->assertSame(1, $this->db->count_all('devices_pending'));

        list(, $body) = $this->ajax($hr, 'devices/save_approve', array('serial_number' => 'NEWDEVICE001', 'name' => 'Gate 2', 'branch_id' => (string) $this->first_branch_id(), 'restrict_ip' => '1', 'timezone' => ''));
        $this->assertSame('success', $body);
        $device = $this->db->get('devices')->row_array();
        $this->assertSame(array('NEWDEVICE001', 'adms_push', '127.0.0.1', 'active'), array($device['serial_number'], $device['comm_mode'], $device['allowed_ips'], $device['status']));
        $this->assertSame(0, $this->db->count_all('devices_pending'));
        $this->assertCount(1, $this->audit_rows('devices', $device['id'], 'approve_pending'));
        $this->dv_employee('A', '101');
        list($status, $text) = $this->http->raw('POST', 'iclock/cdata?SN=NEWDEVICE001&table=ATTLOG', "101\t2026-01-20 08:30:00\t0\t1\t0\n");
        $this->assertSame(array(200, 'OK'), array($status, $text));
        $this->assertSame(1, $this->punch_count());

        list(, $body) = $this->ajax($hr, 'devices/save_approve', array('serial_number' => 'NEWDEVICE001', 'name' => 'Again', 'branch_id' => (string) $this->first_branch_id()));
        $this->assertStringContainsString(t('dev_pending_gone'), $body);
    }

    public function test_the_system_admin_may_choose_the_branch_and_a_pending_serial_can_be_dismissed()
    {
        $other = $this->second_branch();
        $this->http->raw('GET', 'iclock/getrequest?SN=PENDING0001');
        $this->http->raw('GET', 'iclock/getrequest?SN=PENDING0002');
        $role = $this->insert_role('role_dv_admin', array(), TRUE);
        $this->insert_user('dv_admin', $role);
        $admin = new Http_client(self::$server->port);
        $admin->login('dv_admin', 'Passw0rd!x');
        list(, $body) = $this->ajax($admin, 'devices/save_approve', array('serial_number' => 'PENDING0001', 'name' => 'Branch B gate', 'branch_id' => (string) $other, 'restrict_ip' => '0', 'timezone' => 'Asia/Dubai'));
        $this->assertSame('success', $body);
        $device = $this->db->get_where('devices', array('serial_number' => 'PENDING0001'))->row_array();
        $this->assertSame(array($other, NULL, 'Asia/Dubai'), array((int) $device['branch_id'], $device['allowed_ips'], $device['timezone']));
        list(, $body) = $this->ajax($admin, 'devices/dismiss', array('serial_number' => 'PENDING0002'));
        $this->assertSame('success', $body);
        $this->assertSame(0, $this->db->count_all('devices_pending'));
    }

    // ---------------------------------------------------------------------------------------------- commands + enrol

    public function test_queue_a_command_by_hand_and_read_the_list()
    {
        $adms = $this->insert_device('Gate A', array('comm_mode' => 'adms_push', 'serial_number' => 'CMD00000001'));
        $svc = $this->insert_device('Gate S');
        $emp = $this->dv_employee('A', '101');
        $hr = $this->make_user('dv_cmd', array('devices_view', 'devices_commands'));

        list(, $body) = $this->ajax($hr, 'devices/queue', array('device_id' => (string) $adms, 'command_type' => 'sync_time'));
        $this->assertStringStartsWith('success<<<###>>>', $body);
        list(, $body) = $this->ajax($hr, 'devices/queue', array('device_id' => (string) $svc, 'command_type' => 'reboot'));
        $this->assertSame(t('dev_cmd_unsupported'), $body, 'the Windows Service cannot reboot a device');
        list(, $body) = $this->ajax($hr, 'devices/queue', array('device_id' => (string) $adms, 'command_type' => 'delete_user', 'employee_id' => (string) $emp));
        $this->assertStringStartsWith('success<<<###>>>', $body);
        $cmd = $this->db->get_where('device_commands', array('command_type' => 'delete_user'))->row_array();
        $this->assertSame('101', json_decode($cmd['payload'], TRUE)['user_id'], 'the PIN comes from the employee record');
        list(, $body) = $this->ajax($hr, 'devices/queue', array('device_id' => (string) $adms, 'command_type' => 'bogus'));
        $this->assertStringNotContainsString('success', $body);

        list($status, $json) = $this->table($hr, 'devices/commands_list');
        $this->assertSame(200, $status);
        $this->assertSame(2, $json['recordsTotal']);
        list(, $json) = $this->table($hr, 'devices/commands_list', array('status' => 'done'));
        $this->assertSame(0, $json['recordsFiltered']);
    }

    public function test_enrol_an_employee_on_devices()
    {
        $a = $this->insert_device('Gate A');
        $b = $this->insert_device('Gate B');
        $emp = $this->dv_employee('E', '202');
        $nopin = $this->dv_employee('N', NULL);
        $hr = $this->make_user('dv_enrol', array('employees_view', 'devices_commands'));

        list(, $body) = $this->ajax($hr, 'employees/enrol_device/' . $emp, array('device_ids' => array((string) $a, (string) $b), 'device_password' => '4321', 'card_no' => '5551234', 'device_admin' => '1'));
        $this->assertSame('success', $body);
        $rows = $this->db->order_by('device_id')->get_where('device_commands', array('command_type' => 'create_user'))->result_array();
        $this->assertCount(2, $rows);
        $payload = json_decode($rows[0]['payload'], TRUE);
        $this->assertSame(array('202', 'Dev E', '4321', 14, '5551234'), array($payload['user_id'], $payload['name'], $payload['password'], $payload['role'], $payload['card_no']));
        $this->assertCount(1, $this->audit_rows('employees', $emp, 'enrol_device'));

        list(, $body) = $this->ajax($hr, 'employees/enrol_device/' . $nopin, array('device_ids' => array((string) $a)));
        $this->assertSame(t('dev_cmd_pin_required'), $body);
        list(, $body) = $this->ajax($hr, 'employees/enrol_device/' . $emp, array('device_password' => '4321'));
        $this->assertStringNotContainsString('success', $body, 'a device must be chosen');
        list(, $body) = $this->ajax($hr, 'employees/enrol_device/' . $emp, array('device_ids' => array((string) $a), 'device_password' => 'abc'));
        $this->assertStringContainsString(t('dev_password_digits'), $body);

        list(, $json) = $this->table($hr, 'devices/commands_list');
        $this->assertStringNotContainsString('4321', json_encode($json), 'the device password is never listed');

        // without the permission it is a 403, not a hidden form
        $plain = $this->make_user('dv_plain', array('employees_view', 'employees_edit'));
        list($status) = $this->ajax($plain, 'employees/enrol_device/' . $emp, array('device_ids' => array((string) $a)));
        $this->assertSame(403, $status);
        list(, $page) = $plain->get('employees/view/' . $emp);
        $this->assertStringNotContainsString('enrol-form', $page);
        list(, $page) = $hr->get('employees/view/' . $emp);
        $this->assertStringContainsString('enrol-form', $page);
    }

    public function test_an_employee_exit_queues_delete_user_on_every_active_device()
    {
        $a = $this->insert_device('Gate A');
        $b = $this->insert_device('Gate B', array('comm_mode' => 'adms_push', 'serial_number' => 'EXIT0000001'));
        $this->insert_device('Gate Off', array('status' => 'disabled'));
        $other = $this->second_branch();
        $this->insert_device('Elsewhere', array(), $other);
        $emp = $this->dv_employee('L', '303');
        $hr = $this->make_user('dv_exit', array('employees_view', 'employees_edit'));
        list(, $body) = $this->ajax($hr, 'employees/record_exit/' . $emp, array('exit_date' => date('d-m-Y'), 'exit_status' => 'Resigned', 'exit_reason' => 'Moved'));
        $this->assertSame('success', $body);
        $rows = $this->db->order_by('device_id')->get_where('device_commands', array('command_type' => 'delete_user'))->result_array();
        $this->assertSame(array($a, $b), array_map('intval', array_column($rows, 'device_id')), 'every active device of the branch, no other');
        $this->assertSame('303', json_decode($rows[0]['payload'], TRUE)['user_id']);
        $this->assertNull($this->db->get_where('employees', array('id' => $emp))->row()->device_pin, 'the PIN moved to the history');
    }

    // ---------------------------------------------------------------------------------------------- logs

    public function test_the_call_log_filters_and_scopes()
    {
        $id = $this->insert_device('Gate A', array('comm_mode' => 'adms_push', 'serial_number' => 'LOG00000001'));
        $this->http->raw('GET', 'iclock/getrequest?SN=LOG00000001');
        $this->http->raw('GET', 'iclock/getrequest?SN=nope!');
        $other = $this->second_branch();
        Device_auth::log(array('id' => $this->insert_device('Elsewhere', array(), $other)), $other, 'iclock/getrequest', 200, 0, 'foreign heartbeat', '9.9.9.9');
        $hr = $this->make_user('dv_log', array('devices_view'));
        list(, $json) = $this->table($hr, 'devices/logs_list');
        $this->assertSame(1, $json['recordsFiltered'], 'only the working branch (the refused call has no branch: Admin only)');
        $this->assertSame('heartbeat', $json['data'][0]['message']);
        list(, $json) = $this->table($hr, 'devices/logs_list', array('errors_only' => '1'));
        $this->assertSame(0, $json['recordsFiltered']);
        list(, $json) = $this->table($hr, 'devices/logs_list', array('device_id' => (string) $id));
        $this->assertSame(1, $json['recordsFiltered']);

        $role = $this->insert_role('role_dv_admin', array(), TRUE);
        $this->insert_user('dv_admin', $role);
        $admin = new Http_client(self::$server->port);
        $admin->login('dv_admin', 'Passw0rd!x');
        list(, $json) = $this->table($admin, 'devices/logs_list', array('errors_only' => '1'));
        $this->assertSame(1, $json['recordsFiltered'], 'the system Admin also sees the calls that failed authentication');
        $this->assertStringContainsString('bad serial', $json['data'][0]['message']);
    }
}
