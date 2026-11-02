<?php
/** The report layer for split-shift days: periods attached with at most one extra query, incomplete periods counted and explained. */
class Split_shift_reports_test extends Hrm_test_case
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->act_as_new_user('rep_boss', array('attendance_report', 'show_all_attendance'));
        $this->CI->load->model('Attendance_reports_model');
    }

    protected function day($employee, $shift, $date, array $periods, array $extra = array())
    {
        $this->db->insert('attendance', array_merge(array('branch_id' => current_branch_id(), 'employee_id' => $employee, 'attendance_date' => $date, 'shift_id' => $shift, 'status' => 'Present',
            'check_in' => '10:00:00', 'check_out' => '22:00:00', 'approval_status' => 'Approved', 'source' => 'punch', 'created_at' => now_local()), $extra));
        $id = (int) $this->db->insert_id();
        $rows = array();
        foreach ($periods as $i => $p) {
            $rows[] = array('no' => $i + 1, 'check_in' => $p[0], 'check_out' => $p[1], 'worked_minutes' => 0, 'late_minutes' => 0, 'early_leave_minutes' => 0);
        }
        Attendance_periods::store($id, $rows);
        return $id;
    }

    public function test_periods_cost_one_query_and_only_when_a_split_shift_is_in_the_period()
    {
        $normal = $this->insert_shift('NRM', '09:00:00', '18:00:00', array('working_hours' => 8));
        $a = $this->insert_employee('SR1');
        $this->day($a, $normal, '2026-04-06', array());
        hr_setting('weekly_offs');
        has_permission('show_all_attendance');
        $before = $this->db->total_queries();
        $data = $this->CI->Attendance_reports_model->period('2026-04-01', '2026-04-30');
        $plain = $this->db->total_queries() - $before;
        $this->assertSame(array(), $data['grid'][$a]['2026-04-06']['periods']);

        $split = $this->insert_split_shift('SPL');
        $b = $this->insert_employee('SR2');
        $this->day($b, $split, '2026-04-06', array(array('10:00:00', '14:00:00'), array('18:00:00', '22:00:00')));
        $before = $this->db->total_queries();
        $data = $this->CI->Attendance_reports_model->period('2026-04-01', '2026-04-30');
        $this->assertSame($plain + 1, $this->db->total_queries() - $before, 'the periods of all split days come in one query');
        $this->assertCount(2, $data['grid'][$b]['2026-04-06']['periods']);
        $this->assertSame('complete', $data['grid'][$b]['2026-04-06']['periods'][0]['state']);
    }

    public function test_incomplete_periods_are_counted_only_for_days_before_today()
    {
        $split = $this->insert_split_shift('SPL');
        $e = $this->insert_employee('SR3', array('join_date' => '2026-04-01'));
        $this->day($e, $split, '2026-04-06', array(array('10:00:00', '14:00:00'), array('18:00:00', '22:00:00')));                              // complete
        $this->day($e, $split, '2026-04-07', array(array('10:00:00', '14:00:00'), array(NULL, NULL)), array('check_out' => '14:00:00'));         // second period missed
        $this->day($e, $split, '2026-04-08', array(array('10:00:00', '14:00:00'), array('18:00:00', NULL)), array('check_out' => NULL));        // last period open
        $this->day($e, $split, '2026-04-09', array(array('10:00:00', NULL), array('18:00:00', '22:00:00')));                                     // first period open
        $this->day($e, $split, '2026-04-10', array(array('10:00:00', '14:00:00'), array(NULL, NULL)), array('check_out' => '14:00:00'));        // "today"
        $data = $this->CI->Attendance_reports_model->period('2026-04-01', '2026-04-30');
        $stats = Attendance_analytics::summarise($data['employees'], $data['grid'], '2026-04-01', '2026-04-30', '2026-04-10', 3);
        $s = $stats['employees'][$e];
        $this->assertSame(3, $s['incomplete'], '7th, 8th and 9th; the 10th is today and the person may still come back');
        $this->assertSame(1, $s['missing_out'], 'only the 8th has no check-out at all');
        $this->assertContains('ins_incomplete', array_column($stats['insights'], 'key'));
        $reasons = array();
        foreach ($s['incomplete_rows'] as $r) {
            $reasons[$r['attendance_date']] = Attendance_analytics::missing_reason($r);
        }
        $this->assertSame(sprintf(t('rep_reason_period_missed'), 2), $reasons['2026-04-07']);
        $this->assertSame(t('rep_reason_no_out'), $reasons['2026-04-08'], 'the open last period is the "no check-out" already named, not named twice');
        $this->assertSame(sprintf(t('rep_reason_period_open'), 1), $reasons['2026-04-09']);
    }

    public function test_the_employee_report_carries_the_periods_and_a_finding()
    {
        $split = $this->insert_split_shift('SPL');
        $e = $this->insert_employee('SR4', array('join_date' => '2026-04-01'));
        $this->day($e, $split, '2026-04-07', array(array('10:00:00', '14:00:00'), array(NULL, NULL)), array('check_out' => '14:00:00'));
        $r = $this->CI->Attendance_reports_model->employee_report($e, '2026-04-01', '2026-04-30');
        $this->assertCount(2, $r['days']['2026-04-07']['row']['periods']);
        $this->assertSame(1, (int) $r['days']['2026-04-07']['row']['shift_split']);
        $this->assertContains('ins_emp_incomplete', array_column($r['insights'], 'key'));
    }

    public function test_periods_text_for_exports()
    {
        $this->assertSame('10:02-14:01 / 17:55-?', Attendance_periods::text(array(array('check_in' => '10:02:00', 'check_out' => '14:01:00'), array('check_in' => '17:55:00', 'check_out' => NULL))));
        $this->assertSame('10:00-14:00 / -', Attendance_periods::text(array(array('check_in' => '10:00:00', 'check_out' => '14:00:00'), array('check_in' => NULL, 'check_out' => NULL))));
    }
}
