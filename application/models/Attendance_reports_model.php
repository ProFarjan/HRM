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
        $this->db->select('e.id, e.employee_code, e.first_name, e.last_name, d.department_name, g.designation_name')
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
