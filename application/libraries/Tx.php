<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * G3 - one transaction for every money or multi-row action.
 *
 *   list($ok, $message) = Tx::run(function () use (...) {
 *       Idempotency::claim($token, 'payroll_pay');
 *       ...writes...
 *       return TRUE;              // or a string (the error to show) -> everything is rolled back
 *   });
 *
 * Rolls back when the closure throws, returns a string (or anything other than TRUE), when any query failed
 * (trans_status), or when committing fails. Nested Tx::run() calls join the outermost transaction (no savepoints):
 * a failed inner run makes the whole outer run roll back, so half-applied work can never be committed.
 * Failed queries do not abort the request here: db_debug is switched off for the duration so the caller gets a result.
 */
class Tx
{
    /** @var int nesting depth (0 = no transaction open through Tx) */
    protected static $depth = 0;
    /** @var bool a nested run failed: the outermost run must roll back */
    protected static $poisoned = FALSE;
    /** @var array|null stored result of an idempotent replay */
    protected static $replay = NULL;

    /**
     * @param callable $fn
     * @return array array(bool $ok, string $message)
     */
    public static function run($fn)
    {
        $db = get_instance()->db;
        $outer = (self::$depth === 0);
        $old_debug = $db->db_debug;
        $db->db_debug = FALSE;

        if ($outer) {
            self::$poisoned = FALSE;
            self::$replay = NULL;
            Idempotency::reset();
            $db->trans_begin();
        }
        self::$depth++;

        $ok = FALSE;
        $message = '';
        try {
            $result = call_user_func($fn);
            if ($result === TRUE) {
                $ok = TRUE;
                $message = 'success';
            } elseif (is_string($result) && $result !== '') {
                $message = $result;
            } else {
                $message = 'The operation did not complete.';
                log_message('error', 'Tx::run(): closure returned ' . gettype($result) . ' instead of TRUE or an error string');
            }
        } catch (Idempotency_replay $r) {
            self::$replay = array($r->ok, $r->getMessage());
        } catch (Tx_abort $e) {
            $message = $e->getMessage();
        } catch (Throwable $e) {
            log_message('error', 'Tx::run(): ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $message = (ENVIRONMENT === 'production') ? 'The operation failed. Nothing was saved.' : 'The operation failed: ' . $e->getMessage();
        }

        self::$depth--;

        if ($ok && $db->trans_status() === FALSE) {
            $err = $db->error();
            log_message('error', 'Tx::run(): query failed: ' . (isset($err['message']) ? $err['message'] : ''));
            $ok = FALSE;
            $message = 'A database error occurred. Nothing was saved.';
        }
        if ( ! $ok) {
            self::$poisoned = TRUE;
        }

        if ( ! $outer) {
            $db->db_debug = $old_debug;
            return array($ok, $message);
        }

        // outermost run: decide commit or rollback
        if (self::$replay !== NULL) {
            $db->trans_rollback();
            $db->db_debug = $old_debug;
            $replay = self::$replay;
            self::$replay = NULL;
            return array((bool) $replay[0], (string) $replay[1]);
        }
        if ($ok && ! self::$poisoned) {
            Idempotency::store_result(TRUE, $message);          // same transaction as the work it describes
            if ($db->trans_status() !== FALSE && $db->trans_commit()) {
                $db->db_debug = $old_debug;
                return array(TRUE, $message);
            }
            $ok = FALSE;
            $message = 'A database error occurred. Nothing was saved.';
        } elseif ($ok) {
            $ok = FALSE;
            $message = 'The operation failed. Nothing was saved.';
        }
        $db->trans_rollback();
        $db->db_debug = $old_debug;
        return array(FALSE, $message);
    }

    /** true while inside Tx::run() */
    public static function active()
    {
        return self::$depth > 0;
    }

    /**
     * For tests that wrap a test in their own transaction: tell Tx a transaction is already open, so Tx::run()
     * joins it instead of committing. Pair with release_adopted().
     */
    public static function adopt_open_transaction()
    {
        self::$depth = 1;
        self::$poisoned = FALSE;
        self::$replay = NULL;
    }

    public static function release_adopted()
    {
        self::$depth = 0;
        self::$poisoned = FALSE;
        self::$replay = NULL;
    }
}
