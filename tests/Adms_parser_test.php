<?php
/** The ADMS push text protocol and the Windows Service JSON, parsed without a database (CLAUDE.md "Attendance devices"). */
class Adms_parser_test extends Hrm_test_case
{
    protected function fixture($name)
    {
        return file_get_contents(HRM_ROOT . '/tests/fixtures/' . $name);
    }

    public function test_tab_separated_attlog_with_crlf()
    {
        $r = Adms_protocol::parse_attlog($this->fixture('adms/attlog_tabs.txt'));
        $this->assertSame(3, count($r['punches']));
        $this->assertSame(0, $r['junk']);
        $this->assertSame(array('device_pin' => '101', 'punch_at' => '2026-01-20 08:30:15', 'punch_state' => 0, 'verify_mode' => 1, 'work_code' => NULL), $r['punches'][0]);
        $this->assertSame(1, $r['punches'][2]['punch_state']);
        $this->assertSame(15, $r['punches'][1]['verify_mode']);
    }

    public function test_space_separated_lines_and_irregular_spacing()
    {
        $r = Adms_protocol::parse_attlog($this->fixture('adms/attlog_spaces.txt'));
        $this->assertSame(2, count($r['punches']));
        $this->assertSame('2026-01-20 08:31:02', $r['punches'][1]['punch_at']);
        $this->assertSame(4, $r['punches'][1]['verify_mode']);
        $this->assertNull($r['punches'][1]['work_code']);
    }

    public function test_date_and_time_in_separate_columns()
    {
        $r = Adms_protocol::parse_attlog($this->fixture('adms/attlog_split.txt'));
        $this->assertSame(2, count($r['punches']));
        $this->assertSame('2026-01-20 08:30:15', $r['punches'][0]['punch_at']);
        $this->assertSame(4, $r['punches'][1]['punch_state'], 'OT-in state');
    }

    public function test_junk_lines_are_counted_not_stored()
    {
        $r = Adms_protocol::parse_attlog($this->fixture('adms/attlog_junk.txt'));
        // junk: "ATTLOG", non numeric PIN, bad date, 30 February, 10 digit PIN; the two lines of PIN 103 are fine (status and verify are optional)
        $this->assertSame(5, $r['junk']);
        $this->assertSame(2, count($r['punches']));
        $this->assertSame('103', $r['punches'][0]['device_pin']);
        $this->assertSame(255, $r['punches'][0]['punch_state'], 'no status column');
        $this->assertSame(7, $r['lines'], 'blank lines are not counted');
    }

    public function test_an_unknown_status_becomes_255_and_a_zero_workcode_is_none()
    {
        $r = Adms_protocol::parse_attlog("7\t2026-01-20 08:00:00\t9\t1\t0\n");
        $this->assertSame(255, $r['punches'][0]['punch_state']);
        $this->assertNull($r['punches'][0]['work_code']);
        $r = Adms_protocol::parse_attlog("7\t2026-01-20 08:00:00\t0\t1\t12\n");
        $this->assertSame('12', $r['punches'][0]['work_code']);
    }

    public function test_the_line_limit_is_enforced()
    {
        $body = str_repeat("5\t2026-01-20 08:00:00\t0\t1\n", 10);
        $r = Adms_protocol::parse_attlog($body, 4);
        $this->assertSame(4, count($r['punches']));
        $this->assertSame(6, $r['junk']);
        $this->assertSame(10, $r['lines'], 'lines past the limit are still counted (as junk)');
    }

    public function test_serial_numbers_are_validated_before_the_database_sees_them()
    {
        foreach (array('CGDL203760123', 'ABC_def-123', 'abc') as $ok) {
            $this->assertTrue(Adms_protocol::valid_serial($ok), $ok);
        }
        foreach (array('', 'ab', "x'; DROP TABLE devices;--", 'with space', str_repeat('A', 41), array('A'), NULL) as $bad) {
            $this->assertFalse(Adms_protocol::valid_serial($bad), json_encode($bad));
        }
    }

