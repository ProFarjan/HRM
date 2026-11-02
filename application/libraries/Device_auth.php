<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Who is calling a device endpoint (P3, P17). Two kinds of caller:
 *  - the ZKTeco Windows Service, with an API key (X-API-Key, Authorization: Bearer, or ?api_key=): only sha256(key) is stored,
 *    the lookup is by hash and confirmed with hash_equals(); a key belongs to a branch and optionally to one device;
 *  - an ADMS device, which can only say its serial number: only serial numbers HR approved (devices.serial_number) are accepted,
 *    unknown ones land in devices_pending (capped, nothing else is stored), optional allowed_ips, rate limits per IP and per SN.
 * The branch of everything a device sends is the branch of the key / the device row; nothing in a request can change it.
 */
class Device_auth
{
    const KEY_PREFIX = 'hrmk_';
    const FAIL_LIMIT = 20;            // failed key checks per IP and minute
    const ADMS_IP_LIMIT = 600;        // ADMS calls per IP and minute (several devices may sit behind one office address)
    const ADMS_SN_LIMIT = 120;        // ADMS calls per serial number and minute (heartbeat every ~30 s + realtime punches)
    const PENDING_CAP = 50;
    const AUTO_DEVICE_CAP = 50;       // devices a branch-wide key may create by name

    /** a new random key; it is shown once and never stored */
    public static function generate_key()
    {
        return self::KEY_PREFIX . bin2hex(random_bytes(20));
    }

    public static function hash_key($key)
    {
        return hash('sha256', (string) $key);
    }

    /** the key a request carries: X-API-Key, then Authorization: Bearer, then ?api_key= ('' when none) */
    public static function key_from_request($input)
    {
        $key = $input->get_request_header('X-API-Key');
        if (is_string($key) && trim($key) !== '') {
            return trim($key);
        }
        $auth = $input->get_request_header('Authorization');
        if (is_string($auth) && preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
            return $m[1];
        }
        $q = $input->get('api_key');
        return is_string($q) ? trim($q) : '';
    }

    /**
     * @return array ok, code (HTTP), message, key (device_keys row), branch (branches row)
     */
    public static function authenticate_key($key, $ip)
    {
        $db = get_instance()->db;
        $fail = function ($code, $message) use ($ip) {
            Rate_limiter::hit('api_fail:' . $ip, 60);
            return array('ok' => FALSE, 'code' => $code, 'message' => $message, 'key' => NULL, 'branch' => NULL);
        };
        if (Rate_limiter::count('api_fail:' . $ip, 60) >= self::FAIL_LIMIT) {
            return array('ok' => FALSE, 'code' => 429, 'message' => 'Too many failed attempts. Try again in a minute.', 'key' => NULL, 'branch' => NULL);
        }
        if ($key === '' || strlen($key) > 200) {
            return $fail(401, 'API key required.');
        }
        $hash = self::hash_key($key);
        $row = $db->query('SELECT * FROM device_keys WHERE key_hash = ?', array($hash))->row_array();
        if ( ! $row || ! hash_equals((string) $row['key_hash'], $hash) || $row['revoked_at'] !== NULL) {
            return $fail(401, 'Invalid or revoked API key.');
        }
        $branch = $db->query('SELECT * FROM branches WHERE id = ?', array((int) $row['branch_id']))->row_array();
        if ( ! $branch || $branch['status'] !== 'Active') {
            return $fail(403, 'The branch of this key is not active.');
        }
        // last_used_at at most once a minute: the service calls every few seconds
        $db->query('UPDATE device_keys SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)',
            array(date('Y-m-d H:i:s'), (int) $row['id'], date('Y-m-d H:i:s', time() - 60)));
        return array('ok' => TRUE, 'code' => 200, 'message' => '', 'key' => $row, 'branch' => $branch);
    }

    /** the request now runs as this branch: branch id and timezone (device times are branch local times) */
    public static function activate_branch(array $branch)
    {
        set_current_branch_id((int) $branch['id']);
        if ( ! empty($branch['timezone']) && in_array($branch['timezone'], timezone_identifiers_list(), TRUE)) {
            date_default_timezone_set($branch['timezone']);
        }
    }

