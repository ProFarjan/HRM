<?php
/** Users_model: creation rules, anti-escalation, never yourself, last Admin, branch scope, list, audit. */
class Users_test extends Hrm_test_case
{
    /** @var object */
    protected $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Users_model');
        $this->users = $this->CI->Users_model;
    }

    protected function admin()
    {
        return $this->act_as_new_user('boss', array(), TRUE);
    }

    protected function create(array $fields)
    {
        if ( ! isset($fields['role_id'])) {
            $fields['role_id'] = (string) $this->insert_role('plain_role_' . bin2hex(random_bytes(3)));
        }
        $this->post(array_merge(array('username' => 'newbie', 'password' => 'Temp#Pass123', 'branch_id' => (string) current_branch_id(),
            'status' => 'Active', 'must_change_password' => '1'), $fields));
        return $this->users->create();
    }

    protected function id_of($result)
    {
        $this->assertStringStartsWith('success<<<###>>>', $result, $result);
        return (int) substr($result, strlen('success<<<###>>>'));
    }

    // ---------------------------------------------------------------------------------- create

    public function test_admin_creates_a_login()
    {
        $boss = $this->admin();
        $id = $this->id_of($this->create(array('username' => 'Rahim.Uddin')));
        $row = $this->db->get_where('users', array('id' => $id))->row_array();
        $this->assertSame('rahim.uddin', $row['username'], 'stored lower case');
        $this->assertTrue(password_verify('Temp#Pass123', $row['password_hash']));
        $this->assertSame(1, (int) $row['must_change_password']);
        $this->assertSame($boss['branch_id'], (int) $row['branch_id']);
        $this->assertNull($row['employee_id']);
        $audit = $this->audit_rows('users', $id, 'create');
        $this->assertCount(1, $audit);
        $this->assertStringNotContainsString('Temp#Pass123', $audit[0]['new_values'], 'never the password');
        $this->assertStringNotContainsString('password_hash', $audit[0]['new_values']);
    }

    public function test_create_validation()
    {
        $this->admin();
        $this->assertSame(t('users_username_rule'), $this->create(array('username' => 'ab')));
        $this->assertSame(t('users_username_rule'), $this->create(array('username' => 'has space')));
        $this->assertSame(t('users_username_rule'), $this->create(array('username' => 'রহিম')));
        $this->assertStringContainsString('8 to 255', $this->create(array('password' => 'short')));
        $this->assertSame(t('password_same_as_username'), $this->create(array('username' => 'samesame1', 'password' => 'SameSame1')));
        $this->assertStringContainsString('invalid value', $this->create(array('status' => 'Deleted')));
        $this->assertStringContainsString('role', strtolower($this->create(array('role_id' => '999999'))));
        $this->id_of($this->create(array('username' => 'taken')));
        $this->assertSame(t('users_username_taken'), $this->create(array('username' => 'TAKEN')));
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) AS c FROM users WHERE username IN ('ab','samesame1')")->row()->c);
    }

    public function test_admin_may_place_a_login_in_another_active_branch_but_not_an_inactive_one()
    {
        $this->admin();
        $other = $this->insert_branch('UB1');
        $id = $this->id_of($this->create(array('username' => 'elsewhere', 'branch_id' => (string) $other)));
        $this->assertSame($other, (int) $this->db->get_where('users', array('id' => $id))->row()->branch_id);
        $this->db->update('branches', array('status' => 'Inactive'), array('id' => $other));
        $this->assertStringContainsString('not valid', $this->create(array('username' => 'nowhere', 'branch_id' => (string) $other)));
    }

    public function test_others_always_create_in_their_own_branch()
    {
        $me = $this->act_with_permissions('hr1', array('users_view', 'users_add', 'users_edit'));
        $other = $this->insert_branch('UB2');
        $role = $this->insert_role('viewer_only', array('users_view'));
        $id = $this->id_of($this->create(array('username' => 'pinned', 'branch_id' => (string) $other, 'role_id' => (string) $role)));
        $this->assertSame($me['branch_id'], (int) $this->db->get_where('users', array('id' => $id))->row()->branch_id, 'posted branch_id ignored');
    }

    // ---------------------------------------------------------------------------------- escalation

    public function test_only_an_admin_may_assign_the_admin_role()
    {
        $this->act_with_permissions('hr2', array('users_view', 'users_add', 'users_edit'));
        $admin_role = (int) $this->db->query('SELECT id FROM roles WHERE is_system = 1 LIMIT 1')->row()->id;
        $this->assertSame(t('users_admin_only'), $this->create(array('username' => 'sneaky', 'role_id' => (string) $admin_role)));
        $this->assertArrayNotHasKey($admin_role, $this->users->assignable_roles());
    }

    public function test_nobody_assigns_a_role_with_permissions_they_do_not_hold()
    {
        $this->act_with_permissions('hr3', array('users_view', 'users_add', 'users_edit'));
        $bigger = $this->insert_role('payroll_role', array('users_view', 'payroll_pay'));
        $smaller = $this->insert_role('view_role', array('users_view'));
        $this->assertSame(t('users_role_above_you'), $this->create(array('username' => 'climber', 'role_id' => (string) $bigger)));
        $this->id_of($this->create(array('username' => 'fine', 'role_id' => (string) $smaller)));
        $roles = $this->users->assignable_roles();
        $this->assertArrayHasKey($smaller, $roles);
        $this->assertArrayNotHasKey($bigger, $roles);
    }

    public function test_nobody_manages_a_login_above_them()
    {
        $boss = $this->admin();
        $big_role = $this->insert_role('big', array('users_view', 'users_edit', 'users_delete', 'payroll_pay'));
        $target = $this->insert_user('bigshot', $big_role);
        $admin_target = $this->insert_user('otheradmin', (int) $boss['role_id']);
        $this->act_with_permissions('hr4', array('users_view', 'users_edit', 'users_delete'));
        foreach (array($target, $admin_target) as $id) {
            $this->post(array('role_id' => (string) $big_role, 'status' => 'Inactive'));
            $this->assertSame(t('users_target_above_you'), $this->users->update($id));
            $this->post(array('new_password' => 'Hacked#Pass1'));
            $this->assertSame(t('users_target_above_you'), $this->users->reset_password($id));
            $this->assertSame(t('users_target_above_you'), $this->users->toggle_status($id));
            $this->assertSame(t('users_target_above_you'), $this->users->delete($id));
        }
        $this->assertSame('Active', $this->db->get_where('users', array('id' => $target))->row()->status);
    }

    // ---------------------------------------------------------------------------------- yourself, last admin

    public function test_nobody_changes_or_deletes_their_own_login()
    {
        $boss = $this->admin();
        $other_role = $this->insert_role('lesser');
        $this->post(array('role_id' => (string) $other_role, 'status' => 'Active', 'branch_id' => (string) $boss['branch_id']));
        $this->assertSame(t('users_not_yourself'), $this->users->update($boss['id']));
        $this->post(array('role_id' => (string) $boss['role_id'], 'status' => 'Inactive', 'branch_id' => (string) $boss['branch_id']));
        $this->assertSame(t('users_not_yourself'), $this->users->update($boss['id']));
        $this->assertSame(t('users_not_yourself'), $this->users->toggle_status($boss['id']));
        $this->assertSame(t('users_not_yourself'), $this->users->delete($boss['id']));
        $this->post(array('role_id' => (string) $boss['role_id'], 'status' => 'Active', 'branch_id' => (string) $boss['branch_id']));
        $this->assertSame('success', $this->users->update($boss['id']), 'saving your own form unchanged is fine');
    }

    public function test_the_last_active_admin_cannot_be_removed_even_in_a_race()
    {
        // two admins deactivate each other "at the same time": the second one must be refused
        $boss = $this->admin();
        $this->db->update('users', array('status' => 'Inactive'), array('username' => 'admin'));
        $other = $this->insert_user('admin2', (int) $boss['role_id']);
        $this->db->update('users', array('status' => 'Inactive'), array('id' => $boss['id']));   // boss was just deactivated by admin2
        $this->assertSame(t('users_last_admin'), $this->users->toggle_status($other));
        $this->assertSame(t('users_last_admin'), $this->users->delete($other));
        $this->post(array('role_id' => (string) $this->insert_role('demoted'), 'status' => 'Active', 'branch_id' => (string) $boss['branch_id']));
        $this->assertSame(t('users_last_admin'), $this->users->update($other));
        $this->assertSame('Active', $this->db->get_where('users', array('id' => $other))->row()->status);
    }

    // ---------------------------------------------------------------------------------- update, reset, status, delete

    public function test_update_reset_toggle_and_delete_are_audited()
    {
        $this->admin();
        $role_a = $this->insert_role('ra');
        $role_b = $this->insert_role('rb');
        $id = $this->id_of($this->create(array('username' => 'worker', 'role_id' => (string) $role_a)));

        $this->post(array('role_id' => (string) $role_b, 'status' => 'Active', 'branch_id' => (string) current_branch_id()));
        $this->assertSame('success', $this->users->update($id));
        $change = $this->audit_rows('users', $id, 'role_change');
        $this->assertCount(1, $change);
        $this->assertSame(array('role_id' => (string) $role_a), json_decode($change[0]['old_values'], TRUE));
        $this->assertSame(array('role_id' => $role_b), json_decode($change[0]['new_values'], TRUE));

        $this->post(array('new_password' => 'Fresh#Pass99'));
        $this->assertSame('success', $this->users->reset_password($id));
        $row = $this->db->get_where('users', array('id' => $id))->row();
        $this->assertTrue(password_verify('Fresh#Pass99', $row->password_hash));
        $this->assertSame(1, (int) $row->must_change_password);
        $this->assertCount(1, $this->audit_rows('users', $id, 'password_reset'));
        $this->post(array('new_password' => 'worker'));
        $this->assertStringContainsString('8 to 255', $this->users->reset_password($id));

        $this->assertSame('success', $this->users->toggle_status($id));
        $this->assertSame('Inactive', $this->db->get_where('users', array('id' => $id))->row()->status);
        $this->assertSame('success', $this->users->toggle_status($id));
        $this->assertCount(1, $this->audit_rows('users', $id, 'deactivate'));
        $this->assertCount(1, $this->audit_rows('users', $id, 'activate'));

        $this->assertSame('success', $this->users->delete($id));
        $this->assertSame(0, $this->db->get_where('users', array('id' => $id))->num_rows());
        $deleted = $this->audit_rows('users', $id, 'delete');
        $this->assertSame('worker', json_decode($deleted[0]['old_values'], TRUE)['username']);
    }

    public function test_logins_of_another_branch_are_out_of_reach()
    {
        $this->admin();
        $other = $this->insert_branch('UB3');
        $far = $this->insert_user('faraway', $this->insert_role('fr'), $other);
        $this->assertNull($this->users->get($far));
        $this->assertSame(t('not_found'), $this->users->toggle_status($far));
        $this->assertSame(t('not_found'), $this->users->delete($far));
        $this->post(array('new_password' => 'Whatever#123'));
        $this->assertSame(t('not_found'), $this->users->reset_password($far));
    }

    // ---------------------------------------------------------------------------------- list

    public function test_list_is_branch_scoped_filtered_searched_sorted_and_escaped()
    {
        $boss = $this->admin();
        $role = $this->insert_role('R&D <team>');
        foreach (array('alpha', 'bravo', 'charlie') as $name) {
            $this->insert_user($name, $role);
        }
        $this->db->update('users', array('status' => 'Inactive'), array('username' => 'bravo'));
        $this->insert_user('elsewhere1', $role, $this->insert_branch('UB4'));

        $this->post(array('draw' => '3', 'start' => '0', 'length' => '10', 'order' => array(array('column' => '0', 'dir' => 'asc'))));
        $out = $this->users->list_page();
        $names = array_column($out['data'], 'username');
        $this->assertSame(3, $out['draw']);
        $this->assertNotContains('elsewhere1', $names, 'other branch hidden');
        $this->assertSame($out['recordsTotal'], $out['recordsFiltered']);
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
        $row = $out['data'][array_search('alpha', $names, TRUE)];
        $this->assertStringContainsString('R&amp;D &lt;team&gt;', $row['role'], 'escaped for HTML output');

        $this->post(array('draw' => '1', 'status' => 'Inactive'));
        $out = $this->users->list_page();
        $this->assertSame(array('bravo'), array_column($out['data'], 'username'));

        $this->post(array('draw' => '1', 'search' => array('value' => 'char')));
        $this->assertSame(array('charlie'), array_column($this->users->list_page()['data'], 'username'));

        $this->post(array('draw' => '1', 'order' => array(array('column' => '0', 'dir' => 'desc')), 'role_id' => (string) $role));
        $this->assertSame(array('charlie', 'bravo', 'alpha'), array_column($this->users->list_page()['data'], 'username'));
        unset($boss);
    }

    public function test_list_costs_three_queries()
    {
        $this->admin();
        $this->users->assignable_roles();                       // warm the permission and settings caches like a real request
        hr_setting('date_format');
        $this->post(array('draw' => '1'));
        $before = $this->queries_before();
        $this->users->list_page();
        $this->assertLessThanOrEqual(3, $this->queries_before() - $before, 'total + filtered + page');
    }
}
