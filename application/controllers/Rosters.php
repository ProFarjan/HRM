<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Day-level roster grid (week / month), copy week, xlsx/csv import. */
class Rosters extends MY_Controller
{
    protected $permission_map = array(
        'index'     => 'rosters_manage',
        'grid'      => 'rosters_manage',
        'save'      => 'rosters_manage',
        'copy_week' => 'rosters_manage',
        'import'    => 'rosters_manage',
    );
    protected $post_only = array('grid', 'save', 'copy_week', 'import');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Rosters_model');
        $this->data['active_menu'] = 'rosters';
    }

    public function index()
    {
        $this->load->model('Departments_model');
        $this->load->model('Shifts_model');
        $monday = date('Y-m-d', strtotime('monday this week', strtotime(today_local())));
        $this->data['page_title'] = t('menu_rosters');
        $this->render('rosters/index', array(
            'departments' => $this->Departments_model->active_list(), 'shifts' => $this->Shifts_model->active_list(),
            'week_start' => show_date($monday), 'page_js' => array('rosters'),
        ));
    }

    /** JSON for the grid: employees, days, cells (raw text: the page inserts it with .text()) */
    public function grid()
    {
        $this->release_session_lock();
        $v = new Input_validator($this->input);
        $from = $v->date('from_date');
        $view = $v->enum('view', array('week', 'month'));
        $department = $v->ref('department_id', 'departments', FALSE);
        if ( ! $v->ok()) {
            $this->text($v->message(), 422);
            return;
        }
        if ($view === 'month') {
            $range = month_range((int) substr($from, 5, 2), (int) substr($from, 0, 4));
            list($from, $to) = array($range['from'], $range['to']);
        } else {
            $to = date('Y-m-d', strtotime($from . ' +6 days'));
        }
        $grid = $this->Rosters_model->grid($department, $from, $to);
        $days = array();
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $days[] = array('date' => $d, 'label' => show_date($d), 'dow' => date('D', strtotime($d)));
        }
        $rows = array();
        foreach ($grid['employees'] as $e) {
            $cells = array();
            foreach ($days as $day) {
                $p = $grid['plan'][(int) $e['id']][$day['date']];
                $value = '';
                if ($p['roster']) {
                    $value = $p['day_type'] === Shift_calculator::DAY_WEEKLY_OFF ? 'OFF' : ($p['shift_source'] === 'roster' ? (string) $p['shift']['id'] : '');
                }
                $cells[$day['date']] = array(
                    'value'   => $value,
                    'type'    => $p['day_type'],
                    'applies' => $p['day_type'] === Shift_calculator::DAY_HOLIDAY ? (string) $p['holiday_name']
                        : ($p['day_type'] === Shift_calculator::DAY_WEEKLY_OFF ? t('roster_off') : ($p['shift'] ? $p['shift']['shift_code'] : '-')),
                    'employed' => $p['employed'],
                );
            }
            $rows[] = array('id' => (int) $e['id'], 'code' => $e['employee_code'], 'name' => trim($e['first_name'] . ' ' . $e['last_name']), 'cells' => $cells);
        }
        $this->json(array('from' => $from, 'to' => $to, 'days' => $days, 'rows' => $rows));
    }

    public function save()
    {
        $this->text($this->Rosters_model->save());
    }

    public function copy_week()
    {
        $this->text($this->Rosters_model->copy_week());
    }

    public function import()
    {
        $this->text($this->Rosters_model->import());
    }
}
