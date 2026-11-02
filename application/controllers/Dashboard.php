<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Placeholder dashboard so the shell can be opened. The real HR dashboard (widgets, ESS variant) is build step 10. */
class Dashboard extends MY_Controller
{
    protected $permission_map = array(
        'index' => 'dashboard_hr',
        'ping'  => '@any',
    );
    protected $post_only = array('ping');

    public function index()
    {
        $this->data['page_title'] = t('menu_dashboard');
        $this->data['active_menu'] = 'dashboard';
        $this->render('dashboard/index', array(
            'branch_name' => current_user()['branch_name'],
            'today'       => show_date(today_local()),
        ));
    }

    /** keeps the server-side idle timer alive while the user is active on a long form (see theme/js/app.js) */
    public function ping()
    {
        $this->session->set_userdata('last_activity', time());
        $this->json(array('status' => 'ok'));
    }
}
