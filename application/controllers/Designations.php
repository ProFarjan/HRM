<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Designations of the current branch: list, add, edit (incl. status), delete while unused. */
class Designations extends MY_Controller
{
    protected $permission_map = array(
        'index'     => 'designations_view',
        'ajax_list' => 'designations_view',
        'add'       => 'designations_add',
        'save'      => 'designations_add',
        'edit'      => 'designations_edit',
        'update'    => 'designations_edit',
        'delete'    => 'designations_delete',
    );
    protected $post_only = array('ajax_list', 'save', 'update', 'delete');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Designations_model', 'units');
        $this->data['active_menu'] = 'designations';
    }

    public function index()
    {
        $this->data['page_title'] = t('menu_designations');
        $this->render('org_units/index', array('unit' => 'designation', 'page_js' => array('org_units')));
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
        $this->belong_to('designations', $id);
        $this->form($this->units->get($id));
    }

    public function update($id = NULL)
    {
        $this->belong_to('designations', $id);
        $this->text($this->units->update($id));
    }

    public function delete($id = NULL)
    {
        $this->belong_to('designations', $id);
        $this->text($this->units->delete($id));
    }

    protected function form($row)
    {
        $this->data['page_title'] = $row ? t('designations_edit') : t('designations_add');
        $extra = array();
        if ('designation' === 'department') {
            $this->load->model('Employees_model');
            $extra['heads'] = $this->Employees_model->active_list();
        }
        $this->render('org_units/form', array_merge(array('unit' => 'designation', 'row' => $row, 'page_js' => array('org_units')), $extra));
    }
}
