<?php
/**
 * Split shifts: one shift with several duty periods in a day (10:00-14:00 and 18:00-22:00). The pure measurement (Shift_calculator), the
 * way punches are shared out between the periods, and what plan() hands to the rest of the system.
 */
class Split_shift_test extends Hrm_test_case
{
    protected function shift(array $periods = array(array('10:00', '14:00'), array('18:00', '22:00')), $grace = 10)
    {
        $segments = array();
        $minutes = 0;
        foreach ($periods as $i => $p) {
            $segments[] = array('segment_no' => $i + 1, 'start_time' => $p[0] . ':00', 'end_time' => $p[1] . ':00');
            $minutes += (Shift_calculator::minutes($p[1]) - Shift_calculator::minutes($p[0]) + 1440) % 1440;
        }
        return array('is_split' => 1, 'start_time' => $segments[0]['start_time'], 'end_time' => $segments[count($segments) - 1]['end_time'], 'working_hours' => round($minutes / 60, 2),
            'grace_time' => $grace, 'break_duration' => 0, 'segments' => $segments);
    }

    protected function times(array $times, $date = '2026-04-06')
    {
        return array_map(function ($t) use ($date) { return strlen($t) === 5 ? $date . ' ' . $t . ':00' : $t; }, $times);
    }

    protected function opts(array $extra = array())
    {
        return array_merge(array('deduct_break' => 0, 'ot_min_minutes' => 0, 'early_leave_counts_as_late' => 0, 'off_day' => FALSE), $extra);
    }

    public function test_a_full_day_counts_both_periods_and_never_the_gap()
    {
        $m = Shift_calculator::split_metrics($this->shift(), '2026-04-06', $this->times(array('10:00', '14:00', '18:00', '22:00')), $this->opts());
        $this->assertEquals(8.0, $m['working_hours'], 'four hours twice: 10-14 and 18-22 - not 12 hours first in to last out');
        $this->assertSame(array(0, 0), array($m['late_minutes'], $m['early_leave_minutes']));
        $this->assertEquals(0.0, $m['overtime_hours']);
        $this->assertSame(array('10:00:00', '22:00:00'), array($m['check_in'], $m['check_out']));
        $this->assertSame(240, $m['break_minutes'], 'the 14:00-18:00 gap actually taken');
        $this->assertSame(0, $m['incomplete']);
        $this->assertSame(array('complete', 'complete'), array_column($m['periods'], 'state'));
        $this->assertSame(8.0, $m['expected_hours']);
    }

    public function test_late_and_early_leave_are_measured_per_period()
    {
        $m = Shift_calculator::split_metrics($this->shift(), '2026-04-06', $this->times(array('10:05', '13:40', '18:25', '22:00')), $this->opts());
        // period 1: in 5 min after the start, inside the 10 min grace; out 20 min early.   period 2: 25 min late (15 after grace)
        $this->assertSame(array(0, 20, 'complete'), array($m['periods'][0]['late_minutes'], $m['periods'][0]['early_leave_minutes'], $m['periods'][0]['state']));
        $this->assertSame(array(15, 0), array($m['periods'][1]['late_minutes'], $m['periods'][1]['early_leave_minutes']));
        $this->assertSame(array(15, 20), array($m['late_minutes'], $m['early_leave_minutes']));
        $this->assertEquals(round((215 + 215) / 60, 2), $m['working_hours']);
        $counted = Shift_calculator::split_metrics($this->shift(), '2026-04-06', $this->times(array('10:05', '13:40', '18:25', '22:00')), $this->opts(array('early_leave_counts_as_late' => 1)));
        $this->assertSame(35, $counted['late_minutes'], 'early leave added to late when the setting says so');
    }

    public function test_punches_are_shared_out_at_the_middle_of_the_gap()
    {
        // out at 14:02 and back at 17:55: the middle of the gap is 16:00
        $m = Shift_calculator::split_metrics($this->shift(), '2026-04-06', $this->times(array('09:58', '14:02', '17:55', '22:03')), $this->opts());
        $this->assertSame(array('09:58:00', '14:02:00'), array($m['periods'][0]['check_in'], $m['periods'][0]['check_out']));
        $this->assertSame(array('17:55:00', '22:03:00'), array($m['periods'][1]['check_in'], $m['periods'][1]['check_out']));
        // somebody who stays on until 15:50 still belongs to period 1; 16:10 is period 2's check-in
        $m = Shift_calculator::split_metrics($this->shift(), '2026-04-06', $this->times(array('10:00', '15:50', '16:10', '22:00')), $this->opts());
        $this->assertSame('15:50:00', $m['periods'][0]['check_out']);
        $this->assertSame('16:10:00', $m['periods'][1]['check_in']);
        $this->assertGreaterThan(8.0, $m['working_hours']);
        $this->assertGreaterThan(0, $m['overtime_hours'], 'more than the 8 hours of the two periods');
    }

