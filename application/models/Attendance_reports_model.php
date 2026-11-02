<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Daily attendance and the monthly grid (G5: set-based, at most three queries, range predicates on attendance_date).
 * Employees = those employed in the period (join/exit), of the current branch, optionally one department; own-records rules
 * as in the attendance list (show_all_attendance or department head).
 */
class Attendance_reports_model extends MY_Model
{
    protected $module = 'attendance';

    const MAX_EMPLOYEES = 1000;
    /** employees x days of one period report (about 3 months of 1000 people) */
    const MAX_CELLS = 100000;

    /** @return array employees, rows (employee_id => row), totals */
    public function daily($date, $department_id = NULL)
    {
        $employees = $this->employees($date, $date, $department_id);
        $rows = array();
        if ($employees) {
            foreach ($this->db->select('a.*')->from('attendance a')->where('a.attendance_date', $date)
                        ->where_in('a.employee_id', array_keys($employees))->get()->result_array() as $r) {
                $rows[(int) $r['employee_id']] = $r;
            }
        }
        $totals = array('employees' => count($employees), 'Present' => 0, 'Half-day' => 0, 'Absent' => 0, 'Leave' => 0, 'Holiday' => 0, 'Weekly Off' => 0, 'late' => 0, 'none' => 0, 'ot' => 0.0);
        foreach ($employees as $id => $e) {
            if ( ! isset($rows[$id])) {
                $totals['none']++;
                continue;
            }
            $totals[$rows[$id]['status']]++;
            $totals['late'] += (int) $rows[$id]['late_minutes'] > 0 ? 1 : 0;
            $totals['ot'] += (float) $rows[$id]['overtime_hours'];
        }
        return array('employees' => $employees, 'rows' => $rows, 'totals' => $totals);
    }

    /**
     * Monthly grid: employee => date => row (status, late, ...), plus per-employee totals.
     * @return array employees, days (list of Y-m-d), grid, totals (employee_id => counts)
     */
    public function monthly($month, $year, $department_id = NULL)
    {
        $range = month_range($month, $year);
        $employees = $this->employees($range['from'], $range['to'], $department_id);
        $grid = array();
        if ($employees) {
            foreach ($this->db->select('a.employee_id, a.attendance_date, a.status, a.late_minutes, a.overtime_hours, a.working_hours, a.check_in, a.check_out, a.approval_status')
                        ->from('attendance a')->where('a.attendance_date >=', $range['from'])->where('a.attendance_date <=', $range['to'])
                        ->where_in('a.employee_id', array_keys($employees))->get()->result_array() as $r) {
                $grid[(int) $r['employee_id']][$r['attendance_date']] = $r;
            }
        }
        $days = array();
        for ($d = $range['from']; $d <= $range['to']; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $days[] = $d;
        }
        $totals = array();
        foreach ($employees as $id => $e) {
            $t = array('P' => 0.0, 'A' => 0.0, 'L' => 0, 'H' => 0, 'W' => 0, 'late' => 0, 'ot' => 0.0, 'hours' => 0.0);
            foreach (isset($grid[$id]) ? $grid[$id] : array() as $r) {
                switch ($r['status']) {
                    case 'Present':
                        $t['P'] += 1;
                        break;
                    case 'Half-day':
                        $t['P'] += 0.5;
                        $t['A'] += 0.5;
                        break;
                    case 'Absent':
                        $t['A'] += 1;
                        break;
                    case 'Leave':
                        $t['L']++;
                        break;
                    case 'Holiday':
                        $t['H']++;
                        break;
                    default:
                        $t['W']++;
                }
                $t['late'] += (int) $r['late_minutes'] > 0 ? 1 : 0;
                $t['ot'] += (float) $r['overtime_hours'];
                $t['hours'] += (float) $r['working_hours'];
            }
            $totals[$id] = $t;
        }
        return array('employees' => $employees, 'days' => $days, 'grid' => $grid, 'totals' => $totals, 'range' => $range);
    }