    public function test_the_handshake_text()
    {
        $text = Adms_protocol::handshake('SN123');
        $this->assertSame("GET OPTION FROM: SN123\nErrorDelay=60\nDelay=30\nTransTimes=00:00;14:05\nTransInterval=1\nTransFlag=TransData AttLog\tOpLog\nRealtime=1\nEncrypt=0\n", $text);
    }

    public function test_command_results_of_devicecmd()
    {
        $r = Adms_protocol::parse_command_results("ID=7&Return=0&CMD=DATA\r\nID=8&Return=-1002&CMD=DATA\nnonsense\nID=x&Return=0\n");
        $this->assertSame(array(array('id' => 7, 'success' => TRUE, 'return' => '0'), array('id' => 8, 'success' => FALSE, 'return' => '-1002')), $r);
    }

    public function test_command_lines_for_the_device()
    {
        $line = Adms_protocol::command_line(array('id' => 7, 'command_type' => 'create_user', 'payload' => array('user_id' => '101', 'name' => "Ra\thim", 'role' => 0, 'password' => '', 'card_no' => '')));
        $this->assertSame("C:7:DATA UPDATE USERINFO PIN=101\tName=Ra him\tPri=0\tPasswd=\tCard=", $line, 'a tab inside a value cannot break the line');
        $this->assertSame('C:8:DATA DELETE USERINFO PIN=101', Adms_protocol::command_line(array('id' => 8, 'command_type' => 'delete_user', 'payload' => array('user_id' => '101'))));
        $this->assertSame('C:10:REBOOT', Adms_protocol::command_line(array('id' => 10, 'command_type' => 'reboot', 'payload' => array())));
        $this->assertSame('C:11:CLEAR LOG', Adms_protocol::command_line(array('id' => 11, 'command_type' => 'clear_attlog', 'payload' => array())));
        $this->assertSame('C:12:SET OPTIONS DateTime=2026-01-20 10:00:00', Adms_protocol::command_line(array('id' => 12, 'command_type' => 'sync_time', 'payload' => array('datetime' => '2026-01-20 10:00:00'))));
        $this->assertNull(Adms_protocol::command_line(array('id' => 13, 'command_type' => 'unknown', 'payload' => array())));
    }

    public function test_firmware_out_of_info()
    {
        $this->assertSame('Ver 6.60', Adms_protocol::firmware('Ver 6.60,12,300,5,192.168.1.50'));
        $this->assertNull(Adms_protocol::firmware(''));
        $this->assertNull(Adms_protocol::firmware(NULL));
    }

    // ---------------------------------------------------------------------------------------------- Windows Service JSON

    public function test_service_batch_accepts_both_field_spellings()
    {
        $r = Service_payload::parse($this->fixture('service/batch.json'));
        $this->assertTrue($r['ok']);
        $this->assertSame('Main Gate', $r['device_name']);
        $this->assertSame(3, $r['total']);
        $this->assertSame(0, $r['invalid']);
        $this->assertSame('2026-01-20 17:45:00', $r['punches'][2]['punch_at'], 'timestamp with a T');
        $this->assertSame('101', $r['punches'][2]['device_pin'], 'id instead of user_id');
        $this->assertSame(1, $r['punches'][2]['punch_state']);
        $this->assertSame(4, $r['punches'][1]['verify_mode']);
    }

    public function test_service_test_and_heartbeat()
    {
        $t = Service_payload::parse($this->fixture('service/test.json'));
        $this->assertTrue($t['ok']);
        $this->assertTrue($t['test']);
        $h = Service_payload::parse($this->fixture('service/heartbeat.json'));
        $this->assertTrue($h['ok']);
        $this->assertFalse($h['test']);
        $this->assertSame(0, $h['total']);
    }

