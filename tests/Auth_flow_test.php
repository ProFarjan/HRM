<?php
/** Login flow over HTTP: session fixation, forced password change, logout, idle logout, deactivation, throttle. */
class Auth_flow_test extends Hrm_test_case
{
    protected $use_transaction = FALSE;
    /** @var Http_server */
    protected static $server;
    protected static $roles = array();
    protected static $users = array();

    public static function setUpBeforeClass(): void
    {
        self::$server = new Http_server();
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
        $db = get_instance()->db;
        if (self::$users) {
            $db->where_in('id', self::$users)->delete('users');
        }
        if (self::$roles) {
            $db->where_in('id', self::$roles)->delete('roles');
        }
        $db->update('hr_settings', array('idle_logout_minutes' => 0), array('id' => 1));
        $db->empty_table('ci_sessions');
        $db->empty_table('login_attempts');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->empty_table('login_attempts');
    }

    protected function make_user($username, array $permissions = array('dashboard_hr'), $extra = array())
    {
        $role_id = $this->insert_role('role_' . $username, $permissions);
        $id = $this->insert_user($username, $role_id, NULL, 'Passw0rd!x', $extra);
        self::$roles[] = $role_id;
        self::$users[] = $id;
        return $id;
    }

    protected function client()
    {
        return new Http_client(self::$server->port);
    }

    protected function assertRedirectsTo($needle, $status, $headers)
    {
        $this->assertContains($status, array(302, 303, 307));
        $this->assertMatchesRegularExpression('#Location: [^\r\n]*' . preg_quote($needle, '#') . '#i', $headers);
    }

    public function test_login_page_renders_with_a_csrf_field()
    {
        list($status, $body) = $this->client()->get('auth/login');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('name="hrm_csrf"', $body);
        $this->assertStringContainsString('name="password"', $body);
    }

    public function test_good_login_reaches_the_dashboard_and_the_session_id_changes()
    {
        $this->make_user('flow1');
        $http = $this->client();
        $http->get('auth/login');
        $before = $http->cookie('hrm_session');
        $this->assertNotEmpty($before);
        list($status, , $headers) = $http->login('flow1', 'Passw0rd!x');
        $this->assertRedirectsTo('dashboard', $status, $headers);
        $after = $http->cookie('hrm_session');
        $this->assertNotSame($before, $after, 'session id regenerated on login (fixation)');
        list($status, $body) = $http->get('dashboard');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('flow1', $body);
    }

    public function test_bad_login_shows_one_generic_error_and_stays_on_the_form()
    {
        $this->make_user('flow2');
        $http = $this->client();
        list($status, $body) = $http->login('flow2', 'wrong');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Invalid username or password.', $body);
        list($status) = $http->get('dashboard');
        $this->assertContains($status, array(302, 303, 307), 'still not logged in');
    }

    public function test_new_users_must_change_their_password_before_anything_else()
    {
        $this->make_user('flow3', array('dashboard_hr'), array('must_change_password' => 1));
        $http = $this->client();
        list($status, , $headers) = $http->login('flow3', 'Passw0rd!x');
        $this->assertRedirectsTo('auth/change_password', $status, $headers);
        list($status, , $headers) = $http->get('dashboard');
        $this->assertRedirectsTo('auth/change_password', $status, $headers);
        list($status, $body) = $http->get('auth/change_password');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('must choose a new password', $body);

        list($status, $body) = $http->post('auth/change_password', array('current_password' => 'Passw0rd!x', 'new_password' => 'Passw0rd!x', 'confirm_password' => 'Passw0rd!x'));
        $this->assertSame(200, $status);
        $this->assertStringContainsString('must be different', $body);

        list($status, , $headers) = $http->post('auth/change_password', array('current_password' => 'Passw0rd!x', 'new_password' => 'Fresh#Pass42', 'confirm_password' => 'Fresh#Pass42'));
        $this->assertRedirectsTo('dashboard', $status, $headers);
        list($status) = $http->get('dashboard');
        $this->assertSame(200, $status);

        $fresh = $this->client();
        list($status, $body) = $fresh->login('flow3', 'Passw0rd!x');
        $this->assertSame(200, $status, 'the old password no longer works');
        list($status, , $headers) = $fresh->login('flow3', 'Fresh#Pass42');
        $this->assertRedirectsTo('dashboard', $status, $headers);
    }

