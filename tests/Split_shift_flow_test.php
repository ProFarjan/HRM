<?php
/**
 * Split shifts over HTTP: the shift form with its duty periods, the mark / edit forms asking for a period at a time, a Windows Service
 * batch building a split day, and the reports showing the periods (in/out sheet, employee report, missing check-out, Excel).
 */
class Split_shift_flow_test extends Device_test_case
{
    protected function make_user($username, array $permissions)
    {
        $role = $this->insert_role('role_' . $username, $permissions);
        $this->insert_user($username, $role);
        $client = new Http_client(self::$server->port);
        $client->login($username, 'Passw0rd!x');
        return $client;
    }

    protected function ajax(Http_client $c, $url, array $post = array())
    {
        return $c->post($url, $post, array('X-Requested-With: XMLHttpRequest'));
    }

    /** a split-shift employee (PIN 71) on the 10-14 + 18-22 shift DVS1 */
    protected function split_employee()
    {
        set_current_branch_id($this->first_branch_id());
        $shift = $this->insert_split_shift('DVS1', array(array('10:00', '14:00'), array('18:00', '22:00')), array('grace_time' => 10));
        $emp = $this->dv_employee('S1', '71');
        $this->assign_shift($emp, $shift);
        return array($emp, $shift);
    }

    // ---------------------------------------------------------------------------------------------- the shift form

