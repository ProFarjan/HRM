<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Self-service base (the `Ess` controller only). The ONLY place where '@self' in a $permission_map is honoured:
 * the login must be linked to an employee, and the models behind '@self' endpoints read and write rows of
 * current_employee_id() and nothing else.
 */
abstract class Ess_Controller extends MY_Controller
{
    protected $allow_self = TRUE;

    public function __construct()
    {
        parent::__construct();
        if ( ! current_employee_id()) {
            $this->deny(403, $this->input->is_ajax_request() ? 'text' : 'page');
        }
    }
}
