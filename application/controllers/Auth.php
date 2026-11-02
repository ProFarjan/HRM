<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Login, logout, password change (G6). One of the four controllers allowed not to extend MY_Controller (it must work
 * before a login exists); it still has a default-deny map, and every public method is listed in it.
 */
class Auth extends CI_Controller
{
    use Default_deny;

    protected $permission_map = array(
        'login'           => '@public',
        'logout'          => '@login',
        'change_password' => '@login',
    );
    protected $post_only = array('logout');

    public function __construct()
    {
        parent::__construct();
        date_default_timezone_set((string) hr_setting('timezone', 'Asia/Dhaka'));
    }

    public function _remap($method, $params = array())
    {
        $method = strtolower((string) $method);
        if ( ! $this->is_routable($method)) {
            show_error('Not found', 404);
        }
        if (in_array($method, $this->post_only, TRUE) && $this->input->method() !== 'post') {
            header('Allow: POST');
            show_error('Method not allowed.', 405);
        }
        if ($this->permission_map[$method] === '@login' && (int) $this->session->userdata('user_id') < 1) {
            redirect('auth/login');
        }
        return call_user_func_array(array($this, $method), $params);
    }

    public function login()
    {
        if ((int) $this->session->userdata('user_id') > 0) {
            redirect('dashboard');
        }
        $data = array('error' => '', 'notice' => '');
        $reasons = array('idle' => 'logged_out_idle', 'inactive' => 'logged_out_inactive', 'out' => 'logged_out');
        $reason = (string) $this->input->get('r');
        if (isset($reasons[$reason])) {
            $data['notice'] = t($reasons[$reason]);
        }

        if ($this->input->method() === 'post') {
            $this->load->model('Auth_model');
            list($ok, $message, $user) = $this->Auth_model->authenticate();
            if ($ok) {
                $after = (string) $this->session->userdata('after_login');
                $this->session->sess_regenerate(TRUE);            // new session id on login (fixation)
                $this->session->set_userdata(array('user_id' => $user['id'], 'last_activity' => time()));
                $this->session->unset_userdata('after_login');
                $this->Auth_model->touch_login($user['id']);
                if ($user['must_change_password']) {
                    redirect('auth/change_password');
                }
                redirect(preg_match('#^[A-Za-z0-9_/\-]{1,120}$#', $after) ? $after : 'dashboard');
            }
            $data['error'] = $message;
        }
        $data['csrf_name'] = $this->security->get_csrf_token_name();
        $data['csrf_hash'] = $this->security->get_csrf_hash();
        $data['company_name'] = hr_setting('company_name', '') !== '' ? hr_setting('company_name') : t('app_name');
        $this->load->view('auth/login', $data);
    }

    public function logout()
    {
        $this->session->sess_destroy();
        redirect('auth/login?r=out');
    }

    public function change_password()
    {
        $this->load->model('Auth_model');
        $user = $this->Auth_model->session_user((int) $this->session->userdata('user_id'));
        if ( ! $user || $user['status'] !== 'Active') {
            $this->session->sess_destroy();
            redirect('auth/login');
        }
        $data = array('error' => '', 'forced' => (bool) $user['must_change_password'], 'done' => FALSE);
        if ($this->input->method() === 'post') {
            set_current_user($user);                              // audit() needs the actor
            $result = $this->Auth_model->change_password($user['id']);
            if ($result === 'success') {
                $this->session->sess_regenerate(TRUE);
                redirect('dashboard');
            }
            $data['error'] = $result;
        }
        $data['csrf_name'] = $this->security->get_csrf_token_name();
        $data['csrf_hash'] = $this->security->get_csrf_hash();
        $data['company_name'] = t('app_name');
        $this->load->view('auth/change_password', $data);
    }
}