    public function test_punches_inside_a_period_do_not_change_it_and_the_order_does_not_matter()
    {
        $times = $this->times(array('22:00', '10:02', '12:30', '14:00', '18:00', '12:00'));          // a tea break and unsorted input
        $m = Shift_calculator::split_metrics($this->shift(), '2026-04-06', $times, $this->opts());
        $this->assertSame(array('10:02:00', '14:00:00'), array($m['periods'][0]['check_in'], $m['periods'][0]['check_out']));
        $this->assertEquals(round((238 + 240) / 60, 2), $m['working_hours']);
    }

    public function test_a_missed_or_open_period_is_reported_not_guessed()
    {
        $one = Shift_calculator::split_metrics($this->shift(), '2026-04-06', $this->times(array('10:00', '14:00')), $this->opts());
        $this->assertSame(array('complete', 'missed'), array_column($one['periods'], 'state'));
        $this->assertSame(1, $one['incomplete']);
        $this->assertEquals(4.0, $one['working_hours']);
        $this->assertSame('14:00:00', $one['check_out'], 'the last period with punches is complete');
        $this->assertNull($one['periods'][1]['check_in']);
        $this->assertSame(0, $one['periods'][1]['late_minutes'], 'a period nobody came to has no lateness, it has no hours');

        $open = Shift_calculator::split_metrics($this->shift(), '2026-04-06', $this->times(array('10:00', '14:00', '18:02')), $this->opts());
        $this->assertSame(array('complete', 'open'), array_column($open['periods'], 'state'));
        $this->assertNull($open['check_out'], 'still in the second period: no check-out yet');
        $this->assertEquals(4.0, $open['working_hours']);
        $this->assertSame(0, $open['periods'][1]['late_minutes']);

        $only_second = Shift_calculator::split_metrics($this->shift(), '2026-04-06', $this->times(array('18:00', '22:00')), $this->opts());
        $this->assertSame(array('missed', 'complete'), array_column($only_second['periods'], 'state'));
        $this->assertSame('18:00:00', $only_second['check_in']);
        $this->assertEquals(4.0, $only_second['working_hours']);

        $none = Shift_calculator::split_metrics($this->shift(), '2026-04-06', array(), $this->opts());
        $this->assertSame(array(NULL, NULL, 0.0), array($none['check_in'], $none['check_out'], $none['working_hours']));
    }

    public function test_overtime_is_what_is_worked_beyond_the_periods_and_follows_the_block_setting()
    {
        $m = Shift_calculator::split_metrics($this->shift(), '2026-04-06', $this->times(array('10:00', '14:00', '18:00', '22:50')), $this->opts());
        $this->assertEquals(0.83, $m['overtime_hours'], '50 minutes after the last period');
        $m = Shift_calculator::split_metrics($this->shift(), '2026-04-06', $this->times(array('10:00', '14:00', '18:00', '22:50')), $this->opts(array('ot_min_minutes' => 30)));
        $this->assertEquals(0.5, $m['overtime_hours'], 'floored to 30 minute blocks');
        $short = Shift_calculator::split_metrics($this->shift(), '2026-04-06', $this->times(array('10:00', '13:00', '18:00', '22:00')), $this->opts());
        $this->assertEquals(0.0, $short['overtime_hours'], 'an hour short in period 1, no overtime');
    }

    public function test_a_holiday_or_weekly_off_is_all_overtime_and_never_late()
    {
        $m = Shift_calculator::split_metrics($this->shift(), '2026-04-06', $this->times(array('10:40', '14:00', '19:00', '21:00')), $this->opts(array('off_day' => TRUE)));
        $this->assertEquals(5.33, $m['working_hours']);
        $this->assertEquals(5.33, $m['overtime_hours']);
        $this->assertSame(array(0, 0), array($m['late_minutes'], $m['early_leave_minutes']));
    }

    public function test_a_split_shift_across_midnight()
    {
        $shift = $this->shift(array(array('18:00', '22:00'), array('23:00', '02:00')));
        $periods = Shift_calculator::periods($shift);
        $this->assertSame(array(1080, 1320, 1380, 1560), array($periods[0]['s'], $periods[0]['e'], $periods[1]['s'], $periods[1]['e']), 'the second period ends the next day');
        $punches = array('2026-04-06 18:00:00', '2026-04-06 22:00:00', '2026-04-06 23:05:00', '2026-04-07 02:00:00');
        $m = Shift_calculator::split_metrics($shift, '2026-04-06', $punches, $this->opts());
        $this->assertEquals(6.92, $m['working_hours'], '4 hours + 23:05 to 02:00 (the 5 minutes inside the grace are still not worked)');
        $this->assertSame(0, $m['periods'][1]['late_minutes'], '23:05 is inside the 10 minute grace');
        $this->assertSame(array('18:00:00', '02:00:00'), array($m['check_in'], $m['check_out']));
    }

