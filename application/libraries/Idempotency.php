<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * G3 - a double click or browser retry of a money form returns the first result instead of paying twice.
 *
 * Money forms carry a hidden `request_token` (Idempotency::new_token() when the page is rendered). Inside Tx::run():
 *
 *     Idempotency::claim($token, 'payroll_pay');     // first statement of the closure
 *
 * claim() inserts the token. The insert is part of the caller's transaction, so:
 *   - a concurrent duplicate waits on the unique key until the first request commits, then gets its stored result;
 *   - if the first request rolled back, the claim vanished with it and a retry is allowed;
 *   - on commit Tx stores the result (idempotency_keys.result_json) in the same transaction.
 */
class Idempotency
{
    /** @var string|null token claimed in the current transaction */
    protected static $pending = NULL;

    public static function new_token()
    {
        return hrm_uuid();
    }

    /** @return bool whether $token looks like something we issued (uuid) */
    public static function valid_token($token)
    {
        return is_string($token) && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $token);
    }

    /**
     * @throws Tx_abort            invalid token, or the token was used for another action / by another user
     * @throws Idempotency_replay  already done: Tx::run() returns the stored result
     */
    public static function claim($token, $action)
    {
        if ( ! Tx::active()) {
            throw new LogicException('Idempotency::claim() must be called inside Tx::run()');
        }
        if ( ! self::valid_token($token)) {
            throw new Tx_abort('Invalid or missing request token. Reload the page and try again.');
        }
        $db = get_instance()->db;
        $db->query(
            'INSERT IGNORE INTO idempotency_keys (token, action, user_id, result_json, created_at) VALUES (?, ?, ?, NULL, ?)',
            array($token, (string) $action, current_user_id(), now_local())
        );
        if ($db->affected_rows() === 1) {
            self::$pending = $token;
            return TRUE;
        }
        // duplicate: read with a (shared) locking read so we see the latest committed row, not an older snapshot.
        // Shared, not FOR UPDATE: every waiting duplicate already holds a shared lock from INSERT IGNORE, and asking for an
        // exclusive one would deadlock them against each other.
        $row = $db->query('SELECT action, user_id, result_json FROM idempotency_keys WHERE token = ? LOCK IN SHARE MODE', array($token))->row_array();
        if ( ! $row) {
            throw new Tx_abort('The request could not be registered. Try again.');
        }
        $same_user = ($row['user_id'] === NULL ? NULL : (int) $row['user_id']) === current_user_id();
        if ($row['action'] !== (string) $action || ! $same_user) {
            throw new Tx_abort('This request token was already used for a different action.');
        }
        $stored = ($row['result_json'] === NULL) ? NULL : json_decode($row['result_json'], TRUE);
        if ( ! is_array($stored) || ! array_key_exists('ok', $stored)) {
            throw new Tx_abort('This request is already being processed.');
        }
        throw new Idempotency_replay($stored['ok'], isset($stored['message']) ? $stored['message'] : 'success');
    }

    /** called by Tx just before commit */
    public static function store_result($ok, $message)
    {
        if (self::$pending === NULL) {
            return;
        }
        get_instance()->db->query(
            'UPDATE idempotency_keys SET result_json = ? WHERE token = ?',
            array(json_encode(array('ok' => (bool) $ok, 'message' => (string) $message)), self::$pending)
        );
        self::$pending = NULL;
    }

    public static function reset()
    {
        self::$pending = NULL;
    }
}
