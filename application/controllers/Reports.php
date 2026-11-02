<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Attendance reports (`attendance_report`): daily, monthly status grid, monthly in/out sheet, one employee (month or from-to), period
 * summary with insights, late, absent, overtime and missing check-out. Excel needs `export_hr` as well and is written to the audit
 * log (G6). All of them are set-based (G5): the period reports run two queries, the employee report a fixed number.
 */
class Reports extends MY_Controller
{
    protected $permission_map = array(
        'daily'         => 'attendance_report',
        'monthly'       => 'attendance_report',
        'inout'         => 'attendance_report',
        'employee'      => 'attendance_report',
        'summary'       => 'attendance_report',
        'late'          => 'attendance_report',
        'absent'        => 'attendance_report',
        'overtime'      => 'attendance_report',
        'missing'       => 'attendance_report',
        'daily_xlsx'    => 'export_hr',
        'monthly_xlsx'  => 'export_hr',
        'inout_xlsx'    => 'export_hr',
        'employee_xlsx' => 'export_hr',
        'summary_xlsx'  => 'export_hr',
        'late_xlsx'     => 'export_hr',
        'absent_xlsx'   => 'export_hr',
        'overtime_xlsx' => 'export_hr',
        'missing_xlsx'  => 'export_hr',
    );

