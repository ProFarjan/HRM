<?php
/** Shifts_model: validation, night flag, code per branch, delete rules (assigned / rostered / default), audit; Employee_shifts_model assignment history. */
class Shifts_test extends Hrm_test_case
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Shifts_model');
        $this->CI->load->model('Employee_shifts_model');
        $this->act_as_new_user('boss', array(), TRUE);
    }

    protected function form(array $f = array())
    {
        $this->post(array_merge(array('shift_name' => 'General', 'shift_code' => 'gen', 'start_time' => '09:00', 'end_time' => '18:00', 'break_duration' => '60',
            'working_hours' => '8', 'grace_time' => '10', 'shift_allowance' => '0', 'status' => 'Active'), $f));
    }

    protected function new_shift(array $f = array())
    {
        $this->form($f);
        return $this->id_of_result($this->CI->Shifts_model->create());
    }

    public function test_create_and_night_flag()
    {
        $id = $this->new_shift();
        $row = $this->CI->Shifts_model->get($id);
        $this->assertSame(array('GEN', '09:00:00', '18:00:00', 0), array($row['shift_code'], $row['start_time'], $row['end_time'], (int) $row['is_night']));
        $night = $this->new_shift(array('shift_name' => 'Night', 'shift_code' => 'NGT', 'start_time' => '22:00', 'end_time' => '06:00', 'working_hours' => '0', 'break_duration' => '30'));
        $this->assertSame(1, (int) $this->CI->Shifts_model->get($night)['is_night']);
        $this->assertCount(1, $this->audit_rows('shifts', $id, 'create'));
    }

    public function test_validation()
    {
        $cases = array(
            array(array('start_time' => '25:00'), 'not a valid time'),
            array(array('end_time' => '09:00'), t('shift_same_times')),
            array(array('break_duration' => '540'), t('shift_break_too_long')),
            array(array('working_hours' => '10'), t('shift_hours_too_long')),
            array(array('grace_time' => '500'), 'out of range'),
            array(array('shift_code' => 'a b'), t('branches_code_rule')),
        );
        foreach ($cases as $c) {
            $this->form($c[0]);
            $this->assertStringContainsString($c[1], $this->CI->Shifts_model->create(), json_encode($c[0]));
        }
        $this->new_shift();
        $this->form(array('shift_name' => 'Again'));
        $this->assertSame(t('org_code_taken'), $this->CI->Shifts_model->create());
    }

    public function test_delete_rules()
    {
        $id = $this->new_shift();
        $emp = $this->insert_employee('S1');
        $this->post(array('shift_id' => (string) $id, 'effective_from' => '01-02-2026'));
        $this->id_of_result($this->CI->Employee_shifts_model->assign($emp));
        $this->assertSame(t('shift_in_use'), $this->CI->Shifts_model->delete($id));

        $free = $this->new_shift(array('shift_code' => 'FREE'));
        $this->db->update('hr_settings', array('default_shift_id' => $free), array('id' => 1));
        hr_setting_reset_cache();
        $this->assertSame(t('shift_in_use'), $this->CI->Shifts_model->delete($free), 'the default shift');
        $this->form(array('shift_code' => 'FREE', 'status' => 'Inactive'));
        $this->assertSame(t('shift_is_default'), $this->CI->Shifts_model->update($free));
        $this->db->update('hr_settings', array('default_shift_id' => NULL), array('id' => 1));
        hr_setting_reset_cache();
        $this->assertSame('success', $this->CI->Shifts_model->delete($free));
    }

    // ---------------------------------------------------------------------------------- assignments

    protected function assign($emp, $shift, $from)
    {
        $this->post(array('shift_id' => (string) $shift, 'effective_from' => $from));
        return $this->CI->Employee_shifts_model->assign($emp);
    }

    public function test_assignments_never_overlap()
    {
        $a = $this->new_shift();
        $b = $this->new_shift(array('shift_code' => 'B', 'start_time' => '14:00', 'end_time' => '22:00'));
        $emp = $this->insert_employee('A1', array('join_date' => '2026-01-01'));
        $this->assertSame(t('shift_before_join'), $this->assign($emp, $a, '31-12-2025'));
        $first = $this->id_of_result($this->assign($emp, $a, '01-01-2026'));
        $second = $this->id_of_result($this->assign($emp, $b, '16-03-2026'));
        $this->assertSame('2026-03-15', $this->db->get_where('employee_shifts', array('id' => $first))->row()->effective_to);
        $this->assertSame(sprintf(t('shift_after_latest'), '16-03-2026'), $this->assign($emp, $a, '01-03-2026'), 'no back-dated insert');
        $this->assertCount(2, $this->CI->Employee_shifts_model->history($emp));

        $this->assertSame(t('shift_only_latest'), $this->CI->Employee_shifts_model->remove_latest($emp, $first));
        $this->assertSame('success', $this->CI->Employee_shifts_model->remove_latest($emp, $second));
        $this->assertNull($this->db->get_where('employee_shifts', array('id' => $first))->row()->effective_to, 'previous reopened');
    }

    public function test_only_active_shifts_of_the_branch_and_current_employees()
    {
        $inactive = $this->new_shift(array('status' => 'Inactive'));
        $emp = $this->insert_employee('A2');
        $this->assertStringContainsString('not valid', $this->assign($emp, $inactive, '01-02-2026'));
        $home = current_branch_id();
        set_current_branch_id($this->insert_branch('SH2'));
        $far = $this->new_shift(array('shift_code' => 'FAR'));
        set_current_branch_id($home);
        $this->assertStringContainsString('not valid', $this->assign($emp, $far, '01-02-2026'));
        $gone = $this->insert_employee('A3', array('status' => 'Resigned', 'exit_date' => '2025-12-31'));
        $ok_shift = $this->new_shift(array('shift_code' => 'OK'));
        $this->assertSame(t('emp_exited_status'), $this->assign($gone, $ok_shift, '01-02-2026'));
    }

    public function test_bulk_assignment_by_department()
    {
        $shift = $this->new_shift();
        $this->db->insert('departments', array('branch_id' => current_branch_id(), 'department_name' => 'Cutting', 'department_code' => 'CUT', 'status' => 'Active', 'created_at' => now_local()));
        $dept = (int) $this->db->insert_id();
        $e1 = $this->insert_employee('B1', array('department_id' => $dept));
        $e2 = $this->insert_employee('B2', array('department_id' => $dept));
        $other = $this->insert_employee('B3');
        $this->assign($e2, $shift, '01-06-2026');                           // e2 already has a later assignment
        $this->post(array('shift_id' => (string) $shift, 'effective_from' => '01-03-2026', 'department_id' => (string) $dept));
        $this->assertSame('success<<<###>>>1/1', $this->CI->Employee_shifts_model->bulk_assign());
        $this->assertSame(1, $this->db->get_where('employee_shifts', array('employee_id' => $e1))->num_rows());
        $this->assertSame(0, $this->db->get_where('employee_shifts', array('employee_id' => $other))->num_rows(), 'other departments untouched');
    }

    public function test_assignment_list_shows_the_current_shift()
    {
        $shift = $this->new_shift();
        $with = $this->insert_employee('L1');
        $this->insert_employee('L2');
        $this->assign($with, $shift, '01-01-2026');
        $this->post(array('draw' => '1'));
        $rows = $this->CI->Employee_shifts_model->list_page()['data'];
        $by_code = array_column($rows, 'shift', 'code');
        $this->assertStringContainsString('General (09:00-18:00)', $by_code['L1']);
        $this->assertStringContainsString(t('shift_none'), $by_code['L2']);
        $this->post(array('draw' => '1', 'shift' => 'none'));
        $this->assertSame(array('L2'), array_column($this->CI->Employee_shifts_model->list_page()['data'], 'code'));
    }
}
