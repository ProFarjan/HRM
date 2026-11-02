<?php
/** The numbers of the attendance reports (Attendance_analytics), without a database. */
class Attendance_analytics_test extends Hrm_test_case
{
    protected function emp($join = '2026-01-01', $exit = NULL)
    {
        return array('first_name' => 'Rahim', 'last_name' => 'Uddin', 'join_date' => $join, 'exit_date' => $exit);
    }

    protected function row($date, $status, $in = NULL, $out = NULL, array $extra = array())
    {
        return array_merge(array('id' => 1, 'attendance_date' => $date, 'status' => $status, 'check_in' => $in, 'check_out' => $out, 'working_hours' => 0, 'late_minutes' => 0,
            'early_leave_minutes' => 0, 'overtime_hours' => 0, 'break_minutes' => 0, 'shift_name' => 'Day', 'shift_start' => '09:00:00', 'remarks' => NULL), $extra);
    }

    public function test_counts_percentages_averages_and_totals()
    {
        $grid = array(1 => array(
            '2026-04-01' => $this->row('2026-04-01', 'Present', '09:00:00', '18:00:00', array('working_hours' => 9)),
            '2026-04-02' => $this->row('2026-04-02', 'Present', '09:20:00', '18:10:00', array('working_hours' => 8.83, 'late_minutes' => 10, 'overtime_hours' => 0.5)),
            '2026-04-03' => $this->row('2026-04-03', 'Weekly Off'),
            '2026-04-04' => $this->row('2026-04-04', 'Absent'),
            '2026-04-05' => $this->row('2026-04-05', 'Half-day', '09:00:00', '13:00:00', array('working_hours' => 4)),
            '2026-04-06' => $this->row('2026-04-06', 'Leave'),
            '2026-04-07' => $this->row('2026-04-07', 'Holiday'),
        ));
        $r = Attendance_analytics::summarise(array(1 => $this->emp()), $grid, '2026-04-01', '2026-04-07', '2026-04-20', 3);
        $s = $r['employees'][1];
        $this->assertEquals(2.5, $s['present']);
        $this->assertEquals(1.5, $s['absent']);
        $this->assertSame(array(1, 1, 1, 1), array($s['leave'], $s['holiday'], $s['weekly_off'], $s['half']));
        $this->assertEquals(5.0, $s['expected'], 'present + absent + leave; holiday and weekly off are not expected');
        $this->assertEquals(50.0, $s['pct']);
        $this->assertSame(array(1, 10), array($s['late_days'], $s['late_minutes']));
        $this->assertEquals(0.5, $s['ot']);
        $this->assertEquals(21.83, $s['hours']);
        $this->assertSame('09:07', $s['avg_in'], '(09:00 + 09:20 + 09:00) / 3');
        $this->assertSame('16:23', $s['avg_out']);
        $this->assertSame(array(), $s['no_record_dates']);
        $this->assertEquals($s['pct'], $r['totals']['pct']);
    }

    public function test_an_absence_streak_ignores_holidays_and_weekly_offs_between()
    {
        $grid = array(1 => array(
            '2026-04-01' => $this->row('2026-04-01', 'Absent'), '2026-04-02' => $this->row('2026-04-02', 'Weekly Off'),
            '2026-04-03' => $this->row('2026-04-03', 'Absent'), '2026-04-04' => $this->row('2026-04-04', 'Holiday'), '2026-04-05' => $this->row('2026-04-05', 'Absent'),
            '2026-04-06' => $this->row('2026-04-06', 'Present', '09:00:00', '18:00:00'), '2026-04-07' => $this->row('2026-04-07', 'Absent'),
            '2026-04-08' => $this->row('2026-04-08', 'Leave'), '2026-04-09' => $this->row('2026-04-09', 'Absent'),
        ));
        $s = Attendance_analytics::summarise(array(1 => $this->emp()), $grid, '2026-04-01', '2026-04-09', '2026-04-30', 3)['employees'][1];
        $this->assertSame(3, $s['max_streak'], 'three Absent rows with a weekly off and a holiday between them');
        $this->assertCount(5, $s['absent_dates']);
    }

