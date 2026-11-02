<?php
/**
 * Shift_calculator (CLAUDE.md "Shift resolution and daily metrics"): normal day, grace, late, early leave, break deduction,
 * expected hours, overtime blocks, night shift, no shift, holiday / weekly-off work, only a check-in; and plan(): roster >
 * assignment > default shift, holiday > roster > branch > global weekly offs, employment dates, query budget.
 */
class Shift_calculator_test extends Hrm_test_case
{
    protected function day_shift(array $extra = array())
    {
        return array_merge(array('start_time' => '09:00:00', 'end_time' => '18:00:00', 'break_duration' => 60, 'working_hours' => '8.00', 'grace_time' => 10), $extra);
    }

    protected function night_shift(array $extra = array())
    {
        return array_merge(array('start_time' => '22:00:00', 'end_time' => '06:00:00', 'break_duration' => 30, 'working_hours' => '0.00', 'grace_time' => 5), $extra);
    }

    protected function opts(array $o = array())
    {
        return array_merge(array('deduct_break' => 0, 'ot_min_minutes' => 0, 'early_leave_counts_as_late' => 0, 'off_day' => FALSE), $o);
    }

    // ---------------------------------------------------------------------------------- metrics

    public function test_a_normal_day()
    {
        $m = Shift_calculator::metrics($this->day_shift(), '09:00', '18:00', $this->opts());
        $this->assertSame(9.0, $m['working_hours']);
        $this->assertSame(0, $m['late_minutes']);
        $this->assertSame(0, $m['early_leave_minutes']);
        $this->assertSame(8.0, $m['expected_hours']);
        $this->assertSame(1.0, $m['overtime_hours'], '9 h worked - 8 h expected');
    }

    public function test_grace_then_late()
    {
        $this->assertSame(0, Shift_calculator::metrics($this->day_shift(), '09:10', '18:00', $this->opts())['late_minutes'], 'inside the grace');
        $this->assertSame(1, Shift_calculator::metrics($this->day_shift(), '09:11', '18:00', $this->opts())['late_minutes']);
        $this->assertSame(35, Shift_calculator::metrics($this->day_shift(), '09:45:30', '18:00', $this->opts())['late_minutes'], 'late = in - (start + grace)');
        $this->assertSame(0, Shift_calculator::metrics($this->day_shift(), '08:30', '18:00', $this->opts())['late_minutes'], 'early arrival is not negative');
    }

    public function test_early_leave_and_counting_it_as_late()
    {
        $m = Shift_calculator::metrics($this->day_shift(), '09:20', '17:30', $this->opts());
        $this->assertSame(30, $m['early_leave_minutes']);
        $this->assertSame(10, $m['late_minutes']);
        $m = Shift_calculator::metrics($this->day_shift(), '09:20', '17:30', $this->opts(array('early_leave_counts_as_late' => 1)));
        $this->assertSame(40, $m['late_minutes'], 'early leave added to late with the setting');
        $this->assertSame(0, Shift_calculator::metrics($this->day_shift(), '09:00', '19:00', $this->opts())['early_leave_minutes']);
    }

    public function test_break_deduction_only_with_the_setting()
    {
        $this->assertSame(9.0, Shift_calculator::metrics($this->day_shift(), '09:00', '18:00', $this->opts())['working_hours']);
        $m = Shift_calculator::metrics($this->day_shift(), '09:00', '18:00', $this->opts(array('deduct_break' => 1)));
        $this->assertSame(8.0, $m['working_hours']);
        $this->assertSame(0.0, $m['overtime_hours']);
    }

    public function test_expected_hours_from_the_span_when_working_hours_is_zero()
    {
        $shift = $this->day_shift(array('working_hours' => '0.00'));
        $this->assertSame(540, Shift_calculator::expected_minutes($shift));
        $this->assertSame(480, Shift_calculator::expected_minutes($this->night_shift()), '22:00-06:00 crosses midnight');
        $m = Shift_calculator::metrics($shift, '09:00', '19:30', $this->opts());
        $this->assertSame(1.5, $m['overtime_hours']);
    }

