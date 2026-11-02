<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** HR settings (hr_settings row 1): tabs General, Attendance, Payroll, Leave, Devices, Notifications. */
class Settings extends MY_Controller
{
    protected $permission_map = array(
        'index' => 'settings_edit',
        'save'  => 'settings_edit',
        'logo'  => '@any',
    );
    protected $post_only = array('save');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Settings_model');
        $this->load->model('Shifts_model');
        $this->data['active_menu'] = 'settings';
    }

    public function index()
    {
        $this->data['page_title'] = t('menu_settings');
        $this->render('settings/index', array(
            'values'       => $this->Settings_model->current(),
            'timezones'    => DateTimeZone::listIdentifiers(),
            'date_formats' => Settings_model::date_formats(),
            'has_logo'     => $this->Settings_model->logo_path() !== NULL,
            'shifts'       => $this->Shifts_model->all_active_list(),
            'page_js'      => array('settings'),
        ));
    }

    public function save()
    {
        $this->text($this->Settings_model->save());
    }

    /** the company logo (any logged-in user; it is shown in the header) */
    public function logo()
    {
        $path = $this->Settings_model->logo_path();
        if ($path === NULL) {
            show_404();
        }
        $this->release_session_lock();
        $type = substr($path, -4) === '.png' ? 'image/png' : 'image/jpeg';
        $this->output->set_content_type($type)
            ->set_header('Cache-Control: private, max-age=3600')
            ->set_header('X-Content-Type-Options: nosniff')
            ->set_output(file_get_contents($path));
    }
}