    public function test_logout_is_post_only_and_ends_the_session()
    {
        $this->make_user('flow4');
        $http = $this->client();
        $http->login('flow4', 'Passw0rd!x');
        list($status) = $http->get('auth/logout');
        $this->assertSame(405, $status, 'a link or image tag cannot log you out');
        list($status) = $http->get('dashboard');
        $this->assertSame(200, $status, 'still logged in');
        list($status, , $headers) = $http->post('auth/logout');
        $this->assertRedirectsTo('auth/login', $status, $headers);
        list($status, , $headers) = $http->get('dashboard');
        $this->assertRedirectsTo('auth/login', $status, $headers);
        list($status) = $http->get('dashboard', array('X-Requested-With: XMLHttpRequest'));
        $this->assertSame(401, $status);
    }

    public function test_a_deactivated_user_is_cut_off_on_the_next_request()
    {
        $id = $this->make_user('flow5');
        $http = $this->client();
        $http->login('flow5', 'Passw0rd!x');
        list($status) = $http->get('dashboard');
        $this->assertSame(200, $status);
        $this->db->update('users', array('status' => 'Inactive'), array('id' => $id));
        list($status, , $headers) = $http->get('dashboard');
        $this->assertRedirectsTo('r=inactive', $status, $headers);
        list($status) = $http->get('dashboard');
        $this->assertContains($status, array(302, 303, 307));
    }

    public function test_permissions_are_read_fresh_so_a_removed_grant_applies_immediately()
    {
        $id = $this->make_user('flow6');
        $http = $this->client();
        $http->login('flow6', 'Passw0rd!x');
        list($status) = $http->get('dashboard');
        $this->assertSame(200, $status);
        $role_id = (int) $this->db->get_where('users', array('id' => $id))->row()->role_id;
        $this->db->delete('role_permissions', array('role_id' => $role_id));
        list($status) = $http->get('dashboard');
        $this->assertSame(403, $status);
    }

    public function test_idle_logout_after_the_configured_minutes()
    {
        $this->make_user('flow7');
        $this->db->update('hr_settings', array('idle_logout_minutes' => 1), array('id' => 1));
        try {
            $http = $this->client();
            $http->login('flow7', 'Passw0rd!x');
            list($status) = $http->get('dashboard');
            $this->assertSame(200, $status);

            // pretend the last request was 5 minutes ago
            $sid = $http->cookie('hrm_session');
            $row = $this->db->get_where('ci_sessions', array('id' => $sid))->row_array();
            $this->assertNotEmpty($row, 'session row found');
            $data = preg_replace_callback('/last_activity\|i:(\d+);/', function ($m) {
                return 'last_activity|i:' . ((int) $m[1] - 300) . ';';
            }, $row['data']);
            $this->assertNotSame($row['data'], $data);
            $this->db->update('ci_sessions', array('data' => $data), array('id' => $sid));

            list($status, , $headers) = $http->get('dashboard');
            $this->assertRedirectsTo('r=idle', $status, $headers);
            list($status) = $http->get('dashboard');
            $this->assertContains($status, array(302, 303, 307));
        } finally {
            $this->db->update('hr_settings', array('idle_logout_minutes' => 0), array('id' => 1));
        }
    }

    public function test_the_keepalive_ping_extends_an_idle_session()
    {
        $this->make_user('flow8');
        $this->db->update('hr_settings', array('idle_logout_minutes' => 1), array('id' => 1));
        try {
            $http = $this->client();
            $http->login('flow8', 'Passw0rd!x');
            $http->get('dashboard');
            list($status) = $http->post('dashboard/ping');
            $this->assertSame(200, $status);
            list($status) = $http->get('dashboard');
            $this->assertSame(200, $status);
        } finally {
            $this->db->update('hr_settings', array('idle_logout_minutes' => 0), array('id' => 1));
        }
    }

    public function test_the_throttle_locks_the_pair_over_http()
    {
        $this->make_user('flow9');
        $http = $this->client();
        for ($i = 0; $i < 5; $i++) {
            $http->login('flow9', 'wrong');
        }
        list($status, $body) = $http->login('flow9', 'Passw0rd!x');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Too many failed attempts', $body);
    }

    public function test_a_seeded_admin_can_log_in_with_the_environment_password()
    {
        $http = $this->client();
        list($status, , $headers) = $http->login('admin', HRM_TEST_ADMIN_PASSWORD);
        $this->assertRedirectsTo('dashboard', $status, $headers);
    }
}