    public function test_overtime_is_floored_to_blocks()
    {
        $shift = $this->day_shift();
        $this->assertSame(1.73, Shift_calculator::metrics($shift, '09:00', '18:44', $this->opts())['overtime_hours'], 'exact: 104 min');
        $this->assertSame(1.5, Shift_calculator::metrics($shift, '09:00', '18:44', $this->opts(array('ot_min_minutes' => 30)))['overtime_hours'], '104 min -> 90');
        $this->assertSame(1.5, Shift_calculator::metrics($shift, '09:00', '18:44', $this->opts(array('ot_min_minutes' => 15)))['overtime_hours'], '104 min -> 6 blocks of 15');
        $this->assertSame(1.75, Shift_calculator::metrics($shift, '09:00', '18:45', $this->opts(array('ot_min_minutes' => 15)))['overtime_hours'], '105 min -> 7 blocks of 15');
        $this->assertSame(0.0, Shift_calculator::metrics($shift, '09:00', '16:40', $this->opts())['overtime_hours'], 'never negative (460 of 480 min)');
    }

    public function test_night_shift_across_midnight()
    {
        $m = Shift_calculator::metrics($this->night_shift(), '22:12', '06:30', $this->opts());
        $this->assertSame(8.3, $m['working_hours'], '22:12 -> 06:30 = 498 min');
        $this->assertSame(7, $m['late_minutes'], '22:12 - (22:00 + 5)');
        $this->assertSame(0, $m['early_leave_minutes']);
        $this->assertSame(0.3, $m['overtime_hours'], '498 - 480 = 18 min');

        $m = Shift_calculator::metrics($this->night_shift(), '00:30', '05:00', $this->opts());
        $this->assertSame(145, $m['late_minutes'], 'check-in after midnight is late, not 21 h early');
        $this->assertSame(60, $m['early_leave_minutes']);
        $this->assertSame(0, Shift_calculator::metrics($this->night_shift(), '21:40', '06:00', $this->opts())['late_minutes'], 'before the start: not late');
    }

    public function test_without_a_shift_only_hours_count()
    {
        $m = Shift_calculator::metrics(NULL, '10:00', '20:00', $this->opts(array('deduct_break' => 1)));
        $this->assertSame(array(10.0, 0, 0, 0.0, 0.0), array($m['working_hours'], $m['late_minutes'], $m['early_leave_minutes'], $m['overtime_hours'], $m['expected_hours']));
        $this->assertSame(9.0, Shift_calculator::metrics(NULL, '20:00', '05:00', $this->opts())['working_hours'], 'no shift, across midnight');
    }

    public function test_work_on_a_holiday_or_weekly_off_is_all_overtime()
    {
        $m = Shift_calculator::metrics($this->day_shift(), '10:30', '15:00', $this->opts(array('off_day' => TRUE)));
        $this->assertSame(4.5, $m['working_hours']);
        $this->assertSame(4.5, $m['overtime_hours'], 'the whole working time');
        $this->assertSame(0, $m['late_minutes'], 'nobody is late on a day off');
        $this->assertSame(0, $m['early_leave_minutes']);
        $this->assertSame(3.0, Shift_calculator::metrics(NULL, '09:00', '12:00', $this->opts(array('off_day' => TRUE)))['overtime_hours'], 'also without a shift');
    }

    public function test_only_a_check_in()
    {
        $m = Shift_calculator::metrics($this->day_shift(), '09:30', NULL, $this->opts());
        $this->assertSame(20, $m['late_minutes'], 'late is known at check-in');
        $this->assertSame(0.0, $m['working_hours']);
        $this->assertSame(0.0, $m['overtime_hours']);
        $none = Shift_calculator::metrics($this->day_shift(), NULL, NULL, $this->opts());
        $this->assertSame(0, $none['late_minutes']);
    }

    public function test_settings_are_used_when_options_are_not_given()
    {
        $this->db->update('hr_settings', array('deduct_break' => 1, 'ot_min_minutes' => 60), array('id' => 1));
        hr_setting_reset_cache();
        $m = Shift_calculator::metrics($this->day_shift(), '09:00', '19:50');
        $this->assertSame(9.83, $m['working_hours'], '650 - 60 break = 590 min');
        $this->assertSame(1.0, $m['overtime_hours'], '110 min floored to 60');
    }

