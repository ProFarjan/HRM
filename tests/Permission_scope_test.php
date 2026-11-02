<?php
class Scope_probe_model extends MY_Model
{
    protected $module = 'attendance';

    public function branch($alias = '')            { return $this->scope_branch($alias); }
    public function own($alias = '')               { return $this->scope_own($alias); }
    public function dept($alias = '')              { return $this->scope_department($alias); }
    public function dept_sql($col, $employee)      { return $this->department_scope_sql($col, $employee); }
    public function bad_alias()                    { return $this->scope_branch('t; DROP TABLE users'); }
    public function wrote_audit()                  { return $this->audit('probe', 1, 'x'); }
}

class Unscoped_probe_model extends MY_Model
{
    public function own() { return $this->scope_own('t'); }
}

/** has_permission() (one query per request, Admin passes), scope_* helpers, Access_model, audit(). Closes P14's "no inference" half. */
class Permission_scope_test extends Hrm_test_case
{
    // ---------------------------------------------------------------------------------- has_permission

    public function test_no_login_means_no_permission()
    {
        $this->assertFalse(has_permission('dashboard_hr'));
    }

    public function test_a_role_has_exactly_the_keys_it_was_given()
    {
        $this->act_as_new_user('hr_clerk', array('employees_view', 'attendance_view'));
        $this->assertTrue(has_permission('employees_view'));
        $this->assertTrue(has_permission('attendance_view'));
        $this->assertFalse(has_permission('employees_edit'));
        $this->assertFalse(has_permission('attendance_approve'));
        $this->assertFalse(has_permission('show_all_attendance'));
    }

    public function test_holding_edit_or_delete_does_not_imply_manager_rights()
    {
        $this->act_as_new_user('editor', array('attendance_edit', 'attendance_delete'));
        foreach (array('attendance_add', 'attendance_approve', 'show_all_attendance', 'attendance_bulk') as $key) {
            $this->assertFalse(has_permission($key), "$key must be granted explicitly (P14)");
        }
    }

    public function test_the_system_admin_role_passes_every_catalog_key()
    {
        $this->act_as_new_user('sysadmin', array(), TRUE);
        foreach (array_keys(permission_catalog_keys()) as $key) {
            $this->assertTrue(has_permission($key), $key);
        }
    }

    public function test_unknown_keys_never_pass_not_even_for_admin()
    {
        $this->act_as_new_user('sysadmin2', array(), TRUE);
        $this->assertFalse(has_permission('employees_veiw'), 'a typo does not silently grant access');
        $this->assertFalse(has_permission(''));
        $this->assertFalse(has_permission(NULL));
        $this->assertFalse(has_permission(array('employees_view')));
    }

    public function test_permissions_cost_one_query_per_request()
    {
        $this->act_as_new_user('counted', array('employees_view', 'leaves_view'));
        $before = $this->queries_before();
        for ($i = 0; $i < 25; $i++) {
            has_permission('employees_view');
            has_permission('leaves_view');
            has_permission('payroll_pay');
        }
        $this->assertSame($before + 1, $this->queries_before());
    }

    public function test_the_cache_follows_the_user_not_the_process()
    {
        $this->act_as_new_user('first', array('employees_view'));
        $this->assertTrue(has_permission('employees_view'));
        $this->act_as_new_user('second', array('leaves_view'));
        $this->assertFalse(has_permission('employees_view'));
        $this->assertTrue(has_permission('leaves_view'));
    }

    public function test_every_catalog_key_is_unique_and_well_formed()
    {
        $seen = array();
        foreach (config_item('permission_catalog') as $group => $keys) {
            foreach ($keys as $key) {
                $this->assertMatchesRegularExpression('/^[a-z][a-z_]*$/', $key);
                $this->assertArrayNotHasKey($key, $seen, "$key listed twice");
                $this->assertLessThanOrEqual(64, strlen($key));
                $seen[$key] = TRUE;
            }
        }
        foreach (array('employees_view', 'employees_salary', 'employees_sensitive', 'show_all_attendance', 'show_all_leaves', 'payroll_override',
                       'payroll_reopen', 'export_hr', 'dashboard_hr', 'settings_edit', 'roles_manage', 'audit_view', 'devices_keys') as $key) {
            $this->assertArrayHasKey($key, $seen, "CLAUDE.md names $key");
        }
    }

    public function test_a_permission_lookup_in_the_middle_of_a_query_chain_does_not_corrupt_it()
    {
        $this->act_as_new_user('midchain', array('employees_view'));
        has_permission_reset();                                        // the next has_permission() really queries
        hr_setting_reset_cache();
        $this->db->select('id, username')->from('users')->where('username', 'midchain');
        $this->assertTrue(has_permission('employees_view'));           // runs a query while the chain above is pending
        hr_setting('date_format');                                     // so does the first settings read
        $rows = $this->db->get()->result_array();
        $this->assertCount(1, $rows);
        $this->assertSame('midchain', $rows[0]['username']);
    }

    // ---------------------------------------------------------------------------------- scope_*

    protected function compiled($model, $table = 'x')
    {
        return $this->db->get_compiled_select($table);
    }

    public function test_scope_branch_filters_on_the_current_branch()
    {
        $user = $this->act_as_new_user('scoped', array());
        $m = new Scope_probe_model();
        $m->branch('t');
        $this->assertStringContainsString('`t`.`branch_id` = ' . $user['branch_id'], $this->compiled($m, 'things t'));
    }

    public function test_scope_branch_without_a_branch_matches_nothing()
    {
        $m = new Scope_probe_model();
        $m->branch('t');
        $this->assertStringContainsString('`t`.`branch_id` = 0', $this->compiled($m, 'things t'));
    }

