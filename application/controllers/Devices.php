<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Attendance devices of the working branch: the list with health, register / edit / disable, the unknown ADMS serial numbers
 * waiting for approval, API keys of the Windows Service, the command queue and the call log (CLAUDE.md "Attendance devices").
 */
class Devices extends MY_Controller
{
    protected $permission_map = array(
        'index'         => 'devices_view',
        'ajax_list'     => 'devices_view',
        'logs'          => 'devices_view',
        'logs_list'     => 'devices_view',
        'add'           => 'devices_manage',
        'save'          => 'devices_manage',
        'edit'          => 'devices_manage',
        'update'        => 'devices_manage',
        'delete'        => 'devices_manage',
        'allow_ip'      => 'devices_manage',
        'approve'       => 'devices_manage',
        'save_approve'  => 'devices_manage',
        'dismiss'       => 'devices_manage',
        'keys'          => 'devices_keys',
        'keys_list'     => 'devices_keys',
        'create_key'    => 'devices_keys',
        'revoke_key'    => 'devices_keys',
        'commands'      => 'devices_commands',
        'commands_list' => 'devices_commands',
        'queue'         => 'devices_commands',
    );
    protected $post_only = array('ajax_list', 'logs_list', 'save', 'update', 'delete', 'allow_ip', 'save_approve', 'dismiss', 'keys_list', 'create_key', 'revoke_key', 'commands_list', 'queue');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Devices_model');
        $this->data['active_menu'] = 'devices';
    }

    public function index()
    {
        $this->data['page_title'] = t('menu_devices');
        $this->render('devices/index', array(
            'pending' => has_permission('devices_manage') ? $this->Devices_model->pending() : array(),
            'page_js' => array('devices'),
        ));
    }

    public function ajax_list()
    {
        $this->release_session_lock();
        $this->json($this->Devices_model->list_page());
    }

    public function add()
    {
        $this->form(NULL);
    }

    public function save()
    {
        $this->text($this->Devices_model->create());
    }

    public function edit($id = NULL)
    {
        $this->belong_to('devices', $id);
        $this->form($this->Devices_model->get($id));
    }

    public function update($id = NULL)
    {
        $this->belong_to('devices', $id);
        $this->text($this->Devices_model->update($id));
    }

    public function delete($id = NULL)
    {
        $this->belong_to('devices', $id);
        $this->text($this->Devices_model->delete($id));
    }

    public function allow_ip($id = NULL)
    {
        $this->belong_to('devices', $id);
        $this->text($this->Devices_model->allow_blocked_ip($id));
    }

    // ------------------------------------------------------------------------------------------------ pending ADMS devices

    public function approve($serial = NULL)
    {
        $pending = NULL;
        foreach ($this->Devices_model->pending() as $p) {
            if ($p['serial_number'] === $serial) {
                $pending = $p;
            }
        }
        if ($pending === NULL) {
            redirect('devices');
        }
        $this->data['page_title'] = t('dev_approve');
        $this->render('devices/approve', array('pending' => $pending, 'branches' => $this->Devices_model->approvable_branches(), 'page_js' => array('devices')));
    }

    public function save_approve()
    {
        $this->text($this->Devices_model->approve_pending());
    }

    public function dismiss()
    {
        $this->text($this->Devices_model->dismiss_pending());
    }

    // ------------------------------------------------------------------------------------------------ keys

    public function keys()
    {
        $this->load->model('Device_keys_model');
        $this->data['active_menu'] = 'devices/keys';
        $this->data['page_title'] = t('dev_keys');
        $this->render('devices/keys', array('devices' => $this->Devices_model->options('windows_service'), 'page_js' => array('devices')));
    }

    public function keys_list()
    {
        $this->load->model('Device_keys_model');
        $this->release_session_lock();
        $this->json($this->Device_keys_model->list_page());
    }

    public function create_key()
    {
        $this->load->model('Device_keys_model');
        $this->text($this->Device_keys_model->create());
    }

    public function revoke_key($id = NULL)
    {
        $this->belong_to('device_keys', $id);
        $this->load->model('Device_keys_model');
        $this->text($this->Device_keys_model->revoke($id));
    }

    // ------------------------------------------------------------------------------------------------ commands + logs

    public function commands()
    {
        $this->load->model('Device_commands_model');
        $this->load->model('Employees_model');
        $this->data['active_menu'] = 'devices/commands';
        $this->data['page_title'] = t('dev_commands');
        $this->render('devices/commands', array(
            'devices' => $this->Devices_model->options(), 'types' => Device_commands_model::type_labels(),
            'employees' => $this->Employees_model->active_list(), 'page_js' => array('devices'),
        ));
    }

    public function commands_list()
    {
        $this->load->model('Device_commands_model');
        $this->release_session_lock();
        $this->json($this->Device_commands_model->commands_page());
    }

    public function queue()
    {
        $this->load->model('Device_commands_model');
        $this->text($this->Device_commands_model->queue());
    }

    public function logs()
    {
        $this->data['active_menu'] = 'devices/logs';
        $this->data['page_title'] = t('dev_logs');
        $this->render('devices/logs', array('devices' => $this->Devices_model->options(), 'page_js' => array('devices')));
    }

    public function logs_list()
    {
        $this->load->model('Device_commands_model');
        $this->release_session_lock();
        $this->json($this->Device_commands_model->logs_page());
    }

    protected function form($row)
    {
        $this->data['page_title'] = $row ? t('dev_edit') : t('dev_add');
        $this->render('devices/form', array('row' => $row, 'page_js' => array('devices')));
    }
}
