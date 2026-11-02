<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ZKTeco ADMS push protocol (plain text, `/iclock/*`): pure parsers and reply builders, no database (see CLAUDE.md "ADMS push").
 */
class Adms_protocol
{
    const MAX_BODY_BYTES = 2097152;
    const MAX_LINES = 20000;

    /** a serial number as a device sends it; anything else is refused before it touches the database */
    public static function valid_serial($sn)
    {
        return is_string($sn) && preg_match('/^[A-Za-z0-9_-]{3,40}$/', $sn) === 1;
    }

    /** reply to the handshake `GET /iclock/cdata?SN=..&options=all` of a known, active device */
    public static function handshake($sn)
    {
        return "GET OPTION FROM: " . $sn . "\n"
            . "ErrorDelay=60\n"
            . "Delay=30\n"
            . "TransTimes=00:00;14:05\n"
            . "TransInterval=1\n"
            . "TransFlag=TransData AttLog\tOpLog\n"
            . "Realtime=1\n"
            . "Encrypt=0\n";
    }

    /**
     * Body of `table=ATTLOG`: one punch per line, tab or space separated, date and time in one field or two.
     *   PIN <TAB> YYYY-MM-DD HH:MM:SS <TAB> status <TAB> verify <TAB> workcode ...
     * @return array punches (device_pin, punch_at, punch_state, verify_mode, work_code), junk (lines skipped), lines
     */
    public static function parse_attlog($body, $max_lines = self::MAX_LINES)
    {
        $out = array('punches' => array(), 'junk' => 0, 'lines' => 0);
        $body = str_replace("\r", '', (string) $body);
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (++$out['lines'] > $max_lines) {
                $out['junk']++;
                continue;
            }
            $t = preg_split('/[\t ]+/', $line);
            if ( ! preg_match('/^[0-9]{1,9}$/', $t[0]) || count($t) < 3 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $t[1]) || ! preg_match('/^\d{2}:\d{2}:\d{2}$/', $t[2])) {
                $out['junk']++;
                continue;
            }
            $at = $t[1] . ' ' . $t[2];
            $dt = DateTime::createFromFormat('!Y-m-d H:i:s', $at);
            if ( ! $dt || $dt->format('Y-m-d H:i:s') !== $at) {
                $out['junk']++;
                continue;
            }
            $state = isset($t[3]) && is_numeric($t[3]) ? (int) $t[3] : 255;
            $out['punches'][] = array(
                'device_pin' => $t[0], 'punch_at' => $at, 'punch_state' => in_array($state, array(0, 1, 4, 5), TRUE) ? $state : 255,
                'verify_mode' => isset($t[4]) && is_numeric($t[4]) ? (int) $t[4] : NULL,
                'work_code' => isset($t[5]) && is_numeric($t[5]) && (int) $t[5] > 0 ? $t[5] : NULL,
            );
        }
        return $out;
    }

    /** body of `POST /iclock/devicecmd`: lines like `ID=7&Return=0&CMD=DATA` -> list of array(id, success) */
    public static function parse_command_results($body)
    {
        $out = array();
        foreach (explode("\n", str_replace("\r", '', (string) $body)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            parse_str($line, $f);
            if (isset($f['ID']) && is_string($f['ID']) && ctype_digit($f['ID'])) {
                $out[] = array('id' => (int) $f['ID'], 'success' => isset($f['Return']) && (string) $f['Return'] === '0', 'return' => isset($f['Return']) && is_string($f['Return']) ? $f['Return'] : '');
            }
        }
        return $out;
    }

    /** the firmware version out of `INFO=Ver,UserCount,...` of getrequest */
    public static function firmware($info)
    {
        if ( ! is_string($info) || $info === '') {
            return NULL;
        }
        $parts = explode(',', $info);
        $v = trim($parts[0]);
        return $v !== '' ? mb_substr($v, 0, 60) : NULL;
    }

    /** one queued command as the line `C:<id>:<COMMAND>` (see Device_command_queue for the payloads) */
    public static function command_line(array $cmd)
    {
        $p = isset($cmd['payload']) && is_array($cmd['payload']) ? $cmd['payload'] : array();
        $clean = function ($v) {
            return trim(str_replace(array("\t", "\r", "\n"), ' ', (string) $v));
        };
        switch ($cmd['command_type']) {
            case 'create_user':
            case 'update_user':
                $text = "DATA UPDATE USERINFO PIN=" . $clean(isset($p['user_id']) ? $p['user_id'] : '')
                    . "\tName=" . $clean(isset($p['name']) ? $p['name'] : '')
                    . "\tPri=" . (int) (isset($p['role']) ? $p['role'] : 0)
                    . "\tPasswd=" . $clean(isset($p['password']) ? $p['password'] : '')
                    . "\tCard=" . $clean(isset($p['card_no']) ? $p['card_no'] : '');
                break;
            case 'delete_user':
                $text = 'DATA DELETE USERINFO PIN=' . $clean(isset($p['user_id']) ? $p['user_id'] : '');
                break;
            case 'list_users':
                $text = 'DATA QUERY tablename=USERINFO,fielddesc=*,filter=*';
                break;
            case 'sync_time':
                $text = 'SET OPTIONS DateTime=' . $clean(isset($p['datetime']) ? $p['datetime'] : date('Y-m-d H:i:s'));
                break;
            case 'clear_attlog':
                $text = 'CLEAR LOG';
                break;
            case 'reboot':
                $text = 'REBOOT';
                break;
            default:
                return NULL;
        }
        return 'C:' . (int) $cmd['id'] . ':' . $text;
    }
}
