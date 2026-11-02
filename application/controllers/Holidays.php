<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Holidays of the current branch: list per year, year view, add/edit/delete, copy last year's fixed-date holidays. */
class Holidays extends MY_Controller
{
    protected $permission_map = array(
        'index'     => 'holidays_view',
        'ajax_list' => 'holidays_view',
        'add'       => 'holidays_add',
        'save'      => 'holidays_add',
        'copy_year' => 'holidays_add',
        'edit'      => 'holidays_edit',
        'update'    => 'holidays_edit',
        'delete'    => 'holidays_delete',
    );
    protected $post_only = array('ajax_list', 'save', 'copy_year', 'update', 'delete');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Holidays_model');
        $this->data['active_menu'] = 'holidays';
    }

    public function index($year = NULL)
    {
        $year = (is_string($year) && preg_match('/^\d{4}$/', $year)) ? (int) $year : (int) date('Y');
        $this->data['page_title'] = t('menu_holidays');
        $this->render('holidays/index', array('year' => $year, 'months' => $this->Holidays_model->year_view($year), 'page_js' => array('time')));
    }

    public function ajax_list()
    {
        $this->release_session_lock();
        $this->json($this->Holidays_model->list_page());
    }

    public function add()
    {
        $this->form(NULL);
    }

    public function save()
    {
        $this->text($this->Holidays_model->create());
    }

    public function copy_year()
    {
        $this->text($this->Holidays_model->copy_year());
    }

    public function edit($id = NULL)
    {
        $this->belong_to('holidays', $id);
        $this->form($this->Holidays_model->get($id));
    }

    public function update($id = NULL)
    {
        $this->belong_to('holidays', $id);
        $this->text($this->Holidays_model->update($id));
    }

    public function delete($id = NULL)
    {
        $this->belong_to('holidays', $id);
        $this->text($this->Holidays_model->delete($id));
    }

    protected function form($row)
    {
        $this->data['page_title'] = $row ? t('holidays_edit') : t('holidays_add');
        $this->render('holidays/form', array('row' => $row, 'page_js' => array('time')));
    }
}
