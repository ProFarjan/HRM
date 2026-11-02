<?php
/** Audit_model: date range (default 7 days, max 366), filters, branch scope (NULL branch for Admin only), query budget. */
class Audit_test extends Hrm_test_case
{
    /** @var object */
    protected $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Audit_model');
        $this->audit = $this->CI->Audit_model;
        $this->db->empty_table('audit_log');            // rolled back with the test transaction
    }

    protected function entry($module, $action, $branch_id, $user_id = NULL, $days_ago = 0, $record = '1')
    {
        $this->db->insert('audit_log', array('branch_id' => $branch_id, 'user_id' => $user_id, 'module' => $module, 'record_id' => $record,
            'action' => $action, 'old_values' => json_encode(array('a' => 1)), 'new_values' => json_encode(array('a' => 2)), 'ip' => '10.0.0.1',
            'created_at' => date('Y-m-d H:i:s', time() - $days_ago * 86400)));
        return (int) $this->db->insert_id();
    }

    protected function listed(array $post = array())
    {
        $this->post(array_merge(array('draw' => '1'), $post));
        return $this->audit->list_page();
    }

    public function test_default_range_is_the_last_seven_days()
    {
        $me = $this->act_with_permissions('auditor', array('audit_view'));
        $this->entry('users', 'create', $me['branch_id'], $me['id'], 0);
        $this->entry('users', 'update', $me['branch_id'], $me['id'], 6);
        $this->entry('users', 'old', $me['branch_id'], $me['id'], 10);
        $out = $this->listed();
        $this->assertSame(2, $out['recordsTotal']);
        $this->assertSame(array('create', 'update'), array_column($out['data'], 'action'), 'newest first');
        $out = $this->listed(array('from_date' => date('d-m-Y', time() - 12 * 86400)));
        $this->assertSame(3, $out['recordsTotal']);
    }

    public function test_bad_ranges_are_refused()
    {
        $this->act_with_permissions('auditor', array('audit_view'));
        $this->assertSame(t('audit_range_order'), $this->listed(array('from_date' => '10-11-2026', 'to_date' => '01-11-2026'))['error']);
        $this->assertSame(sprintf(t('audit_range_too_long'), 366), $this->listed(array('from_date' => '01-01-2024', 'to_date' => '01-11-2026'))['error']);
        $this->assertStringContainsString('valid date', $this->listed(array('from_date' => '31-02-2026'))['error']);
    }

    public function test_filters_and_search()
    {
        $me = $this->act_with_permissions('auditor', array('audit_view'));
        $other_user = $this->insert_user('someone', $this->insert_role('r'));
        $this->entry('users', 'create', $me['branch_id'], $me['id']);
        $this->entry('roles', 'update', $me['branch_id'], $me['id']);
        $this->entry('roles', 'delete', $me['branch_id'], $other_user, 0, '77');
        $this->assertSame(2, $this->listed(array('module' => 'roles'))['recordsFiltered']);
        $this->assertSame(1, $this->listed(array('action' => 'delete'))['recordsFiltered']);
        $this->assertSame(1, $this->listed(array('user_id' => (string) $other_user))['recordsFiltered']);
        $this->assertSame(1, $this->listed(array('search' => array('value' => '77')))['recordsFiltered']);
        $this->assertSame(1, $this->listed(array('search' => array('value' => 'someone')))['recordsFiltered']);
        $this->assertSame(3, $this->listed()['recordsTotal'], 'filters do not change the total');
    }

    public function test_other_branches_are_hidden_and_system_rows_are_for_the_admin_only()
    {
        $me = $this->act_with_permissions('auditor', array('audit_view'));
        $other = $this->insert_branch('AUD2');
        $mine = $this->entry('users', 'create', $me['branch_id']);
        $foreign = $this->entry('users', 'create', $other);
        $system = $this->entry('cron', 'housekeeping', NULL);
        $this->assertSame(1, $this->listed()['recordsTotal']);
        $this->assertNotNull($this->audit->get($mine));
        $this->assertNull($this->audit->get($foreign));
        $this->assertNull($this->audit->get($system));

        $this->act_as_new_user('boss', array(), TRUE);
        $this->assertSame(2, $this->listed()['recordsTotal'], 'own branch + rows without a branch');
        $this->assertNotNull($this->audit->get($system));
        $this->assertNull($this->audit->get($foreign), 'even the Admin sees one branch at a time');
    }

    public function test_detail_decodes_values()
    {
        $me = $this->act_with_permissions('auditor', array('audit_view'));
        $id = $this->entry('settings', 'update', $me['branch_id'], $me['id']);
        $row = $this->audit->get($id);
        $this->assertSame(array('a' => 1), $row['old']);
        $this->assertSame(array('a' => 2), $row['new']);
        $this->assertSame('auditor', $row['username']);
    }

    public function test_sorting_uses_the_whitelist_only()
    {
        $me = $this->act_with_permissions('auditor', array('audit_view'));
        $this->entry('b_module', 'x', $me['branch_id']);
        $this->entry('a_module', 'x', $me['branch_id']);
        $out = $this->listed(array('order' => array(array('column' => '2', 'dir' => 'asc'))));
        $this->assertSame(array('a_module', 'b_module'), array_column($out['data'], 'module'));
        $out = $this->listed(array('order' => array(array('column' => '1; DROP TABLE audit_log', 'dir' => 'sideways'))));
        $this->assertCount(2, $out['data'], 'garbage sort falls back to the default');
    }

    public function test_list_costs_three_queries()
    {
        $me = $this->act_with_permissions('auditor', array('audit_view'));
        $this->entry('users', 'create', $me['branch_id']);
        hr_setting('date_format');
        has_permission('audit_view');
        $this->post(array('draw' => '1'));
        $before = $this->queries_before();
        $this->audit->list_page();
        $this->assertLessThanOrEqual(3, $this->queries_before() - $before);
    }
}
