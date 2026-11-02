<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Branches (company settings: permission settings_edit). Switching the working branch is for the system Admin only
 * ('@system'); everyone else always works in the branch of their login.
 */
class Branches extends MY_Controller
{
    protected $permission_map = array(
        'index'         => 'settings_edit',
        'ajax_list'     => 'settings_edit',
        'add'           => 'settings_edit',
        'save'          => 'settings_edit',
        'edit'          => 'settings_edit',
        'update'        => 'settings_edit',
        'switch_branch' => '@system',
    );
    protected $post_only = array('ajax_list', 'save', 'update', 'switch_branch');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Branches_model');
        $this->data['active_menu'] = 'branches';
    }

    public function index()
    {
        $this->data['page_title'] = t('menu_branches');
        $this->render('branches/index', array('page_js' => array('branches')));
    }

    public function ajax_list()
    {
        $this->release_session_lock();
        $this->json($this->Branches_model->list_page());
    }

    public function add()
    {
        $this->form(NULL);
    }

    public function save()
    {
        $this->text($this->Branches_model->create());
    }

    public function edit($id = NULL)
    {
        $this->belong_to('branches', $id);
        $this->form($this->Branches_model->get($id));
    }

    public function update($id = NULL)
    {
        $this->belong_to('branches', $id);
        $this->text($this->Branches_model->update($id));
    }

    /** header switcher (form POST): work in another active branch; the home branch clears the switch */
    public function switch_branch()
    {
        $target = $this->Branches_model->switch_target();
        if ($target === NULL) {
            show_error(t('branches_switch_invalid'), 400);
        }
        $user = current_user();
        if ((int) $target['id'] === (int) $user['home_branch_id']) {
            $this->session->unset_userdata('branch_id');
        } else {
            $this->session->set_userdata('branch_id', (int) $target['id']);
        }
        log_message('info', sprintf('branch switch user=%d to=%d', current_user_id(), (int) $target['id']));
        redirect('dashboard/home');
    }

    protected function form($row)
    {
        $this->data['page_title'] = $row ? t('branches_edit') : t('branches_add');
        $this->render('branches/form', array('row' => $row, 'timezones' => DateTimeZone::listIdentifiers(), 'page_js' => array('branches')));
    }
}
