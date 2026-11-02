<?php
/** G3 - Idempotency: a double submit (sequential or truly concurrent) takes effect once. Closes the double-click half of P9. */
class Idempotency_test extends Hrm_test_case
{
    protected $use_transaction = FALSE;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    protected function clean()
    {
        $this->db->query('DELETE FROM tx_probe');
        $this->db->query("DELETE FROM idempotency_keys WHERE action IN ('probe_pay', 'probe_other')");
    }

    protected function pay($token, $action = 'probe_pay', $fail = FALSE)
    {
        return Tx::run(function () use ($token, $action, $fail) {
            Idempotency::claim($token, $action);
            $this->db->query('INSERT INTO tx_probe (val) VALUES (?)', array('paid'));
            return $fail ? 'Account balance is too low.' : TRUE;
        });
    }

    protected function paid_rows()
    {
        return (int) $this->db->query('SELECT COUNT(*) AS c FROM tx_probe')->row()->c;
    }

    public function test_double_submit_pays_once_and_returns_the_first_result()
    {
        $token = Idempotency::new_token();
        $first = $this->pay($token);
        $second = $this->pay($token);
        $third = $this->pay($token);
        $this->assertSame(array(TRUE, 'success'), $first);
        $this->assertSame(array(TRUE, 'success'), $second, 'the retry gets the stored result');
        $this->assertSame(array(TRUE, 'success'), $third);
        $this->assertSame(1, $this->paid_rows(), 'the payment was made exactly once');
        $row = $this->db->get_where('idempotency_keys', array('token' => $token))->row_array();
        $this->assertSame('probe_pay', $row['action']);
        $this->assertSame(array('ok' => TRUE, 'message' => 'success'), json_decode($row['result_json'], TRUE));
    }

    public function test_a_failed_attempt_does_not_burn_the_token()
    {
        $token = Idempotency::new_token();
        list($ok, $message) = $this->pay($token, 'probe_pay', TRUE);
        $this->assertFalse($ok);
        $this->assertSame('Account balance is too low.', $message);
        $this->assertSame(0, $this->paid_rows());
        $this->assertSame(0, (int) $this->db->get_where('idempotency_keys', array('token' => $token))->num_rows(), 'the claim was rolled back with the failure');

        list($ok) = $this->pay($token);
        $this->assertTrue($ok, 'after fixing the problem the same form can be submitted again');
        $this->assertSame(1, $this->paid_rows());
    }

    public function test_a_token_cannot_be_reused_for_another_action()
    {
        $token = Idempotency::new_token();
        $this->pay($token);
        list($ok, $message) = $this->pay($token, 'probe_other');
        $this->assertFalse($ok);
        $this->assertStringContainsString('different action', $message);
        $this->assertSame(1, $this->paid_rows());
    }

    public function test_invalid_tokens_are_refused_before_anything_is_written()
    {
        foreach (array('', 'abc', str_repeat('a', 36), '12345678-1234-1234-1234-12345678901g', NULL) as $bad) {
            list($ok, $message) = $this->pay($bad);
            $this->assertFalse($ok, var_export($bad, TRUE));
            $this->assertStringContainsString('request token', $message);
        }
        $this->assertSame(0, $this->paid_rows());
    }

    public function test_claim_outside_a_transaction_is_a_programming_error()
    {
        $this->expectException(LogicException::class);
        Idempotency::claim(Idempotency::new_token(), 'probe_pay');
    }

    public function test_tokens_are_random_uuids()
    {
        $a = Idempotency::new_token();
        $this->assertTrue(Idempotency::valid_token($a));
        $this->assertNotSame($a, Idempotency::new_token());
    }

    public function test_concurrent_double_submit_pays_once()
    {
        $token = Idempotency::new_token();
        $start = microtime(TRUE) + 1.5;
        $procs = array();
        for ($i = 0; $i < 4; $i++) {
            $procs[$i] = proc_open(
                array(PHP_BINARY, HRM_ROOT . '/tests/support/seq_worker.php', 'idempotent', $token, (string) $start),
                array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes[$i], HRM_ROOT
            );
        }
        $results = array();
        foreach ($procs as $i => $proc) {
            $out = stream_get_contents($pipes[$i][1]);
            $err = stream_get_contents($pipes[$i][2]);
            $code = proc_close($proc);
            $results[] = array($out, $err, $code);
        }
        foreach ($results as $r) {
            $this->assertSame(0, $r[2], 'worker failed: ' . $r[0] . $r[1]);
            $decoded = json_decode($r[0], TRUE);
            $this->assertTrue($decoded[0], 'every concurrent caller sees success: ' . $r[0]);
        }
        $this->assertSame(1, $this->paid_rows(), 'four simultaneous submits of the same form paid once');
    }
}
