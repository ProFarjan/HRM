<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once __DIR__ . '/Default_deny.php';

/**
 * Session-less base for the device endpoints (Api = ZKTeco Windows Service JSON, Iclock = ADMS push).
 *
 * No session, no CSRF (config: csrf_exclude_uris api/.*, iclock/.*) - the device authenticates with its own key or
 * registered serial number (Api and Iclock override authenticate_device()). Until then the default refuses everything, so a
 * device controller added early can never be reached anonymously. The branch of a punch comes from the authenticated
 * key / device only, never from the request body (P3).
 */
abstract class Api_Controller extends CI_Controller
{
    use Default_deny;

    /** method => '@device' (authenticated by key / serial number) */
    protected $permission_map = array();

    public function __construct()
    {
        parent::__construct();
    }

    public function _remap($method, $params = array())
    {
        $method = strtolower((string) $method);
        if ( ! $this->is_routable($method)) {
            $this->json(array('status' => 'error', 'message' => 'Not found'), 404);
            return;
        }
        if ($this->permission_map[$method] === '@device') {
            $this->authenticate_device();
        }
        return call_user_func_array(array($this, $method), $params);
    }

    /** Default: refuse. Api verifies the hashed device key, Iclock the registered serial number; both call set_current_branch_id(). */
    protected function authenticate_device()
    {
        $this->json(array('status' => 'error', 'message' => 'Device authentication is not configured.'), 401);
        $this->output->_display();
        exit;
    }

    /** plain-text answer (ADMS) */
    protected function plain($text, $status = 200)
    {
        $this->output->set_status_header($status)
            ->set_content_type('text/plain', 'UTF-8')
            ->set_output((string) $text);
    }

    /** the request body, at most $max_bytes (NULL when it is bigger: the caller answers 413) */
    protected function body($max_bytes)
    {
        $length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($length > $max_bytes) {
            return NULL;
        }
        $body = file_get_contents('php://input', FALSE, NULL, 0, $max_bytes + 1);
        return ($body === FALSE || strlen($body) > $max_bytes) ? NULL : $body;
    }

    protected function json($payload, $status = 200)
    {
        $this->output->set_status_header($status)
            ->set_content_type('application/json', 'UTF-8')
            ->set_output(json_encode($payload));
    }
}
