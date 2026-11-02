<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__ . '/Default_deny.php';

/**
 * Base of every web controller (G1: default-deny access, enforced in one place).
 *
 * Rules:
 *  - ALL helpers on this class are protected. CI3 turns any public method into a URL (P18).
 *  - A controller declares `$permission_map` (method => permission key | '@any' | '@self') and `$post_only` at the top.
 *    A public method without an entry answers 403 and fails tests/static_checks.php.
 *  - A by-id endpoint calls belong_to() AFTER the permission check, so a user without permission cannot learn
 *    whether an id exists.
 *  - Only Api, Iclock, Cron and Auth may skip this class (static check).
 */
abstract class MY_Controller extends CI_Controller
{
    use Default_deny;

    /** method => permission key | '@any' | '@system' (system Admin role only) | '@self' (Ess_Controller only) */
    protected $permission_map = array();

    /** methods that change data: POST only (405 otherwise) */
    protected $post_only = array();

    /** '@self' entries are honoured only where this is TRUE (Ess_Controller) */
    protected $allow_self = FALSE;

    /** variables handed to every layout view; filled by load_info() */
    protected $data = array();

    public function __construct()
    {
        parent::__construct();
        $this->load_global();
    }

    public function _remap($method, $params = array())
    {
        $mode = $this->input->is_ajax_request() ? 'text' : 'page';
        $method = strtolower((string) $method);

        if ( ! $this->is_routable($method)) {
            $this->deny(403, $mode);                              // not in the map = no access
        }
        if (in_array($method, $this->post_only, TRUE) && $this->input->method() !== 'post') {
            $this->deny(405, $mode);
        }
        $key = $this->permission_map[$method];
        if ($key === '@self') {
            if ( ! $this->allow_self) {
                $this->deny(403, $mode);
            }
        } elseif ($key === '@system') {
            $user = current_user();
            if (empty($user['is_system'])) {                      // the system Admin role only (e.g. switching branch)
                $this->deny(403, $mode);
            }
        } elseif ($key !== '@any' && ! has_permission($key)) {
            $this->deny(403, $mode);                              // AJAX gets a plain text message
        }
        return call_user_func_array(array($this, $method), $params);
    }

    // ------------------------------------------------------------------------------------------------ access

    /** log (user, route) and stop. $mode 'text' = plain text for AJAX, 'page' = the HTML error page */
    protected function deny($code, $mode = 'page')
    {
        $messages = array(
            401 => 'Your session has expired. Please log in again.',
            403 => 'You do not have permission to do this.',
            405 => 'Method not allowed.',
        );
        $message = isset($messages[$code]) ? $messages[$code] : 'Access denied.';
        log_message('error', sprintf('DENY %d user=%s route=%s method=%s', $code, (string) current_user_id(), uri_string(), $this->input->method()));
        if ($code === 405) {
            header('Allow: POST');
        }
        if ($mode === 'text') {
            set_status_header($code);
            header('Content-Type: text/plain; charset=UTF-8');
            echo $message;
            exit;
        }
        show_error($message, $code, $code === 405 ? 'Method not allowed' : 'Access denied');
    }

    /** an action that needs a second permission besides its map entry (e.g. salary changes also need employees_edit) */
    protected function require_permission($key)
    {
        if ( ! has_permission($key)) {
            $this->deny(403, $this->input->is_ajax_request() ? 'text' : 'page');
        }
        return TRUE;
    }

    /** The record exists in the current branch and inside the user's own-records scope; 403 otherwise. */
    protected function belong_to($table, $id)
    {
        $this->load->model('Access_model');
        if ( ! $this->Access_model->record_in_scope($table, $id)) {
            $this->deny(403, $this->input->is_ajax_request() ? 'text' : 'page');
        }
        return TRUE;
    }

    /** Read-only AJAX endpoints that can be slow call this after the permission check, so parallel widgets do not queue. */
    protected function release_session_lock()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    // ------------------------------------------------------------------------------------------------ request setup

