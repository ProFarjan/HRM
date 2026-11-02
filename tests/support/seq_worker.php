<?php
/**
 * Child process for Sequences_test / Idempotency_test: boots CI on hrm_test, waits for the start time, then works.
 *   php seq_worker.php sequence <branch_id> <name> <count> <start_at_microtime>
 *   php seq_worker.php idempotent <token> <start_at_microtime>
 */
define('HRM_ROOT', dirname(__DIR__, 2));
chdir(HRM_ROOT);
putenv('CI_ENV=testing');
$_SERVER['CI_ENV'] = 'testing';
putenv('TENANT_DB=hrm_test');
$mode = $argv[1];
$_SERVER['argv'] = array('index.php', 'cron', 'ping');
$_SERVER['argc'] = 3;
ob_start();
require HRM_ROOT . '/index.php';
ob_end_clean();
$CI =& get_instance();
$CI->db->db_debug = FALSE;

$start_at = (float) ($mode === 'sequence' ? $argv[5] : $argv[3]);
while (microtime(TRUE) < $start_at) {
    usleep(500);
}

if ($mode === 'sequence') {
    $branch_id = (int) $argv[2];
    $name = $argv[3];
    $out = array();
    for ($i = 0; $i < (int) $argv[4]; $i++) {
        list($ok, $msg) = Tx::run(function () use ($branch_id, $name, &$out) {
            $out[] = next_sequence_value($name, 0, $branch_id);
            usleep(random_int(0, 2000));                   // hold the row lock a moment so callers really queue
            return TRUE;
        });
        if ( ! $ok) {
            fwrite(STDERR, "FAILED: $msg\n");
            exit(3);
        }
    }
    echo json_encode($out);
    exit(0);
}

if ($mode === 'idempotent') {
    $token = $argv[2];
    list($ok, $msg) = Tx::run(function () use ($token, $CI) {
        Idempotency::claim($token, 'probe_pay');
        usleep(50000);                                     // the first caller holds the claim while the others arrive
        $CI->db->query('INSERT INTO tx_probe (val) VALUES (?)', array('paid-' . getmypid()));
        return TRUE;
    });
    echo json_encode(array($ok, $msg));
    exit($ok ? 0 : 3);
}
