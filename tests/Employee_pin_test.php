<?php
/**
 * Device PIN and PIN history (groundwork for P5): unique per branch, history with validity dates on change and exit,
 * reuse only after the old owner's dates, pin_owner() by date, corrections, re-hire, exit deactivates the login.
 */
class Employee_pin_test extends Hrm_test_case
{
    /** @var object */
    protected $emps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Employees_model');
        $this->emps = $this->CI->Employees_model;
        $this->act_as_new_user('boss', array(), TRUE);
    }

    protected function create($code, $pin, $join = '01-01-2024')
    {
        $this->post(array('employee_code' => $code, 'first_name' => 'E ' . $code, 'employment_type' => 'Permanent', 'join_date' => $join, 'device_pin' => $pin));
        return $this->emps->create();
    }

    protected function change($id, $pin, $from)
    {
        $this->post(array('new_pin' => $pin, 'effective_from' => $from));
        return $this->emps->change_pin($id);
    }

    protected function leave($id, $date, $status = 'Resigned')
    {
        $this->post(array('exit_date' => $date, 'exit_status' => $status, 'exit_reason' => 'Moved away'));
        return $this->emps->record_exit($id);
    }

    public function test_pin_is_unique_per_branch()
    {
        $a = $this->id_of_result($this->create('A', '101'));
        $this->assertSame('2024-01-01', $this->emps->get($a)['device_pin_from'], 'the PIN starts on the join date');
        $this->assertSame(sprintf(t('emp_pin_taken'), 'A'), $this->create('B', '101'));
        set_current_branch_id($this->insert_branch('PB1'));
        $this->id_of_result($this->create('A', '101'));
    }

    public function test_changing_a_pin_moves_the_old_one_to_the_history()
    {
        $a = $this->id_of_result($this->create('A', '101'));
        $this->assertSame('success', $this->change($a, '202', '01-06-2024'));
        $row = $this->emps->get($a);
        $this->assertSame(array('202', '2024-06-01'), array($row['device_pin'], $row['device_pin_from']));
        $this->assertSame(array(array('device_pin' => '101', 'valid_from' => '2024-01-01', 'valid_to' => '2024-05-31')), $this->emps->pin_history($a));
        $this->assertCount(1, $this->audit_rows('employees', $a, 'pin_change'));

        $branch = current_branch_id();
        $this->assertSame($a, $this->emps->pin_owner($branch, '101', '2024-05-31'));
        $this->assertNull($this->emps->pin_owner($branch, '101', '2024-06-01'), 'after the change 101 is free');
        $this->assertSame($a, $this->emps->pin_owner($branch, '202', '2024-06-01'));
        $this->assertNull($this->emps->pin_owner($branch, '202', '2024-05-31'), 'the new PIN does not reach back');
        $this->assertNull($this->emps->pin_owner($branch, '101', '2023-12-31'), 'before the first PIN started');
    }

    public function test_a_change_on_or_before_the_pin_start_is_a_correction()
    {
        $a = $this->id_of_result($this->create('A', '101', '01-06-2024'));
        $this->assertSame('success', $this->change($a, '111', '01-06-2024'));
        $this->assertSame(array(), $this->emps->pin_history($a), 'a mistyped PIN leaves no history');
        $this->assertSame('111', $this->emps->get($a)['device_pin']);
        $this->assertSame(t('emp_pin_before_join'), $this->change($a, '112', '01-05-2024'));
    }

    public function test_exit_moves_the_pin_and_the_pin_can_be_reused_later_without_stealing_old_punches()
    {
        $leaver = $this->id_of_result($this->create('L', '555'));
        $this->assertSame('success', $this->leave($leaver, '30-06-2024'));
        $row = $this->emps->get($leaver);
        $this->assertSame(array('Resigned', '2024-06-30', NULL), array($row['status'], $row['exit_date'], $row['device_pin']));
        $this->assertSame('2024-06-30', $this->emps->pin_history($leaver)[0]['valid_to']);

        $this->assertSame(sprintf(t('emp_pin_history_overlap'), 'L', '30-06-2024'), $this->create('N', '555', '15-06-2024'), 'cannot start inside the leaver\'s dates');
        $newcomer = $this->id_of_result($this->create('N', '555', '01-07-2024'));

        $branch = current_branch_id();
        $this->assertSame($leaver, $this->emps->pin_owner($branch, '555', '2024-06-30'), 'a late punch of the leaver still finds the leaver');
        $this->assertSame($newcomer, $this->emps->pin_owner($branch, '555', '2024-07-01'), 'new punches go to the newcomer');
    }

    public function test_exit_rules_and_rehire()
    {
        $a = $this->id_of_result($this->create('A', '101', '01-03-2024'));
        $this->assertSame(t('emp_exit_before_join'), $this->leave($a, '28-02-2024'));
        $this->post(array('exit_date' => '01-04-2024', 'exit_status' => 'Fired', 'exit_reason' => 'x'));
        $this->assertStringContainsString('invalid value', $this->emps->record_exit($a));
        $this->assertSame('success', $this->leave($a, '31-12-2024', 'Terminated'));
        $this->assertSame(t('emp_already_exited'), $this->leave($a, '31-12-2024'));
        $this->assertSame(t('emp_exited_pin'), $this->change($a, '777', '01-01-2025'));

        $this->post(array('rehire_date' => '31-12-2024'));
        $this->assertSame(t('emp_rehire_after_exit'), $this->emps->rehire($a));
        $this->post(array('rehire_date' => '01-02-2025'));
        $this->assertSame('success', $this->emps->rehire($a));
        $row = $this->emps->get($a);
        $this->assertSame(array('Active', '2025-02-01', NULL), array($row['status'], $row['join_date'], $row['exit_date']));
        $this->assertCount(1, $this->audit_rows('employees', $a, 'rehire'));
        $this->assertSame('success', $this->change($a, '101', '01-02-2025'), 'the old PIN again, after its history ends');
        $this->post(array('rehire_date' => '01-03-2025'));
        $this->assertSame(t('emp_not_exited'), $this->emps->rehire($a));
    }

    public function test_exit_deactivates_the_linked_login_but_never_your_own()
    {
        $a = $this->id_of_result($this->create('A', NULL));
        $user = $this->insert_user('a_login', $this->insert_role('emp_role'));
        $this->db->update('users', array('employee_id' => $a), array('id' => $user));
        $this->db->update('employees', array('user_id' => $user), array('id' => $a));
        $this->assertSame('success', $this->leave($a, '30-06-2024'));
        $this->assertSame('Inactive', $this->db->get_where('users', array('id' => $user))->row()->status);
        $this->assertCount(1, $this->audit_rows('users', $user, 'deactivate'));

        $b = $this->id_of_result($this->create('B', NULL));
        $me = current_user();
        $this->db->update('users', array('employee_id' => $b), array('id' => $me['id']));
        $this->db->update('employees', array('user_id' => $me['id']), array('id' => $b));
        $this->assertSame(t('users_not_yourself'), $this->leave($b, '30-06-2024'));
    }

    public function test_link_and_unlink_a_login_on_both_sides()
    {
        $a = $this->id_of_result($this->create('A', NULL));
        $user = $this->insert_user('to_link', $this->insert_role('plain'));
        $this->post(array('user_id' => (string) $user));
        $this->assertSame('success', $this->emps->link_login($a));
        $this->assertSame($a, (int) $this->db->get_where('users', array('id' => $user))->row()->employee_id);
        $this->assertSame($user, (int) $this->emps->get($a)['user_id']);
        $this->assertArrayNotHasKey($user, $this->emps->linkable_users());

        $b = $this->id_of_result($this->create('B', NULL));
        $this->post(array('user_id' => (string) $user));
        $this->assertSame(t('emp_login_taken'), $this->emps->link_login($b));

        $this->assertSame('success', $this->emps->unlink_login($a));
        $this->assertNull($this->db->get_where('users', array('id' => $user))->row()->employee_id);
        $this->assertNull($this->emps->get($a)['user_id']);
    }

    public function test_nobody_links_a_login_above_them()
    {
        $a = $this->id_of_result($this->create('A', NULL));
        $big = $this->insert_user('big_login', $this->insert_role('big', array('payroll_pay')));
        $this->act_with_permissions('linker', array('employees_edit', 'users_edit'));
        $this->post(array('user_id' => (string) $big));
        $this->assertSame(t('users_target_above_you'), $this->emps->link_login($a));
    }
}
