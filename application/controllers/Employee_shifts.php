<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Shift assignments. Seeing them needs shifts_view; assigning people to shifts is scheduling and needs rosters_manage
 * (the catalog has no employee_shifts_* keys - CLAUDE.md "Time (build step 3)").
 */
class Employee_shifts extends MY_Controller
{
    protected $permission_map = array(
        'index'     => 'shifts_view',
        'ajax_list' => 'shifts_view',
        'employee'  => 'shifts_view',
        'assign'    => 'rosters_manage',
        'bulk'      => 'rosters_manage',
        'remove'    => 'rosters_manage',
    );
    protected $post_only = array('ajax_list', 'assign', 'bulk', 'remove');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Employee_shifts_model');
        $this->load->model('Shifts_model');
        $this->data['active_menu'] = 'employee_shifts';
    }

    public function index()
    {
        $this->load->model('Departments_model');
        $this->data['page_title'] = t('menu_employee_shifts');
        $this->render('employee_shifts/index', array(
            'departments' => $this->Departments_model->active_list(), 'shifts' => $this->Shifts_model->active_list(), 'page_js' => array('time'),
        ));
    }

    public function ajax_list()
    {
        $this->release_session_lock();
        $this->json($this->Employee_shifts_model->list_page());
    }

    /** one employee: assignment history + assign form */
    public function employee($id = NULL)
    {
        $this->belong_to('employees', $id);
        $this->load->model('Employees_model');
        $this->data['page_title'] = t('shift_assignments');
        $this->render('employee_shifts/employee', array(
            'emp' => $this->Employees_model->get($id), 'history' => $this->Employee_shifts_model->history($id),
            'shifts' => $this->Shifts_model->active_list(), 'page_js' => array('time'),
        ));
    }

    public function assign($id = NULL)
    {
        $this->belong_to('employees', $id);
        $this->text($this->Employee_shifts_model->assign($id));
    }

    public function bulk()
    {
        $this->text($this->Employee_shifts_model->bulk_assign());
    }

    public function remove($id = NULL, $assignment_id = NULL)
    {
        $this->belong_to('employees', $id);
        $this->text($this->Employee_shifts_model->remove_latest($id, $assignment_id));
    }
}