    public function test_missing_check_out_excludes_today_and_no_record_respects_employment()
    {
        $grid = array(1 => array(
            '2026-04-01' => $this->row('2026-04-01', 'Present', '09:00:00', NULL),
            '2026-04-02' => $this->row('2026-04-02', 'Present', '09:00:00', NULL),                  // "today": still at work
        ));
        $e = $this->emp('2026-04-01', '2026-04-04');                                             // left on the 4th
        $s = Attendance_analytics::summarise(array(1 => $e), $grid, '2026-03-28', '2026-04-06', '2026-04-02', 3)['employees'][1];
        $this->assertSame(1, $s['missing_out']);
        $this->assertSame('2026-04-01', $s['missing_rows'][0]['attendance_date']);
        $this->assertSame(array(), $s['no_record_dates'], 'before today: nothing without a row (2 April is today, 3-4 April are in the future)');

        $s = Attendance_analytics::summarise(array(1 => $e), $grid, '2026-03-28', '2026-04-06', '2026-04-10', 3)['employees'][1];
        $this->assertSame(array('2026-04-03', '2026-04-04'), $s['no_record_dates'], 'days of employment without a row; not before the join date, not after the exit');
    }

    public function test_daily_series_and_expected_days()
    {
        $grid = array(1 => array('2026-04-01' => $this->row('2026-04-01', 'Present', '09:00:00', '18:00:00'), '2026-04-02' => $this->row('2026-04-02', 'Absent')),
            2 => array('2026-04-01' => $this->row('2026-04-01', 'Absent'), '2026-04-02' => $this->row('2026-04-02', 'Weekly Off')));
        $r = Attendance_analytics::summarise(array(1 => $this->emp(), 2 => $this->emp()), $grid, '2026-04-01', '2026-04-02', '2026-04-30', 3);
        $this->assertEquals(array('present' => 1.0, 'absent' => 1.0, 'late' => 0, 'expected' => 2.0), $r['daily']['2026-04-01']);
        $this->assertEquals(array('present' => 0.0, 'absent' => 1.0, 'late' => 0, 'expected' => 1.0), $r['daily']['2026-04-02']);
    }

    public function test_insights_name_the_findings()
    {
        $employees = array(1 => array('first_name' => 'Late', 'last_name' => 'Lata', 'join_date' => '2026-01-01', 'exit_date' => NULL),
            2 => array('first_name' => 'Perfect', 'last_name' => 'Pia', 'join_date' => '2026-01-01', 'exit_date' => NULL));
        $grid = array(1 => array(), 2 => array());
        foreach (array('01', '02', '03') as $d) {
            $grid[1]['2026-04-' . $d] = $this->row('2026-04-' . $d, 'Present', '09:30:00', NULL, array('late_minutes' => 20));
            $grid[2]['2026-04-' . $d] = $this->row('2026-04-' . $d, 'Present', '09:00:00', '18:00:00', array('overtime_hours' => 1));
        }
        $r = Attendance_analytics::summarise($employees, $grid, '2026-04-01', '2026-04-03', '2026-04-30', 3);
        $keys = array_column($r['insights'], 'key');
        foreach (array('ins_overall', 'ins_late_limit', 'ins_top_late', 'ins_perfect', 'ins_ot', 'ins_missing') as $k) {
            $this->assertContains($k, $keys, $k);
        }
        $by = array_column($r['insights'], 'args', 'key');
        $this->assertStringContainsString('Late Lata (3)', $by['ins_top_late'][0]);
        $this->assertSame(array(1, 3), $by['ins_late_limit']);
        $this->assertSame(1, $by['ins_perfect'][0]);
        $this->assertNotContains('ins_top_absent', $keys, 'nobody was absent');
        // every key has an English and a Bangla sentence with the same number of placeholders
        foreach ($r['insights'] as $i) {
            $this->assertNotSame($i['key'], t($i['key']), $i['key']);
            $this->assertIsString(vsprintf(t($i['key']), $i['args']));
        }
    }

    public function test_late_limit_zero_switches_that_finding_off()
    {
        $grid = array(1 => array('2026-04-01' => $this->row('2026-04-01', 'Present', '09:30:00', '18:00:00', array('late_minutes' => 20))));
        $r = Attendance_analytics::summarise(array(1 => $this->emp()), $grid, '2026-04-01', '2026-04-01', '2026-04-30', 0);
        $this->assertNotContains('ins_late_limit', array_column($r['insights'], 'key'));
    }

    public function test_helpers()
    {
        $this->assertSame(array('2026-02-27', '2026-02-28', '2026-03-01'), Attendance_analytics::days('2026-02-27', '2026-03-01'));
        $this->assertSame(545, Attendance_analytics::minutes('09:05:59'));
        $this->assertSame('09:05', Attendance_analytics::clock(545));
        $this->assertSame('00:30', Attendance_analytics::clock(1470), 'wraps over midnight');
        $this->assertSame('1.5', Attendance_analytics::plain(1.5));
        $this->assertSame('2', Attendance_analytics::plain(2.0));
        $this->assertSame('0', Attendance_analytics::plain(0));
    }
}
