<?php
/**
 * P16 compensation (4): Attendance > Import punches takes the USB export (.dat) or a CSV, previews it without writing, and
 * importing is idempotent, so a punch lost between the device and the server can be put back by hand - twice is harmless.
 */
class Punch_import_test extends Device_test_case
{
    protected $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = HRM_ROOT . '/tests/_output/';
    }

    protected function make_user($username, array $permissions)
    {
        $role = $this->insert_role('role_' . $username, $permissions);
        $this->insert_user($username, $role);
        $client = new Http_client(self::$server->port);
        $client->login($username, 'Passw0rd!x');
        return $client;
    }

    protected function file($name, $content)
    {
        file_put_contents($this->dir . $name, $content);
        return $this->dir . $name;
    }

    protected function preview(Http_client $c, $device_id, $path)
    {
        list($status, $body) = $c->post_multipart('attendance/import_preview', array('device_id' => (string) $device_id), array('punch_file' => $path));
        return array($status, json_decode($body, TRUE), $body);
    }

    protected function usb_file()
    {
        return $this->file('usb.dat', "      1\t2026-01-20 08:30:15\t1\t1\t0\t0\n"
            . "      1\t2026-01-20 17:45:00\t1\t1\t0\t0\n"
            . "      2\t2026-01-20 08:41:00\t1\t4\t0\t0\n"
            . "     99\t2026-01-20 09:00:00\t1\t1\t0\t0\n"
            . "garbage line\n");
    }

    public function test_the_preview_reports_and_writes_nothing()
    {
        $e1 = $this->dv_employee('A', '1');
        $this->dv_employee('B', '2');
        $device = $this->insert_device('USB gate');
        $hr = $this->make_user('dv_imp', array('attendance_import'));
        list($status, $r) = $this->preview($hr, $device, $this->usb_file());
        $this->assertSame(200, $status);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(array(5, 4, 1, 4, 0), array($r['rows'], $r['valid'], $r['invalid'], $r['new'], $r['already']));
        $this->assertSame('USB gate', $r['device']);
        $this->assertSame(array(array('pin' => '99', 'punches' => 1)), $r['unmatched'], 'a PIN of nobody is reported');
        $this->assertCount(4, $r['sample']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $r['token']);
        $this->assertSame(0, $this->punch_count(), 'nothing is written by the preview');
        $this->assertSame(0, $this->db->count_all('attendance'));
        $this->assertNull($this->db->get_where('attendance', array('employee_id' => $e1))->row());
    }

    public function test_importing_stores_the_punches_and_is_idempotent()
    {
        $e1 = $this->dv_employee('A', '1');
        $e2 = $this->dv_employee('B', '2');
        $device = $this->insert_device('USB gate');
        $hr = $this->make_user('dv_imp', array('attendance_import'));
        list(, $r) = $this->preview($hr, $device, $this->usb_file());
        list(, $body) = $hr->post('attendance/import_commit', array('token' => $r['token']), array('X-Requested-With: XMLHttpRequest'));
        $this->assertSame('success<<<###>>>4/0/1', $body, 'stored / already stored / unmatched');
        $this->assertSame(4, $this->punch_count(array('device_id' => $device, 'source' => 'import')));
        $day = $this->db->get_where('attendance', array('employee_id' => $e1))->row_array();
        $this->assertSame(array('08:30:15', '17:45:00'), array($day['check_in'], $day['check_out']));
        $this->assertSame('08:41:00', $this->db->get_where('attendance', array('employee_id' => $e2))->row()->check_in);
        $this->assertNull($this->db->get_where('attendance_punches', array('device_pin' => '99'))->row()->employee_id);
        $this->assertCount(1, $this->audit_rows('attendance', $device, 'import_punches'));
        $this->assertSame(array(), glob(HRM_ROOT . '/uploads/import/*.json'), 'the parsed rows are removed after the import');

        // the same file again: everything is "already stored", and nothing changes
        list(, $r2) = $this->preview($hr, $device, $this->usb_file());
        $this->assertSame(array(0, 4), array($r2['new'], $r2['already']));
        list(, $body) = $hr->post('attendance/import_commit', array('token' => $r2['token']), array('X-Requested-With: XMLHttpRequest'));
        $this->assertSame('success<<<###>>>0/4/0', $body);
        $this->assertSame(4, $this->punch_count());

        // a punch the service already delivered for the same device counts as stored too
        $this->service($this->make_key($this->first_branch_id(), $device), array('records' => array(array('user_id' => '1', 'attendance_datetime' => '2026-01-21 08:00:00', 'attendance_type' => '0'))));
        $file = $this->file('usb2.dat', "1\t2026-01-21 08:00:00\t0\t1\t0\n1\t2026-01-21 18:00:00\t1\t1\t0\n");
        list(, $r3) = $this->preview($hr, $device, $file);
        $this->assertSame(array(1, 1), array($r3['new'], $r3['already']));
        $hr->post('attendance/import_commit', array('token' => $r3['token']), array('X-Requested-With: XMLHttpRequest'));
        $day = $this->db->get_where('attendance', array('employee_id' => $e1, 'attendance_date' => '2026-01-21'))->row_array();
        $this->assertSame(array('08:00:00', '18:00:00'), array($day['check_in'], $day['check_out']), 'the day is rebuilt from the union');
    }

    public function test_a_csv_with_a_header_and_the_ui_date_format()
    {
        $this->dv_employee('A', '1');
        $device = $this->insert_device('CSV gate');
        $hr = $this->make_user('dv_imp', array('attendance_import'));
        $csv = $this->file('punches.csv', "pin,datetime,state\n1,20-01-2026 08:30,0\n1,20-01-2026 17:30:00,1\n1,not a date,1\n");
        list(, $r) = $this->preview($hr, $device, $csv);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(array(3, 2, 1), array($r['rows'], $r['valid'], $r['invalid']));
        $hr->post('attendance/import_commit', array('token' => $r['token']), array('X-Requested-With: XMLHttpRequest'));
        $this->assertSame(2, $this->punch_count());
        $this->assertSame('2026-01-20 08:30:00', $this->db->order_by('punch_at')->get('attendance_punches')->row()->punch_at);
    }

    public function test_device_times_are_converted_to_branch_time()
    {
        $this->dv_employee('A', '1');
        $device = $this->insert_device('Dubai', array('timezone' => 'Asia/Dubai'));
        $hr = $this->make_user('dv_imp', array('attendance_import'));
        list(, $r) = $this->preview($hr, $device, $this->file('tz.dat', "1\t2026-01-20 07:00:00\t0\t1\t0\n"));
        $hr->post('attendance/import_commit', array('token' => $r['token']), array('X-Requested-With: XMLHttpRequest'));
        $this->assertSame('2026-01-20 09:00:00', $this->db->get('attendance_punches')->row()->punch_at);
    }

    public function test_bad_files_and_bad_requests_are_refused()
    {
        $device = $this->insert_device('Gate');
        $other = $this->insert_device('Elsewhere', array(), $this->second_branch());
        $hr = $this->make_user('dv_imp', array('attendance_import'));

        list(, $r) = $this->preview($hr, $device, $this->file('x.exe', 'MZ'));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('.dat, .txt or .csv', $r['message']);
        list(, $r) = $this->preview($hr, $device, $this->file('bin.dat', "1\t2026-01-20 08:00:00\0\n"));
        $this->assertFalse($r['ok'], 'a file with NUL bytes is not a text export');
        list(, $r) = $this->preview($hr, $device, $this->file('empty.dat', "just\nwords\n"));
        $this->assertSame(t('imp_nothing'), $r['message']);
        list(, $r) = $this->preview($hr, $other, $this->usb_file());
        $this->assertFalse($r['ok'], 'a device of another branch');
        list($status, $body) = $hr->post_multipart('attendance/import_preview', array('device_id' => (string) $device), array());
        $this->assertFalse(json_decode($body, TRUE)['ok'], 'no file');

        foreach (array('', '../../etc/passwd', str_repeat('a', 32), 'zz') as $token) {
            list(, $body) = $hr->post('attendance/import_commit', array('token' => $token), array('X-Requested-With: XMLHttpRequest'));
            $token === '' ? $this->assertStringContainsString('required', $body) : $this->assertSame(t('imp_expired'), $body, 'token: ' . $token);
        }
        $this->assertSame(0, $this->punch_count());
    }

    public function test_a_preview_belongs_to_the_user_who_made_it_and_expires()
    {
        $this->dv_employee('A', '1');
        $device = $this->insert_device('Gate');
        $one = $this->make_user('dv_imp1', array('attendance_import'));
        $two = $this->make_user('dv_imp2', array('attendance_import'));
        list(, $r) = $this->preview($one, $device, $this->usb_file());
        list(, $body) = $two->post('attendance/import_commit', array('token' => $r['token']), array('X-Requested-With: XMLHttpRequest'));
        $this->assertSame(t('imp_expired'), $body, 'another user cannot import it');
        $path = HRM_ROOT . '/uploads/import/' . $r['token'] . '.json';
        $this->assertFileExists($path);
        $this->CI->load->model('Punch_import_model');
        touch($path, time() - Punch_import_model::KEEP_SECONDS - 5);
        list(, $body) = $one->post('attendance/import_commit', array('token' => $r['token']), array('X-Requested-With: XMLHttpRequest'));
        $this->assertSame(t('imp_expired'), $body, 'after an hour it is gone');
        $this->assertSame(0, $this->punch_count());
    }

    public function test_the_import_is_audited_and_needs_its_permission()
    {
        $device = $this->insert_device('Gate');
        $punches_only = $this->make_user('dv_punch', array('attendance_punches'));
        list($status) = $punches_only->post_multipart('attendance/import_preview', array('device_id' => (string) $device), array('punch_file' => $this->usb_file()));
        $this->assertSame(403, $status);
        list($status) = $punches_only->post('attendance/import_commit', array('token' => str_repeat('a', 32)), array('X-Requested-With: XMLHttpRequest'));
        $this->assertSame(403, $status);
    }
}
