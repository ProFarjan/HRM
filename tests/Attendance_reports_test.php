<?php
/** The reports' data layer in process: the one-employee report (days without a row, punches incl. a night shift, scope) and the period rows. */
class Attendance_reports_test extends Hrm_test_case
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->act_as_new_user('rep_boss', array('attendance_report', 'show_all_attendance'));
        $this->db->update('branches', array('weekly_offs' => '5'), array('id' => current_branch_id()));
        $this->CI->load->model('Attendance_reports_model');
    }

    protected function att($employee, $date, $status, array $extra = array())
    {
        $this->db->insert('attendance', array_merge(array('branch_id' => current_branch_id(), 'employee_id' => $employee, 'attendance_date' => $date, 'status' => $status,
            'approval_status' => 'Approved', 'source' => 'punch', 'created_at' => now_local()), $extra));
        return (int) $this->db->insert_id();
    }

    public function test_the_employee_report_covers_every_day_and_labels_the_ones_without_a_row()
    {
        $shift = $this->insert_shift('DAY', '09:00:00', '18:00:00');
        $emp = $this->insert_employee('ER1', array('join_date' => '2026-04-03', 'device_pin' => '41', 'device_pin_from' => '2026-04-03'));
        $this->assign_shift($emp, $shift);
        $this->db->insert('holidays', array('branch_id' => current_branch_id(), 'holiday_name' => 'Pohela', 'holiday_date' => '2026-04-14', 'year' => 2026, 'status' => 'Active', 'created_at' => now_local()));
        $this->att($emp, '2026-04-06', 'Present', array('check_in' => '09:20:00', 'check_out' => '18:00:00', 'late_minutes' => 20, 'working_hours' => 8.67, 'shift_id' => $shift, 'first_punch_at' => '2026-04-06 09:20:00', 'last_punch_at' => '2026-04-06 18:00:00', 'break_minutes' => 45));
        $this->att($emp, '2026-04-07', 'Absent');
        $this->db->insert_batch('attendance_punches', array(
            array('branch_id' => current_branch_id(), 'employee_id' => $emp, 'device_pin' => '41', 'punch_at' => '2026-04-06 09:20:00', 'source' => 'import', 'ignored' => 0, 'received_at' => now_local()),
            array('branch_id' => current_branch_id(), 'employee_id' => $emp, 'device_pin' => '41', 'punch_at' => '2026-04-06 13:00:00', 'source' => 'import', 'ignored' => 0, 'received_at' => now_local()),
            array('branch_id' => current_branch_id(), 'employee_id' => $emp, 'device_pin' => '41', 'punch_at' => '2026-04-06 13:00:20', 'source' => 'import', 'ignored' => 1, 'received_at' => now_local()),
            array('branch_id' => current_branch_id(), 'employee_id' => $emp, 'device_pin' => '41', 'punch_at' => '2026-04-06 18:00:00', 'source' => 'import', 'ignored' => 0, 'received_at' => now_local()),
        ));
        $r = $this->CI->Attendance_reports_model->employee_report($emp, '2026-04-01', '2026-04-30');
        $this->assertCount(30, $r['days']);
        $this->assertFalse($r['days']['2026-04-01']['plan']['employed'], 'before the join date');
        $this->assertSame('weekly_off', $r['days']['2026-04-03']['plan']['day_type'], 'a Friday with no row');
        $this->assertSame('holiday', $r['days']['2026-04-14']['plan']['day_type']);
        $this->assertNull($r['days']['2026-04-08']['row'], 'a working day nobody closed yet');
        $this->assertCount(4, $r['days']['2026-04-06']['punches']);
        $this->assertSame(1, (int) $r['days']['2026-04-06']['punches'][2]['ignored'], 'the double tap is shown, marked');
        $this->assertSame('Shift DAY', $r['days']['2026-04-06']['row']['shift_name']);
        $s = $r['stats'];
        $this->assertSame(array(1, 1, 20), array((int) $s['present'], (int) $s['absent'], $s['late_minutes']));
        $this->assertGreaterThan(0, $s['no_record'], 'working days before today without a row');
        $keys = array_column($r['insights'], 'key');
        $this->assertContains('ins_emp_late', $keys);
        $this->assertContains('ins_no_record', $keys);
        $this->assertNull($this->CI->Attendance_reports_model->employee_report(999999, '2026-04-01', '2026-04-30'), 'unknown employee');
    }

    public function test_the_tail_of_a_night_shift_is_shown_under_the_day_it_belongs_to()
    {
        $emp = $this->insert_employee('ER2');
        $this->att($emp, '2026-04-06', 'Present', array('check_in' => '22:05:00', 'check_out' => '05:58:00', 'first_punch_at' => '2026-04-06 22:05:00', 'last_punch_at' => '2026-04-07 05:58:00'));
        $this->db->insert_batch('attendance_punches', array(
            array('branch_id' => current_branch_id(), 'employee_id' => $emp, 'device_pin' => '1', 'punch_at' => '2026-04-06 22:05:00', 'source' => 'import', 'ignored' => 0, 'received_at' => now_local()),
            array('branch_id' => current_branch_id(), 'employee_id' => $emp, 'device_pin' => '1', 'punch_at' => '2026-04-07 05:58:00', 'source' => 'import', 'ignored' => 0, 'received_at' => now_local()),
        ));
        $r = $this->CI->Attendance_reports_model->employee_report($emp, '2026-04-06', '2026-04-07');
        $this->assertCount(2, $r['days']['2026-04-06']['punches']);
        $this->assertCount(0, $r['days']['2026-04-07']['punches']);
    }

    public function test_the_period_rows_are_scoped_and_departments_filter()
    {
        $dept = $this->db->insert('departments', array('branch_id' => current_branch_id(), 'department_name' => 'Cut', 'department_code' => 'CUT', 'status' => 'Active', 'created_at' => now_local())) ? (int) $this->db->insert_id() : 0;
        $a = $this->insert_employee('PR1', array('department_id' => $dept));
        $b = $this->insert_employee('PR2');
        $other = $this->insert_branch('RPB');
        $c = $this->insert_employee('PR3', array('branch_id' => $other));
        $this->att($a, '2026-04-06', 'Present', array('check_in' => '09:00:00', 'check_out' => '18:00:00'));
        $this->att($b, '2026-04-06', 'Absent');
        $all = $this->CI->Attendance_reports_model->period('2026-04-01', '2026-04-30');
        $this->assertEqualsCanonicalizing(array($a, $b), array_keys($all['employees']), 'the working branch only: ' . implode(',', array_column($all['employees'], 'employee_code')));
        $this->assertArrayNotHasKey($c, $all['employees']);
        $this->assertSame('Present', $all['grid'][$a]['2026-04-06']['status']);
        $one = $this->CI->Attendance_reports_model->period('2026-04-01', '2026-04-30', $dept);
        $this->assertSame(array($a), array_keys($one['employees']));
        // an employee who left before the period or joins after it is not in it
        $this->insert_employee('PR4', array('join_date' => '2026-06-01'));
        $this->insert_employee('PR5', array('exit_date' => '2026-03-31', 'status' => 'Resigned'));
        $this->assertCount(2, $this->CI->Attendance_reports_model->period('2026-04-01', '2026-04-30')['employees']);
    }

    public function test_the_employee_picker_follows_the_scope_and_includes_leavers()
    {
        $emp = $this->insert_employee('PK1');
        $this->insert_employee('PK2', array('status' => 'Resigned', 'exit_date' => '2026-02-01'));
        $options = $this->CI->Attendance_reports_model->employee_options();
        $this->assertCount(2, $options);
        $this->assertStringContainsString('Resigned', implode(' ', $options));
        $user = $this->act_with_permissions('rep_plain', array('attendance_report'));
        set_current_user(array_merge($user, array('employee_id' => $emp)));
        $this->assertSame(array($emp), array_keys($this->CI->Attendance_reports_model->employee_options()), 'without show_all_attendance: yourself');
    }
}
