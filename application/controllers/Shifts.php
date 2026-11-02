<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Shifts of the current branch. */
class Shifts extends MY_Controller
{
    protected $permission_map = array(
        'index'     => 'shifts_view',
        'ajax_list' => 'shifts_view',
        'add'       => 'shifts_add',
        'save'      => 'shifts_add',
        'edit'      => 'shifts_edit',
        'update'    => 'shifts_edit',
        'delete'    => 'shifts_delete',
    );
    protected $post_only = array('ajax_list', 'save', 'update', 'delete');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Shifts_model');
        $this->data['active_menu'] = 'shifts';
    }

    public function index()
    {
        $this->data['page_title'] = t('menu_shifts');
        $this->render('shifts/index', array('page_js' => array('time')));
    }

    public function ajax_list()
    {
        $this->release_session_lock();
        $this->json($this->Shifts_model->list_page());
    }

    public function add()
    {
        $this->form(NULL);
    }

    public function save()
    {
        $this->text($this->Shifts_model->create());
    }

    public function edit($id = NULL)
    {
        $this->belong_to('shifts', $id);
        $this->form($this->Shifts_model->get($id));
    }

    public function update($id = NULL)
    {
        $this->belong_to('shifts', $id);
        $this->text($this->Shifts_model->update($id));
    }

    public function delete($id = NULL)
    {
        $this->belong_to('shifts', $id);
        $this->text($this->Shifts_model->delete($id));
    }

    protected function form($row)
    {
        $this->data['page_title'] = $row ? t('shifts_edit') : t('shifts_add');
        $this->render('shifts/form', array('row' => $row, 'page_js' => array('time')));
    }
}
