<?php
/** Departments and designations: code unique per branch, branch scope, head must be a current employee, delete only unused, audit. */
class Organisation_units_test extends Hrm_test_case
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Departments_model');
        $this->CI->load->model('Designations_model');
        $this->act_as_new_user('boss', array(), TRUE);
    }

    protected function dept(array $fields = array())
    {
        $this->post(array_merge(array('department_name' => 'Production', 'department_code' => 'prod', 'description' => 'Sewing floor', 'status' => 'Active'), $fields));
    }

    public function test_create_update_and_audit()
    {
        $this->dept();
        $id = $this->id_of_result($this->CI->Departments_model->create());
        $row = $this->CI->Departments_model->get($id);
        $this->assertSame('PROD', $row['department_code']);
        $this->assertSame((int) current_branch_id(), (int) $row['branch_id']);
        $this->assertSame((int) current_user_id(), (int) $row['created_by']);
        $this->assertCount(1, $this->audit_rows('departments', $id, 'create'));

        $this->dept(array('status' => 'Inactive'));
        $this->assertSame('success', $this->CI->Departments_model->update($id));
        $this->assertCount(1, $this->audit_rows('departments', $id, 'deactivate'));
        $this->assertArrayNotHasKey($id, $this->CI->Departments_model->active_list(), 'inactive units are not offered');
    }

    public function test_codes_are_unique_per_branch_only()
    {
        $this->dept();
        $this->id_of_result($this->CI->Departments_model->create());
        $this->dept(array('department_name' => 'Other'));
        $this->assertSame(t('org_code_taken'), $this->CI->Departments_model->create());

        $other = $this->insert_branch('OU1');
        set_current_branch_id($other);
        $this->dept();
        $this->id_of_result($this->CI->Departments_model->create());
    }

    public function test_other_branches_are_out_of_reach()
    {
        $this->dept();
        $id = $this->id_of_result($this->CI->Departments_model->create());
        set_current_branch_id($this->insert_branch('OU2'));
        $this->assertNull($this->CI->Departments_model->get($id));
        $this->dept(array('department_name' => 'Hijack'));
        $this->assertSame(t('not_found'), $this->CI->Departments_model->update($id));
        $this->assertSame(t('not_found'), $this->CI->Departments_model->delete($id));
        $this->post(array('draw' => '1'));
        $this->assertSame(0, $this->CI->Departments_model->list_page()['recordsTotal']);
    }

    public function test_head_must_be_a_current_employee_of_the_branch()
    {
        $head = $this->insert_employee('H1');
        $leaver = $this->insert_employee('H2', array('status' => 'Resigned', 'exit_date' => '2025-01-01'));
        $branch = current_branch_id();
        set_current_branch_id($this->insert_branch('OU3'));
        $foreign = $this->insert_employee('H3');
        set_current_branch_id($branch);

        $this->dept(array('head_employee_id' => (string) $foreign));
        $this->assertStringContainsString('not valid', $this->CI->Departments_model->create());
        $this->dept(array('head_employee_id' => (string) $leaver));
        $this->assertSame(t('departments_head_inactive'), $this->CI->Departments_model->create());
        $this->dept(array('head_employee_id' => (string) $head));
        $id = $this->id_of_result($this->CI->Departments_model->create());
        $this->post(array('draw' => '1'));
        $this->assertSame('Emp H1', $this->CI->Departments_model->list_page()['data'][0]['extra']);
        unset($id);
    }

    public function test_delete_only_while_unused()
    {
        $this->dept();
        $id = $this->id_of_result($this->CI->Departments_model->create());
        $emp = $this->insert_employee('D1', array('department_id' => $id));
        $this->assertSame(t('org_in_use'), $this->CI->Departments_model->delete($id));
        $this->db->update('employees', array('department_id' => NULL), array('id' => $emp));
        $this->assertSame('success', $this->CI->Departments_model->delete($id));
        $this->assertCount(1, $this->audit_rows('departments', $id, 'delete'));
    }

    public function test_designations_have_a_level()
    {
        $this->post(array('designation_name' => 'Line supervisor', 'designation_code' => 'LS', 'level' => '3', 'status' => 'Active'));
        $id = $this->id_of_result($this->CI->Designations_model->create());
        $this->assertSame(3, (int) $this->CI->Designations_model->get($id)['level']);
        $this->post(array('designation_name' => 'Line supervisor', 'designation_code' => 'LS', 'level' => '1000', 'status' => 'Active'));
        $this->assertStringContainsString('out of range', $this->CI->Designations_model->update($id));
        $this->post(array('designation_name' => '<b>x</b>', 'designation_code' => 'LS2', 'status' => 'Active'));
        $this->assertStringContainsString('HTML', $this->CI->Designations_model->create());
    }
}
