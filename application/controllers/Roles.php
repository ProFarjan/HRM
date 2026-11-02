<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Roles and their permission keys (role_permissions). */
class Roles extends MY_Controller
{
    protected $permission_map = array(
        'index'     => 'roles_manage',
        'ajax_list' => 'roles_manage',
        'add'       => 'roles_manage',
        'save'      => 'roles_manage',
        'edit'      => 'roles_manage',
        'update'    => 'roles_manage',
        'delete'    => 'roles_manage',
    );
    protected $post_only = array('ajax_list', 'save', 'update', 'delete');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Roles_model');
        $this->data['active_menu'] = 'roles';
    }

    public function index()
    {
        $this->data['page_title'] = t('menu_roles');
        $this->render('roles/index', array('page_js' => array('roles')));
    }

    public function ajax_list()
    {
        $this->release_session_lock();
        $this->json($this->Roles_model->list_page());
    }

    public function add()
    {
        $this->form(NULL);
    }

    public function save()
    {
        $this->text($this->Roles_model->create());
    }

    public function edit($id = NULL)
    {
        $this->belong_to('roles', $id);
        $this->form($this->Roles_model->get($id));
    }

    public function update($id = NULL)
    {
        $this->belong_to('roles', $id);
        $this->text($this->Roles_model->update($id));
    }

    public function delete($id = NULL)
    {
        $this->belong_to('roles', $id);
        $this->text($this->Roles_model->delete($id));
    }

    protected function form($row)
    {
        $this->data['page_title'] = $row ? t('roles_edit') : t('roles_add');
        $this->render('roles/form', array(
            'row' => $row, 'catalog' => config_item('permission_catalog'), 'grantable' => array_flip($this->Roles_model->grantable_keys()),
            'page_js' => array('roles'),
        ));
    }
}
