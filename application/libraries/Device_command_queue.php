<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * `device_commands`: what HR (or an employee action) wants a device to do, delivered on the device's next call.
 * Windows Service devices get create_user / update_user / delete_user / list_users in the JSON `commands`; ADMS devices get the
 * command lines of Adms_protocol::command_line() on `getrequest`. queued -> sent (on delivery) -> done / failed (on the result).
 * A command that was sent but never answered is failed after SENT_TIMEOUT_MINUTES so the queue cannot jam.
 */
class Device_command_queue
{
    const SENT_TIMEOUT_MINUTES = 30;
    const SERVICE_TYPES = 'create_user,update_user,delete_user,list_users';
    const ALL_TYPES = 'create_user,update_user,delete_user,list_users,sync_time,clear_attlog,reboot';

    /** @return string[] the command types a device of this comm_mode understands */
    public static function types_for($comm_mode)
    {
        return explode(',', $comm_mode === 'adms_push' ? self::ALL_TYPES : self::SERVICE_TYPES);
    }

    /**
     * Queue a command. An identical command that is still queued is not queued twice.
     * @param array $device row of `devices` (id, comm_mode)
     * @return int|string the command id, or an error text
     */
    public static function enqueue(array $device, $type, array $payload, $user_id = NULL)
    {
        $db = get_instance()->db;
        if ( ! in_array($type, self::types_for($device['comm_mode']), TRUE)) {
            return t('dev_cmd_unsupported');
        }
        if ($device['status'] !== 'active') {
            return t('dev_disabled_cmd');
        }
        if (in_array($type, array('create_user', 'update_user', 'delete_user'), TRUE)) {
            if ( ! isset($payload['user_id']) || ! preg_match('/^[0-9]{1,9}$/', (string) $payload['user_id'])) {
                return t('dev_cmd_pin_required');
            }
            if ($type !== 'delete_user') {
                $payload['name'] = mb_substr(trim((string) (isset($payload['name']) ? $payload['name'] : '')), 0, 24);
                $payload['password'] = mb_substr(preg_replace('/\D/', '', (string) (isset($payload['password']) ? $payload['password'] : '')), 0, 8);
                $payload['role'] = (isset($payload['role']) && (int) $payload['role'] === 14) ? 14 : 0;
                $payload['card_no'] = mb_substr(preg_replace('/\D/', '', (string) (isset($payload['card_no']) ? $payload['card_no'] : '')), 0, 12);
            }
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $same = $db->query('SELECT id FROM device_commands WHERE device_id = ? AND status = ? AND command_type = ? AND payload = ? LIMIT 1',
            array((int) $device['id'], 'queued', $type, $json))->row_array();
        if ($same) {
            return (int) $same['id'];
        }
        $db->insert('device_commands', array('device_id' => (int) $device['id'], 'command_type' => $type, 'payload' => $json, 'status' => 'queued',
            'created_by' => $user_id, 'created_at' => now_local()));
        return (int) $db->insert_id();
    }

    /**
     * Queue the same command on every active device of a branch that understands it (employee exit: delete_user). Used inside the
     * caller's transaction; a device that refuses it is skipped, never an error.
     * @return int commands queued
     */
    public static function enqueue_for_branch($branch_id, $type, array $payload, $user_id = NULL)
    {
        $db = get_instance()->db;
        $n = 0;
        foreach ($db->query("SELECT id, comm_mode, status FROM devices WHERE branch_id = ? AND status = 'active'", array((int) $branch_id))->result_array() as $d) {
            if (is_int(self::enqueue($d, $type, $payload, $user_id))) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Take the queued commands of a device (oldest first) and mark them sent, in one transaction so two calls never get the same one.
     * @return array rows with `payload` decoded
     */
    public static function deliver($device_id)
    {
        $db = get_instance()->db;
        $device_id = (int) $device_id;
        $rows = array();
        Tx::run(function () use ($db, $device_id, &$rows) {
            $limit = date('Y-m-d H:i:s', time() - self::SENT_TIMEOUT_MINUTES * 60);
            $db->query('UPDATE device_commands SET status = ?, result = ?, done_at = ? WHERE device_id = ? AND status = ? AND sent_at < ?',
                array('failed', json_encode(array('message' => 'No answer from the device.')), now_local(), $device_id, 'sent', $limit));
            $rows = $db->query('SELECT * FROM device_commands WHERE device_id = ? AND status = ? ORDER BY id LIMIT 20 FOR UPDATE',
                array($device_id, 'queued'))->result_array();
            foreach ($rows as $r) {
                $db->query('UPDATE device_commands SET status = ?, sent_at = ? WHERE id = ?', array('sent', now_local(), (int) $r['id']));
            }
            return TRUE;
        });
        foreach ($rows as $i => $r) {
            $decoded = json_decode((string) $r['payload'], TRUE);
            $rows[$i]['payload'] = is_array($decoded) ? $decoded : array();
        }
        return $rows;
    }

    /** the device answered command $id (only a command of this device that was sent can be completed; a repeat is a no-op) */
    public static function complete($device_id, $command_id, $success, $result = NULL)
    {
        $db = get_instance()->db;
        $db->query('UPDATE device_commands SET status = ?, result = ?, done_at = ? WHERE id = ? AND device_id = ? AND status = ?',
            array($success ? 'done' : 'failed', $result === NULL ? NULL : json_encode($result, JSON_UNESCAPED_UNICODE), now_local(), (int) $command_id, (int) $device_id, 'sent'));
        return $db->affected_rows() === 1;
    }

    /** the shape the Windows Service expects in the JSON `commands` array */
    public static function for_service(array $row)
    {
        return array('id' => (int) $row['id'], 'type' => $row['command_type'], 'payload' => (object) $row['payload']);
    }
}
