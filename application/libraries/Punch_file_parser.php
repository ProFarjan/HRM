<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Pure parser of a device's punch export (Attendance > Import punches): the USB `.dat` of a ZKTeco device
 * (`PIN <TAB> YYYY-MM-DD HH:MM:SS <TAB> status <TAB> verify ...`, the same lines as the ADMS push) or a `.csv`
 * (pin, date and time in one column or two, optional state and verify mode, header row optional).
 */
class Punch_file_parser
{
    const MAX_ROWS = 50000;

    /**
     * @param string $text      file contents (UTF-8, a BOM is dropped)
     * @param string $ext       dat | txt | csv
     * @param string $date_format UI date format (setting date_format) accepted in CSV files besides Y-m-d
     * @return array punches (device_pin, punch_at, punch_state, verify_mode, work_code), invalid (rows skipped), rows, truncated
     */
    public static function parse($text, $ext, $date_format = 'd-m-Y')
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', (string) $text);
        if ($ext !== 'csv') {
            $r = Adms_protocol::parse_attlog($text, self::MAX_ROWS);
            $truncated = count($r['punches']) > self::MAX_ROWS;
            return array('punches' => array_slice($r['punches'], 0, self::MAX_ROWS), 'invalid' => $r['junk'], 'rows' => $r['lines'], 'truncated' => $truncated);
        }
        $out = array('punches' => array(), 'invalid' => 0, 'rows' => 0, 'truncated' => FALSE);
        $lines = preg_split('/\r\n|\n|\r/', $text);
        $delimiter = self::delimiter($lines);
        $first = TRUE;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', str_getcsv($line, $delimiter));
            if ($first) {
                $first = FALSE;
                if ( ! preg_match('/^[0-9]{1,9}$/', $cells[0])) {
                    continue;                                          // header row
                }
            }
            if (++$out['rows'] > self::MAX_ROWS) {
                $out['truncated'] = TRUE;
                break;
            }
            $punch = self::csv_row($cells, $date_format);
            if ($punch === NULL) {
                $out['invalid']++;
            } else {
                $out['punches'][] = $punch;
            }
        }
        return $out;
    }

    /** one CSV row -> punch, or NULL */
    public static function csv_row(array $c, $date_format)
    {
        if (count($c) < 2 || ! preg_match('/^[0-9]{1,9}$/', $c[0])) {
            return NULL;
        }
        // date and time in one cell, or in two
        $at = self::datetime($c[1], $date_format);
        $next = 2;
        if ($at === NULL && isset($c[2])) {
            $at = self::datetime($c[1] . ' ' . $c[2], $date_format);
            $next = 3;
        }
        if ($at === NULL) {
            return NULL;
        }
        $state = isset($c[$next]) && is_numeric($c[$next]) ? (int) $c[$next] : 255;
        $verify = isset($c[$next + 1]) && is_numeric($c[$next + 1]) ? (int) $c[$next + 1] : NULL;
        return array('device_pin' => $c[0], 'punch_at' => $at, 'punch_state' => in_array($state, array(0, 1, 4, 5), TRUE) ? $state : 255, 'verify_mode' => $verify, 'work_code' => NULL);
    }

    /** Y-m-d H:i[:s] or the UI date format + H:i[:s] -> 'Y-m-d H:i:s' (strict), else NULL */
    public static function datetime($text, $date_format)
    {
        $text = trim($text);
        $iso = Service_payload::datetime($text);
        if ($iso !== NULL) {
            return $iso;
        }
        foreach (array($date_format . ' H:i:s', $date_format . ' H:i', 'd/m/Y H:i:s', 'd/m/Y H:i') as $format) {
            $dt = DateTime::createFromFormat('!' . $format, $text);
            $errors = DateTime::getLastErrors();
            if ($dt && (! $errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $dt->format($format) === $text) {
                return $dt->format('Y-m-d H:i:s');
            }
        }
        return NULL;
    }

    protected static function delimiter(array $lines)
    {
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $best = ',';
            $max = -1;
            foreach (array(',', ';', "\t") as $d) {
                $n = substr_count($line, $d);
                if ($n > $max) {
                    $max = $n;
                    $best = $d;
                }
            }
            return $best;
        }
        return ',';
    }
}