    /**
     * The device a service call belongs to: the one the key is bound to, else the windows_service device of the key's branch
     * with this name (created on first sight, at most AUTO_DEVICE_CAP per branch).
     * @return array ok, code, message, device
     */
    public static function service_device(array $key_row, $name, $ip)
    {
        $db = get_instance()->db;
        $branch_id = (int) $key_row['branch_id'];
        $error = function ($code, $message) {
            return array('ok' => FALSE, 'code' => $code, 'message' => $message, 'device' => NULL);
        };
        if ($key_row['device_id'] !== NULL) {
            $device = $db->query('SELECT * FROM devices WHERE id = ? AND branch_id = ?', array((int) $key_row['device_id'], $branch_id))->row_array();
            if ( ! $device) {
                return $error(403, 'The device of this key no longer exists.');
            }
        } else {
            $name = trim(preg_replace('/[\x00-\x1F<>]/u', '', (string) $name));
            $name = $name !== '' ? mb_substr($name, 0, 100) : 'Windows Service';
            $device = $db->query('SELECT * FROM devices WHERE branch_id = ? AND name = ?', array($branch_id, $name))->row_array();
            if ( ! $device) {
                $count = (int) $db->query('SELECT COUNT(*) AS c FROM devices WHERE branch_id = ? AND comm_mode = ?', array($branch_id, 'windows_service'))->row()->c;
                if ($count >= self::AUTO_DEVICE_CAP) {
                    return $error(422, 'Too many devices in this branch; register the device on the Devices page.');
                }
                $db->query('INSERT IGNORE INTO devices (branch_id, name, comm_mode, status, created_at) VALUES (?, ?, ?, ?, ?)',
                    array($branch_id, $name, 'windows_service', 'active', now_local()));
                $device = $db->query('SELECT * FROM devices WHERE branch_id = ? AND name = ?', array($branch_id, $name))->row_array();
            }
        }
        if ($device['comm_mode'] !== 'windows_service') {
            return $error(409, 'This device talks ADMS push, not the Windows Service.');
        }
        if ($device['status'] !== 'active') {
            return $error(403, 'This device is disabled.');
        }
        if ( ! self::ip_allowed($ip, $device['allowed_ips'])) {
            self::block_ip($device, $ip);
            return $error(403, 'This address is not allowed for the device.');
        }
        return array('ok' => TRUE, 'code' => 200, 'message' => '', 'device' => $device);
    }

    /**
     * ADMS caller by serial number.
     * @return array state (ok, invalid, rate, unknown, disabled, ip), code (HTTP when not ok), device (row + branch_timezone, or NULL), branch
     */
    public static function adms($sn, $ip)
    {
        $db = get_instance()->db;
        $result = function ($state, $code, $device = NULL, $branch = NULL) {
            return array('state' => $state, 'code' => $code, 'device' => $device, 'branch' => $branch);
        };
        if ( ! Adms_protocol::valid_serial($sn)) {
            return $result('invalid', 400);
        }
        if (Rate_limiter::hit('adms_ip:' . $ip, 60) > self::ADMS_IP_LIMIT || Rate_limiter::hit('adms_sn:' . $sn, 60) > self::ADMS_SN_LIMIT) {
            return $result('rate', 429);
        }
        $device = $db->query('SELECT * FROM devices WHERE serial_number = ?', array($sn))->row_array();
        if ( ! $device) {
            self::remember_pending($sn, $ip);
            return $result('unknown', 403);
        }
        $branch = $db->query('SELECT * FROM branches WHERE id = ?', array((int) $device['branch_id']))->row_array();
        if ( ! $branch || $branch['status'] !== 'Active' || $device['status'] !== 'active' || $device['comm_mode'] !== 'adms_push') {
            return $result('disabled', 403, $device, $branch);
        }
        if ( ! self::ip_allowed($ip, $device['allowed_ips'])) {
            self::block_ip($device, $ip);
            return $result('ip', 403, $device, $branch);
        }
        return $result('ok', 200, $device, $branch);
    }

