<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Reports. Step 4: daily attendance and the monthly attendance grid (attendance_report). Excel export needs export_hr as well
 * and is written to the audit log (G6).
 */
class Reports extends MY_Controller
{
    protected $permission_map = array(
        'daily'        => 'attendance_report',
        'monthly'      => 'attendance_report',
        'daily_xlsx'   => 'export_hr',
        'monthly_xlsx' => 'export_hr',
    );

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

    // ------------------------------------------------------------------------------------------------ internals

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