    public function test_the_shift_form_offers_periods_and_saves_them()
    {
        $hr = $this->make_user('dv_shifts', array('shifts_view', 'shifts_add', 'shifts_edit'));
        list($status, $html) = $hr->get('shifts/add');
        $this->assertSame(200, $status);
        foreach (array('f_split', 'periods-table', 'seg_start[]', 'seg_end[]', 'period-add', 'single-fields') as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        list(, $body) = $this->ajax($hr, 'shifts/save', array('shift_name' => 'Two parts', 'shift_code' => 'dvs2', 'is_split' => '1', 'seg_start' => array('10:00', '18:00'),
            'seg_end' => array('14:00', '22:00'), 'grace_time' => '10', 'shift_allowance' => '0', 'status' => 'Active'));
        $this->assertStringStartsWith('success<<<###>>>', $body, $body);
        $id = (int) substr($body, strlen('success<<<###>>>'));
        list(, $html) = $hr->get('shifts/edit/' . $id);
        $this->assertStringContainsString('value="18:00"', $html, 'the periods come back into the form');
        $this->assertMatchesRegularExpression('/id="f_split"[^>]*checked/', $html);
        list(, $body) = $this->ajax($hr, 'shifts/update/' . $id, array('shift_name' => 'Two parts', 'shift_code' => 'dvs2', 'is_split' => '1', 'seg_start' => array('10:00'), 'seg_end' => array('14:00'),
            'grace_time' => '10', 'shift_allowance' => '0', 'status' => 'Active'));
        $this->assertStringContainsString(t('shift_periods_count'), $body);
        list(, $json) = $hr->post('shifts/ajax_list', array('draw' => '1', 'start' => '0', 'length' => '25'), array('X-Requested-With: XMLHttpRequest'));
        $rows = json_decode($json, TRUE)['data'];
        $row = array_values(array_filter($rows, function ($r) { return $r['code'] === 'DVS2'; }))[0];
        $this->assertStringContainsString('10:00-14:00 + 18:00-22:00', $row['name']);
        $this->assertStringContainsString(t('shift_split'), $row['name']);
        $this->assertSame('8.00', $row['hours']);
    }

    // ---------------------------------------------------------------------------------------------- marking by hand

    public function test_the_mark_form_asks_for_each_period_and_day_info_is_protected()
    {
        list($emp) = $this->split_employee();
        $hr = $this->make_user('dv_marker', array('attendance_view', 'attendance_add', 'attendance_edit', 'show_all_attendance'));
        list(, $body) = $this->ajax($hr, 'attendance/day_info', array('employee_id' => (string) $emp, 'attendance_date' => '06-04-2026'));
        $info = json_decode($body, TRUE);
        $this->assertTrue($info['split']);
        $this->assertCount(2, $info['periods']);
        list($status, $html) = $hr->get('attendance/mark');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('period-fields', $html);
        $this->assertStringContainsString('data-day-info-url', $html);

        list(, $body) = $this->ajax($hr, 'attendance/save_mark', array('employee_id' => (string) $emp, 'attendance_date' => '06-04-2026', 'status' => 'Present',
            'seg_in' => array('10:00', '18:15'), 'seg_out' => array('14:00', '22:00')));
        $id = (int) substr($body, strlen('success<<<###>>>'));
        $this->assertGreaterThan(0, $id, $body);
        $row = $this->attendance_row($emp, '2026-04-06');
        $this->assertEquals(round((240 + 225) / 60, 2), (float) $row['working_hours']);
        list(, $html) = $hr->get('attendance/edit/' . $id);
        $this->assertStringContainsString('data-periods', $html);
        $this->assertStringContainsString('&quot;in&quot;:&quot;18:15&quot;', $html, 'the edit form is filled with what was recorded in each period');

        $nobody = $this->make_user('dv_nobody', array('attendance_view'));
        list($status) = $this->ajax($nobody, 'attendance/day_info', array('employee_id' => (string) $emp, 'attendance_date' => '06-04-2026'));
        $this->assertSame(403, $status);
    }

    public function test_a_service_batch_builds_the_split_day()
    {
        list($emp) = $this->split_employee();
        $key = $this->make_key();
        list($status, $body) = $this->service($key, array('device_name' => 'Gate', 'records' => array(
            array('user_id' => '71', 'attendance_datetime' => '2026-04-06 10:03:00'), array('user_id' => '71', 'attendance_datetime' => '2026-04-06 14:00:00'),
            array('user_id' => '71', 'attendance_datetime' => '2026-04-06 17:58:00'), array('user_id' => '71', 'attendance_datetime' => '2026-04-06 22:02:00'),
        )));
        $this->assertSame(200, $status, json_encode($body));
        $row = $this->attendance_row($emp, '2026-04-06');
        $this->assertEquals(round((237 + 244) / 60, 2), (float) $row['working_hours']);
        $this->assertCount(2, $this->periods_of($row['id']));
        list($status) = $this->service($key, array('device_name' => 'Gate', 'records' => array(array('user_id' => '71', 'attendance_datetime' => '2026-04-06 22:02:00'))));
        $this->assertSame(200, $status);
        $this->assertCount(2, $this->periods_of($row['id']), 'a resend changes nothing');
    }

    // ---------------------------------------------------------------------------------------------- reports

    protected function seed_report_days($emp)
    {
        $this->db->insert('attendance', array('branch_id' => $this->first_branch_id(), 'employee_id' => $emp, 'attendance_date' => '2026-04-06', 'status' => 'Present', 'check_in' => '10:02:00',
            'check_out' => '22:03:00', 'working_hours' => 8.12, 'late_minutes' => 0, 'approval_status' => 'Approved', 'source' => 'punch', 'created_at' => date('Y-m-d H:i:s')));
        $full = (int) $this->db->insert_id();
        $this->db->insert('attendance', array('branch_id' => $this->first_branch_id(), 'employee_id' => $emp, 'attendance_date' => '2026-04-07', 'status' => 'Present', 'check_in' => '10:00:00',
            'check_out' => '14:00:00', 'working_hours' => 4, 'approval_status' => 'Approved', 'source' => 'punch', 'remarks' => Punch_ingestor::REMARK_INCOMPLETE, 'created_at' => date('Y-m-d H:i:s')));
        $half = (int) $this->db->insert_id();
        $this->db->update('attendance', array('shift_id' => (int) $this->db->get_where('shifts', array('shift_code' => 'DVS1'))->row()->id), array('employee_id' => $emp));
        Attendance_periods::store($full, array(array('no' => 1, 'check_in' => '10:02:00', 'check_out' => '14:01:00', 'worked_minutes' => 239, 'late_minutes' => 0, 'early_leave_minutes' => 0),
            array('no' => 2, 'check_in' => '17:55:00', 'check_out' => '22:03:00', 'worked_minutes' => 248, 'late_minutes' => 0, 'early_leave_minutes' => 0)));
        Attendance_periods::store($half, array(array('no' => 1, 'check_in' => '10:00:00', 'check_out' => '14:00:00', 'worked_minutes' => 240, 'late_minutes' => 0, 'early_leave_minutes' => 0),
            array('no' => 2, 'check_in' => NULL, 'check_out' => NULL, 'worked_minutes' => 0, 'late_minutes' => 0, 'early_leave_minutes' => 0)));
    }

    public function test_the_reports_show_every_period()
    {
        list($emp) = $this->split_employee();
        $this->seed_report_days($emp);
        $viewer = $this->make_user('dv_viewer', array('attendance_report', 'show_all_attendance', 'export_hr'));

        list(, $html) = $viewer->get('reports/inout?month=4&year=2026');
        $this->assertStringContainsString('io-split', $html);
        $this->assertStringContainsString('17:55', $html);
        $this->assertStringContainsString('22:03', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'io-period'), 'a block per period');

        list(, $html) = $viewer->get('reports/employee?employee_id=' . $emp . '&mode=month&month=4&year=2026');
        $this->assertStringContainsString(t('shift_split'), $html);
        $this->assertStringContainsString('<div>14:01</div>', $html);
        $this->assertStringContainsString('<div>22:03</div>', $html);
        $this->assertStringContainsString('A duty period is incomplete on 1 day(s).', $html);

        list(, $html) = $viewer->get('reports/missing?from_date=01-04-2026&to_date=30-04-2026');
        $this->assertStringContainsString('07-04-2026', $html);
        $this->assertStringContainsString(sprintf(t('rep_reason_period_missed'), 2), $html);
        $this->assertStringNotContainsString('06-04-2026 Mon', $html, 'the complete day is not listed');

        list(, $html) = $viewer->get('reports/summary?from_date=01-04-2026&to_date=30-04-2026');
        $this->assertStringContainsString('duty period with no punches or no check-out', $html);

        // Excel
        $sheet = function ($bytes) {
            $file = HRM_ROOT . '/tests/_output/split_export.xlsx';
            file_put_contents($file, $bytes);
            $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet()->toArray();
            @unlink($file);
            return $rows;
        };
        list(, $bytes) = $viewer->get('reports/inout_xlsx?month=4&year=2026');
        $line = NULL;
        foreach ($sheet($bytes) as $r) {
            $line = $r[0] === 'DV-S1' ? $r : $line;
        }
        $this->assertSame('10:02-14:01 / 17:55-22:03', $line[2 + 5], '6 April');
        $this->assertSame('10:00-14:00 / -', $line[2 + 6], '7 April: the second period has no punches');
        list(, $bytes) = $viewer->get('reports/employee_xlsx?employee_id=' . $emp . '&mode=range&from_date=06-04-2026&to_date=07-04-2026');
        $rows = $sheet($bytes);
        $this->assertSame(array('10:02 / 17:55', '14:01 / 22:03'), array($rows[1][4], $rows[1][5]));
        list(, $bytes) = $viewer->get('reports/missing_xlsx?from_date=01-04-2026&to_date=30-04-2026');
        $rows = $sheet($bytes);
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('Duty period 2: no punches', $rows[1][5]);
    }
}
