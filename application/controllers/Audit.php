<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Audit log viewer (read only). */
class Audit extends MY_Controller
{
    protected $permission_map = array(
        'index'     => 'audit_view',
        'ajax_list' => 'audit_view',
        'view'      => 'audit_view',
    );
    protected $post_only = array('ajax_list');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Audit_model');
        $this->data['active_menu'] = 'audit';
    }

    public function index()
    {
        $this->data['page_title'] = t('menu_audit');
        $this->render('audit/index', array(
            'modules'   => $this->Audit_model->modules(),
            'users'     => $this->Audit_model->users(),
            'from_date' => show_date(date('Y-m-d', strtotime(today_local() . ' -6 days'))),
            'to_date'   => show_date(today_local()),
            'page_js'   => array('audit'),
        ));
    }

    public function ajax_list()
    {
        $this->release_session_lock();
        $result = $this->Audit_model->list_page();
        if (isset($result['error'])) {
            $this->text($result['error'], 422);
            return;
        }
        $this->json($result);
    }

    /** one entry as JSON (old / new values) */
    public function view($id = NULL)
    {
        $row = $this->Audit_model->get($id);
        if ($row === NULL) {
            $this->deny(403, $this->input->is_ajax_request() ? 'text' : 'page');
        }
        $this->json(array(
            'id' => (int) $row['id'], 'when' => show_date($row['created_at']) . substr($row['created_at'], 10),
            'user' => $row['username'], 'module' => $row['module'], 'action' => $row['action'], 'record_id' => $row['record_id'],
            'ip' => $row['ip'], 'old' => $row['old'], 'new' => $row['new'],
        ));
    }
}