    public function test_metrics_is_pure()
    {
        $before = $this->queries_before();
        $a = Shift_calculator::metrics($this->night_shift(), '22:12', '06:30', $this->opts());
        $b = Shift_calculator::metrics($this->night_shift(), '22:12', '06:30', $this->opts());
        $this->assertSame($a, $b);
        $this->assertSame($before, $this->queries_before(), 'no database access with explicit options');
    }

    // ---------------------------------------------------------------------------------- plan()

    protected function shift($code, $start, $end, $branch = NULL)
    {
        $this->db->insert('shifts', array('branch_id' => $branch === NULL ? current_branch_id() : $branch, 'shift_name' => 'Shift ' . $code, 'shift_code' => $code,
            'start_time' => $start, 'end_time' => $end, 'working_hours' => 0, 'is_night' => $end < $start ? 1 : 0, 'status' => 'Active', 'created_at' => now_local()));
        return (int) $this->db->insert_id();
    }

    public function test_plan_shift_precedence()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $day = $this->shift('DAY', '09:00:00', '18:00:00');
        $eve = $this->shift('EVE', '14:00:00', '22:00:00');
        $def = $this->shift('DEF', '08:00:00', '16:00:00');
        $this->db->update('hr_settings', array('default_shift_id' => $def), array('id' => 1));
        hr_setting_reset_cache();
        $emp = $this->insert_employee('P1');
        $this->db->insert('employee_shifts', array('employee_id' => $emp, 'shift_id' => $day, 'effective_from' => '2026-03-03', 'effective_to' => '2026-03-04', 'created_at' => now_local()));
        $this->db->insert('employee_shifts', array('employee_id' => $emp, 'shift_id' => $eve, 'effective_from' => '2026-03-05', 'effective_to' => NULL, 'created_at' => now_local()));
        $this->db->insert('shift_rosters', array('employee_id' => $emp, 'shift_id' => $day, 'roster_date' => '2026-03-07', 'is_working_day' => 1, 'created_at' => now_local()));

