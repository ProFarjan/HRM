<?php
/** G3 - Tx::run(): rolls back on exception, error string, failed query, non-TRUE result; nested runs share one transaction. */

class Audit_probe_model extends MY_Model
{
    public function write($module, $id, $action, $old = NULL, $new = NULL)
    {
        return $this->audit($module, $id, $action, $old, $new);
    }
}

class Tx_test extends Hrm_test_case
{
    protected $use_transaction = FALSE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM tx_probe');
        $this->db->query("DELETE FROM audit_log WHERE module = 'tx_probe'");
    }

    protected function tearDown(): void
    {
        $this->db->query('DELETE FROM tx_probe');
        $this->db->query("DELETE FROM audit_log WHERE module = 'tx_probe'");
        parent::tearDown();
    }

    protected function rows()
    {
        return (int) $this->db->query('SELECT COUNT(*) AS c FROM tx_probe')->row()->c;
    }

    protected function insert($val)
    {
        $this->db->query('INSERT INTO tx_probe (val) VALUES (?)', array($val));
    }

    public function test_success_commits_and_reports_success()
    {
        list($ok, $message) = Tx::run(function () {
            $this->insert('a');
            $this->insert('b');
            return TRUE;
        });
        $this->assertTrue($ok);
        $this->assertSame('success', $message);
        $this->assertSame(2, $this->rows());
    }

    public function test_returning_a_string_rolls_everything_back_and_shows_the_string()
    {
        list($ok, $message) = Tx::run(function () {
            $this->insert('a');
            $this->insert('b');
            return 'Insufficient balance in the account.';
        });
        $this->assertFalse($ok);
        $this->assertSame('Insufficient balance in the account.', $message);
        $this->assertSame(0, $this->rows());
    }

    public function test_an_exception_rolls_back_and_is_reported()
    {
        list($ok, $message) = Tx::run(function () {
            $this->insert('a');
            throw new RuntimeException('boom');
        });
        $this->assertFalse($ok);
        $this->assertStringContainsString('boom', $message, 'detail is shown outside production');
        $this->assertSame(0, $this->rows());
    }

    public function test_tx_abort_message_is_shown_verbatim()
    {
        list($ok, $message) = Tx::run(function () {
            $this->insert('a');
            throw new Tx_abort('This leave overlaps another one.');
        });
        $this->assertFalse($ok);
        $this->assertSame('This leave overlaps another one.', $message);
        $this->assertSame(0, $this->rows());
    }

    public function test_a_failed_query_rolls_back_even_if_the_closure_returns_true()
    {
        $this->db->query('INSERT INTO tx_probe (id, val) VALUES (7, ?)', array('existing'));
        list($ok, $message) = Tx::run(function () {
            $this->insert('first');                                              // succeeds
            $this->db->query('INSERT INTO tx_probe (id, val) VALUES (7, ?)', array('dup'));   // duplicate key: fails
            return TRUE;                                                         // the programmer "forgot" to check
        });
        $this->assertFalse($ok);
        $this->assertStringContainsString('database error', strtolower($message));
        $this->assertSame(1, $this->rows(), 'only the pre-existing row; "first" was rolled back');
    }

    public function test_a_closure_that_returns_nothing_is_a_failure_not_a_commit()
    {
        list($ok) = Tx::run(function () {
            $this->insert('a');
        });
        $this->assertFalse($ok);
        $this->assertSame(0, $this->rows());
        list($ok) = Tx::run(function () {
            $this->insert('a');
            return FALSE;
        });
        $this->assertFalse($ok);
        $this->assertSame(0, $this->rows());
    }

    public function test_a_failed_inner_run_rolls_back_the_outer_one_too()
    {
        list($ok, $message) = Tx::run(function () {
            $this->insert('outer');
            list($inner_ok) = Tx::run(function () {
                $this->insert('inner');
                return 'inner failed';
            });
            unset($inner_ok);
            return TRUE;                                                         // outer ignores the inner result
        });
        $this->assertFalse($ok, 'half-applied work is never committed');
        $this->assertSame(0, $this->rows());
    }

    public function test_nested_runs_that_succeed_commit_together()
    {
        list($ok) = Tx::run(function () {
            $this->insert('outer');
            list($inner_ok) = Tx::run(function () {
                $this->insert('inner');
                return TRUE;
            });
            return $inner_ok;
        });
        $this->assertTrue($ok);
        $this->assertSame(2, $this->rows());
    }

    public function test_failure_after_a_nested_success_rolls_the_nested_work_back()
    {
        list($ok) = Tx::run(function () {
            Tx::run(function () {
                $this->insert('inner');
                return TRUE;
            });
            return 'outer failed afterwards';
        });
        $this->assertFalse($ok);
        $this->assertSame(0, $this->rows());
    }

    public function test_state_is_clean_after_a_failure_so_the_next_run_can_commit()
    {
        Tx::run(function () {
            $this->insert('a');
            return 'no';
        });
        list($ok) = Tx::run(function () {
            $this->insert('b');
            return TRUE;
        });
        $this->assertTrue($ok);
        $this->assertSame(1, $this->rows());
        $this->assertFalse(Tx::active());
    }

    public function test_db_debug_is_restored()
    {
        $this->db->db_debug = TRUE;
        Tx::run(function () {
            return TRUE;
        });
        $this->assertTrue($this->db->db_debug);
        $this->db->db_debug = FALSE;
    }

    public function test_audit_rows_follow_the_transaction()
    {
        $this->CI->load->model('Audit_probe_model');
        $admin = $this->db->query('SELECT id FROM users ORDER BY id LIMIT 1')->row()->id;
        $this->act_as((int) $admin);

        Tx::run(function () {
            $this->CI->Audit_probe_model->write('tx_probe', 5, 'pay', array('net' => 100), array('net' => 90, 'reason' => 'x'));
            return 'rolled back';
        });
        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) AS c FROM audit_log WHERE module = 'tx_probe'")->row()->c, 'a rolled back action leaves no audit row');

        list($ok) = Tx::run(function () {
            $this->CI->Audit_probe_model->write('tx_probe', 5, 'pay', array('net' => 100), array('net' => 90, 'reason' => 'বোনাস'));
            return TRUE;
        });
        $this->assertTrue($ok);
        $row = $this->db->query("SELECT * FROM audit_log WHERE module = 'tx_probe'")->row_array();
        $this->assertSame('5', $row['record_id']);
        $this->assertSame('pay', $row['action']);
        $this->assertSame((int) $admin, (int) $row['user_id']);
        $this->assertSame($this->first_branch_id(), (int) $row['branch_id']);
        $this->assertSame(array('net' => 100), json_decode($row['old_values'], TRUE));
        $this->assertSame(array('net' => 90, 'reason' => 'বোনাস'), json_decode($row['new_values'], TRUE));
        $this->assertNull($row['ip'], 'CLI has no client IP');
    }
}