    public function test_service_junk_is_counted_and_a_non_json_body_is_refused()
    {
        $r = Service_payload::parse(json_encode(array('records' => array(
            array('user_id' => '5', 'attendance_datetime' => '2026-01-20 08:00:00'), array('user_id' => 'x1', 'attendance_datetime' => '2026-01-20 08:00:00'),
            array('user_id' => '6', 'attendance_datetime' => 'yesterday'), 'string', array('attendance_datetime' => '2026-01-20 08:00:00'),
        ))));
        $this->assertSame(5, $r['total']);
        $this->assertSame(4, $r['invalid']);
        $this->assertCount(1, $r['punches']);
        $this->assertFalse(Service_payload::parse('not json')['ok']);
        $this->assertFalse(Service_payload::parse('"a string"')['ok']);
        $big = Service_payload::parse(json_encode(array('records' => array_fill(0, Service_payload::MAX_RECORDS + 1, array('user_id' => '1', 'attendance_datetime' => '2026-01-20 08:00:00')))));
        $this->assertFalse($big['ok']);
    }

    public function test_service_datetime_forms()
    {
        $this->assertSame('2026-01-20 08:30:00', Service_payload::datetime('2026-01-20 08:30'));
        $this->assertSame('2026-01-20 08:30:15', Service_payload::datetime('2026-01-20T08:30:15.250'));
        $this->assertSame('2026-01-20 08:30:15', Service_payload::datetime('2026-01-20T08:30:15Z'), 'device time is local: the zone suffix is dropped');
        $this->assertNull(Service_payload::datetime('2026-02-30 08:30:15'));
        $this->assertNull(Service_payload::datetime('20-01-2026 08:30:15'));
    }

    public function test_command_results_of_the_service()
    {
        $r = Service_payload::parse(json_encode(array('records' => array(), 'command_results' => array(
            array('id' => 7, 'success' => TRUE, 'message' => 'User created.', 'data' => array('uid' => 12)), array('id' => 'x'), array('id' => 8, 'success' => FALSE),
        ))));
        $this->assertCount(2, $r['command_results']);
        $this->assertSame(array('id' => 7, 'success' => TRUE, 'message' => 'User created.', 'data' => array('uid' => 12)), $r['command_results'][0]);
        $this->assertFalse($r['command_results'][1]['success']);
    }

    public function test_claimed_branches_are_only_reported()
    {
        $this->assertSame(array('999', '2'), Service_payload::claimed_branches($this->fixture('service/foreign_branch.json')));
        $this->assertSame(array(), Service_payload::claimed_branches($this->fixture('service/batch.json')));
    }

    // ---------------------------------------------------------------------------------------------- USB / CSV files

    public function test_dat_export_and_csv_files()
    {
        $dat = Punch_file_parser::parse("      1\t2026-01-20 08:30:15\t1\t1\t0\t0\n      2\t2026-01-20 08:31:00\t1\t1\t0\t0\n", 'dat');
        $this->assertCount(2, $dat['punches']);
        $this->assertSame('1', $dat['punches'][0]['device_pin']);

        $csv = Punch_file_parser::parse("pin,datetime,state\n101,2026-01-20 08:30:15,0\n102,20-01-2026 08:31,1\n103;x\nabc,2026-01-20 08:00:00\n", 'csv', 'd-m-Y');
        $this->assertCount(2, $csv['punches'], 'header skipped, UI date format accepted');
        $this->assertSame('2026-01-20 08:31:00', $csv['punches'][1]['punch_at']);
        $this->assertSame(2, $csv['invalid']);

        $split = Punch_file_parser::parse("101;2026-01-20;08:30:15;0;1\n", 'csv');
        $this->assertSame('2026-01-20 08:30:15', $split['punches'][0]['punch_at'], 'semicolon delimiter, date and time in two columns');

        $bom = Punch_file_parser::parse("\xEF\xBB\xBF101,2026-01-20 08:30:15\n", 'csv');
        $this->assertCount(1, $bom['punches']);
    }
}
