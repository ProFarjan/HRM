<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Departments of the current branch: list, add, edit (incl. status), delete while unused. */
class Departments extends MY_Controller
{
    protected $permission_map = array(
        'index'     => 'departments_view',
        'ajax_list' => 'departments_view',
        'add'       => 'departments_add',
        'save'      => 'departments_add',
        'edit'      => 'departments_edit',
        'update'    => 'departments_edit',
        'delete'    => 'departments_delete',
    );
    protected $post_only = array('ajax_list', 'save', 'update', 'delete');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Departments_model', 'units');
        $this->data['active_menu'] = 'departments';
    }

    public function index()
    {
        $this->data['page_title'] = t('menu_departments');
        $this->render('org_units/index', array('unit' => 'department', 'page_js' => array('org_units')));
    }

    public function ajax_list()
    {
        $this->release_session_lock();
        $this->json($this->units->list_page());
    }

    public function add()
    {
        $this->form(NULL);
    }

    public function save()
    {
        $this->text($this->units->create());
    }

    public function edit($id = NULL)
    {
        $this->belong_to('departments', $id);
        $this->form($this->units->get($id));
    }

    public function update($id = NULL)
    {
        $this->belong_to('departments', $id);
        $this->text($this->units->update($id));
    }

    public function delete($id = NULL)
    {
        $this->belong_to('departments', $id);
        $this->text($this->units->delete($id));
    }

    protected function form($row)
    {
        $this->data['page_title'] = $row ? t('departments_edit') : t('departments_add');
        $extra = array();
        if ('department' === 'department') {
            $this->load->model('Employees_model');
            $extra['heads'] = $this->Employees_model->active_list();
        }
        $this->render('org_units/form', array_merge(array('unit' => 'department', 'row' => $row, 'page_js' => array('org_units')), $extra));
    }
}