    /**
     * The rows behind the period reports (summary, late, absent, overtime, missing check-out, in/out sheet): two queries.
     * @return array employees, grid (employee_id => date => row), days, error (NULL or a message)
     */
    public function period($from, $to, $department_id = NULL)
    {
        $employees = $this->employees($from, $to, $department_id);
        $day_count = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        if (count($employees) * $day_count > self::MAX_CELLS) {
            return array('employees' => array(), 'grid' => array(), 'days' => array(), 'error' => sprintf(t('rep_too_big'), self::MAX_CELLS));
        }
        $days = Attendance_analytics::days($from, $to);
        $grid = array();
        if ($employees) {
            foreach ($this->db->select('a.id, a.employee_id, a.attendance_date, a.status, a.check_in, a.check_out, a.working_hours, a.break_minutes, a.late_minutes, a.early_leave_minutes, a.overtime_hours, a.approval_status, a.remarks, a.locked, s.shift_name, s.start_time AS shift_start, s.end_time AS shift_end')
                        ->from('attendance a')->join('shifts s', 's.id = a.shift_id', 'left')
                        ->where('a.attendance_date >=', $from)->where('a.attendance_date <=', $to)->where_in('a.employee_id', array_keys($employees))
                        ->get()->result_array() as $r) {
                $grid[(int) $r['employee_id']][$r['attendance_date']] = $r;
            }
        }
        return array('employees' => $employees, 'grid' => $grid, 'days' => $days, 'error' => NULL);
    }

    /**
     * One employee over a period (month or from-to): the attendance rows, a plan for the days without a row (weekly off / holiday /
     * no record), the raw punches and the analytics. Fixed number of queries, whatever the length of the period.
     * @return array|NULL employee, days (date => array(row, plan, punches)), stats, error
     */
    public function employee_report($employee_id, $from, $to)
    {
        $employee = $this->db->select('e.id, e.employee_code, e.first_name, e.last_name, e.join_date, e.exit_date, e.device_pin, e.status, d.department_name, g.designation_name')
            ->from('employees e')->join('departments d', 'd.id = e.department_id', 'left')->join('designations g', 'g.id = e.designation_id', 'left')
            ->where('e.id', (int) $employee_id)->where('e.branch_id', (int) current_branch_id())->get()->row_array();
        if ( ! $employee) {
            return NULL;
        }
        $rows = array();
        foreach ($this->db->select('a.*, s.shift_name, s.start_time AS shift_start, s.end_time AS shift_end')->from('attendance a')->join('shifts s', 's.id = a.shift_id', 'left')
                    ->where('a.employee_id', (int) $employee_id)->where('a.attendance_date >=', $from)->where('a.attendance_date <=', $to)->get()->result_array() as $r) {
            $rows[$r['attendance_date']] = $r;
        }
        $plan = (new Shift_calculator())->plan(array((int) $employee_id), $from, $to);
        $plan = isset($plan[(int) $employee_id]) ? $plan[(int) $employee_id] : array();

        // raw punches of this one employee (until 06:00 of the day after the period: the tail of a night shift)
        $punches = array();
        foreach ($this->db->select('punch_at, punch_state, ignored, source')->from('attendance_punches')->where('employee_id', (int) $employee_id)
                    ->where('punch_at >=', $from . ' 00:00:00')->where('punch_at <=', date('Y-m-d', strtotime($to . ' +1 day')) . ' 06:00:00')->order_by('punch_at')->get()->result_array() as $p) {
            $date = substr($p['punch_at'], 0, 10);
            if ( ! isset($rows[$date]) || ($rows[$date]['first_punch_at'] !== NULL && $p['punch_at'] < $rows[$date]['first_punch_at'])) {
                $prev = date('Y-m-d', strtotime($date . ' -1 day'));
                if (isset($rows[$prev]) && $rows[$prev]['last_punch_at'] !== NULL && $p['punch_at'] <= $rows[$prev]['last_punch_at']) {
                    $date = $prev;                                       // belongs to the night shift that began the day before
                }
            }
            $punches[$date][] = $p;
        }
        $days = array();
        foreach (Attendance_analytics::days($from, $to) as $d) {
            $days[$d] = array('row' => isset($rows[$d]) ? $rows[$d] : NULL, 'plan' => isset($plan[$d]) ? $plan[$d] : NULL, 'punches' => isset($punches[$d]) ? $punches[$d] : array());
        }
        $stats = Attendance_analytics::summarise(array((int) $employee_id => $employee), array((int) $employee_id => $rows), $from, $to, today_local(), (int) hr_setting('late_days_per_absent', 3));
        return array('employee' => $employee, 'days' => $days, 'stats' => $stats['employees'][(int) $employee_id], 'insights' => $this->employee_insights($employee, $days, $stats['employees'][(int) $employee_id]), 'error' => NULL);
    }

