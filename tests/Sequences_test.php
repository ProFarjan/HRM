<?php
/** G4 - sequential codes: formats, per-branch / per-period counters, gap-free on rollback, unique under real concurrency (P10). */
class Sequences_test extends Hrm_test_case
{
    protected $use_transaction = FALSE;
    /** @var int */
    protected $branch_id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch_id = $this->insert_branch('SQ' . random_int(1000, 9999));
    }

    protected function tearDown(): void
    {
        $this->db->query('DELETE FROM sequences WHERE branch_id = ?', array($this->branch_id));
        $this->db->query('DELETE FROM branches WHERE id = ?', array($this->branch_id));
        parent::tearDown();
    }

    protected function code($name, $date = NULL, $branch = NULL)
    {
        $code = NULL;
        $branch = $branch === NULL ? $this->branch_id : $branch;
        list($ok, $message) = Tx::run(function () use (&$code, $name, $date, $branch) {
            $code = next_code($name, $branch, $date);
            return TRUE;
        });
        $this->assertTrue($ok, $message);
        return $code;
    }

    public function test_codes_follow_the_documented_formats()
    {
        $year = date('Y');
        $this->assertSame("LV-$year-0001", $this->code('leave'));
        $this->assertSame("LV-$year-0002", $this->code('leave'));
        $this->assertSame('LN-0001', $this->code('loan'));
        $this->assertSame('ADV-0001', $this->code('advance'));
        $this->assertSame('ADV-0002', $this->code('advance'));
        $this->assertSame('PS-202611-0001', $this->code('payslip', '2026-11-05'));
        $this->assertSame('PS-202611-0002', $this->code('payslip', '2026-11-30'));
    }

    public function test_counters_restart_per_period_and_stay_separate_per_branch_and_name()
    {
        $this->assertSame('PS-202611-0001', $this->code('payslip', '2026-11-05'));
        $this->assertSame('PS-202612-0001', $this->code('payslip', '2026-12-01'), 'monthly counter restarts');
        $this->assertSame('PS-202611-0002', $this->code('payslip', '2026-11-20'), 'November continues where it stopped');
        $this->assertSame('LV-2027-0001', $this->code('leave', '2027-01-02'), 'yearly counter');
        $this->assertSame('LV-2026-0001', $this->code('leave', '2026-12-31'));
        $other = $this->insert_branch('SQX' . random_int(100, 999));
        try {
            $this->assertSame('ADV-0001', $this->code('advance', NULL, $other), 'another branch has its own counter');
            $this->assertSame('ADV-0001', $this->code('advance'));
        } finally {
            $this->db->query('DELETE FROM sequences WHERE branch_id = ?', array($other));
            $this->db->query('DELETE FROM branches WHERE id = ?', array($other));
        }
    }

    public function test_a_rolled_back_transaction_does_not_burn_a_number()
    {
        $this->assertSame('ADV-0001', $this->code('advance'));
        Tx::run(function () {
            next_code('advance', $this->branch_id);
            next_code('advance', $this->branch_id);
            return 'cancelled';
        });
        $this->assertSame('ADV-0002', $this->code('advance'), 'no gap after the rollback');
    }

    public function test_bad_input_is_refused()
    {
        $this->expectException(InvalidArgumentException::class);
        next_code('no_such_sequence', $this->branch_id);
    }

    public function test_a_valid_branch_is_required()
    {
        $this->expectException(InvalidArgumentException::class);
        next_sequence_value('advance', 0, 0);
    }

    public function test_sequence_names_are_validated_before_touching_the_database()
    {
        $this->expectException(InvalidArgumentException::class);
        next_sequence_value("x'; DROP TABLE users;--", 0, $this->branch_id);
    }

    public function test_concurrent_processes_get_unique_gap_free_numbers()
    {
        $workers = 6;
        $per_worker = 12;
        $start = microtime(TRUE) + 2.0;
        $procs = array();
        $pipes = array();
        for ($i = 0; $i < $workers; $i++) {
            $procs[$i] = proc_open(
                array(PHP_BINARY, HRM_ROOT . '/tests/support/seq_worker.php', 'sequence', (string) $this->branch_id, 'advance', (string) $per_worker, (string) $start),
                array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes[$i], HRM_ROOT
            );
        }
        $all = array();
        foreach ($procs as $i => $proc) {
            $out = stream_get_contents($pipes[$i][1]);
            $err = stream_get_contents($pipes[$i][2]);
            $code = proc_close($proc);
            $this->assertSame(0, $code, "worker $i: $out $err");
            $values = json_decode($out, TRUE);
            $this->assertIsArray($values, "worker $i output: $out $err");
            $all = array_merge($all, $values);
        }
        sort($all);
        $this->assertCount($workers * $per_worker, $all);
        $this->assertSame(range(1, $workers * $per_worker), $all, 'every number exactly once, no gaps (codes unique under concurrency, P10)');
    }
}
