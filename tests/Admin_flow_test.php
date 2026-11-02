<?php
/** Step 1 over HTTP: landing page per permissions, branch switching, logo upload, CSRF on list endpoints. */
class Admin_flow_test extends Hrm_test_case
{
    protected $use_transaction = FALSE;
    /** @var Http_server */
    protected static $server;
    protected static $roles = array();
    protected static $users = array();
    protected static $branches = array();

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
        if (self::$branches) {
            $db->where_in('branch_id', self::$branches)->delete('audit_log');
            $db->where_in('id', self::$branches)->delete('branches');
        }
        $logo = (string) $db->get_where('hr_settings', array('id' => 1))->row()->logo;
        if ($logo !== '') {
            @unlink(FCPATH . 'uploads/settings/' . $logo);
        }
        $db->update('hr_settings', array('logo' => '', 'company_name' => ''), array('id' => 1));
        $db->empty_table('ci_sessions');
        $db->empty_table('login_attempts');
    }

    protected function make_user($username, array $permissions, $system = FALSE, $branch = NULL)
    {
        $role_id = $this->insert_role('role_' . $username, $permissions, $system);
        $id = $this->insert_user($username, $role_id, $branch, 'Passw0rd!x');
        self::$roles[] = $role_id;
        self::$users[] = $id;
        return $id;
    }

    protected function client_as($username)
    {
        $http = new Http_client(self::$server->port);
        list($status) = $http->login($username, 'Passw0rd!x');
        $this->assertContains($status, array(302, 303, 307), "login $username");
        return $http;
    }

    protected function location($headers)
    {
        return preg_match('/^Location: (.*)$/mi', $headers, $m) ? trim($m[1]) : '';
    }

    // ---------------------------------------------------------------------------------- landing

    public function test_each_user_lands_on_the_first_page_they_may_open()
    {
        $this->make_user('land_dash', array('dashboard_hr', 'users_view'));
        $this->make_user('land_users', array('users_view'));
        $this->make_user('land_audit', array('audit_view'));
        $this->make_user('land_none', array());

        list(, , $h) = $this->client_as('land_dash')->get('dashboard/home');
        $this->assertStringEndsWith('/dashboard', $this->location($h));
        list(, , $h) = $this->client_as('land_users')->get('dashboard/home');
        $this->assertStringEndsWith('/users', $this->location($h));
        list(, , $h) = $this->client_as('land_audit')->get('');
        $this->assertStringEndsWith('/audit', $this->location($h), 'the site root is dashboard/home');
        list($status, $body) = $this->client_as('land_none')->get('dashboard/home');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('id="welcome-text"', $body);
    }

    // ---------------------------------------------------------------------------------- branch switch

    public function test_only_the_admin_switches_branch_and_lists_follow_the_switch()
    {
        $branch = $this->insert_branch('SWB' . random_int(10, 99));
        self::$branches[] = $branch;
        $this->make_user('in_other_branch', array(), FALSE, $branch);
        $this->make_user('sw_admin', array(), TRUE);
        $everything = array_keys(permission_catalog_keys());
        $this->make_user('sw_almost', $everything);

        $almost = $this->client_as('sw_almost');
        list($status) = $almost->post('branches/switch_branch', array('branch_id' => (string) $branch));
        $this->assertSame(403, $status, 'holding every permission key is not the Admin role');

        $admin = $this->client_as('sw_admin');
        list(, $body) = $admin->post('users/ajax_list', array('draw' => '1', 'search' => array('value' => 'in_other_branch')), array('X-Requested-With: XMLHttpRequest'));
        $this->assertSame(0, json_decode($body, TRUE)['recordsFiltered'], 'not visible from the home branch');

        list($status, , $h) = $admin->post('branches/switch_branch', array('branch_id' => (string) $branch));
        $this->assertContains($status, array(302, 303));
        list(, $body) = $admin->post('users/ajax_list', array('draw' => '1', 'search' => array('value' => 'in_other_branch')), array('X-Requested-With: XMLHttpRequest'));
        $this->assertSame(1, json_decode($body, TRUE)['recordsFiltered'], 'visible after switching');
        list(, $page) = $admin->get('users');
        $this->assertMatchesRegularExpression('/<option value="' . $branch . '" selected>/', $page, 'header shows the working branch');

        // switching to an inactive branch is refused; deactivating the working branch sends the admin home
        $dead = $this->insert_branch('DEAD' . random_int(10, 99));
        self::$branches[] = $dead;
        $this->db->update('branches', array('status' => 'Inactive'), array('id' => $dead));
        list($status) = $admin->post('branches/switch_branch', array('branch_id' => (string) $dead));
        $this->assertSame(400, $status);
        $this->db->update('branches', array('status' => 'Inactive'), array('id' => $branch));
        list(, $body) = $admin->post('users/ajax_list', array('draw' => '1', 'search' => array('value' => 'in_other_branch')), array('X-Requested-With: XMLHttpRequest'));
        $this->assertSame(0, json_decode($body, TRUE)['recordsFiltered'], 'back in the home branch');
    }

    // ---------------------------------------------------------------------------------- logo upload

    protected function png($path, $w = 40, $h = 20)
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 30, 120, 200));
        imagepng($img, $path);
        imagedestroy($img);
        return $path;
    }

    public function test_logo_upload_is_checked_by_content_size_and_served_through_the_controller()
    {
        $this->make_user('logo_admin', array('settings_edit'));
        $this->make_user('logo_viewer', array());
        $http = $this->client_as('logo_admin');
        $dir = HRM_ROOT . '/tests/_output/';
        $this->CI->load->model('Settings_model');
        hr_setting_reset_cache();
        $form = settings_form($this->CI->Settings_model->current());

        file_put_contents($dir . 'fake.png', "<?php echo 'not an image'; ?>");
        list(, $body) = $http->post_multipart('settings/save', $form, array('logo' => $dir . 'fake.png'));
        $this->assertStringContainsString('PNG or JPEG', $body);

        $big = imagecreatetruecolor(1200, 1200);
        for ($i = 0; $i < 200000; $i++) {
            imagesetpixel($big, random_int(0, 1199), random_int(0, 1199), random_int(0, 0xFFFFFF));
        }
        imagepng($big, $dir . 'big.png', 0);
        imagedestroy($big);
        list(, $body) = $http->post_multipart('settings/save', $form, array('logo' => $dir . 'big.png'));
        $this->assertStringContainsString('512 KB', $body);

        list(, $body) = $http->post_multipart('settings/save', $form, array('logo' => $this->png($dir . 'logo.php.png')));
        $this->assertSame('success', $body);
        $stored = (string) $this->db->get_where('hr_settings', array('id' => 1))->row()->logo;
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $stored, 'random name, extension from the contents');
        $this->assertFileExists(FCPATH . 'uploads/settings/' . $stored);

        list($status, $img, $headers) = $this->client_as('logo_viewer')->get('settings/logo');
        $this->assertSame(200, $status, 'any logged-in user sees the logo');
        $this->assertStringContainsString('Content-Type: image/png', $headers);
        $this->assertSame(file_get_contents(FCPATH . 'uploads/settings/' . $stored), $img);
        list($status) = (new Http_client(self::$server->port))->get('settings/logo');
        $this->assertContains($status, array(302, 303, 307), 'not for anonymous visitors');
        list($status) = $http->get('uploads/settings/' . $stored);
        $this->assertSame(404, $status, 'uploads/ is never served directly');

        $form['remove_logo'] = '1';
        list(, $body) = $http->post_multipart('settings/save', $form);
        $this->assertSame('success', $body);
        $this->assertSame('', (string) $this->db->get_where('hr_settings', array('id' => 1))->row()->logo);
        $this->assertFileDoesNotExist(FCPATH . 'uploads/settings/' . $stored, 'old file removed');
        list($status) = $http->get('settings/logo');
        $this->assertSame(404, $status);
        foreach (array('fake.png', 'big.png') as $f) {
            @unlink($dir . $f);
        }
    }

    public function test_list_endpoints_need_post_with_csrf_and_return_escaped_json()
    {
        $this->make_user('lister', array('users_view', 'roles_manage', 'settings_edit', 'audit_view'));
        $http = $this->client_as('lister');
        foreach (array('users', 'roles', 'branches', 'audit') as $module) {
            list($status) = $http->get($module . '/ajax_list');
            $this->assertSame(405, $status, "$module/ajax_list is POST only");
            list($status) = $http->request('POST', $module . '/ajax_list', array('draw' => '1'));
            $this->assertSame(403, $status, "$module/ajax_list needs the CSRF token");
            list($status, $body, $headers) = $http->post($module . '/ajax_list', array('draw' => '5'));
            $this->assertSame(200, $status, $module);
            $this->assertStringContainsString('application/json', $headers);
            $this->assertSame(5, json_decode($body, TRUE)['draw']);
        }
    }

    public function test_by_id_pages_check_the_record_after_the_permission()
    {
        $other = $this->insert_branch('BYID' . random_int(10, 99));
        self::$branches[] = $other;
        $far = $this->make_user('far_user', array(), FALSE, $other);
        $this->make_user('byid_editor', array('users_view', 'users_edit'));
        $http = $this->client_as('byid_editor');
        list($status) = $http->get('users/edit/' . $far);
        $this->assertSame(403, $status, 'a login of another branch');
        list($status) = $http->get('users/edit/999999');
        $this->assertSame(403, $status, 'an id that does not exist looks the same');
        list($status) = $http->post('users/delete/' . $far);
        $this->assertSame(403, $status);
        $this->assertSame(1, $this->db->get_where('users', array('id' => $far))->num_rows());
    }
}
