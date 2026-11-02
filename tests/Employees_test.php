<?php
/** Employees_model: create/update rules, auto code, branch refs, reporting cycles, sensitive masking (G6), delete rules, list, department scope. */
class Employees_test extends Hrm_test_case
{
    /** @var object */
    protected $emps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Employees_model');
        $this->emps = $this->CI->Employees_model;
    }

    protected function form(array $fields = array())
    {
        $this->post(array_merge(array('first_name' => 'রহিম', 'last_name' => 'Uddin', 'gender' => 'Male', 'dob' => '01-02-1990', 'mobile' => '+880 1711-000000',
            'employment_type' => 'Permanent', 'join_date' => '01-03-2024', 'bank_name' => 'City Bank', 'bank_account_number' => '1234567890123',
            'national_id' => '19901234567890', 'tin' => '123456789012'), $fields));
    }

    public function test_create_with_an_automatic_code_and_audit_without_clear_sensitive_values()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $this->form();
        $id = $this->id_of_result($this->emps->create());
        $row = $this->emps->get($id);
        $this->assertSame('EMP-0001', $row['employee_code']);
        $this->assertSame('রহিম', $row['first_name']);
        $this->assertSame('2024-03-01', $row['join_date']);
        $this->assertSame('Active', $row['status']);
        $this->assertSame('1234567890123', $row['bank_account_number'], 'stored in clear');
        $audit = $this->audit_rows('employees', $id, 'create');
        $this->assertStringNotContainsString('1234567890123', $audit[0]['new_values']);
        $this->assertStringContainsString('*********0123', $audit[0]['new_values']);

        $this->form(array('first_name' => 'Karim'));
        $this->assertSame('EMP-0002', $this->emps->get($this->id_of_result($this->emps->create()))['employee_code']);
        $this->form(array('employee_code' => 'EMP-0002'));
        $this->assertSame(t('emp_code_taken'), $this->emps->create());
    }

    public function test_validation_and_cross_field_rules()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $cases = array(
            array(array('first_name' => ''), 'First name is required'),
            array(array('employment_type' => 'Slave'), 'invalid value'),
            array(array('join_date' => '31-02-2024'), 'not a valid date'),
            array(array('mobile' => 'call me'), 'phone number'),
            array(array('email' => 'x@'), 'e-mail'),
            array(array('employee_code' => 'has space'), t('emp_code_rule')),
            array(array('device_pin' => '12a'), t('emp_pin_rule')),
            array(array('dob' => '01-03-2024'), t('emp_dob_after_join')),
            array(array('confirmation_date' => '01-01-2024'), t('emp_confirm_before_join')),
        );
        foreach ($cases as $case) {
            $this->form($case[0]);
            $this->assertStringContainsString($case[1], $this->emps->create(), json_encode($case[0]));
        }
        $this->assertSame(0, $this->db->count_all('employees'));
    }

    public function test_references_must_belong_to_the_branch()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $home = current_branch_id();
        set_current_branch_id($this->insert_branch('EB1'));
        $this->db->insert('departments', array('branch_id' => current_branch_id(), 'department_name' => 'Far', 'department_code' => 'FAR', 'status' => 'Active', 'created_at' => now_local()));
        $far_dept = (int) $this->db->insert_id();
        $far_emp = $this->insert_employee('FAR1');
        set_current_branch_id($home);
        $this->form(array('department_id' => (string) $far_dept));
        $this->assertStringContainsString('Selected department is not valid', $this->emps->create());
        $this->form(array('reporting_manager_id' => (string) $far_emp));
        $this->assertStringContainsString('Selected reporting manager is not valid', $this->emps->create());
    }

    public function test_reporting_lines_cannot_loop()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $a = $this->insert_employee('A');
        $b = $this->insert_employee('B', array('reporting_manager_id' => $a));
        $c = $this->insert_employee('C', array('reporting_manager_id' => $b));
        $this->form(array('employee_code' => 'A', 'reporting_manager_id' => (string) $c));
        $this->assertSame(t('emp_manager_cycle'), $this->emps->update($a));
        $this->form(array('employee_code' => 'A', 'reporting_manager_id' => (string) $a));
        $this->assertSame(t('emp_manager_self'), $this->emps->update($a));
    }

    public function test_sensitive_values_are_masked_and_kept_without_permission()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $this->form();
        $id = $this->id_of_result($this->emps->create());

        $this->act_with_permissions('clerk', array('employees_view', 'employees_edit'));
        $profile = $this->emps->profile($id);
        $this->assertSame('*********0123', $profile['bank_account_number']);
        $this->assertSame('**********7890', $profile['national_id']);
        $this->assertSame('********9012', $profile['tin']);

        // the clerk saves the form (which can only contain masked or forged values): the stored values do not change
        $this->form(array('employee_code' => 'EMP-0001', 'bank_account_number' => '999', 'national_id' => '', 'tin' => '*********0123', 'mobile' => '01811000000'));
        $this->assertSame('success', $this->emps->update($id));
        $row = $this->emps->get($id);
        $this->assertSame('1234567890123', $row['bank_account_number']);
        $this->assertSame('19901234567890', $row['national_id']);
        $this->assertSame('01811000000', $row['mobile'], 'other fields are saved');

        $this->act_with_permissions('hr_head', array('employees_view', 'employees_edit', 'employees_sensitive'));
        $this->assertSame('1234567890123', $this->emps->profile($id)['bank_account_number']);
        $this->form(array('employee_code' => 'EMP-0001', 'bank_account_number' => '5555666677'));
        $this->assertSame('success', $this->emps->update($id));
        $this->assertSame('5555666677', $this->emps->get($id)['bank_account_number']);
    }

    public function test_mask()
    {
        $this->assertSame('', Employees_model::mask(NULL));
        $this->assertSame('123', Employees_model::mask('123'));
        $this->assertSame('*2345', Employees_model::mask('12345'));
        $this->assertSame('**১২৩৪', Employees_model::mask('৫৬১২৩৪'));
    }

    public function test_status_changes_and_delete_rules()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $this->form();
        $id = $this->id_of_result($this->emps->create());
        $this->form(array('employee_code' => 'EMP-0001', 'status' => 'On Leave'));
        $this->assertSame('success', $this->emps->update($id));
        $this->assertSame('On Leave', $this->emps->get($id)['status']);
        $this->form(array('employee_code' => 'EMP-0001', 'status' => 'Resigned'));
        $this->assertStringContainsString('invalid value', $this->emps->update($id), 'leaving goes through record_exit');

        $sub = $this->insert_employee('SUB', array('reporting_manager_id' => $id));
        $this->assertSame(t('emp_delete_has_records'), $this->emps->delete($id));
        $this->db->delete('employees', array('id' => $sub));
        $this->assertSame('success', $this->emps->delete($id));
        $this->assertNull($this->emps->get($id));
        $this->assertCount(1, $this->audit_rows('employees', $id, 'delete'));
    }

    public function test_list_filters_search_and_escaping()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $this->insert_employee('L1', array('first_name' => 'Anika <b>', 'employment_type' => 'Contract'));
        $this->insert_employee('L2', array('first_name' => 'Babul', 'status' => 'Resigned', 'exit_date' => '2025-01-01'));
        $this->insert_employee('L3', array('first_name' => 'Chaity', 'device_pin' => '345'));
        $this->post(array('draw' => '1', 'status' => 'current'));
        $out = $this->emps->list_page();
        $this->assertSame(3, $out['recordsTotal']);
        $this->assertSame(2, $out['recordsFiltered'], 'leavers hidden by default');
        $this->assertSame('Anika &lt;b&gt;', $out['data'][0]['name']);
        $this->post(array('draw' => '1', 'employment_type' => 'Contract'));
        $this->assertSame(array('L1'), array_column($this->emps->list_page()['data'], 'code'));
        $this->post(array('draw' => '1', 'search' => array('value' => '345')));
        $this->assertSame(array('L3'), array_column($this->emps->list_page()['data'], 'code'), 'search by PIN');
        $this->post(array('draw' => '1', 'status' => 'Resigned'));
        $this->assertSame(array('L2'), array_column($this->emps->list_page()['data'], 'code'));
    }

    public function test_department_head_scope_with_real_tables()
    {
        $this->act_as_new_user('boss', array(), TRUE);
        $head = $this->insert_employee('HD');
        $this->db->insert('departments', array('branch_id' => current_branch_id(), 'department_name' => 'QC', 'department_code' => 'QC', 'head_employee_id' => $head, 'status' => 'Active', 'created_at' => now_local()));
        $qc = (int) $this->db->insert_id();
        $mine = $this->insert_employee('Q1', array('department_id' => $qc));
        $other = $this->insert_employee('X1');
        $this->db->query('CREATE TEMPORARY TABLE scope_rows (id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->query('INSERT INTO scope_rows (employee_id) VALUES (?), (?), (?)', array($head, $mine, $other));

        $user = $this->act_with_permissions('headlogin', array('attendance_view'));
        set_current_user(array_merge($user, array('employee_id' => $head)));
        $probe = new Dept_scope_probe();
        $probe->scoped();
        $ids = array_map('intval', array_column($this->db->get()->result_array(), 'employee_id'));
        sort($ids);
        $expected = array($head, $mine);
        sort($expected);
        $this->assertSame($expected, $ids, 'own rows + the department they head');
    }
}

class Dept_scope_probe extends MY_Model
{
    protected $module = 'attendance';

    public function scoped()
    {
        $this->db->from('scope_rows r');
        $this->scope_department('r');
    }
}