    public function test_typed_times_are_measured_period_by_period()
    {
        $m = Shift_calculator::pair_metrics($this->shift(), array(array('10:00', '14:00'), array('18:20', '22:00')), $this->opts());
        $this->assertEquals(round((240 + 220) / 60, 2), $m['working_hours']);
        $this->assertSame(10, $m['periods'][1]['late_minutes']);
        // what is typed in the first row stays in the first period, even past the middle of the gap
        $m = Shift_calculator::pair_metrics($this->shift(), array(array('10:00', '16:10'), array('18:00', '22:00')), $this->opts());
        $this->assertSame('16:10:00', $m['periods'][0]['check_out']);
        $this->assertEquals(round((370 + 240) / 60, 2), $m['working_hours']);
        // a period left empty
        $m = Shift_calculator::pair_metrics($this->shift(), array(array('10:00', '14:00'), array(NULL, NULL)), $this->opts());
        $this->assertSame(array('complete', 'missed'), array_column($m['periods'], 'state'));
        // an in without an out
        $m = Shift_calculator::pair_metrics($this->shift(), array(array('10:00', '14:00'), array('18:00', NULL)), $this->opts());
        $this->assertNull($m['check_out']);
        $this->assertSame(1, $m['incomplete']);
    }

    public function test_one_in_and_one_out_means_present_through_every_period()
    {
        $m = Shift_calculator::span_metrics($this->shift(), '10:00', '22:00', $this->opts());
        $this->assertEquals(8.0, $m['working_hours'], 'the 4 hour gap between 14:00 and 18:00 is not worked time');
        $this->assertSame(array('10:00:00', '14:00:00'), array($m['periods'][0]['check_in'], $m['periods'][0]['check_out']));
        $this->assertSame(array('18:00:00', '22:00:00'), array($m['periods'][1]['check_in'], $m['periods'][1]['check_out']));
        $this->assertEquals(0.0, $m['overtime_hours']);

        $late = Shift_calculator::span_metrics($this->shift(), '10:30', '21:30', $this->opts());
        $this->assertSame(array(20, 30), array($late['late_minutes'], $late['early_leave_minutes']));
        $this->assertEquals(7.0, $late['working_hours']);

        $extra = Shift_calculator::span_metrics($this->shift(), '09:30', '22:45', $this->opts());
        $this->assertEquals(1.25, $extra['overtime_hours'], '30 minutes before and 45 after the periods');
        $this->assertEquals(8.0, $extra['working_hours'], 'hours inside the periods only, the overtime is reported separately');

        $half = Shift_calculator::span_metrics($this->shift(), '10:00', '14:00', $this->opts());
        $this->assertSame(array('complete', 'missed'), array_column($half['periods'], 'state'));
        $off = Shift_calculator::span_metrics($this->shift(), '10:00', '22:00', $this->opts(array('off_day' => TRUE)));
        $this->assertEquals(12.0, $off['working_hours'], 'a day off: in to out, all of it overtime');
        $this->assertEquals(12.0, $off['overtime_hours']);
        $open = Shift_calculator::span_metrics($this->shift(), '10:20', NULL, $this->opts());
        $this->assertSame(10, $open['late_minutes']);
        $this->assertNull($open['check_out']);
    }

    public function test_a_single_period_shift_is_unchanged()
    {
        $row = array('start_time' => '09:00:00', 'end_time' => '18:00:00', 'working_hours' => 8, 'grace_time' => 10, 'break_duration' => 60, 'is_split' => 0);
        $this->assertFalse(Shift_calculator::is_split($row));
        $this->assertFalse(Shift_calculator::is_split(NULL));
        $this->assertCount(1, Shift_calculator::periods($row));
        $m = Shift_calculator::metrics($row, '09:00', '18:00', $this->opts());
        $this->assertEquals(9.0, $m['working_hours']);
    }

    // ---------------------------------------------------------------------------------------------- plan()

    public function test_plan_hands_over_the_periods_with_one_extra_query_only_when_a_split_shift_is_involved()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $normal = $this->insert_shift('NRM', '09:00:00', '18:00:00', array('working_hours' => 8));
        $split = $this->insert_split_shift('SPL');
        $a = $this->insert_employee('SP1');
        $b = $this->insert_employee('SP2');
        $this->assign_shift($a, $normal);
        $this->assign_shift($b, $split);
        hr_setting('weekly_offs');
        $calc = new Shift_calculator();
        $before = $this->db->total_queries();
        $plan = $calc->plan(array($a), '2026-04-06', '2026-04-08');
        $this->assertSame(6, $this->db->total_queries() - $before, 'no split shift: still 6 queries');
        $this->assertFalse(Shift_calculator::is_split($plan[$a]['2026-04-06']['shift']));
        $before = $this->db->total_queries();
        $plan = $calc->plan(array($a, $b), '2026-04-06', '2026-04-08');
        $this->assertSame(7, $this->db->total_queries() - $before, 'with a split shift: 6 + the periods');
        $shift = $plan[$b]['2026-04-06']['shift'];
        $this->assertTrue(Shift_calculator::is_split($shift));
        $this->assertCount(2, $shift['segments']);
        $this->assertSame('18:00:00', $shift['segments'][1]['start_time']);
        $p = Shift_calculator::periods($shift);
        $this->assertSame(array(600, 840, 1080, 1320), array($p[0]['s'], $p[0]['e'], $p[1]['s'], $p[1]['e']));
    }
}
