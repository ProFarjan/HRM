<?php
/**
 * G1 - default-deny access. Closes P6 (missing permission checks), P7 (controller without login) and P18 (base-class
 * helpers routable as URLs).
 *
 *  - reflection over every controller: each public method has a $permission_map entry, every entry points to a real
 *    public method with a catalog key or an allowed token, '@self' only under Ess_Controller;
 *  - the base controllers expose nothing but __construct and _remap;
 *  - HTTP smoke test against a real server on hrm_test: every mapped route as a user without permissions is 403,
 *    helper names are 403, not logged in is a redirect (401 for AJAX), CSRF is enforced, Cron is invisible to the web.
 */
class Guardrail_permission_coverage_test extends Hrm_test_case
{
    protected $use_transaction = FALSE;

    /** @var Http_server */
    protected static $server;
    /** @var array ids to clean up */
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
        $db->db_debug = FALSE;
        if (self::$users) {
            $db->where_in('id', self::$users)->delete('users');
        }
        if (self::$roles) {
            $db->where_in('id', self::$roles)->delete('roles');
        }
        $db->empty_table('ci_sessions');
        $db->empty_table('login_attempts');
    }

    // ---------------------------------------------------------------------------------- helpers

    protected function controller_classes()
    {
        $classes = array();
        foreach (glob(APPPATH . 'controllers/*.php') as $file) {
            require_once $file;
            $classes[] = basename($file, '.php');
        }
        $this->assertNotEmpty($classes);
        return $classes;
    }

    protected function declared_public_methods(ReflectionClass $rc)
    {
        $methods = array();
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() === $rc->getName()) {
                $methods[] = $m->getName();
            }
        }
        return $methods;
    }

    protected function permission_map(ReflectionClass $rc)
    {
        $defaults = $rc->getDefaultProperties();
        return isset($defaults['permission_map']) ? $defaults['permission_map'] : array();
    }

    protected function post_only(ReflectionClass $rc)
    {
        $defaults = $rc->getDefaultProperties();
        return isset($defaults['post_only']) ? $defaults['post_only'] : array();
    }

    /** a user with exactly these permissions, committed; returns the username */
    protected function make_user($username, array $permissions)
    {
        $role_id = $this->insert_role('role_' . $username, $permissions);
        $user_id = $this->insert_user($username, $role_id, NULL, 'Passw0rd!x');
        self::$roles[] = $role_id;
        self::$users[] = $user_id;
        return $username;
    }

    // ---------------------------------------------------------------------------------- reflection

    public function test_every_public_controller_method_is_in_the_permission_map()
    {
        $catalog = permission_catalog_keys();
        $tokens = config_item('permission_tokens');
        foreach ($this->controller_classes() as $class) {
            $rc = new ReflectionClass($class);
            $map = $this->permission_map($rc);
            $this->assertNotEmpty($map, "$class declares a permission_map");

            foreach ($this->declared_public_methods($rc) as $method) {
                if (in_array($method, array('__construct', '_remap'), TRUE)) {
                    continue;
                }
                $this->assertArrayHasKey($method, $map, "$class::$method() is public but not in \$permission_map (P6/P18)");
            }
            foreach ($map as $method => $key) {
                $this->assertSame(strtolower($method), $method, "$class: map keys are lower case");
                $this->assertTrue($rc->hasMethod($method) && $rc->getMethod($method)->isPublic(), "$class::$method is mapped but is not a public method");
                $this->assertTrue(isset($catalog[$key]) || in_array($key, $tokens, TRUE), "$class::$method maps to unknown permission '$key'");
                if ($key === '@self') {
                    $this->assertTrue($rc->isSubclassOf('Ess_Controller'), "$class uses '@self' but is not an Ess_Controller");
                }
            }
            foreach ($this->post_only($rc) as $method) {
                $this->assertArrayHasKey($method, $map, "$class: post_only method $method is not in the map");
            }
        }
    }

    public function test_only_login_free_controllers_skip_my_controller()
    {
        foreach ($this->controller_classes() as $class) {
            $rc = new ReflectionClass($class);
            if (in_array($class, array('Api', 'Iclock', 'Cron', 'Auth'), TRUE)) {
                continue;
            }
            $this->assertTrue($rc->isSubclassOf('MY_Controller'), "$class must extend MY_Controller (P7)");
            if ($class === 'Ess') {
                $this->assertTrue($rc->isSubclassOf('Ess_Controller'));
            }
        }
    }

    public function test_base_controllers_expose_no_public_helpers()
    {
        foreach (array('MY_Controller', 'Ess_Controller', 'Api_Controller') as $class) {
            $rc = new ReflectionClass($class);
            $public = array_diff($this->declared_public_methods($rc), array('__construct', '_remap'));
            $this->assertSame(array(), array_values($public), "$class has public helpers (routable URLs, P18): " . implode(', ', $public));
        }
        $trait = new ReflectionClass('Default_deny');
        $this->assertSame(array(), $this->declared_public_methods($trait));
    }

    public function test_is_routable_decides_by_map_existence_and_visibility()
    {
        $stub = new class extends MY_Controller {
            protected $permission_map = array('listed' => 'shifts_view', 'hidden' => 'shifts_view', 'ghost' => 'shifts_view', 'static_one' => 'shifts_view');
            public function __construct() {}
            public function listed() {}
            public function unlisted() {}
            protected function hidden() {}
            public static function static_one() {}
            public function check($m) { return $this->is_routable($m); }
        };
        $this->assertTrue($stub->check('listed'));
        $this->assertTrue($stub->check('LISTED'), 'method names are case-insensitive');
        $this->assertFalse($stub->check('unlisted'), 'public but not in the map');
        $this->assertFalse($stub->check('hidden'), 'in the map but protected');
        $this->assertFalse($stub->check('ghost'), 'in the map but does not exist');
        $this->assertFalse($stub->check('static_one'));
        $this->assertFalse($stub->check('_remap'));
        $this->assertFalse($stub->check('__construct'));
        $this->assertFalse($stub->check(''));
    }

    // ---------------------------------------------------------------------------------- HTTP smoke test

    protected function web_controllers()
    {
        $out = array();
        foreach ($this->controller_classes() as $class) {
            $rc = new ReflectionClass($class);
            if ($rc->isSubclassOf('MY_Controller')) {
                $out[$class] = $rc;
            }
        }
        return $out;
    }

    public function test_every_mapped_route_is_403_for_a_user_without_permissions()
    {
        $this->make_user('nopriv', array());
        $http = new Http_client(self::$server->port);
        list($status) = $http->login('nopriv', 'Passw0rd!x');
        $this->assertContains($status, array(302, 303, 307), 'login redirects');

        $checked = 0;
        foreach ($this->web_controllers() as $class => $rc) {
            $post_only = $this->post_only($rc);
            foreach ($this->permission_map($rc) as $method => $key) {
                $path = strtolower($class) . '/' . $method;
                $is_post = in_array($method, $post_only, TRUE);
                list($status, $body) = $is_post ? $http->post($path) : $http->get($path);
                if ($key === '@any' || $key === '@self') {
                    $this->assertNotContains($status, array(403, 404, 405, 500), "$path ($key) must be reachable for a logged-in user");
                } else {
                    $this->assertSame(403, $status, "$path requires $key: a user without permissions must get 403, got $status");
                }
                $checked++;
            }
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function test_post_only_routes_refuse_get_with_405()
    {
        $this->make_user('nopriv2', array());
        $http = new Http_client(self::$server->port);
        $http->login('nopriv2', 'Passw0rd!x');
        foreach ($this->web_controllers() as $class => $rc) {
            foreach ($this->post_only($rc) as $method) {
                list($status) = $http->get(strtolower($class) . '/' . $method);
                $this->assertSame(405, $status, "$class::$method accepts only POST");
            }
        }
    }

    public function test_helpers_and_unmapped_names_are_403_not_pages()
    {
        $this->make_user('nopriv3', array());
        $http = new Http_client(self::$server->port);
        $http->login('nopriv3', 'Passw0rd!x');
        foreach (array('load_info', 'load_global', 'deny', 'belong_to', 'release_session_lock', 'render', 'json', 'is_routable',
                       'visible_menu', 'login_required', 'force_logout', 'permission_map', 'nothing_here', 'get_instance') as $name) {
            list($status) = $http->get('dashboard/' . $name);
            $this->assertContains($status, array(403, 404), "dashboard/$name must not be served (got $status)");
        }
        // the admin is not special either: a helper is still not routable
        $http2 = new Http_client(self::$server->port);
        $http2->login('admin', HRM_TEST_ADMIN_PASSWORD);
        foreach (array('load_info', 'deny', 'belong_to', 'render', 'is_routable') as $name) {
            list($status) = $http2->get('dashboard/' . $name);
            $this->assertContains($status, array(403, 404), "admin: dashboard/$name must not be served (got $status)");
        }
    }

    public function test_anonymous_requests_are_sent_to_login_and_ajax_gets_401()
    {
        $http = new Http_client(self::$server->port);
        list($status, , $headers) = $http->get('dashboard');
        $this->assertContains($status, array(302, 303, 307));
        $this->assertMatchesRegularExpression('#Location: .*auth/login#i', $headers);
        list($status, $body) = $http->get('dashboard', array('X-Requested-With: XMLHttpRequest'));
        $this->assertSame(401, $status);
        $this->assertStringContainsString('session', strtolower($body));
    }

    public function test_csrf_is_enforced_on_post()
    {
        $http = new Http_client(self::$server->port);
        $http->get('auth/login');
        list($status) = $http->request('POST', 'auth/login', array('username' => 'admin', 'password' => HRM_TEST_ADMIN_PASSWORD));
        $this->assertSame(403, $status, 'POST without a CSRF token is refused');
    }

    public function test_cron_and_unknown_controllers_are_invisible_to_the_web()
    {
        $http = new Http_client(self::$server->port);
        foreach (array('cron/ping', 'cron/migrate', 'migrate', 'migrate/version/1', 'cron/housekeeping', 'nosuchcontroller', 'auth/nothing_here') as $path) {
            list($status) = $http->get($path);
            $this->assertSame(404, $status, "$path must be 404 over HTTP");
        }
    }

    public function test_admin_can_open_the_shell_and_a_permitted_user_can_too()
    {
        $http = new Http_client(self::$server->port);
        list($status) = $http->login('admin', HRM_TEST_ADMIN_PASSWORD);
        $this->assertContains($status, array(302, 303, 307));
        list($status, $body) = $http->get('dashboard');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('id="dash-branch"', $body);
        $this->assertStringContainsString('sidebar-menu', $body);
        $this->assertStringContainsString('name="csrf-hash"', $body);

        $this->make_user('dash_user', array('dashboard_hr'));
        $http2 = new Http_client(self::$server->port);
        $http2->login('dash_user', 'Passw0rd!x');
        list($status) = $http2->get('dashboard');
        $this->assertSame(200, $status);

        $this->make_user('other_perm_only', array('employees_view'));
        $http3 = new Http_client(self::$server->port);
        $http3->login('other_perm_only', 'Passw0rd!x');
        list($status) = $http3->get('dashboard');
        $this->assertSame(403, $status, 'holding some other permission does not open the dashboard');
    }
}
