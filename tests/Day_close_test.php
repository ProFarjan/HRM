<?php
/**
 * P1: every closed day without a row gets one - Holiday > Weekly Off > (Leave, step 6) > Absent - for every employee employed
 * that day; re-running fills gaps only; today is never closed; a check-in without check-out is flagged, not hidden.
 */
class Day_close_test extends Hrm_test_case
{
    /** @var object */
    protected $close;

    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Day_close_model');
        $this->close = $this->CI->Day_close_model;
        $this->act_as_new_user('boss', array(), TRUE);
        $this->db->update('branches', array('weekly_offs' => '5'), array('id' => current_branch_id()));   // Friday
        $this->db->insert('holidays', array('branch_id' => current_branch_id(), 'holiday_name' => 'Independence Day', 'holiday_date' => '2026-03-26',
            'year' => 2026, 'status' => 'Active', 'created_at' => now_local()));
    }

    protected function status($emp, $date)
    {
        $row = $this->attendance_row($emp, $date);
        return $row ? $row['status'] : NULL;
    }

    public function test_missing_days_become_holiday_weekly_off_or_absent()
    {
        $a = $this->insert_employee('A', array('join_date' => '2026-01-01'));
        foreach (array('2026-03-25', '2026-03-26', '2026-03-27') as $d) {           // Wed, holiday Thu, Fri
            $this->assertTrue($this->close->close_day(current_branch_id(), $d)['ok']);
        }
        $this->assertSame(array('Absent', 'Holiday', 'Weekly Off'), array($this->status($a, '2026-03-25'), $this->status($a, '2026-03-26'), $this->status($a, '2026-03-27')));
        $row = $this->attendance_row($a, '2026-03-26');
        $this->assertSame(array('day_close', 'Approved', 'Independence Day'), array($row['source'], $row['approval_status'], $row['remarks']));
    }

    public function test_only_employees_employed_that_day()
    {
        $joiner = $this->insert_employee('J', array('join_date' => '2026-03-25'));
        $leaver = $this->insert_employee('L', array('join_date' => '2025-01-01', 'exit_date' => '2026-03-23', 'status' => 'Resigned'));
        $last_day = $this->insert_employee('X', array('join_date' => '2025-01-01', 'exit_date' => '2026-03-24', 'status' => 'Terminated'));
        $r = $this->close->close_day(current_branch_id(), '2026-03-24');
        $this->assertSame(1, $r['employees']);
        $this->assertNull($this->status($joiner, '2026-03-24'), 'not yet joined');
        $this->assertNull($this->status($leaver, '2026-03-24'), 'already left');
        $this->assertSame('Absent', $this->status($last_day, '2026-03-24'), 'the exit date itself is still employed');
    }

    public function test_existing_rows_are_kept_and_rerunning_fills_gaps_only()
    {
        $present = $this->insert_employee('P');
        $gap = $this->insert_employee('G');
        $this->db->insert('attendance', array('branch_id' => current_branch_id(), 'employee_id' => $present, 'attendance_date' => '2026-03-25', 'status' => 'Present',
            'check_in' => '09:00:00', 'check_out' => '18:00:00', 'approval_status' => 'Approved', 'source' => 'punch', 'created_at' => now_local()));
        $first = $this->close->close_day(current_branch_id(), '2026-03-25');
        $this->assertSame(array('Absent' => 1), $first['created']);
        $this->assertSame(1, $first['already']);
        $this->assertSame('Present', $this->status($present, '2026-03-25'));
        $again = $this->close->close_day(current_branch_id(), '2026-03-25');
        $this->assertSame(array(), $again['created'], 'idempotent');
        $this->assertSame(2, $again['already']);
        $this->assertSame(1, $this->db->get_where('attendance', array('employee_id' => $gap))->num_rows());
    }

    public function test_a_check_in_without_check_out_is_flagged_not_hidden()
    {
        $emp = $this->insert_employee('C');
        $this->db->insert('attendance', array('branch_id' => current_branch_id(), 'employee_id' => $emp, 'attendance_date' => '2026-03-25', 'status' => 'Present',
            'check_in' => '09:00:00', 'approval_status' => 'Approved', 'source' => 'punch', 'created_at' => now_local()));
        $r = $this->close->close_day(current_branch_id(), '2026-03-25');
        $this->assertSame(1, $r['missing_checkout']);
        $row = $this->attendance_row($emp, '2026-03-25');
        $this->assertSame(array('Present', Day_close_model::REMARK_MISSING_OUT), array($row['status'], $row['remarks']), 'stays Present, not a full day or absent');
        $this->assertSame(0, $this->close->close_day(current_branch_id(), '2026-03-25')['missing_checkout'], 'flagged once');
    }

    public function test_roster_rows_decide_weekly_offs()
    {
        $emp = $this->insert_employee('R');
        $this->db->insert('shift_rosters', array('employee_id' => $emp, 'roster_date' => '2026-03-27', 'is_working_day' => 1, 'created_at' => now_local()));   // a working Friday
        $this->db->insert('shift_rosters', array('employee_id' => $emp, 'roster_date' => '2026-03-25', 'is_working_day' => 0, 'created_at' => now_local()));   // off on Wednesday
        $this->close->close_day(current_branch_id(), '2026-03-25');
        $this->close->close_day(current_branch_id(), '2026-03-27');
        $this->assertSame(array('Weekly Off', 'Absent'), array($this->status($emp, '2026-03-25'), $this->status($emp, '2026-03-27')));
    }

    public function test_today_and_the_future_are_never_closed()
    {
        $this->insert_employee('T');
        $r = $this->close->close_day(current_branch_id(), Day_close_model::today_in('Asia/Dhaka'));
        $this->assertFalse($r['ok']);
        $this->assertFalse($this->close->close_day(current_branch_id(), '2999-01-01')['ok']);
        $this->assertSame(0, $this->db->count_all('attendance'));
    }

    public function test_dry_run_writes_nothing()
    {
        $this->insert_employee('D');
        $r = $this->close->close_day(current_branch_id(), '2026-03-25', TRUE);
        $this->assertSame(array('Absent' => 1), $r['created']);
        $this->assertSame(0, $this->db->count_all('attendance'));
    }

    public function test_close_range_fills_every_day_up_to_yesterday()
    {
        $emp = $this->insert_employee('W', array('join_date' => '2026-03-01'));
        $r = $this->close->close_range(current_branch_id(), '2026-03-01', '2026-03-31');
        $this->assertTrue($r['ok']);
        $this->assertSame(31, $r['days']);
        $this->assertSame(31, $this->db->get_where('attendance', array('employee_id' => $emp))->num_rows());
        $counts = array_count_values(array_column($this->db->get_where('attendance', array('employee_id' => $emp))->result_array(), 'status'));
        $this->assertSame(array('Absent' => 26, 'Weekly Off' => 4, 'Holiday' => 1), array('Absent' => $counts['Absent'], 'Weekly Off' => $counts['Weekly Off'], 'Holiday' => $counts['Holiday']),
            'March 2026: 4 Fridays (6, 13, 20, 27), 1 holiday (26th), the rest absent with no punches');
        $this->assertSame(0, $this->close->close_range(current_branch_id(), '2026-03-01', '2026-03-31')['created'], 'a second catch-up creates nothing');
    }
}
