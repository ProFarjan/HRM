<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Logins of the current branch. Rules live in Users_model (anti-escalation, last Admin, never yourself). */
class Users extends MY_Controller
{
    protected $permission_map = array(
        'index'          => 'users_view',
        'ajax_list'      => 'users_view',
        'add'            => 'users_add',
        'save'           => 'users_add',
        'edit'           => 'users_edit',
        'update'         => 'users_edit',
        'reset_password' => 'users_edit',
        'toggle_status'  => 'users_edit',
        'delete'         => 'users_delete',
    );
    protected $post_only = array('ajax_list', 'save', 'update', 'reset_password', 'toggle_status', 'delete');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Users_model');
        $this->data['active_menu'] = 'users';
    }

    public function index()
    {
        $this->data['page_title'] = t('menu_users');
        $this->render('users/index', array('roles' => $this->Users_model->assignable_roles(), 'page_js' => array('users')));
    }

    public function ajax_list()
    {
        $this->release_session_lock();
        $this->json($this->Users_model->list_page());
    }

    public function add()
    {
        $this->data['page_title'] = t('users_add');
        $this->render('users/form', array(
            'row' => NULL, 'roles' => $this->Users_model->assignable_roles(), 'page_js' => array('users'),
            'branches' => $this->Auth_model->active_branches(),
        ));
    }

    public function save()
    {
        $this->text($this->Users_model->create());
    }

    public function edit($id = NULL)
    {
        $this->belong_to('users', $id);
        $this->data['page_title'] = t('users_edit');
        $this->render('users/form', array(
            'row' => $this->Users_model->get($id), 'roles' => $this->Users_model->assignable_roles(), 'page_js' => array('users'),
            'branches' => $this->Auth_model->active_branches(),
        ));
    }

    public function update($id = NULL)
    {
        $this->belong_to('users', $id);
        $this->text($this->Users_model->update($id));
    }

    public function reset_password($id = NULL)
    {
        $this->belong_to('users', $id);
        $this->text($this->Users_model->reset_password($id));
    }

    public function toggle_status($id = NULL)
    {
        $this->belong_to('users', $id);
        $this->text($this->Users_model->toggle_status($id));
    }

    public function delete($id = NULL)
    {
        $this->belong_to('users', $id);
        $this->text($this->Users_model->delete($id));
    }
}
