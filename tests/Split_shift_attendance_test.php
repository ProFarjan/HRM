<?php
/**
 * A person who works in parts (10:00-14:00 and 18:00-22:00) through the attendance section: rebuilding the day from punches, marking by
 * hand (a period at a time, or one in and out), the list, day close, and the periods staying in step with the day.
 */
class Split_shift_attendance_test extends Hrm_test_case
{
    /** @var Punch_ingestor */
    protected $ingestor;
    protected $emp;
    protected $shift;
    protected $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Attendance_model');
        $this->act_as_new_user('boss', array(), TRUE);
        $this->db->update('branches', array('weekly_offs' => ''), array('id' => current_branch_id()));
        $this->db->update('hr_settings', array('punch_debounce_seconds' => 60, 'ot_min_minutes' => 0, 'early_leave_counts_as_late' => 0), array('id' => 1));
        hr_setting_reset_cache();
        $this->shift = $this->insert_split_shift('SPL', array(array('10:00', '14:00'), array('18:00', '22:00')), array('grace_time' => 10));
        $this->emp = $this->insert_employee('SA1', array('device_pin' => '61', 'device_pin_from' => '2024-01-01'));
        $this->assign_shift($this->emp, $this->shift);
        $this->ingestor = new Punch_ingestor();
        $this->device = $this->insert_device('Split gate', array(), current_branch_id());
    }

    protected function punch(array $times, $date = '2026-04-06')
    {
        $punches = array();
        foreach ($times as $t) {
            $punches[] = array('device_pin' => '61', 'punch_at' => $date . ' ' . $t . ':00');
        }
        $r = $this->ingestor->ingest($punches, array('branch_id' => current_branch_id(), 'device_id' => $this->device, 'source' => 'device_service'));
        $this->assertTrue($r['ok'], $r['message']);
        return $r;
    }

    protected function day($date = '2026-04-06')
    {
        return $this->attendance_row($this->emp, $date);
    }

    // ---------------------------------------------------------------------------------------------- from punches

    public function test_four_punches_build_a_day_of_two_periods()
    {
        $this->punch(array('10:02', '14:01', '17:55', '22:03'));
        $row = $this->day();
        $this->assertSame(array('Present', 'punch', '10:02:00', '22:03:00'), array($row['status'], $row['source'], $row['check_in'], $row['check_out']));
        $this->assertEquals(round((239 + 248) / 60, 2), (float) $row['working_hours'], 'both periods, and not the four hours in between');
        $this->assertSame(234, (int) $row['break_minutes'], '14:01 to 17:55 is the gap actually taken');
        $this->assertSame(array(0, 0), array((int) $row['late_minutes'], (int) $row['early_leave_minutes']));
        $this->assertNull($row['remarks']);
        $this->assertEquals(0.12, (float) $row['overtime_hours'], '5 min early to the second period and 3 after: worked 487 of 480 minutes (7 minutes)');
        $periods = $this->periods_of($row['id']);
        $this->assertCount(2, $periods);
        $this->assertSame(array('10:02:00', '14:01:00', 239), array($periods[1]['check_in'], $periods[1]['check_out'], (int) $periods[1]['worked_minutes']));
        $this->assertSame(array('17:55:00', '22:03:00', 248), array($periods[2]['check_in'], $periods[2]['check_out'], (int) $periods[2]['worked_minutes']));
        $this->assertSame($this->shift, (int) $row['shift_id']);
    }

    public function test_late_back_from_the_break_and_leaving_early_are_found_per_period()
    {
        $this->punch(array('10:00', '13:45', '18:30', '22:00'));
        $row = $this->day();
        $this->assertSame(array(20, 15), array((int) $row['late_minutes'], (int) $row['early_leave_minutes']), '30 min late back from the break (minus 10 grace); left the first period 15 min early');
        $periods = $this->periods_of($row['id']);
        $this->assertSame(array(0, 15), array((int) $periods[1]['late_minutes'], (int) $periods[1]['early_leave_minutes']));
        $this->assertSame(array(20, 0), array((int) $periods[2]['late_minutes'], (int) $periods[2]['early_leave_minutes']));
    }

    public function test_an_incomplete_day_is_marked_and_completes_when_the_punch_arrives()
    {
        $this->punch(array('10:00', '14:00', '18:00'));
        $row = $this->day();
        $this->assertNull($row['check_out'], 'still in the second period');
        $this->assertSame(Punch_ingestor::REMARK_INCOMPLETE, $row['remarks']);
        $this->assertSame(array('complete', 'open'), array_map(function ($p) { return $p['check_out'] === NULL ? 'open' : 'complete'; }, array_values($this->periods_of($row['id']))));
        $this->punch(array('22:00'));
        $row = $this->day();
        $this->assertSame(array('22:00:00', NULL), array($row['check_out'], $row['remarks']));
        $this->assertEquals(8.0, (float) $row['working_hours']);
        $this->assertCount(2, $this->periods_of($row['id']), 'the periods are rewritten, not added to');
    }

    public function test_only_one_period_punched()
    {
        $this->punch(array('10:00', '14:00'));
        $row = $this->day();
        $this->assertEquals(4.0, (float) $row['working_hours']);
        $this->assertSame(Punch_ingestor::REMARK_INCOMPLETE, $row['remarks']);
        $periods = $this->periods_of($row['id']);
        $this->assertNull($periods[2]['check_in'], 'a period without punches is kept as a row with no times');
        $this->assertSame('14:00:00', $row['check_out']);
    }

    public function test_a_day_off_is_all_overtime()
    {
        $this->db->update('branches', array('weekly_offs' => '1,2,3,4,5,6,7'), array('id' => current_branch_id()));       // every day a weekly off
        $this->punch(array('10:40', '14:00', '19:00', '21:00'));
        $row = $this->day();
        $this->assertEquals(5.33, (float) $row['working_hours']);
        $this->assertEquals(5.33, (float) $row['overtime_hours']);
        $this->assertSame(0, (int) $row['late_minutes']);
    }

    public function test_changing_the_shift_back_clears_the_periods_on_rebuild()
    {
        $this->punch(array('10:00', '14:00', '18:00', '22:00'));
        $id = (int) $this->day()['id'];
        $this->assertCount(2, $this->periods_of($id));
        $normal = $this->insert_shift('NRM', '09:00:00', '18:00:00', array('working_hours' => 8));
        $this->db->query('DELETE FROM employee_shifts');
        $this->assign_shift($this->emp, $normal);
        $this->assertSame('rebuilt', $this->ingestor->rebuild_day($this->emp, '2026-04-06'));
        $this->assertSame(array(), $this->periods_of($id), 'an ordinary shift has no periods');
        $this->assertSame('22:00:00', $this->day()['check_out']);
    }

    public function test_a_night_split_shift()
    {
        $night = $this->insert_split_shift('SPN', array(array('18:00', '22:00'), array('23:00', '02:00')), array('grace_time' => 10));
        $emp = $this->insert_employee('SA2', array('device_pin' => '62', 'device_pin_from' => '2024-01-01'));
        $this->assign_shift($emp, $night);
        $punches = array(array('device_pin' => '62', 'punch_at' => '2026-04-06 18:00:00'), array('device_pin' => '62', 'punch_at' => '2026-04-06 22:00:00'),
            array('device_pin' => '62', 'punch_at' => '2026-04-06 23:00:00'), array('device_pin' => '62', 'punch_at' => '2026-04-07 02:01:00'));
        $r = $this->ingestor->ingest($punches, array('branch_id' => current_branch_id(), 'device_id' => $this->device, 'source' => 'device_service'));
        $this->assertTrue($r['ok']);
        $row = $this->attendance_row($emp, '2026-04-06');
        $this->assertSame(array('18:00:00', '02:01:00'), array($row['check_in'], $row['check_out']));
        $this->assertEquals(7.02, (float) $row['working_hours']);
        $this->assertNull($this->attendance_row($emp, '2026-04-07'), 'the after-midnight punch belongs to the day the shift began');
    }

    // ---------------------------------------------------------------------------------------------- by hand

    protected function mark(array $f)
    {
        $this->post(array_merge(array('employee_id' => (string) $this->emp, 'attendance_date' => '06-04-2026', 'status' => 'Present'), $f));
        return $this->CI->Attendance_model->mark();
    }

    public function test_marking_a_day_period_by_period()
    {
        $id = $this->id_of_result($this->mark(array('seg_in' => array('10:00', '18:20'), 'seg_out' => array('14:00', '22:00'))));
        $row = $this->day();
        $this->assertSame(array('manual', '10:00:00', '22:00:00'), array($row['source'], $row['check_in'], $row['check_out']));
        $this->assertEquals(round((240 + 220) / 60, 2), (float) $row['working_hours']);
        $this->assertSame(10, (int) $row['late_minutes']);
        $periods = $this->periods_of($id);
        $this->assertSame(array('18:20:00', '22:00:00', 10), array($periods[2]['check_in'], $periods[2]['check_out'], (int) $periods[2]['late_minutes']));
        $this->assertCount(1, $this->audit_rows('attendance', $id, 'mark'));
    }

    public function test_a_period_left_empty_or_without_a_check_out()
    {
        $id = $this->id_of_result($this->mark(array('seg_in' => array('10:00', ''), 'seg_out' => array('14:00', ''))));
        $row = $this->day();
        $this->assertEquals(4.0, (float) $row['working_hours']);
        $this->assertSame(Punch_ingestor::REMARK_INCOMPLETE, $row['remarks']);
        $this->assertNull($this->periods_of($id)[2]['check_in']);
        $this->assertStringContainsString(sprintf(t('att_period_out_without_in'), 2), $this->mark(array('attendance_date' => '07-04-2026', 'seg_in' => array('10:00', ''), 'seg_out' => array('14:00', '22:00'))));
        $this->assertStringContainsString(t('att_check_in_required'), $this->mark(array('attendance_date' => '08-04-2026', 'seg_in' => array('', ''), 'seg_out' => array('', ''))));
    }

    public function test_one_in_and_out_for_a_split_shift_counts_the_periods_between_them()
    {
        $id = $this->id_of_result($this->mark(array('check_in' => '10:00', 'check_out' => '22:00')));
        $row = $this->day();
        $this->assertEquals(8.0, (float) $row['working_hours'], 'not 12: the gap is not worked');
        $this->assertCount(2, $this->periods_of($id));
        $this->assertSame(array('10:00:00', '14:00:00'), array($this->periods_of($id)[1]['check_in'], $this->periods_of($id)[1]['check_out']));
    }

    public function test_editing_rewrites_the_periods_and_absent_clears_them()
    {
        $id = $this->id_of_result($this->mark(array('seg_in' => array('10:00', '18:00'), 'seg_out' => array('14:00', '22:00'))));
        $this->post(array('status' => 'Present', 'seg_in' => array('10:30', '18:00'), 'seg_out' => array('14:00', '21:00')));
        $this->assertSame('success', $this->CI->Attendance_model->update($id));
        $this->assertSame('10:30:00', $this->periods_of($id)[1]['check_in']);
        $this->assertSame(array(20, 60), array((int) $this->day()['late_minutes'], (int) $this->day()['early_leave_minutes']));
        $this->post(array('status' => 'Absent'));
        $this->assertSame('success', $this->CI->Attendance_model->update($id));
        $this->assertSame(array(), $this->periods_of($id));
        $this->assertSame(array(NULL, 0.0), array($this->day()['check_in'], (float) $this->day()['working_hours']));
    }

    public function test_the_row_and_its_periods_are_deleted_together()
    {
        $id = $this->id_of_result($this->mark(array('seg_in' => array('10:00', '18:00'), 'seg_out' => array('14:00', '22:00'))));
        $this->assertSame('success', $this->CI->Attendance_model->delete($id));
        $this->assertSame(0, (int) $this->db->where('attendance_id', $id)->count_all_results('attendance_segments'));
    }

    public function test_bulk_marking_a_department_with_one_in_and_out()
    {
        $other = $this->insert_employee('SA3');
        $normal = $this->insert_shift('NR2', '09:00:00', '18:00:00', array('working_hours' => 8));
        $this->assign_shift($other, $normal);
        $this->post(array('attendance_date' => '06-04-2026', 'status' => 'Present', 'check_in' => '10:00', 'check_out' => '22:00'));
        $this->assertSame('success<<<###>>>2/0/0', $this->CI->Attendance_model->bulk_mark());
        $this->assertEquals(8.0, (float) $this->day()['working_hours']);
        $this->assertCount(2, $this->periods_of($this->day()['id']));
        $this->assertSame(array(), $this->periods_of($this->attendance_row($other, '2026-04-06')['id']), 'the ordinary shift has no periods');
    }

    public function test_marking_your_own_day()
    {
        $this->act_with_permissions('selfie', array('attendance_mark_self'));
        set_current_user(array_merge(current_user(), array('employee_id' => $this->emp)));
        $this->post(array('check_in' => '10:05', 'check_out' => '21:55'));
        $id = $this->id_of_result($this->CI->Attendance_model->mark_self());
        $this->assertCount(2, $this->periods_of($id));
        $this->assertSame('Pending', $this->db->get_where('attendance', array('id' => $id))->row()->approval_status);
    }

    public function test_the_form_asks_about_periods_only_for_a_split_shift()
    {
        $this->post(array('employee_id' => (string) $this->emp, 'attendance_date' => '06-04-2026'));
        $info = $this->CI->Attendance_model->day_info();
        $this->assertTrue($info['ok']);
        $this->assertTrue($info['split']);
        $this->assertSame(array(array('no' => 1, 'start' => '10:00', 'end' => '14:00'), array('no' => 2, 'start' => '18:00', 'end' => '22:00')), $info['periods']);
        $other = $this->insert_employee('SA4');
        $this->assign_shift($other, $this->insert_shift('NR3', '09:00:00', '18:00:00', array('working_hours' => 8)));
        $this->post(array('employee_id' => (string) $other, 'attendance_date' => '06-04-2026'));
        $this->assertFalse($this->CI->Attendance_model->day_info()['split']);
        $this->post(array('employee_id' => (string) $this->emp, 'attendance_date' => '01-01-2020'));
        $this->assertFalse($this->CI->Attendance_model->day_info()['employed'], 'before the join date');
        $this->post(array('employee_id' => '999999', 'attendance_date' => '06-04-2026'));
        $this->assertFalse($this->CI->Attendance_model->day_info()['ok']);
    }

    public function test_get_returns_the_periods_for_the_edit_form_and_the_list_stacks_them()
    {
        $id = $this->id_of_result($this->mark(array('seg_in' => array('10:00', '18:00'), 'seg_out' => array('14:00', ''))));
        $row = $this->CI->Attendance_model->get($id);
        $this->assertCount(2, $row['periods']);
        $this->assertCount(2, $row['shift_periods']);
        $this->post(array('draw' => '1', 'start' => '0', 'length' => '25', 'from_date' => '01-04-2026', 'to_date' => '30-04-2026'));
        $page = $this->CI->Attendance_model->list_page();
        $this->assertSame('10:00<br>18:00', $page['data'][0]['in']);
        $this->assertStringContainsString('14:00<br><span class="text-muted">?</span>', $page['data'][0]['out'], 'an open period shows a ?');
    }

    public function test_an_open_last_period_is_in_the_missing_check_out_list_and_day_close_leaves_its_remark()
    {
        $this->punch(array('10:00', '14:00', '18:00'));
        $this->CI->load->model('Day_close_model');
        $r = $this->CI->Day_close_model->close_day(current_branch_id(), '2026-04-06', FALSE);
        $this->assertTrue($r['ok']);
        $this->assertSame(Punch_ingestor::REMARK_INCOMPLETE, $this->day()['remarks'], 'the row already says what is wrong; the day close does not overwrite it');
        $this->post(array('draw' => '1', 'start' => '0', 'length' => '25', 'from_date' => '01-04-2026', 'to_date' => '30-04-2026', 'missing_checkout' => '1'));
        $page = $this->CI->Attendance_model->list_page();
        $this->assertSame(1, $page['recordsFiltered']);
    }

    public function test_the_calendar_shows_the_periods()
    {
        $this->punch(array('10:02', '14:01', '17:55', '22:03'));
        $events = $this->CI->Attendance_model->calendar_events($this->emp, '2026-04-01', '2026-04-30');
        $titles = array_column($events, 'title', 'start');
        $this->assertStringContainsString('10:02-14:01 / 17:55-22:03', $titles['2026-04-06']);
    }
}