    /** longest from-to range of the all-employee reports / of the one-employee report, in days */
    const MAX_DAYS = 92;
    const MAX_DAYS_EMPLOYEE = 366;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Attendance_reports_model', 'reports');
        $this->load->model('Departments_model');
        $this->load->model('Attendance_model');                 // status labels
    }

    public function daily()
    {
        list($date, $department) = $this->daily_params();
        $this->data['active_menu'] = 'reports/daily';
        $this->data['page_title'] = t('rep_daily');
        $this->render('reports/daily', array_merge($this->reports->daily($date, $department), array(
            'date' => $date, 'department_id' => $department, 'departments' => $this->Departments_model->active_list(),
        )));
    }

    public function monthly()
    {
        list($month, $year, $department) = $this->monthly_params();
        $this->data['active_menu'] = 'reports/monthly';
        $this->data['page_title'] = t('rep_monthly');
        $this->render('reports/monthly', array_merge($this->reports->monthly($month, $year, $department), array(
            'month' => $month, 'year' => $year, 'department_id' => $department, 'departments' => $this->Departments_model->active_list(),
            'late_limit' => (int) hr_setting('late_days_per_absent', 3),
        )));
    }

    public function daily_xlsx()
    {
        $this->require_permission('attendance_report');
        list($date, $department) = $this->daily_params();
        $data = $this->reports->daily($date, $department);
        $rows = array(array(t('emp_code'), t('emp_name'), t('emp_department'), t('status'), t('att_in'), t('att_out'), t('att_hours'), t('att_late'), t('att_ot')));
        foreach ($data['employees'] as $id => $e) {
            $r = isset($data['rows'][$id]) ? $data['rows'][$id] : NULL;
            $rows[] = array($e['employee_code'], trim($e['first_name'] . ' ' . $e['last_name']), (string) $e['department_name'],
                $r ? $r['status'] : '-', $r ? substr((string) $r['check_in'], 0, 5) : '', $r ? substr((string) $r['check_out'], 0, 5) : '',
                $r ? (float) $r['working_hours'] : '', $r ? (int) $r['late_minutes'] : '', $r ? (float) $r['overtime_hours'] : '');
        }
        $this->send_xlsx('attendance-daily-' . $date . '.xlsx', $rows, array('report' => 'daily', 'date' => $date, 'department_id' => $department, 'rows' => count($rows) - 1));
    }

    public function monthly_xlsx()
    {
        $this->require_permission('attendance_report');
        list($month, $year, $department) = $this->monthly_params();
        $data = $this->reports->monthly($month, $year, $department);
        $head = array(t('emp_code'), t('emp_name'));
        foreach ($data['days'] as $d) {
            $head[] = (int) substr($d, 8, 2);
        }
        $rows = array(array_merge($head, array('P', 'A', 'L', 'H', 'W', t('att_late'), t('att_ot'))));
        foreach ($data['employees'] as $id => $e) {
            $line = array($e['employee_code'], trim($e['first_name'] . ' ' . $e['last_name']));
            foreach ($data['days'] as $d) {
                $line[] = isset($data['grid'][$id][$d]) ? Attendance_reports_model::letter($data['grid'][$id][$d]['status']) : '';
            }
            $t = $data['totals'][$id];
            $rows[] = array_merge($line, array($t['P'], $t['A'], $t['L'], $t['H'], $t['W'], $t['late'], round($t['ot'], 2)));
        }
        $this->send_xlsx(sprintf('attendance-%04d-%02d.xlsx', $year, $month), $rows, array('report' => 'monthly', 'month' => $month, 'year' => $year, 'department_id' => $department, 'rows' => count($rows) - 1));
    }


    // ------------------------------------------------------------------------------------------------ in/out sheet

    /** all employees x the days of a month, each cell with the in and out time */
    public function inout()
    {
        list($month, $year, $department) = $this->monthly_params();
        $range = month_range($month, $year);
        $data = $this->reports->period($range['from'], $range['to'], $department);
        $this->page('inout', array_merge($data, array(
            'month' => $month, 'year' => $year, 'department_id' => $department, 'range' => $range, 'late_limit' => (int) hr_setting('late_days_per_absent', 3),
            'stats' => $this->summarise($data, $range['from'], $range['to']),
        )));
    }

    public function inout_xlsx()
    {
        $this->require_permission('attendance_report');
        list($month, $year, $department) = $this->monthly_params();
        $range = month_range($month, $year);
        $data = $this->reports->period($range['from'], $range['to'], $department);
        $stats = $this->summarise($data, $range['from'], $range['to']);
        $head = array(t('emp_code'), t('emp_name'));
        foreach ($data['days'] as $d) {
            $head[] = (int) substr($d, 8, 2) . ' ' . substr(date('D', strtotime($d)), 0, 2);
        }
        $rows = array(array_merge($head, array(t('rep_present'), t('rep_absent_days'), t('rep_leave'), t('rep_late_days'), t('att_hours'), t('att_ot'))));
        foreach ($data['employees'] as $id => $e) {
            $line = array($e['employee_code'], trim($e['first_name'] . ' ' . $e['last_name']));
            foreach ($data['days'] as $d) {
                $line[] = isset($data['grid'][$id][$d]) ? $this->cell_text($data['grid'][$id][$d]) : '';
            }
            $x = $stats['employees'][$id];
            $rows[] = array_merge($line, array((float) $x['present'], (float) $x['absent'], $x['leave'], $x['late_days'], $x['hours'], $x['ot']));
        }
        $this->send_xlsx(sprintf('attendance-inout-%04d-%02d.xlsx', $year, $month), $rows, array('report' => 'inout', 'month' => $month, 'year' => $year, 'department_id' => $department, 'rows' => count($rows) - 1));
    }

    // ------------------------------------------------------------------------------------------------ one employee

    /** one employee, a month or a from-to range: every day with in / out / punches, totals and findings */
    public function employee()
    {
        $p = $this->employee_params();
        $data = array('p' => $p, 'employees' => $this->reports->employee_options(), 'report' => NULL, 'error' => $p['error']);
        if ($p['employee_id'] !== NULL && $p['error'] === NULL) {
            $this->check_employee_scope($p['employee_id']);
            $data['report'] = $this->reports->employee_report($p['employee_id'], $p['from'], $p['to']);
        }
        $this->page('employee', $data);
    }

    public function employee_xlsx()
    {
        $this->require_permission('attendance_report');
        $p = $this->employee_params();
        if ($p['employee_id'] === NULL || $p['error'] !== NULL) {
            show_error(t('rep_choose_employee'), 422);
        }
        $this->check_employee_scope($p['employee_id']);
        $r = $this->reports->employee_report($p['employee_id'], $p['from'], $p['to']);
        $rows = array(array(t('att_date'), t('rep_weekday'), t('rep_shift'), t('status'), t('att_in'), t('att_out'), t('att_break'), t('att_hours'), t('att_late'), t('att_early'), t('att_ot'), t('rep_punches'), t('att_remarks')));
        foreach ($r['days'] as $d => $day) {
            $x = $day['row'];
            $times = array();
            foreach ($day['punches'] as $pt) {
                $times[] = substr($pt['punch_at'], 11, 5) . ((int) $pt['ignored'] ? '*' : '');
            }
            $rows[] = array(show_date($d), date('D', strtotime($d)), $x && $x['shift_name'] ? $x['shift_name'] . ' ' . substr($x['shift_start'], 0, 5) . '-' . substr($x['shift_end'], 0, 5) : '',
                $x ? $x['status'] : $this->plan_status($day['plan']), $x ? substr((string) $x['check_in'], 0, 5) : '', $x ? substr((string) $x['check_out'], 0, 5) : '',
                $x ? (int) $x['break_minutes'] : '', $x ? (float) $x['working_hours'] : '', $x ? (int) $x['late_minutes'] : '', $x ? (int) $x['early_leave_minutes'] : '',
                $x ? (float) $x['overtime_hours'] : '', implode(' ', $times), $x ? (string) $x['remarks'] : '');
        }
        $e = $r['employee'];
        $this->send_xlsx('attendance-' . preg_replace('/[^A-Za-z0-9_-]/', '', $e['employee_code']) . '-' . $p['from'] . '_' . $p['to'] . '.xlsx', $rows,
            array('report' => 'employee', 'employee_id' => $p['employee_id'], 'from' => $p['from'], 'to' => $p['to'], 'rows' => count($rows) - 1));
    }

    // ------------------------------------------------------------------------------------------------ period reports

    public function summary()
    {
        $p = $this->period_params();
        $data = $this->reports->period($p['from'], $p['to'], $p['department_id']);
        $stats = $this->summarise($data, $p['from'], $p['to']);
        $this->page('summary', array_merge($data, array('p' => $p, 'stats' => $stats, 'late_limit' => (int) hr_setting('late_days_per_absent', 3), 'error' => $p['error'] !== NULL ? $p['error'] : $data['error'], 'page_vendor_js' => array('chartjs/Chart.min.js'))));
    }

    public function summary_xlsx()
    {
        $this->require_permission('attendance_report');
        list($data, $stats, $p) = $this->period_data();
        $rows = array(array(t('emp_code'), t('emp_name'), t('emp_department'), t('rep_present'), t('rep_absent_days'), t('rep_leave'), t('rep_holiday'), t('rep_weekly_off'), t('rep_attendance_pct'),
            t('rep_late_days'), t('rep_late_minutes'), t('att_early'), t('att_hours'), t('att_ot'), t('rep_avg_in'), t('rep_avg_out'), t('rep_missing_out'), t('rep_no_record')));
        foreach ($data['employees'] as $id => $e) {
            $x = $stats['employees'][$id];
            $rows[] = array($e['employee_code'], trim($e['first_name'] . ' ' . $e['last_name']), (string) $e['department_name'], (float) $x['present'], (float) $x['absent'], $x['leave'], $x['holiday'], $x['weekly_off'],
                $x['pct'] === NULL ? '' : $x['pct'], $x['late_days'], $x['late_minutes'], $x['early_minutes'], $x['hours'], $x['ot'], $x['avg_in'], $x['avg_out'], $x['missing_out'], $x['no_record']);
        }
        $this->send_period_xlsx('summary', $p, $rows);
    }

    public function late()
    {
        $p = $this->period_params();
        $min = $this->min_minutes();
        $data = $this->reports->period($p['from'], $p['to'], $p['department_id']);
        $this->page('late', array_merge($data, array('p' => $p, 'min' => $min, 'stats' => $this->summarise($data, $p['from'], $p['to']), 'late_limit' => (int) hr_setting('late_days_per_absent', 3),
            'error' => $p['error'] !== NULL ? $p['error'] : $data['error'])));
    }

    public function late_xlsx()
    {
        $this->require_permission('attendance_report');
        list($data, $stats, $p) = $this->period_data();
        $min = $this->min_minutes();
        $rows = array(array(t('emp_code'), t('emp_name'), t('att_date'), t('rep_shift_start'), t('att_in'), t('att_late')));
        foreach ($data['employees'] as $id => $e) {
            foreach ($stats['employees'][$id]['late_rows'] as $r) {
                if ((int) $r['late_minutes'] >= $min) {
                    $rows[] = array($e['employee_code'], trim($e['first_name'] . ' ' . $e['last_name']), show_date($r['attendance_date']), substr((string) $r['shift_start'], 0, 5), substr((string) $r['check_in'], 0, 5), (int) $r['late_minutes']);
                }
            }
        }
        $this->send_period_xlsx('late', $p, $rows);
    }

    public function absent()
    {
        $p = $this->period_params();
        $data = $this->reports->period($p['from'], $p['to'], $p['department_id']);
        $this->page('absent', array_merge($data, array('p' => $p, 'stats' => $this->summarise($data, $p['from'], $p['to']), 'error' => $p['error'] !== NULL ? $p['error'] : $data['error'])));
    }

    public function absent_xlsx()
    {
        $this->require_permission('attendance_report');
        list($data, $stats, $p) = $this->period_data();
        $rows = array(array(t('emp_code'), t('emp_name'), t('emp_department'), t('rep_absent_days'), t('rep_longest_streak'), t('rep_dates')));
        foreach ($data['employees'] as $id => $e) {
            $x = $stats['employees'][$id];
            if ($x['absent_dates']) {
                $dates = array();
                foreach ($x['absent_dates'] as $a) {
                    $dates[] = show_date($a['date']) . ($a['half'] ? ' (1/2)' : '');
                }
                $rows[] = array($e['employee_code'], trim($e['first_name'] . ' ' . $e['last_name']), (string) $e['department_name'], (float) $x['absent'], $x['max_streak'], implode(', ', $dates));
            }
        }
        $this->send_period_xlsx('absent', $p, $rows);
    }

    public function overtime()
    {
        $p = $this->period_params();
        $data = $this->reports->period($p['from'], $p['to'], $p['department_id']);
        $this->page('overtime', array_merge($data, array('p' => $p, 'stats' => $this->summarise($data, $p['from'], $p['to']), 'error' => $p['error'] !== NULL ? $p['error'] : $data['error'])));
    }

    public function overtime_xlsx()
    {
        $this->require_permission('attendance_report');
        list($data, $stats, $p) = $this->period_data();
        $rows = array(array(t('emp_code'), t('emp_name'), t('att_date'), t('att_in'), t('att_out'), t('att_hours'), t('att_ot')));
        foreach ($data['employees'] as $id => $e) {
            foreach ($stats['employees'][$id]['ot_rows'] as $r) {
                $rows[] = array($e['employee_code'], trim($e['first_name'] . ' ' . $e['last_name']), show_date($r['attendance_date']), substr((string) $r['check_in'], 0, 5), substr((string) $r['check_out'], 0, 5), (float) $r['working_hours'], (float) $r['overtime_hours']);
            }
        }
        $this->send_period_xlsx('overtime', $p, $rows);
    }

    public function missing()
    {
        $p = $this->period_params();
        $data = $this->reports->period($p['from'], $p['to'], $p['department_id']);
        $this->page('missing', array_merge($data, array('p' => $p, 'stats' => $this->summarise($data, $p['from'], $p['to']), 'error' => $p['error'] !== NULL ? $p['error'] : $data['error'],
            'can_edit' => has_permission('attendance_edit'))));
    }

    public function missing_xlsx()
    {
        $this->require_permission('attendance_report');
        list($data, $stats, $p) = $this->period_data();
        $rows = array(array(t('emp_code'), t('emp_name'), t('att_date'), t('rep_shift'), t('att_in'), t('att_remarks')));
        foreach ($data['employees'] as $id => $e) {
            foreach ($stats['employees'][$id]['missing_rows'] as $r) {
                $rows[] = array($e['employee_code'], trim($e['first_name'] . ' ' . $e['last_name']), show_date($r['attendance_date']), (string) $r['shift_name'], substr((string) $r['check_in'], 0, 5), (string) $r['remarks']);
            }
        }
        $this->send_period_xlsx('missing', $p, $rows);
    }

    // ------------------------------------------------------------------------------------------------ internals

    protected function page($view, array $data)
    {
        $this->data['active_menu'] = 'reports/' . $view;
        $this->data['page_title'] = t('rep_' . $view);
        if ( ! isset($data['departments'])) {
            $data['departments'] = $this->Departments_model->active_list();
        }
        $data['page_js'] = array('reports');
        $this->render('reports/' . $view, $data);
    }

    /** analytics of a period fetched by Attendance_reports_model::period() */
    protected function summarise(array $data, $from, $to)
    {
        return Attendance_analytics::summarise($data['employees'], $data['grid'], $from, $to, today_local(), (int) hr_setting('late_days_per_absent', 3));
    }

    /** data, stats and params of a period report for its Excel export */
    protected function period_data()
    {
        $p = $this->period_params();
        if ($p['error'] !== NULL) {
            show_error($p['error'], 422);
        }
        $data = $this->reports->period($p['from'], $p['to'], $p['department_id']);
        if ($data['error'] !== NULL) {
            show_error($data['error'], 422);
        }
        return array($data, $this->summarise($data, $p['from'], $p['to']), $p);
    }

    protected function send_period_xlsx($report, array $p, array $rows)
    {
        $this->send_xlsx('attendance-' . $report . '-' . $p['from'] . '_' . $p['to'] . '.xlsx', $rows,
            array('report' => $report, 'from' => $p['from'], 'to' => $p['to'], 'department_id' => $p['department_id'], 'rows' => count($rows) - 1));
    }

    /** from_date / to_date / department_id (default: this month up to today; at most MAX_DAYS days) */
    protected function period_params()
    {
        $v = new Input_validator($this->input, 'get');
        $from = $v->date('from_date', FALSE);
        $to = $v->date('to_date', FALSE);
        $department = $v->ref('department_id', 'departments', FALSE);
        $error = $v->ok() ? NULL : $v->message();
        $to = $to !== NULL && $error === NULL ? $to : today_local();
        $from = $from !== NULL && $error === NULL ? $from : date('Y-m-01', strtotime($to));
        if ($error === NULL) {
            $error = $this->range_error($from, $to, self::MAX_DAYS);
        }
        if ($error !== NULL) {
            $to = today_local();
            $from = date('Y-m-01', strtotime($to));
            $department = NULL;
        }
        return array('from' => $from, 'to' => $to, 'department_id' => $department, 'error' => $error);
    }

    /** employee_id, mode (month | range), month / year or from_date / to_date */
    protected function employee_params()
    {
        $v = new Input_validator($this->input, 'get');
        $employee = $v->ref('employee_id', 'employees', FALSE);
        $mode = $v->enum('mode', array('month', 'range'), FALSE);
        $month = $v->int('month', 1, 12, FALSE);
        $year = $v->int('year', 2000, 2999, FALSE);
        $from = $v->date('from_date', FALSE);
        $to = $v->date('to_date', FALSE);
        $error = $v->ok() ? NULL : $v->message();
        $mode = $mode !== NULL && $error === NULL ? $mode : ($from !== NULL || $to !== NULL ? 'range' : 'month');
        $month = $month ? $month : (int) date('n');
        $year = $year ? $year : (int) date('Y');
        if ($mode === 'month' || $error !== NULL) {
            $r = month_range($month, $year);
            $from = $r['from'];
            $to = $r['to'];
        } else {
            $to = $to !== NULL ? $to : today_local();
            $from = $from !== NULL ? $from : date('Y-m-01', strtotime($to));
            $error = $this->range_error($from, $to, self::MAX_DAYS_EMPLOYEE);
        }
        return array('employee_id' => $error === NULL ? $employee : NULL, 'mode' => $mode, 'month' => $month, 'year' => $year, 'from' => $from, 'to' => $to, 'error' => $error);
    }

    protected function range_error($from, $to, $max_days)
    {
        if ($from > $to) {
            return t('audit_range_order');
        }
        if ((strtotime($to) - strtotime($from)) / 86400 + 1 > $max_days) {
            return sprintf(t('audit_range_too_long'), $max_days);
        }
        return NULL;
    }

    protected function min_minutes()
    {
        $v = new Input_validator($this->input, 'get');
        $min = $v->int('min_minutes', 1, 600, FALSE);
        return $v->ok() && $min ? $min : 1;
    }

    /** the employee is in the branch and (without show_all_attendance) the user or in a department the user heads */
    protected function check_employee_scope($employee_id)
    {
        $this->belong_to('employees', $employee_id);
        if (has_permission('show_all_attendance') || (int) $employee_id === (int) current_employee_id()) {
            return;
        }
        if ( ! $this->Attendance_model->in_my_department($employee_id)) {
            $this->deny(403, 'page');
        }
    }

    /** the text of a day in the in/out sheet and its Excel: "09:05-18:20", "09:05-?" or the status letter */
    protected function cell_text(array $r)
    {
        if (in_array($r['status'], array('Present', 'Half-day'), TRUE)) {
            return substr((string) $r['check_in'], 0, 5) . '-' . ($r['check_out'] !== NULL ? substr($r['check_out'], 0, 5) : '?');
        }
        return Attendance_reports_model::letter($r['status']);
    }

    /** a day without an attendance row, from the shift plan */
    protected function plan_status($plan)
    {
        if ($plan === NULL || ! $plan['employed']) {
            return t('rep_not_employed');
        }
        if ($plan['day_type'] === 'holiday') {
            return 'Holiday';
        }
        return $plan['day_type'] === 'weekly_off' ? 'Weekly Off' : t('rep_no_row');
    }

    protected function daily_params()
    {
        $v = new Input_validator($this->input, 'get');
        $date = $v->date('date', FALSE);
        $department = $v->ref('department_id', 'departments', FALSE);
        return array($date !== NULL && $v->ok() ? $date : today_local(), $v->ok() ? $department : NULL);
    }

    protected function monthly_params()
    {
        $v = new Input_validator($this->input, 'get');
        $month = $v->int('month', 1, 12, FALSE);
        $year = $v->int('year', 2000, 2999, FALSE);
        $department = $v->ref('department_id', 'departments', FALSE);
        $ok = $v->ok();
        return array($ok && $month ? $month : (int) date('n'), $ok && $year ? $year : (int) date('Y'), $ok ? $department : NULL);
    }

    /** write rows to an xlsx download and audit the export (G6: every export is logged) */
    protected function send_xlsx($filename, array $rows, array $audit)
    {
        $this->reports->log_export($audit);
        $sheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet->getActiveSheet()->fromArray($rows, NULL, 'A1', TRUE);
        $sheet->getActiveSheet()->getStyle('A1:' . $sheet->getActiveSheet()->getHighestColumn() . '1')->getFont()->setBold(TRUE);
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sheet))->save($tmp);
        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        $this->output->set_content_type('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->set_header('Content-Disposition: attachment; filename="' . $filename . '"')->set_header('Cache-Control: private, no-store')
            ->set_output($bytes);
    }
}