    /** every call of a known device: when and from where it was seen (and what the call told about the device) */
    public static function seen(array $device, $ip, array $extra = array())
    {
        $set = array('last_seen_at' => now_local(), 'last_ip' => $ip);
        foreach (array('firmware', 'clock_offset_seconds', 'last_punch_at', 'last_upload_at') as $k) {
            if (array_key_exists($k, $extra)) {
                $set[$k] = $extra[$k];
            }
        }
        if (isset($set['last_upload_at'])) {
            $set['lost_punch_alert'] = 0;            // data arrived: the "possible lost punches" flag of the last health check is stale
        }
        get_instance()->db->where('id', (int) $device['id'])->update('devices', $set);
    }

    /** one row of device_logs (every device call is logged, with the reason when it was refused) */
    public static function log($device, $branch_id, $endpoint, $http_code, $records, $message, $ip, $direction = 'in')
    {
        $db = get_instance()->db;
        $debug = $db->db_debug;
        $db->db_debug = FALSE;                 // a log row must never break the answer to the device
        $db->insert('device_logs', array(
            'device_id' => $device ? (int) $device['id'] : NULL, 'branch_id' => $branch_id !== NULL ? (int) $branch_id : NULL,
            'direction' => $direction, 'endpoint' => mb_substr($endpoint, 0, 60), 'http_code' => (int) $http_code, 'records' => max(0, (int) $records),
            'ip' => mb_substr((string) $ip, 0, 45), 'message' => $message === NULL ? NULL : mb_substr((string) $message, 0, 255), 'created_at' => now_local(),
        ));
        $db->db_debug = $debug;
    }

    /** `allowed_ips`: comma separated addresses (IPv4 may be a /n range); empty = any address */
    public static function ip_allowed($ip, $allowed)
    {
        $allowed = trim((string) $allowed);
        if ($allowed === '') {
            return TRUE;
        }
        foreach (explode(',', $allowed) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (strcasecmp($entry, $ip) === 0) {
                return TRUE;
            }
            if (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $entry, $m) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $bits = (int) $m[2];
                $mask = $bits === 0 ? 0 : (-1 << (32 - min(32, $bits))) & 0xFFFFFFFF;
                if ((ip2long($ip) & $mask) === (ip2long($m[1]) & $mask)) {
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    /** device-local time to the branch's local time (equal unless HR gave the device a different timezone) */
    public static function to_branch_time($datetime, $device_timezone, $branch_timezone)
    {
        if (empty($device_timezone) || empty($branch_timezone) || $device_timezone === $branch_timezone) {
            return $datetime;
        }
        try {
            $dt = new DateTime($datetime, new DateTimeZone($device_timezone));
            $dt->setTimezone(new DateTimeZone($branch_timezone));
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return $datetime;
        }
    }

    protected static function block_ip(array $device, $ip)
    {
        get_instance()->db->query('UPDATE devices SET blocked_ip = ?, blocked_at = ? WHERE id = ?', array(mb_substr($ip, 0, 45), now_local(), (int) $device['id']));
    }

    /** an unknown serial number asked for service: remember it for HR (capped, nothing else is stored) */
    protected static function remember_pending($sn, $ip)
    {
        $db = get_instance()->db;
        $now = date('Y-m-d H:i:s');
        $db->query('UPDATE devices_pending SET hits = hits + 1, last_seen_at = ?, ip = ? WHERE serial_number = ?', array($now, $ip, $sn));
        if ($db->affected_rows() > 0) {
            return;
        }
        if ((int) $db->query('SELECT COUNT(*) AS c FROM devices_pending')->row()->c >= self::PENDING_CAP) {
            $db->query('DELETE FROM devices_pending WHERE last_seen_at < ?', array(date('Y-m-d H:i:s', time() - 30 * 86400)));
            if ((int) $db->query('SELECT COUNT(*) AS c FROM devices_pending')->row()->c >= self::PENDING_CAP) {
                return;
            }
        }
        $db->query('INSERT IGNORE INTO devices_pending (serial_number, ip, first_seen_at, last_seen_at, hits) VALUES (?, ?, ?, ?, 1)', array($sn, $ip, $now, $now));
    }
}