    public function test_scope_own_limits_to_the_employee_unless_show_all_is_held()
    {
        $this->act_as_new_user('plain', array());
        set_current_user(array_merge(current_user(), array('employee_id' => 42)));
        $m = new Scope_probe_model();
        $m->own('a');
        $this->assertStringContainsString('`a`.`employee_id` = 42', $this->compiled($m, 'attendance a'));

        $this->act_as_new_user('boss', array('show_all_attendance'));
        $m->own('a');
        $this->assertStringNotContainsString('employee_id', $this->compiled($m, 'attendance a'), 'show_all_attendance lifts the restriction');
    }

    public function test_scope_own_for_a_login_without_an_employee_sees_nothing()
    {
        $this->act_as_new_user('noemp', array());
        $m = new Scope_probe_model();
        $m->own('a');
        $this->assertStringContainsString('`a`.`employee_id` = 0', $this->compiled($m, 'attendance a'));
    }

    public function test_scope_own_needs_a_module_or_permission()
    {
        $this->act_as_new_user('x1', array());
        $this->expectException(LogicException::class);
        $m = new Unscoped_probe_model();
        $m->own();
    }

    public function test_department_scope_sql_is_integer_only_and_covers_own_plus_department()
    {
        $m = new Scope_probe_model();
        $sql = $m->dept_sql('a.employee_id', '7; DROP TABLE users');
        $this->assertStringContainsString('a.employee_id = 7 ', $sql, 'the id is cast to int before it is placed in SQL');
        $this->assertStringNotContainsString('DROP', $sql);
        $this->assertStringContainsString('d.head_employee_id = 7', $sql);
        $this->assertStringContainsString('e.department_id IN', $sql);
        $this->assertStringContainsString('= 0 ', $m->dept_sql('a.employee_id', NULL));
    }

    public function test_scope_department_is_skipped_for_users_who_see_everything()
    {
        $this->act_as_new_user('all', array('show_all_attendance'));
        $m = new Scope_probe_model();
        $m->dept('a');
        $this->assertStringNotContainsString('head_employee_id', $this->compiled($m, 'attendance a'));
    }

    public function test_aliases_and_columns_are_checked_as_identifiers()
    {
        $this->expectException(InvalidArgumentException::class);
        $m = new Scope_probe_model();
        $m->bad_alias();
    }

    // ---------------------------------------------------------------------------------- Access_model (backs ref() and belong_to())

    public function test_record_in_scope()
    {
        $me = $this->act_as_new_user('scoped_me', array());
        $this->CI->load->model('Access_model');
        $this->assertTrue($this->CI->Access_model->record_in_scope('users', $me['id']));
        $other_branch = $this->insert_branch('OB2');
        $foreign = $this->insert_user('foreign2', $this->insert_role('fr2'), $other_branch);
        $this->assertFalse($this->CI->Access_model->record_in_scope('users', $foreign), 'another branch');
        $this->assertFalse($this->CI->Access_model->record_in_scope('users', 0));
        $this->assertFalse($this->CI->Access_model->record_in_scope('users', 'abc'));
        $this->assertFalse($this->CI->Access_model->record_in_scope('roles', 1), 'table not registered');
        $this->assertFalse($this->CI->Access_model->record_in_scope('users; DROP TABLE users', 1));
        set_current_user(NULL);
        $this->assertFalse($this->CI->Access_model->record_in_scope('users', $me['id']), 'nobody logged in');
    }

    public function test_own_records_scope_applies_to_registered_tables_with_an_owner_column()
    {
        // register a table the way later steps will: branch + owner column + module
        $this->db->query('CREATE TEMPORARY TABLE scope_probe (id INT AUTO_INCREMENT PRIMARY KEY, branch_id INT, employee_id INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->CI->config->set_item('scoped_tables', array_merge(config_item('scoped_tables'), array(
            'scope_probe' => array('branch' => 'branch_id', 'own' => 'employee_id', 'module' => 'attendance'),
        )));
        $user = $this->act_as_new_user('owner_user', array());
        set_current_user(array_merge(current_user(), array('employee_id' => 10)));
        $this->db->query('INSERT INTO scope_probe (branch_id, employee_id) VALUES (?, 10), (?, 11)', array($user['branch_id'], $user['branch_id']));
        $mine = (int) $this->db->query('SELECT id FROM scope_probe WHERE employee_id = 10')->row()->id;
        $theirs = (int) $this->db->query('SELECT id FROM scope_probe WHERE employee_id = 11')->row()->id;
        $this->CI->load->model('Access_model');
        $this->assertTrue($this->CI->Access_model->record_in_scope('scope_probe', $mine));
        $this->assertFalse($this->CI->Access_model->record_in_scope('scope_probe', $theirs), 'someone else\'s record without show_all_attendance');

        $this->act_as_new_user('manager_user', array('show_all_attendance'), FALSE, $user['branch_id']);
        $this->assertTrue($this->CI->Access_model->record_in_scope('scope_probe', $theirs));
    }

    // ---------------------------------------------------------------------------------- audit

    public function test_audit_writes_actor_branch_and_json()
    {
        $user = $this->act_as_new_user('auditor', array());
        $m = new Scope_probe_model();
        $id = $m->wrote_audit();
        $row = $this->db->get_where('audit_log', array('id' => $id))->row_array();
        $this->assertSame((int) $user['id'], (int) $row['user_id']);
        $this->assertSame((int) $user['branch_id'], (int) $row['branch_id']);
        $this->assertSame('probe', $row['module']);
        $this->assertSame('x', $row['action']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['created_at']);
    }
}
