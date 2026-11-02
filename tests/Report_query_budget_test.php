<?php
/** G5 / P15: a monthly attendance report for 500 employees x 30 days runs in <= 6 queries; the daily report in <= 3. */
class Report_query_budget_test extends Hrm_test_case
{
    public function test_monthly_report_for_500_employees_by_30_days()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $branch = current_branch_id();
        $now = now_local();
        $employees = array();
        for ($i = 0; $i < 500; $i++) {
            $employees[] = array('branch_id' => $branch, 'employee_code' => sprintf('B%04d', $i), 'first_name' => 'Worker ' . $i, 'employment_type' => 'Permanent',
                'join_date' => '2025-01-01', 'status' => 'Active', 'created_at' => $now);
        }
        $this->db->insert_batch('employees', $employees, NULL, 500);
        $ids = array_map('intval', array_column($this->db->query("SELECT id FROM employees WHERE employee_code LIKE 'B%'")->result_array(), 'id'));
        $rows = array();
        foreach ($ids as $id) {
            for ($d = 1; $d <= 30; $d++) {
                $rows[] = array('branch_id' => $branch, 'employee_id' => $id, 'attendance_date' => sprintf('2026-04-%02d', $d), 'status' => $d % 7 === 3 ? 'Weekly Off' : 'Present',
                    'check_in' => '09:05:00', 'check_out' => '18:00:00', 'late_minutes' => $d % 5 === 0 ? 12 : 0, 'working_hours' => 8.92, 'approval_status' => 'Approved',
                    'source' => 'punch', 'created_at' => $now);
            }
            if (count($rows) >= 3000) {
                $this->db->insert_batch('attendance', $rows, NULL, 3000);
                $rows = array();
            }
        }
        if ($rows) {
            $this->db->insert_batch('attendance', $rows, NULL, 3000);
        }

        $this->CI->load->model('Attendance_reports_model');
        has_permission('show_all_attendance');                        // per-request caches warm, as on a real page
        hr_setting('date_format');
        $before = $this->queries_before();
        $report = $this->CI->Attendance_reports_model->monthly(4, 2026);
        $used = $this->queries_before() - $before;
        $this->assertLessThanOrEqual(6, $used, "monthly report used $used queries");
        $this->assertCount(500, $report['employees']);
        $this->assertCount(30, $report['days']);
        $first = $report['totals'][$ids[0]];
        $this->assertSame(array(26.0, 4, 6), array($first['P'], $first['W'], $first['late']));

        $before = $this->queries_before();
        $daily = $this->CI->Attendance_reports_model->daily('2026-04-11');            // the 10th is a weekly off in this seed
        $this->assertLessThanOrEqual(3, $this->queries_before() - $before);
        $this->assertSame(500, $daily['totals']['Present']);
    }

    public function test_reports_follow_the_own_records_rule()
    {
        $user = $this->act_with_permissions('reporter', array('attendance_report'));
        $mine = $this->insert_employee('MINE');
        $this->insert_employee('OTHER');
        set_current_user(array_merge($user, array('employee_id' => $mine)));
        $this->CI->load->model('Attendance_reports_model');
        $this->assertSame(array($mine), array_keys($this->CI->Attendance_reports_model->daily('2026-04-10')['employees']), 'without show_all_attendance: own row only');
        $this->act_with_permissions('reporter2', array('attendance_report', 'show_all_attendance'));
        $this->assertCount(2, $this->CI->Attendance_reports_model->daily('2026-04-10')['employees']);
    }
}
