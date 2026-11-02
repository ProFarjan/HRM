<?php
/**
 * P16 compensation: the server makes lost punches detectable. cron device_health raises "possible lost punches" when a device
 * uploaded nothing for device_gap_hours although a shift of its branch began meanwhile; the Devices page shows green / amber / red
 * and the clock drift.
 */
class Device_health_test extends Device_test_case
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Devices_model');
    }

    protected function ts($seconds_ago)
    {
        return date('Y-m-d H:i:s', time() - $seconds_ago);
    }

    /** an employee of the first branch on a shift that began $minutes_ago minutes ago (every day is a working day) */
    protected function worker_on_shift_started($minutes_ago)
    {
        set_current_branch_id($this->first_branch_id());
        $start = date('H:i:s', time() - $minutes_ago * 60);
        $this->db->insert('shifts', array('branch_id' => $this->first_branch_id(), 'shift_name' => 'Health', 'shift_code' => 'DVH', 'start_time' => $start, 'end_time' => '23:59:00',
            'break_duration' => 0, 'working_hours' => 8, 'grace_time' => 0, 'status' => 'Active', 'created_at' => date('Y-m-d H:i:s')));
        $shift = (int) $this->db->insert_id();
        $emp = $this->dv_employee('W', '300');
        $this->db->insert('employee_shifts', array('employee_id' => $emp, 'shift_id' => $shift, 'effective_from' => '2024-01-01', 'created_at' => date('Y-m-d H:i:s')));
        return $emp;
    }

    protected function check()
    {
        set_current_branch_id($this->first_branch_id());
        $this->CI->load->model('Devices_model');
        return $this->CI->Devices_model->check_health($this->first_branch_id());
    }

    // ---------------------------------------------------------------------------------------------- colours and drift

    public function test_the_health_colours()
    {
        $now = time();
        $at = function ($seconds) use ($now) { return array('last_seen_at' => date('Y-m-d H:i:s', $now - $seconds), 'clock_offset_seconds' => NULL); };
        $this->assertSame('green', Devices_model::health($at(5), $now)['color']);
        $this->assertSame('green', Devices_model::health($at(599), $now)['color']);
        $this->assertSame('yellow', Devices_model::health($at(600), $now)['color'], 'from 10 minutes: amber');
        $this->assertSame('yellow', Devices_model::health($at(3599), $now)['color']);
        $this->assertSame('red', Devices_model::health($at(3600), $now)['color'], 'from 60 minutes: red');
        $this->assertSame('red', Devices_model::health(array('last_seen_at' => NULL, 'clock_offset_seconds' => NULL), $now)['color'], 'never seen');
    }

    public function test_clock_drift_over_five_minutes()
    {
        $base = array('last_seen_at' => NULL);
        $this->assertFalse(Devices_model::health($base + array('clock_offset_seconds' => NULL))['drift']);
        $this->assertFalse(Devices_model::health($base + array('clock_offset_seconds' => 300))['drift']);
        $this->assertTrue(Devices_model::health($base + array('clock_offset_seconds' => 301))['drift']);
        $this->assertTrue(Devices_model::health($base + array('clock_offset_seconds' => -400))['drift']);
    }

    public function test_a_realtime_push_estimates_the_device_clock()
    {
        $this->db->insert('devices', array('branch_id' => $this->first_branch_id(), 'name' => 'Drifter', 'serial_number' => 'DRIFT00001', 'comm_mode' => 'adms_push', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')));
        date_default_timezone_set('Asia/Dhaka');
        $ahead = date('Y-m-d H:i:s', time() + 1800);               // the device clock runs 30 minutes fast
        $this->http->raw('POST', 'iclock/cdata?SN=DRIFT00001&table=ATTLOG', "55\t" . $ahead . "\t0\t1\t0\n");
        $offset = (int) $this->db->get('devices')->row()->clock_offset_seconds;
        $this->assertEqualsWithDelta(1800, $offset, 5);
        // a large batch (a backlog) says nothing about the clock
        $this->db->update('devices', array('clock_offset_seconds' => NULL));
        $lines = '';
        for ($i = 0; $i < 5; $i++) {
            $lines .= '56' . "\t" . date('Y-m-d H:i:s', time() - 7200 - $i * 60) . "\t0\t1\t0\n";
        }
        $this->http->raw('POST', 'iclock/cdata?SN=DRIFT00001&table=ATTLOG', $lines);
        $this->assertNull($this->db->get('devices')->row()->clock_offset_seconds);
    }

    // ---------------------------------------------------------------------------------------------- lost punches

    public function test_silence_while_a_shift_began_raises_the_alert()
    {
        $this->worker_on_shift_started(60);
        $id = $this->insert_device('Quiet', array('last_upload_at' => $this->ts(20 * 3600), 'last_seen_at' => $this->ts(60)));
        $report = $this->check();
        $this->assertTrue($report['Quiet']['alert']);
        $this->assertEqualsWithDelta(20, $report['Quiet']['silent_hours'], 0.1);
        $row = $this->db->get_where('devices', array('id' => $id))->row_array();
        $this->assertSame(1, (int) $row['lost_punch_alert']);
        $this->assertNotNull($row['health_checked_at']);
    }

    public function test_no_alert_below_the_gap_or_without_a_shift()
    {
        $this->worker_on_shift_started(60);
        $this->insert_device('Recent', array('last_upload_at' => $this->ts(3 * 3600)));
        $report = $this->check();
        $this->assertFalse($report['Recent']['alert'], 'silent for 3 h only (gap 12 h)');

        $this->db->query('DELETE FROM employee_shifts');
        $this->db->update('devices', array('last_upload_at' => $this->ts(40 * 3600)));
        $report = $this->check();
        $this->assertFalse($report['Recent']['alert'], 'silent for 40 h but nobody had a shift: nothing was expected');
    }

    public function test_no_alert_when_the_shift_began_before_the_last_upload()
    {
        // shift began 30 min ago, the device uploaded 10 min ago and was silent for 13 h before: it was working when the shift began
        $this->worker_on_shift_started(30);
        $this->insert_device('Alive', array('last_upload_at' => $this->ts(600)));
        $report = $this->check();
        $this->assertFalse($report['Alive']['alert']);
    }

    public function test_the_gap_is_a_setting()
    {
        $this->worker_on_shift_started(60);
        $this->insert_device('Quiet', array('last_upload_at' => $this->ts(20 * 3600)));
        $this->db->query('UPDATE hr_settings SET device_gap_hours = 24 WHERE id = 1');
        hr_setting_reset_cache();
        $this->assertFalse($this->check()['Quiet']['alert']);
        $this->db->query('UPDATE hr_settings SET device_gap_hours = 12 WHERE id = 1');
        hr_setting_reset_cache();
        $this->assertTrue($this->check()['Quiet']['alert']);
    }

    public function test_a_device_that_never_uploaded_counts_from_its_creation()
    {
        $this->worker_on_shift_started(60);
        $this->insert_device('Newcomer', array('created_at' => $this->ts(30 * 3600)));
        $this->assertTrue($this->check()['Newcomer']['alert']);
        $this->insert_device('Brand new');
        $this->assertFalse($this->check()['Brand new']['alert'], 'created just now: give it time');
    }

    public function test_the_alert_clears_when_punches_arrive_and_disabled_devices_are_skipped()
    {
        $this->worker_on_shift_started(60);
        $id = $this->insert_device('Quiet', array('last_upload_at' => $this->ts(20 * 3600)));
        $this->insert_device('Off', array('last_upload_at' => $this->ts(20 * 3600), 'status' => 'disabled'));
        $report = $this->check();
        $this->assertArrayNotHasKey('Off', $report, 'a disabled device is not expected to upload');
        $this->assertSame(1, (int) $this->db->get_where('devices', array('id' => $id))->row()->lost_punch_alert);

        $key = $this->make_key($this->first_branch_id(), $id);
        $this->service($key, array('records' => array(array('user_id' => '300', 'attendance_datetime' => date('Y-m-d H:i:s'), 'attendance_type' => '0'))));
        $this->assertSame(0, (int) $this->db->get_where('devices', array('id' => $id))->row()->lost_punch_alert, 'data arrived');
    }

    public function test_a_heartbeat_does_not_hide_lost_punches()
    {
        $this->worker_on_shift_started(60);
        $id = $this->insert_device('Quiet', array('last_upload_at' => $this->ts(20 * 3600)));
        $key = $this->make_key($this->first_branch_id(), $id);
        $this->service($key, array('records' => array()));
        $this->assertTrue($this->check()['Quiet']['alert'], 'the device is alive but still delivers nothing');
        $this->assertSame('green', Devices_model::health($this->db->get_where('devices', array('id' => $id))->row_array())['color']);
    }

    // ---------------------------------------------------------------------------------------------- cron

    public function test_the_cron_job_prints_one_line_per_device()
    {
        $this->worker_on_shift_started(60);
        $this->insert_device('Quiet', array('last_upload_at' => $this->ts(20 * 3600), 'last_seen_at' => $this->ts(7200)));
        $this->insert_device('Fine', array('last_upload_at' => $this->ts(60), 'last_seen_at' => $this->ts(30), 'clock_offset_seconds' => 900));
        $env = array_filter(array_merge($_SERVER, array('CI_ENV' => 'testing', 'TENANT_DB' => 'hrm_test')), 'is_string');
        $proc = proc_open(array(PHP_BINARY, HRM_ROOT . '/index.php', 'cron', 'device_health'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, HRM_ROOT, $env);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($proc), $out);
        $this->assertMatchesRegularExpression('/hrm_test HO Quiet: silent 20\.\d h - POSSIBLE LOST PUNCHES, stale/', $out);
        $this->assertMatchesRegularExpression('/hrm_test HO Fine: silent 0\.\d h - clock drift/', $out);
        $this->assertSame(1, (int) $this->db->get_where('devices', array('name' => 'Quiet'))->row()->lost_punch_alert);
        $this->assertSame(404, (new Http_client(self::$server->port))->get('cron/device_health')[0], 'not over HTTP');
    }
}