        $calc = new Shift_calculator();
        $plan = $calc->plan(array($emp), '2026-03-02', '2026-03-07')[$emp];
        $this->assertSame(array('DEF', 'default'), array($plan['2026-03-02']['shift']['shift_code'], $plan['2026-03-02']['shift_source']), 'no assignment yet: default shift');
        $this->assertSame(array('DAY', 'assignment'), array($plan['2026-03-04']['shift']['shift_code'], $plan['2026-03-04']['shift_source']));
        $this->assertSame('EVE', $plan['2026-03-05']['shift']['shift_code'], 'the next assignment');
        $this->assertSame(array('DAY', 'roster'), array($plan['2026-03-07']['shift']['shift_code'], $plan['2026-03-07']['shift_source']), 'roster wins');
        $this->assertSame($calc->day($emp, '2026-03-05'), $plan['2026-03-05']);
    }

    public function test_plan_default_shift_of_another_branch_is_ignored()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $far = $this->shift('FAR', '07:00:00', '15:00:00', $this->insert_branch('PB2'));
        $this->db->update('hr_settings', array('default_shift_id' => $far), array('id' => 1));
        hr_setting_reset_cache();
        $emp = $this->insert_employee('P2');
        $calc = new Shift_calculator();
        $this->assertNull($calc->day($emp, '2026-03-02')['shift']);
    }

    public function test_plan_day_types()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $this->db->update('hr_settings', array('weekly_offs' => '5'), array('id' => 1));       // Friday
        hr_setting_reset_cache();
        $emp = $this->insert_employee('P3');
        // 2026-03-06 is a Friday, 2026-03-07 Saturday
        $this->db->insert('holidays', array('branch_id' => current_branch_id(), 'holiday_name' => 'Independence Day', 'holiday_date' => '2026-03-26', 'year' => 2026, 'is_optional' => 0, 'status' => 'Active', 'created_at' => now_local()));
        $this->db->insert('holidays', array('branch_id' => current_branch_id(), 'holiday_name' => 'Optional day', 'holiday_date' => '2026-03-24', 'year' => 2026, 'is_optional' => 1, 'status' => 'Active', 'created_at' => now_local()));
        $this->db->insert('holidays', array('branch_id' => current_branch_id(), 'holiday_name' => 'Cancelled', 'holiday_date' => '2026-03-25', 'year' => 2026, 'is_optional' => 0, 'status' => 'Inactive', 'created_at' => now_local()));
        $this->db->insert('shift_rosters', array('employee_id' => $emp, 'shift_id' => NULL, 'roster_date' => '2026-03-09', 'is_working_day' => 0, 'created_at' => now_local()));
        $this->db->insert('shift_rosters', array('employee_id' => $emp, 'shift_id' => NULL, 'roster_date' => '2026-03-13', 'is_working_day' => 1, 'created_at' => now_local()));
        $this->db->insert('shift_rosters', array('employee_id' => $emp, 'shift_id' => NULL, 'roster_date' => '2026-03-26', 'is_working_day' => 1, 'created_at' => now_local()));

        $calc = new Shift_calculator();
        $p = $calc->plan(array($emp), '2026-03-01', '2026-03-31')[$emp];
        $this->assertSame('weekly_off', $p['2026-03-06']['day_type'], 'global Friday');
        $this->assertSame('working', $p['2026-03-07']['day_type']);
        $this->assertSame('weekly_off', $p['2026-03-09']['day_type'], 'roster off on a Monday');
        $this->assertSame('working', $p['2026-03-13']['day_type'], 'roster working on a Friday');
        $this->assertSame(array('holiday', 'Independence Day'), array($p['2026-03-26']['day_type'], $p['2026-03-26']['holiday_name']), 'holiday wins over a roster working day');
        $this->assertSame('working', $p['2026-03-24']['day_type'], 'optional holidays are not days off');
        $this->assertSame('working', $p['2026-03-25']['day_type'], 'inactive holidays are ignored');

        $this->db->update('branches', array('weekly_offs' => '6,7'), array('id' => current_branch_id()));
        $p = $calc->plan(array($emp), '2026-03-06', '2026-03-08')[$emp];
        $this->assertSame(array('working', 'weekly_off', 'weekly_off'), array_column($p, 'day_type'), 'branch weekly offs override the global setting');
        $this->db->update('branches', array('weekly_offs' => ''), array('id' => current_branch_id()));
        $p = $calc->plan(array($emp), '2026-03-06', '2026-03-08')[$emp];
        $this->assertSame(array('working', 'working', 'working'), array_column($p, 'day_type'), 'branch with no weekly off');
    }

    public function test_plan_marks_days_outside_employment()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $emp = $this->insert_employee('P4', array('join_date' => '2026-03-10', 'exit_date' => '2026-03-20', 'status' => 'Resigned'));
        $calc = new Shift_calculator();
        $p = $calc->plan(array($emp), '2026-03-09', '2026-03-21')[$emp];
        $this->assertFalse($p['2026-03-09']['employed']);
        $this->assertTrue($p['2026-03-10']['employed']);
        $this->assertTrue($p['2026-03-20']['employed']);
        $this->assertFalse($p['2026-03-21']['employed']);
    }

    public function test_plan_costs_a_fixed_number_of_queries()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $shift = $this->shift('BIG', '09:00:00', '18:00:00');
        $ids = array();
        for ($i = 0; $i < 120; $i++) {
            $ids[] = $id = $this->insert_employee('Q' . $i);
            $this->db->insert('employee_shifts', array('employee_id' => $id, 'shift_id' => $shift, 'effective_from' => '2026-01-01', 'created_at' => now_local()));
        }
        hr_setting('weekly_offs');
        $calc = new Shift_calculator();
        $before = $this->queries_before();
        $plan = $calc->plan($ids, '2026-03-01', '2026-03-31');
        $this->assertLessThanOrEqual(6, $this->queries_before() - $before, '120 employees x 31 days');
        $this->assertCount(120, $plan);
        $this->assertCount(31, $plan[$ids[0]]);
    }
}