    /** id => "CODE - Name" of the employees of the branch the user may report on (also leavers), for the employee picker */
    public function employee_options()
    {
        $this->db->select('e.id, e.employee_code, e.first_name, e.last_name, e.status')->from('employees e');
        $this->scope_branch('e');
        $this->scope_department('e', 'id');
        $out = array();
        foreach ($this->db->order_by('e.employee_code')->limit(self::MAX_EMPLOYEES)->get()->result_array() as $r) {
            $out[(int) $r['id']] = $r['employee_code'] . ' - ' . trim($r['first_name'] . ' ' . $r['last_name']) . (in_array($r['status'], array('Resigned', 'Terminated'), TRUE) ? ' (' . t('emp_status_' . strtolower($r['status'])) . ')' : '');
        }
        return $out;
    }

    /** findings for one person's period */
    protected function employee_insights(array $employee, array $days, array $s)
    {
        $items = array();
        $worked = $s['present'];
        if ($s['pct'] !== NULL) {
            $items[] = array('type' => $s['pct'] >= 90 ? 'success' : ($s['pct'] >= 75 ? 'info' : 'warning'), 'icon' => 'fa-line-chart', 'key' => 'ins_emp_overall',
                'args' => array(Attendance_analytics::plain($s['present']), Attendance_analytics::plain($s['expected']), number_format($s['pct'], 1)));
        }
        if ($s['late_days'] > 0) {
            $items[] = array('type' => 'warning', 'icon' => 'fa-clock-o', 'key' => 'ins_emp_late', 'args' => array($s['late_days'], $s['late_minutes'], round($s['late_minutes'] / $s['late_days']), $s['late_max']));
        } elseif ($worked > 0) {
            $items[] = array('type' => 'success', 'icon' => 'fa-check-circle', 'key' => 'ins_emp_on_time', 'args' => array());
        }
        if ($s['max_streak'] >= 2) {
            $items[] = array('type' => $s['max_streak'] >= 3 ? 'danger' : 'warning', 'icon' => 'fa-exclamation-triangle', 'key' => 'ins_emp_streak', 'args' => array($s['max_streak']));
        }
        if ($s['avg_in'] !== '') {
            $items[] = array('type' => 'info', 'icon' => 'fa-sign-in', 'key' => 'ins_emp_avg', 'args' => array($s['avg_in'], $s['avg_out'] !== '' ? $s['avg_out'] : '-', number_format($worked > 0 ? $s['hours'] / $worked : 0, 2)));
        }
        if ($s['ot'] > 0) {
            $items[] = array('type' => 'info', 'icon' => 'fa-plus-circle', 'key' => 'ins_emp_ot', 'args' => array(number_format($s['ot'], 2), $s['ot_days']));
        }
        if ($s['missing_out'] > 0) {
            $items[] = array('type' => 'warning', 'icon' => 'fa-sign-out', 'key' => 'ins_emp_missing', 'args' => array($s['missing_out']));
        }
        if ($s['no_record'] > 0) {
            $items[] = array('type' => 'danger', 'icon' => 'fa-database', 'key' => 'ins_no_record', 'args' => array($s['no_record']));
        }
        return $items;
    }

    /** G6: every export is written to the audit log */
    public function log_export(array $details)
    {
        Tx::run(function () use ($details) {
            $this->audit('reports', NULL, 'export', NULL, $details);
            return TRUE;
        });
    }

    /** grid letter of a status */
    public static function letter($status)
    {
        $map = array('Present' => 'P', 'Absent' => 'A', 'Half-day' => '½', 'Leave' => 'L', 'Holiday' => 'H', 'Weekly Off' => 'W');
        return isset($map[$status]) ? $map[$status] : '';
    }

    /** employees employed in [$from, $to], current branch, scope; id => row (one query, joined names) */
    protected function employees($from, $to, $department_id)
    {
        $this->db->select('e.id, e.employee_code, e.first_name, e.last_name, e.join_date, e.exit_date, e.device_pin, d.department_name, g.designation_name')
            ->from('employees e')->join('departments d', 'd.id = e.department_id', 'left')->join('designations g', 'g.id = e.designation_id', 'left')
            ->where('e.join_date <=', $to)->group_start()->where('e.exit_date IS NULL', NULL, FALSE)->or_where('e.exit_date >=', $from)->group_end();
        $this->scope_branch('e');
        $this->scope_department('e', 'id');
        if ($department_id !== NULL) {
            $this->db->where('e.department_id', (int) $department_id);
        }
        $out = array();
        foreach ($this->db->order_by('e.employee_code')->limit(self::MAX_EMPLOYEES)->get()->result_array() as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }
}
