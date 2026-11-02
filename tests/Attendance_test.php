<?php
/** Attendance_model: mark / mark own day / edit / delete / approve / bulk / rebuild / manual punch / list, with audit. */
class Attendance_test extends Hrm_test_case
{
    /** @var object */
    protected $att;
    /** @var int */
    protected $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Attendance_model');
        $this->att = $this->CI->Attendance_model;
        $this->act_as_new_user('boss', array(), TRUE);
        $this->db->update('branches', array('weekly_offs' => ''), array('id' => current_branch_id()));
        $shift = $this->insert_shift('DAY', '09:00:00', '18:00:00', array('grace_time' => 5, 'working_hours' => 8));
        $this->emp = $this->insert_employee('A1');
        $this->assign_shift($this->emp, $shift);
    }

    protected function mark(array $f)
    {
        $this->post(array_merge(array('employee_id' => (string) $this->emp, 'attendance_date' => '06-04-2026', 'status' => 'Present', 'check_in' => '09:30', 'check_out' => '18:30'), $f));
        return $this->att->mark();
    }

    public function test_mark_computes_the_metrics_and_audits()
    {
        $id = $this->id_of_result($this->mark(array()));
        $row = $this->attendance_row($this->emp, '2026-04-06');
        $this->assertSame(array('Present', 'manual', 'Approved', '09:30:00', '18:30:00'), array($row['status'], $row['source'], $row['approval_status'], $row['check_in'], $row['check_out']));
        $this->assertSame(25, (int) $row['late_minutes']);
        $this->assertEquals(9.0, (float) $row['working_hours']);
        $this->assertEquals(1.0, (float) $row['overtime_hours']);
        $this->assertCount(1, $this->audit_rows('attendance', $id, 'mark'));
        $this->assertSame(t('att_exists'), $this->mark(array()), 'a second mark is an edit');
    }

    public function test_mark_rules()
    {
        $this->assertStringContainsString(t('att_check_in_required'), $this->mark(array('check_in' => '')));
        $this->assertSame(t('att_future'), $this->mark(array('attendance_date' => '01-01-2999')));
        $this->assertSame(t('att_not_employed'), $this->mark(array('attendance_date' => '01-01-2020')), 'before the join date');
        $this->id_of_result($this->mark(array('attendance_date' => '07-04-2026', 'status' => 'Absent', 'check_in' => '09:00')));
        $row = $this->attendance_row($this->emp, '2026-04-07');
        $this->assertSame(array('Absent', NULL, NULL), array($row['status'], $row['check_in'], $row['check_out']), 'Absent clears the times');
    }

    public function test_a_day_close_row_may_be_replaced_by_a_mark()
    {
        $this->db->insert('attendance', array('branch_id' => current_branch_id(), 'employee_id' => $this->emp, 'attendance_date' => '2026-04-06', 'status' => 'Absent',
            'approval_status' => 'Approved', 'source' => 'day_close', 'created_at' => now_local()));
        $id = (int) $this->db->insert_id();
        $this->assertSame('success<<<###>>>' . $id, $this->mark(array()));
        $this->assertSame('Present', $this->attendance_row($this->emp, '2026-04-06')['status']);
    }

    public function test_mark_own_day_is_pending_and_needs_an_employee()
    {
        $user = $this->act_with_permissions('selfie', array('attendance_mark_self'));
        $this->post(array('check_in' => '09:00'));
        $this->assertSame(t('att_no_employee'), $this->att->mark_self());
        set_current_user(array_merge($user, array('employee_id' => $this->emp)));
        $this->post(array('check_in' => '09:00', 'employee_id' => '999', 'attendance_date' => '01-01-2020'));
        $id = $this->id_of_result($this->att->mark_self());
        $row = $this->db->get_where('attendance', array('id' => $id))->row_array();
        $this->assertSame(array((string) $this->emp, today_local(), 'Pending', 'self'), array($row['employee_id'], $row['attendance_date'], $row['approval_status'], $row['source']),
            'own employee and today only, whatever is posted');
    }

    public function test_approve_and_reject_only_pending_and_never_your_own()
    {
        $this->db->insert('attendance', array('branch_id' => current_branch_id(), 'employee_id' => $this->emp, 'attendance_date' => '2026-04-06', 'status' => 'Present',
            'check_in' => '09:00:00', 'approval_status' => 'Pending', 'source' => 'self', 'created_at' => now_local()));
        $id = (int) $this->db->insert_id();
        $approver = $this->act_with_permissions('approver', array('attendance_approve'));
        set_current_user(array_merge($approver, array('employee_id' => $this->emp)));
        $this->post(array());
        $this->assertSame(t('att_not_own_approval'), $this->att->decide($id, TRUE));
        $this->act_with_permissions('approver2', array('attendance_approve'));
        $this->post(array());
        $this->assertStringContainsString('required', $this->att->decide($id, FALSE), 'a rejection needs a note');
        $this->assertSame('success', $this->att->decide($id, TRUE));
        $this->assertSame('Approved', $this->db->get_where('attendance', array('id' => $id))->row()->approval_status);
        $this->assertSame(t('att_not_pending'), $this->att->decide($id, TRUE), 'approve twice: the second sees it is no longer pending');
        $this->assertCount(1, $this->audit_rows('attendance', $id, 'approve'));
    }

    public function test_edit_and_delete_respect_the_lock()
    {
        $id = $this->id_of_result($this->mark(array()));
        $this->post(array('status' => 'Half-day', 'check_in' => '09:00', 'check_out' => '13:00', 'remarks' => 'doctor'));
        $this->assertSame('success', $this->att->update($id));
        $row = $this->attendance_row($this->emp, '2026-04-06');
        $this->assertSame(array('Half-day', 'doctor', 'manual'), array($row['status'], $row['remarks'], $row['source']));
        $this->assertSame(300, (int) $row['early_leave_minutes'], '13:00 vs 18:00');
        $this->db->update('attendance', array('locked' => 1), array('id' => $id));
        $this->assertSame(t('att_locked'), $this->att->update($id));
        $this->assertSame(t('att_locked'), $this->att->delete($id));
        $this->assertSame(t('att_locked'), $this->att->rebuild($id));
        $this->db->update('attendance', array('locked' => 0), array('id' => $id));
        $this->assertSame('success', $this->att->delete($id));
        $this->assertNull($this->attendance_row($this->emp, '2026-04-06'));
    }

    public function test_bulk_mark_skips_or_overwrites()
    {
        $second = $this->insert_employee('A2');
        $locked = $this->insert_employee('A3');
        $this->db->insert('attendance', array('branch_id' => current_branch_id(), 'employee_id' => $locked, 'attendance_date' => '2026-04-06', 'status' => 'Absent',
            'approval_status' => 'Approved', 'source' => 'day_close', 'locked' => 1, 'created_at' => now_local()));
        $this->mark(array());
        $this->post(array('attendance_date' => '06-04-2026', 'status' => 'Holiday'));
        $this->assertSame('success<<<###>>>1/0/2', $this->att->bulk_mark(), 'one new, existing skipped');
        $this->assertSame('Holiday', $this->attendance_row($second, '2026-04-06')['status']);
        $this->post(array('attendance_date' => '06-04-2026', 'status' => 'Present', 'check_in' => '09:00', 'check_out' => '18:00', 'overwrite' => '1'));
        $this->assertSame('success<<<###>>>0/2/1', $this->att->bulk_mark(), 'overwrite, but never the locked row');
        $this->assertSame('Absent', $this->attendance_row($locked, '2026-04-06')['status']);
    }

    public function test_manual_punch_and_rebuild()
    {
        foreach (array('09:00', '18:00') as $time) {
            $this->post(array('employee_id' => (string) $this->emp, 'punch_date' => '06-04-2026', 'punch_time' => $time, 'punch_state' => '255'));
            $this->assertSame('success', $this->att->add_punch());
        }
        $this->assertSame(t('att_punch_exists'), $this->att->add_punch());
        $row = $this->attendance_row($this->emp, '2026-04-06');
        $this->assertSame(array('punch', '09:00:00', '18:00:00'), array($row['source'], $row['check_in'], $row['check_out']));
        $this->assertSame('manual', $this->db->get_where('attendance_punches', array('employee_id' => $this->emp))->row()->source);

        $this->post(array('status' => 'Absent'));
        $this->att->update((int) $row['id']);
        $this->post(array('employee_id' => (string) $this->emp, 'punch_date' => '06-04-2026', 'punch_time' => '12:00', 'punch_state' => '255'));
        $this->assertSame('success<<<###>>>manual', $this->att->add_punch(), 'stored, but the hand-edited day is kept');
        $this->assertSame('success', $this->att->rebuild((int) $row['id']));
        $this->assertSame('Present', $this->attendance_row($this->emp, '2026-04-06')['status']);
    }

    public function test_list_scope_and_missing_check_out_filter()
    {
        $other = $this->insert_employee('B1');
        foreach (array(array($this->emp, '2026-04-06', NULL), array($other, '2026-04-06', '18:00:00')) as $r) {
            $this->db->insert('attendance', array('branch_id' => current_branch_id(), 'employee_id' => $r[0], 'attendance_date' => $r[1], 'status' => 'Present',
                'check_in' => '09:00:00', 'check_out' => $r[2], 'approval_status' => 'Approved', 'source' => 'punch', 'created_at' => now_local()));
        }
        $this->post(array('draw' => '1', 'from_date' => '01-04-2026', 'to_date' => '10-04-2026'));
        $this->assertSame(2, $this->att->list_page()['recordsTotal']);
        $this->post(array('draw' => '1', 'from_date' => '01-04-2026', 'to_date' => '10-04-2026', 'missing_checkout' => '1'));
        $this->assertSame(array('A1'), array_column($this->att->list_page()['data'], 'code'));

        $viewer = $this->act_with_permissions('viewer', array('attendance_view'));
        set_current_user(array_merge($viewer, array('employee_id' => $other)));
        $this->post(array('draw' => '1', 'from_date' => '01-04-2026', 'to_date' => '10-04-2026'));
        $this->assertSame(array('B1'), array_column($this->att->list_page()['data'], 'code'), 'own rows only without show_all_attendance');
        $this->post(array('draw' => '1', 'from_date' => '01-01-2026', 'to_date' => '10-12-2026'));
        $this->assertArrayHasKey('error', $this->att->list_page(), 'range cap');
    }
}
