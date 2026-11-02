<?php
/**
 * P4 / P5: raw punches are stored first and are the source of the day; breaks from punch pairs; re-send is a no-op; double taps
 * are debounced; rebuild after a manual edit; unmatched PINs are kept and later linked; a reused PIN never attaches new punches
 * to the leaver; night-shift punches belong to the shift's day; locked days are never changed.
 */
class Punch_ingestion_test extends Hrm_test_case
{
    /** @var Punch_ingestor */
    protected $ingestor;
    /** @var int */
    protected $emp;
    /** @var array */
    protected $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->act_as_new_user('boss', array(), TRUE);
        $this->db->update('branches', array('weekly_offs' => ''), array('id' => current_branch_id()));      // every day a working day
        $this->db->update('hr_settings', array('punch_debounce_seconds' => 60, 'deduct_break' => 0), array('id' => 1));
        hr_setting_reset_cache();
        $this->ingestor = new Punch_ingestor();
        $shift = $this->insert_shift('DAY', '09:00:00', '18:00:00', array('grace_time' => 10, 'working_hours' => 8));
        $this->emp = $this->insert_employee('P101', array('device_pin' => '101', 'device_pin_from' => '2024-01-01'));
        $this->assign_shift($this->emp, $shift);
        $this->device = array('branch_id' => current_branch_id(), 'device_id' => 7, 'source' => 'device_service');
    }

    protected function punches(array $times, $pin = '101', array $states = array())
    {
        $out = array();
        foreach ($times as $i => $at) {
            $out[] = array('device_pin' => $pin, 'punch_at' => $at, 'punch_state' => isset($states[$i]) ? $states[$i] : 255);
        }
        return $out;
    }

    protected function ingest(array $punches)
    {
        $r = $this->ingestor->ingest($punches, $this->device);
        $this->assertTrue($r['ok'], $r['message']);
        return $r;
    }

    public function test_four_punches_give_one_break_of_the_right_length()
    {
        $r = $this->ingest($this->punches(array('2026-04-06 08:55:00', '2026-04-06 13:00:00', '2026-04-06 13:45:00', '2026-04-06 18:05:00')));
        $this->assertSame(array(4, 4, 0, 1), array($r['received'], $r['stored'], $r['duplicates'], $r['rebuilt']));
        $row = $this->attendance_row($this->emp, '2026-04-06');
        $this->assertSame(array('Present', '08:55:00', '18:05:00', 'Approved', 'punch'), array($row['status'], $row['check_in'], $row['check_out'], $row['approval_status'], $row['source']));
        $this->assertSame(45, (int) $row['break_minutes']);
        $this->assertEquals(9.17, (float) $row['working_hours']);
        $this->assertSame(0, (int) $row['late_minutes']);
        $this->assertEquals(1.17, (float) $row['overtime_hours'], '550 - 480 min');
        $this->assertSame(4, $this->db->get_where('attendance_punches', array('employee_id' => $this->emp))->num_rows(), 'every raw punch is stored');
    }

    public function test_in_out_states_decide_the_breaks_when_present()
    {
        $this->ingest($this->punches(array('2026-04-06 09:00:00', '2026-04-06 11:00:00', '2026-04-06 11:20:00', '2026-04-06 13:00:00', '2026-04-06 14:00:00', '2026-04-06 18:00:00'),
            '101', array(0, 1, 0, 1, 0, 1)));
        $this->assertSame(80, (int) $this->attendance_row($this->emp, '2026-04-06')['break_minutes'], '20 + 60');
        $this->assertSame(0, Punch_ingestor::break_minutes(array(array('punch_at' => '2026-01-01 09:00:00', 'punch_state' => 255), array('punch_at' => '2026-01-01 18:00:00', 'punch_state' => 255))));
        $three = array(array('punch_at' => '2026-01-01 09:00:00', 'punch_state' => 255), array('punch_at' => '2026-01-01 13:00:00', 'punch_state' => 255), array('punch_at' => '2026-01-01 18:00:00', 'punch_state' => 255));
        $this->assertSame(0, Punch_ingestor::break_minutes($three), 'the last punch is the check-out, never the start of a break');
    }

    public function test_a_resent_batch_is_a_no_op()
    {
        $batch = $this->punches(array('2026-04-06 08:55:00', '2026-04-06 18:05:00'));
        $this->ingest($batch);
        $before = $this->attendance_row($this->emp, '2026-04-06');
        $r = $this->ingest($batch);
        $this->assertSame(array(0, 2, 0), array($r['stored'], $r['duplicates'], $r['rebuilt']));
        $this->assertSame(2, $this->db->get_where('attendance_punches', array('employee_id' => $this->emp))->num_rows());
        $this->assertSame($before, $this->attendance_row($this->emp, '2026-04-06'));
    }

    public function test_a_double_tap_is_stored_but_ignored()
    {
        $r = $this->ingest($this->punches(array('2026-04-06 08:55:00', '2026-04-06 08:55:30', '2026-04-06 08:56:10', '2026-04-06 18:00:00')));
        $this->assertSame(1, $r['ignored'], 'only the tap 30 s after a kept punch');
        $ignored = $this->db->get_where('attendance_punches', array('employee_id' => $this->emp, 'ignored' => 1))->result_array();
        $this->assertSame(array('2026-04-06 08:55:30'), array_column($ignored, 'punch_at'));
        $this->assertSame(3, $this->db->get_where('attendance_punches', array('employee_id' => $this->emp, 'ignored' => 0))->num_rows(), 'the 08:56:10 punch is 70 s after a kept one: kept');
        $this->assertSame(0, (int) $this->attendance_row($this->emp, '2026-04-06')['break_minutes'], 'the double tap did not create a fake break');
        $r = $this->ingest($this->punches(array('2026-04-06 18:00:40')));
        $this->assertSame(1, $r['ignored'], 'debounce also against punches stored earlier');
    }

    public function test_one_punch_is_a_check_in_only()
    {
        $this->ingest($this->punches(array('2026-04-06 09:25:00')));
        $row = $this->attendance_row($this->emp, '2026-04-06');
        $this->assertSame(array('Present', '09:25:00', NULL), array($row['status'], $row['check_in'], $row['check_out']));
        $this->assertSame(15, (int) $row['late_minutes']);
        $this->assertEquals(0, (float) $row['working_hours']);
    }

    public function test_a_manual_row_is_kept_until_rebuild_from_punches()
    {
        $this->db->insert('attendance', array('branch_id' => current_branch_id(), 'employee_id' => $this->emp, 'attendance_date' => '2026-04-06', 'status' => 'Absent',
            'approval_status' => 'Approved', 'source' => 'manual', 'created_at' => now_local()));
        $id = (int) $this->db->insert_id();
        $r = $this->ingest($this->punches(array('2026-04-06 09:00:00', '2026-04-06 18:00:00')));
        $this->assertSame(1, $r['kept_manual']);
        $this->assertSame('Absent', $this->attendance_row($this->emp, '2026-04-06')['status'], 'HR\'s edit is not overwritten');
        $this->assertSame(2, $this->db->get_where('attendance_punches', array('employee_id' => $this->emp))->num_rows(), 'but the punches are stored');

        $this->assertSame('rebuilt', $this->ingestor->rebuild_day($this->emp, '2026-04-06', TRUE));
        $row = $this->attendance_row($this->emp, '2026-04-06');
        $this->assertSame(array($id, 'Present', 'punch'), array((int) $row['id'], $row['status'], $row['source']));
    }

    public function test_locked_days_are_never_changed()
    {
        $this->db->insert('attendance', array('branch_id' => current_branch_id(), 'employee_id' => $this->emp, 'attendance_date' => '2026-03-31', 'status' => 'Absent',
            'approval_status' => 'Approved', 'source' => 'day_close', 'locked' => 1, 'created_at' => now_local()));
        $r = $this->ingest($this->punches(array('2026-03-31 09:00:00', '2026-03-31 18:00:00')));
        $this->assertSame(1, $r['locked']);
        $this->assertSame('Absent', $this->attendance_row($this->emp, '2026-03-31')['status']);
        $this->assertSame('locked', $this->ingestor->rebuild_day($this->emp, '2026-03-31', TRUE), 'not even a forced rebuild');
    }

    public function test_unmatched_pins_are_kept_and_processed_once_linked()
    {
        $r = $this->ingest($this->punches(array('2026-04-06 09:00:00', '2026-04-06 18:00:00'), '999'));
        $this->assertSame(2, $r['unmatched']);
        $this->assertSame(2, $this->db->query('SELECT COUNT(*) AS c FROM attendance_punches WHERE device_pin = ? AND employee_id IS NULL', array('999'))->row()->c + 0);

        $newcomer = $this->insert_employee('N999', array('device_pin' => '999', 'device_pin_from' => '2026-04-01'));
        $linked = $this->ingestor->reprocess_unmatched(current_branch_id());
        $this->assertTrue($linked['ok']);
        $this->assertSame(array(2, 1), array($linked['linked'], $linked['rebuilt']));
        $this->assertSame('Present', $this->attendance_row($newcomer, '2026-04-06')['status']);
        $this->assertSame(0, $this->db->query('SELECT COUNT(*) AS c FROM attendance_punches WHERE employee_id IS NULL')->row()->c + 0);
    }

    public function test_a_reused_pin_never_attaches_new_punches_to_the_leaver()
    {
        $leaver = $this->insert_employee('L555', array('status' => 'Resigned', 'exit_date' => '2026-03-31'));
        $this->db->insert('employee_pin_history', array('branch_id' => current_branch_id(), 'employee_id' => $leaver, 'device_pin' => '555',
            'valid_from' => '2025-01-01', 'valid_to' => '2026-03-31', 'created_at' => now_local()));
        $newcomer = $this->insert_employee('N555', array('device_pin' => '555', 'device_pin_from' => '2026-04-01'));

        $this->ingest($this->punches(array('2026-03-31 09:00:00', '2026-03-31 18:00:00', '2026-04-01 09:00:00', '2026-04-01 18:00:00'), '555'));
        $this->assertNotNull($this->attendance_row($leaver, '2026-03-31'), 'a late upload of the leaver\'s last day finds the leaver');
        $this->assertNull($this->attendance_row($leaver, '2026-04-01'));
        $this->assertNotNull($this->attendance_row($newcomer, '2026-04-01'), 'new punches go to the new holder');
        $this->assertNull($this->attendance_row($newcomer, '2026-03-31'));
    }

    public function test_night_shift_punches_belong_to_the_shift_day()
    {
        $night = $this->insert_shift('NGT', '22:00:00', '06:00:00', array('working_hours' => 8));
        $guard = $this->insert_employee('G1', array('device_pin' => '202', 'device_pin_from' => '2024-01-01'));
        $this->assign_shift($guard, $night);
        $this->ingest($this->punches(array('2026-04-06 21:55:00', '2026-04-07 06:10:00', '2026-04-07 10:30:00'), '202'));
        $row = $this->attendance_row($guard, '2026-04-06');
        $this->assertSame(array('21:55:00', '06:10:00', '2026-04-07 06:10:00'), array($row['check_in'], $row['check_out'], $row['last_punch_at']));
        $this->assertEquals(8.25, (float) $row['working_hours']);
        $this->assertEquals(0.25, (float) $row['overtime_hours']);
        $next = $this->attendance_row($guard, '2026-04-07');
        $this->assertSame('10:30:00', $next['check_in'], 'after end + 4 h it is the next day');
    }

    public function test_the_branch_comes_from_the_device_context_only()
    {
        $other = $this->insert_branch('PX1');
        $batch = $this->punches(array('2026-04-06 09:00:00'));
        $batch[0]['branch_id'] = $other;                 // a record claiming another branch is ignored (P3)
        $batch[0]['store_id'] = $other;
        $this->ingest($batch);
        $this->assertSame((int) current_branch_id(), (int) $this->db->get_where('attendance_punches', array('device_pin' => '101'))->row()->branch_id);
    }

    public function test_junk_is_counted_not_stored()
    {
        $r = $this->ingest(array(array('device_pin' => 'abc', 'punch_at' => '2026-04-06 09:00:00'), array('device_pin' => '101', 'punch_at' => '2026-02-30 09:00:00'),
            array('device_pin' => '101', 'punch_at' => 'yesterday')));
        $this->assertSame(array(3, 0), array($r['invalid'], $r['stored']));
    }
}
