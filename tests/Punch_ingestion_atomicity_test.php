<?php
/** "The whole batch is one Tx::run(); any failure => HTTP 500 so the device keeps the data": a failure stores nothing at all. */
class Punch_ingestion_atomicity_test extends Hrm_test_case
{
    protected $use_transaction = FALSE;

    protected function tearDown(): void
    {
        $this->db->query('DROP TRIGGER IF EXISTS test_attendance_fail');
        $this->db->query('DELETE FROM attendance');
        $this->db->query('DELETE FROM attendance_punches');
        $this->db->query('DELETE FROM employee_shifts');
        $this->db->query("DELETE FROM employees WHERE employee_code = 'ATOM'");
        $this->db->query("DELETE FROM shifts WHERE shift_code = 'ATOM'");
        $this->db->query('DELETE FROM audit_log');
        parent::tearDown();
    }

    public function test_a_failure_while_building_the_day_stores_no_punch()
    {
        set_current_branch_id($this->first_branch_id());
        $emp = $this->insert_employee('ATOM', array('device_pin' => '4242', 'device_pin_from' => '2024-01-01'));
        $this->db->query("CREATE TRIGGER test_attendance_fail BEFORE INSERT ON attendance FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'");
        $r = (new Punch_ingestor())->ingest(array(array('device_pin' => '4242', 'punch_at' => '2026-04-06 09:00:00'), array('device_pin' => '4242', 'punch_at' => '2026-04-06 18:00:00')),
            array('branch_id' => $this->first_branch_id(), 'device_id' => 3, 'source' => 'device_push'));
        $this->assertFalse($r['ok'], 'the caller answers HTTP 500');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) AS c FROM attendance_punches')->row()->c, 'nothing half-stored: the device resends');

        $this->db->query('DROP TRIGGER test_attendance_fail');
        $r = (new Punch_ingestor())->ingest(array(array('device_pin' => '4242', 'punch_at' => '2026-04-06 09:00:00'), array('device_pin' => '4242', 'punch_at' => '2026-04-06 18:00:00')),
            array('branch_id' => $this->first_branch_id(), 'device_id' => 3, 'source' => 'device_push'));
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['stored'], 'the resend is accepted');
        $this->assertNotNull($this->attendance_row($emp, '2026-04-06'));
    }
}
