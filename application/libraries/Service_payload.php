<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pure parser of the ZKTeco Windows Service JSON (`POST /api/v1/pull_attendance`, see CLAUDE.md "Attendance devices").
 * It turns the body into punches for Punch_ingestor and never decides anything about the branch (P3): ids a payload claims for
 * itself are only reported by claimed_branches() so the controller can log that they were ignored.
 */
class Service_payload
{
    const MAX_RECORDS = 10000;

    /**
     * @return array ok, error, test (bool), device_name, punches, invalid, total, command_results
     */
    public static function parse($body)
    {
        $out = array('ok' => FALSE, 'error' => '', 'test' => FALSE, 'device_name' => '', 'punches' => array(), 'invalid' => 0, 'total' => 0, 'command_results' => array());
        $data = json_decode((string) $body, TRUE);
        if ( ! is_array($data)) {
            $out['error'] = 'The body is not valid JSON.';
            return $out;
        }
        $out['test'] = ! empty($data['test']) && empty($data['records']);
        $out['device_name'] = isset($data['device_name']) && is_string($data['device_name']) ? trim(mb_substr($data['device_name'], 0, 100)) : '';
        $records = isset($data['records']) && is_array($data['records']) ? $data['records'] : array();
        if (count($records) > self::MAX_RECORDS) {
            $out['error'] = 'Too many records in one request (limit ' . self::MAX_RECORDS . ').';
            return $out;
        }
        $out['total'] = count($records);
        foreach ($records as $r) {
            $punch = is_array($r) ? self::record($r) : NULL;
            if ($punch === NULL) {
                $out['invalid']++;
            } else {
                $out['punches'][] = $punch;
            }
        }
        if (isset($data['command_results']) && is_array($data['command_results'])) {
            foreach ($data['command_results'] as $c) {
                if (is_array($c) && isset($c['id']) && is_numeric($c['id'])) {
                    $out['command_results'][] = array(
                        'id' => (int) $c['id'], 'success' => ! empty($c['success']),
                        'message' => isset($c['message']) && is_scalar($c['message']) ? mb_substr((string) $c['message'], 0, 255) : '',
                        'data' => isset($c['data']) ? $c['data'] : NULL,
                    );
                }
            }
        }
        $out['ok'] = TRUE;
        return $out;
    }

    /** branch / store ids the payload or its records claim (the service's custom_params may add them). For logging only. */
    public static function claimed_branches($body)
    {
        $data = json_decode((string) $body, TRUE);
        $claimed = array();
        if ( ! is_array($data)) {
            return $claimed;
        }
        $take = function ($row) use (&$claimed) {
            foreach (array('branch_id', 'store_id') as $k) {
                if (is_array($row) && isset($row[$k]) && is_scalar($row[$k]) && $row[$k] !== '') {
                    $claimed[(string) $row[$k]] = (string) $row[$k];
                }
            }
        };
        $take($data);
        if (isset($data['records']) && is_array($data['records'])) {
            foreach ($data['records'] as $r) {
                $take($r);
            }
        }
        return array_values($claimed);
    }

    /** one record -> array(device_pin, punch_at, punch_state, verify_mode), or NULL when the PIN or the time is unusable */
    public static function record(array $r)
    {
        $pin = NULL;
        foreach (array('user_id', 'id', 'uid') as $k) {
            if (isset($r[$k]) && is_scalar($r[$k]) && trim((string) $r[$k]) !== '') {
                $pin = trim((string) $r[$k]);
                break;
            }
        }
        $at = NULL;
        foreach (array('attendance_datetime', 'timestamp') as $k) {
            if (isset($r[$k]) && is_string($r[$k])) {
                $at = self::datetime($r[$k]);
                if ($at !== NULL) {
                    break;
                }
            }
        }
        if ($pin === NULL || ! preg_match('/^[0-9]{1,9}$/', $pin) || $at === NULL) {
            return NULL;
        }
        $state = isset($r['attendance_type']) && is_numeric($r['attendance_type']) ? (int) $r['attendance_type'] : 255;
        $verify = isset($r['auth_method']) && is_numeric($r['auth_method']) ? (int) $r['auth_method'] : NULL;
        return array('device_pin' => $pin, 'punch_at' => $at, 'punch_state' => in_array($state, array(0, 1, 4, 5), TRUE) ? $state : 255, 'verify_mode' => $verify);
    }

    /** 'Y-m-d H:i:s' (also with a T, with or without seconds / fraction; a trailing Z or offset is dropped: device time is local) */
    public static function datetime($text)
    {
        $text = trim($text);
        if ( ! preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(?::(\d{2}))?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/', $text, $m)) {
            return NULL;
        }
        $value = $m[1] . ' ' . $m[2] . ':' . (isset($m[3]) && $m[3] !== '' ? $m[3] : '00');
        $dt = DateTime::createFromFormat('!Y-m-d H:i:s', $value);
        return ($dt && $dt->format('Y-m-d H:i:s') === $value) ? $value : NULL;
    }
}
