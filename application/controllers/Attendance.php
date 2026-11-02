<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Attendance (P14: every action has its own explicit permission). The list, calendar and by-id actions respect the
 * own-records rule (show_all_attendance; department heads see their department) through the model scopes and belong_to().
 */
class Attendance extends MY_Controller
{
    protected $permission_map = array(
        'index'           => 'attendance_view',
        'ajax_list'       => 'attendance_view',
        'calendar'        => 'attendance_view',
        'calendar_events' => 'attendance_view',
        'mark'            => 'attendance_add',
        'save_mark'       => 'attendance_add',
        'add_punch'       => 'attendance_add',
        'my_day'          => 'attendance_mark_self',
        'save_my_day'     => 'attendance_mark_self',
        'edit'            => 'attendance_edit',
        'update'          => 'attendance_edit',
        'rebuild'         => 'attendance_edit',
        'delete'          => 'attendance_delete',
        'approve'         => 'attendance_approve',
        'reject'          => 'attendance_approve',
        'bulk'            => 'attendance_bulk',
        'save_bulk'       => 'attendance_bulk',
        'punches'         => 'attendance_punches',
        'punches_list'    => 'attendance_punches',
    );
    protected $post_only = array('ajax_list', 'calendar_events', 'save_mark', 'add_punch', 'save_my_day', 'update', 'rebuild', 'delete',
        'approve', 'reject', 'save_bulk', 'punches_list');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Attendance_model');
        $this->data['active_menu'] = 'attendance';
    }

    public function index()
    {
        $this->load->model('Departments_model');
        $this->data['page_title'] = t('menu_attendance');
        $this->render('attendance/index', array(
            'departments' => $this->Departments_model->active_list(), 'from_date' => show_date(date('Y-m-d', strtotime(today_local() . ' -6 days'))),
            'to_date' => show_date(today_local()), 'page_js' => array('attendance'),
        ));
    }

    public function ajax_list()
    {
        $this->release_session_lock();
        $result = $this->Attendance_model->list_page();
        if (isset($result['error'])) {
            $this->text($result['error'], 422);
            return;
        }
        $this->json($result);
    }

    /** month calendar of one employee (own employee when no id is given) */
    public function calendar($employee_id = NULL)
    {
        $employee_id = $employee_id === NULL ? current_employee_id() : $employee_id;
        if ( ! $employee_id) {
            $this->deny(403);
        }
        $this->check_employee_scope($employee_id);
        $this->load->model('Employees_model');
        $this->data['page_title'] = t('att_calendar');
        $this->render('attendance/calendar', array(
            'emp' => $this->Employees_model->get($employee_id), 'page_js' => array('attendance'),
            'page_vendor_css' => array('fullcalendar/fullcalendar.min.css'),
            'page_vendor_js' => array('moment/moment.min.js', 'fullcalendar/fullcalendar.min.js'),
        ));
    }

    public function calendar_events($employee_id = NULL)
    {
        $this->check_employee_scope($employee_id);
        $this->release_session_lock();
        $v = new Input_validator($this->input);
        $from = $v->date('start');
        $to = $v->date('end');
        if ( ! $v->ok() || $from > $to || (strtotime($to) - strtotime($from)) / 86400 > 62) {
            $this->json(array());
            return;
        }
        $this->json($this->Attendance_model->calendar_events($employee_id, $from, $to));
    }

    public function mark()
    {
        $this->load->model('Employees_model');
        $this->data['page_title'] = t('att_mark');
        $this->render('attendance/form', array('row' => NULL, 'employees' => $this->Employees_model->active_list(), 'page_js' => array('attendance')));
    }

    public function save_mark()
    {
        $this->text($this->Attendance_model->mark());
    }

    public function add_punch()
    {
        $this->text($this->Attendance_model->add_punch());
    }

    public function my_day()
    {
        $this->data['page_title'] = t('att_mark_self');
        $this->render('attendance/my_day', array('has_employee' => current_employee_id() !== NULL, 'page_js' => array('attendance')));
    }

    public function save_my_day()
    {
        $this->text($this->Attendance_model->mark_self());
    }

    public function edit($id = NULL)
    {
        $this->belong_to('attendance', $id);
        $this->data['page_title'] = t('att_edit');
        $this->render('attendance/form', array('row' => $this->Attendance_model->get($id), 'employees' => array(), 'page_js' => array('attendance')));
    }

    public function update($id = NULL)
    {
        $this->belong_to('attendance', $id);
        $this->text($this->Attendance_model->update($id));
    }

    public function rebuild($id = NULL)
    {
        $this->belong_to('attendance', $id);
        $this->text($this->Attendance_model->rebuild($id));
    }

    public function delete($id = NULL)
    {
        $this->belong_to('attendance', $id);
        $this->text($this->Attendance_model->delete($id));
    }

    public function approve($id = NULL)
    {
        $this->belong_to('attendance', $id);
        $this->text($this->Attendance_model->decide($id, TRUE));
    }

    public function reject($id = NULL)
    {
        $this->belong_to('attendance', $id);
        $this->text($this->Attendance_model->decide($id, FALSE));
    }

    public function bulk()
    {
        $this->load->model('Departments_model');
        $this->data['page_title'] = t('att_bulk');
        $this->render('attendance/bulk', array('departments' => $this->Departments_model->active_list(), 'page_js' => array('attendance')));
    }

    public function save_bulk()
    {
        $this->text($this->Attendance_model->bulk_mark());
    }

    public function punches()
    {
        $this->load->model('Employees_model');
        $this->data['page_title'] = t('att_punches');
        $this->render('attendance/punches', array(
            'employees' => $this->Employees_model->active_list(), 'from_date' => show_date(today_local()), 'to_date' => show_date(today_local()), 'page_js' => array('attendance'),
        ));
    }

    public function punches_list()
    {
        $this->release_session_lock();
        $result = $this->Attendance_model->punches_page();
        if (isset($result['error'])) {
            $this->text($result['error'], 422);
            return;
        }
        $this->json($result);
    }

    /** the employee is in the branch and (without show_all_attendance) is the user or in a department they head */
    protected function check_employee_scope($employee_id)
    {
        $this->belong_to('employees', $employee_id);
        if (has_permission('show_all_attendance') || (int) $employee_id === (int) current_employee_id()) {
            return;
        }
        if ( ! $this->Attendance_model->in_my_department($employee_id)) {
            $this->deny(403, $this->input->is_ajax_request() ? 'text' : 'page');
        }
    }
}