    /** login check, idle logout, branch/user active check, branch timezone, then load_info() */
    protected function load_global()
    {
        $user_id = (int) $this->session->userdata('user_id');
        if ($user_id < 1) {
            $this->login_required();
        }

        $this->load->model('Auth_model');
        $user = $this->Auth_model->session_user($user_id);
        if ( ! $user || $user['status'] !== 'Active' || $user['branch_status'] !== 'Active') {
            $this->force_logout('inactive');
        }

        $idle_minutes = (int) hr_setting('idle_logout_minutes', 0);
        $last = (int) $this->session->userdata('last_activity');
        if ($idle_minutes > 0 && $last > 0 && (time() - $last) > $idle_minutes * 60) {
            $this->force_logout('idle');
        }
        if (time() - $last > 30) {                                // not on every request: avoids a session write per call
            $this->session->set_userdata('last_activity', time());
        }

        // The system Admin may work in another branch (header switcher); everyone else is fixed to their own branch.
        $user['home_branch_id'] = $user['branch_id'];
        $switched = (int) $this->session->userdata('branch_id');
        if ($user['is_system'] && $switched > 0 && $switched !== $user['branch_id']) {
            $branch = $this->Auth_model->active_branch($switched);
            if ($branch) {
                $user['branch_id'] = (int) $branch['id'];
                $user['branch_name'] = $branch['name'];
                $user['branch_timezone'] = $branch['timezone'];
            } else {
                $this->session->unset_userdata('branch_id');      // switched-to branch was deactivated: back home
            }
        }
        set_current_user($user);

        $timezone = $user['branch_timezone'] ? $user['branch_timezone'] : hr_setting('timezone', 'Asia/Dhaka');
        if ( ! @date_default_timezone_set($timezone)) {
            date_default_timezone_set('Asia/Dhaka');
        }

        if ((int) $user['must_change_password'] === 1) {
            if ($this->input->is_ajax_request()) {
                $this->deny(403, 'text');
            }
            redirect('auth/change_password');
        }
        $this->load_info();
    }

    /** common view variables */
    protected function load_info()
    {
        $user = current_user();
        $this->data['company_name'] = hr_setting('company_name', '') !== '' ? hr_setting('company_name') : t('app_name');
        $this->data['user'] = $user;
        $this->data['menu'] = $this->visible_menu();
        $this->data['idle_minutes'] = (int) hr_setting('idle_logout_minutes', 0);
        $this->data['csrf_name'] = $this->security->get_csrf_token_name();
        $this->data['csrf_hash'] = $this->security->get_csrf_hash();
        $this->data['page_title'] = t('app_name');
        $this->data['active_menu'] = '';
        $this->data['switch_branches'] = array();
        if ( ! empty($user['is_system'])) {
            $this->data['switch_branches'] = $this->Auth_model->active_branches();
        }
        $this->data['has_logo'] = (string) hr_setting('logo', '') !== '';
    }

    /** config/menu.php filtered by what this user may open */
    protected function visible_menu()
    {
        $visible = array();
        foreach ((array) config_item('menu') as $item) {
            if (isset($item['children'])) {
                $children = array();
                foreach ($item['children'] as $child) {
                    if ($child['permission'] === '@any' || has_permission($child['permission'])) {
                        $children[] = $child;
                    }
                }
                if ($children) {
                    $item['children'] = $children;
                    $visible[] = $item;
                }
            } elseif ($item['permission'] === '@any' || has_permission($item['permission'])) {
                $visible[] = $item;
            }
        }
        return $visible;
    }

    /** render a page inside the AdminLTE layout */
    protected function render($view, $data = array())
    {
        $data = array_merge($this->data, $data);
        $this->load->view('layout/head', $data);
        $this->load->view('layout/header', $data);
        $this->load->view('layout/sidebar', $data);
        $this->load->view($view, $data);
        $this->load->view('layout/footer', $data);
        $this->load->view('layout/scripts', $data);
    }

    /** plain-text response: a model result ("success", "success<<<###>>>id" or an error) for the page JS / toastr */
    protected function text($message, $status = 200)
    {
        $this->output->set_status_header($status)
            ->set_content_type('text/plain', 'UTF-8')
            ->set_output((string) $message);
    }

    /** JSON response for AJAX endpoints */
    protected function json($payload, $status = 200)
    {
        $this->output->set_status_header($status)
            ->set_content_type('application/json', 'UTF-8')
            ->set_output(json_encode($payload));
    }

    // ------------------------------------------------------------------------------------------------ login / logout

    protected function login_required()
    {
        if ($this->input->is_ajax_request()) {
            $this->deny(401, 'text');
        }
        if ($this->input->method() === 'get' && preg_match('#^[A-Za-z0-9_/\-]{1,120}$#', uri_string())) {
            $this->session->set_userdata('after_login', uri_string());
        }
        redirect('auth/login');
    }

    protected function force_logout($reason)
    {
        $this->session->sess_destroy();
        if ($this->input->is_ajax_request()) {
            $this->deny(401, 'text');
        }
        redirect('auth/login?r=' . rawurlencode($reason));
    }
}

// Base classes that extend MY_Controller / are used by the controllers.
require_once __DIR__ . '/Ess_Controller.php';
require_once __DIR__ . '/Api_Controller.php';
