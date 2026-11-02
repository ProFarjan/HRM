<?php
/** Roles_model: permission sets, system role locked, delete rules, no granting above yourself, audit with added/removed. */
class Roles_test extends Hrm_test_case
{
    /** @var object */
    protected $roles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Roles_model');
        $this->roles = $this->CI->Roles_model;
    }

    protected function keys($role_id)
    {
        return array_column($this->db->order_by('permission_key')->get_where('role_permissions', array('role_id' => $role_id))->result_array(), 'permission_key');
    }

    protected function create($name, array $keys)
    {
        $this->post(array('role_name' => $name, 'permissions' => $keys));
        return $this->roles->create();
    }

    protected function id_of($result)
    {
        $this->assertStringStartsWith('success<<<###>>>', $result, $result);
        return (int) substr($result, strlen('success<<<###>>>'));
    }

    public function test_admin_creates_and_updates_a_role_and_the_audit_shows_what_changed()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $id = $this->id_of($this->create('Attendance clerk', array('attendance_view', 'attendance_add', 'attendance_view')));
        $this->assertSame(array('attendance_add', 'attendance_view'), $this->keys($id), 'de-duplicated');

        $this->post(array('role_name' => 'Attendance officer', 'permissions' => array('attendance_view', 'attendance_approve')));
        $this->assertSame('success', $this->roles->update($id));
        $this->assertSame(array('attendance_approve', 'attendance_view'), $this->keys($id));
        $audit = $this->audit_rows('roles', $id, 'update');
        $this->assertCount(1, $audit);
        $new = json_decode($audit[0]['new_values'], TRUE);
        $this->assertSame(array('attendance_approve'), $new['added']);
        $this->assertSame(array('attendance_add'), $new['removed']);
        $this->assertSame('Attendance clerk', json_decode($audit[0]['old_values'], TRUE)['role_name']);

        $this->post(array('role_name' => 'Attendance officer', 'permissions' => array('attendance_view', 'attendance_approve')));
        $this->assertSame('success', $this->roles->update($id));
        $this->assertCount(1, $this->audit_rows('roles', $id, 'update'), 'saving without a change writes no audit row');
    }

    public function test_validation()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $this->assertStringContainsString('invalid value', $this->create('Typo role', array('employees_veiw')));
        $this->assertStringContainsString('invalid value', $this->create('Token role', array('@any')));
        $this->assertStringContainsString('at least 2', $this->create('x', array()));
        $this->assertStringContainsString('HTML', $this->create('<b>bold</b>', array()));
        $this->id_of($this->create('Twice', array()));
        $this->assertSame(t('roles_name_taken'), $this->create('Twice', array()));
        $this->id_of($this->create('No permissions at all', array()));
    }

    public function test_the_system_role_is_locked()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $admin_role = (int) $this->db->query('SELECT id FROM roles WHERE is_system = 1 ORDER BY id LIMIT 1')->row()->id;
        $this->post(array('role_name' => 'Renamed', 'permissions' => array()));
        $this->assertSame(t('roles_system_locked'), $this->roles->update($admin_role));
        $this->assertSame(t('roles_system_locked'), $this->roles->delete($admin_role));
    }

    public function test_a_role_in_use_cannot_be_deleted_an_unused_one_can()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $used = $this->id_of($this->create('Used role', array('users_view')));
        $this->insert_user('holder', $used);
        $this->assertSame(t('roles_in_use'), $this->roles->delete($used));
        $free = $this->id_of($this->create('Free role', array('users_view', 'audit_view')));
        $this->assertSame('success', $this->roles->delete($free));
        $this->assertSame(0, $this->db->get_where('roles', array('id' => $free))->num_rows());
        $this->assertSame(array(), $this->keys($free), 'grants removed with the role');
        $this->assertSame(array('audit_view', 'users_view'), json_decode($this->audit_rows('roles', $free, 'delete')[0]['old_values'], TRUE)['permissions']);
    }

    public function test_others_can_only_grant_what_they_hold()
    {
        $this->act_with_permissions('rolemgr', array('roles_manage', 'users_view', 'attendance_view'));
        $this->assertSame(t('roles_grant_above_you'), $this->create('Payroll power', array('payroll_pay')));
        $this->assertSame(t('roles_grant_above_you'), $this->create('Mixed', array('users_view', 'settings_edit')));
        $ok = $this->id_of($this->create('Subset', array('users_view')));
        $this->assertEqualsCanonicalizing(array('attendance_view', 'roles_manage', 'users_view'), $this->roles->grantable_keys());

        // a role above me can neither be edited (even to strip it) nor deleted
        $above = $this->insert_role('Above me', array('users_view', 'payroll_pay'));
        $this->post(array('role_name' => 'Above me', 'permissions' => array('users_view')));
        $this->assertSame(t('roles_above_you'), $this->roles->update($above));
        $this->assertSame(t('roles_above_you'), $this->roles->delete($above));
        $this->assertSame(array('payroll_pay', 'users_view'), $this->keys($above));

        // adding a permission I lack to a role I may edit is refused
        $this->post(array('role_name' => 'Subset', 'permissions' => array('users_view', 'payroll_pay')));
        $this->assertSame(t('roles_grant_above_you'), $this->roles->update($ok));
    }

    public function test_cannot_escalate_through_my_own_role()
    {
        $me = $this->act_with_permissions('selfgrant', array('roles_manage'));
        $this->post(array('role_name' => 'role_selfgrant', 'permissions' => array('roles_manage', 'settings_edit')));
        $this->assertSame(t('roles_grant_above_you'), $this->roles->update($me['role_id']));
        has_permission_reset();
        $this->assertFalse(has_permission('settings_edit'));
    }

    public function test_list_shows_counts_and_escapes()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $this->db->insert('roles', array('role_name' => 'A&B "quoted"', 'is_system' => 0, 'created_at' => now_local()));
        $this->post(array('draw' => '2', 'search' => array('value' => 'quoted')));
        $out = $this->roles->list_page();
        $this->assertSame(1, $out['recordsFiltered']);
        $this->assertSame('A&amp;B &quot;quoted&quot;', $out['data'][0]['role_name']);
        $this->assertSame(0, $out['data'][0]['users']);
        $this->post(array('draw' => '2', 'search' => array('value' => 'boss')));
        $row = $this->roles->list_page()['data'][0];
        $this->assertSame(1, $row['is_system']);
        $this->assertStringContainsString(t('roles_all_permissions'), $row['permissions']);
    }
}
