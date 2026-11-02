<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Punch to day (CLAUDE.md "Punch to day"; fixes P4, P5). Used by the device endpoints (step 5), imports and manual punches.
 *
 *   $result = (new Punch_ingestor())->ingest($punches, $device);   // $device: branch_id, device_id, source (from the key / device row)
 *   if ( ! $result['ok']) { -> HTTP 500, the device / service keeps the data }
 *
 * 1. every punch is stored first (INSERT IGNORE on the unique key: a re-sent punch is a no-op) - raw punches are never edited;
 * 2. debounce: within punch_debounce_seconds of the previous kept punch of the same PIN => stored with ignored = 1;
 * 3. the owner of a PIN on the punch date: employees.device_pin (from device_pin_from), else employee_pin_history; unmatched
 *    punches keep employee_id NULL until the PIN is linked (reprocess_unmatched());
 * 4. each touched employee/day is rebuilt from all its non-ignored punches (rebuild_day()); a punch up to the previous day's
 *    night-shift end + 4 h belongs to that previous day;
 * 5. rows HR made by hand (source manual / self) are kept unless a rebuild is forced; locked rows are never changed;
 * 6. the whole batch is one Tx::run().
 *
 * The branch of every punch comes from $device (the authenticated key / device row, or the logged-in user for manual
 * punches), never from a punch (P3).
 */
class Punch_ingestor
{
    const NIGHT_TAIL_MINUTES = 240;
    /** remark on a split-shift day with a duty period that has no punches or no check-out */
    const REMARK_INCOMPLETE = 'incomplete duty period';

    /** @var Shift_calculator */
    protected $calc;

    public function __construct()
    {
        $this->calc = new Shift_calculator();
    }

    /**
     * @param array $punches list of array(device_pin, punch_at 'Y-m-d H:i:s', punch_state, verify_mode, work_code[, employee_id for manual])
     * @param array $device  the authenticated source of the batch: branch_id (required), device_id (NULL for manual), source,
     *                       created_by - for manual punches the logged-in user's branch
     * @return array ok, message, received, stored, duplicates, ignored, unmatched, invalid, rebuilt, kept_manual, locked
     */
    public function ingest(array $punches, array $device)
    {
        $branch_id = isset($device['branch_id']) ? (int) $device['branch_id'] : 0;
        $device_id = isset($device['device_id']) && $device['device_id'] !== NULL ? (int) $device['device_id'] : NULL;
        $source = isset($device['source']) ? $device['source'] : 'manual';
        $stats = array('ok' => FALSE, 'message' => '', 'received' => count($punches), 'stored' => 0, 'duplicates' => 0, 'ignored' => 0,
            'unmatched' => 0, 'invalid' => 0, 'rebuilt' => 0, 'kept_manual' => 0, 'locked' => 0);
        if ($branch_id < 1 || ! in_array($source, array('device_push', 'device_service', 'manual', 'import', 'mobile'), TRUE)) {
            $stats['message'] = 'Invalid ingestion context.';
            return $stats;
        }

        // normalise and sort; drop junk (counted)
        $clean = array();
        foreach ($punches as $p) {
            $pin = isset($p['device_pin']) && $p['device_pin'] !== '' && $p['device_pin'] !== NULL ? trim((string) $p['device_pin']) : NULL;
            $at = isset($p['punch_at']) ? trim((string) $p['punch_at']) : '';
            $dt = DateTime::createFromFormat('!Y-m-d H:i:s', $at);
            $employee = isset($p['employee_id']) ? (int) $p['employee_id'] : 0;
            if ( ! $dt || $dt->format('Y-m-d H:i:s') !== $at || ($pin === NULL && $employee < 1) || ($pin !== NULL && ! preg_match('/^[0-9]{1,9}$/', $pin))) {
                $stats['invalid']++;
                continue;
            }
            $state = isset($p['punch_state']) && is_numeric($p['punch_state']) ? (int) $p['punch_state'] : 255;
            $clean[] = array(
                'device_pin' => $pin, 'punch_at' => $at, 'employee_id' => $employee > 0 ? $employee : NULL,
                'punch_state' => in_array($state, array(0, 1, 4, 5), TRUE) ? $state : 255,
                'verify_mode' => isset($p['verify_mode']) && is_numeric($p['verify_mode']) ? (int) $p['verify_mode'] : NULL,
                'work_code' => isset($p['work_code']) && $p['work_code'] !== '' ? mb_substr((string) $p['work_code'], 0, 20) : NULL,
            );
        }
        usort($clean, function ($a, $b) { return strcmp($a['punch_at'], $b['punch_at']); });

        $created_by = isset($device['created_by']) ? $device['created_by'] : NULL;
        list($ok, $message) = Tx::run(function () use ($clean, $branch_id, $device_id, $source, $created_by, &$stats) {
            $db = get_instance()->db;
            $touched = $this->store($clean, $branch_id, $device_id, $source, $created_by, $stats);
            $this->rebuild_touched($touched, $stats);
            return $db->trans_status() === FALSE ? 'Storing the punches failed.' : TRUE;
        });
        $stats['ok'] = $ok;
        $stats['message'] = $message;
        return $stats;
    }

    /**
     * Give unmatched punches of a branch their owner (after HR linked / assigned the PIN) and build the days.
     * @return array ok, message, linked, rebuilt, ...
     */
    public function reprocess_unmatched($branch_id, $pin = NULL)
    {
        $stats = array('ok' => FALSE, 'message' => '', 'linked' => 0, 'rebuilt' => 0, 'kept_manual' => 0, 'locked' => 0);
        list($ok, $message) = Tx::run(function () use ($branch_id, $pin, &$stats) {
            $db = get_instance()->db;
            $db->select('id, device_pin, punch_at, ignored')->from('attendance_punches')->where('branch_id', (int) $branch_id)
                ->where('employee_id IS NULL', NULL, FALSE)->where('device_pin IS NOT NULL', NULL, FALSE);
            if ($pin !== NULL) {
                $db->where('device_pin', (string) $pin);
            }
            $rows = $db->get()->result_array();
            if ( ! $rows) {
                return TRUE;
            }
            $owners = $this->owners((int) $branch_id, array_unique(array_column($rows, 'device_pin')));
            $by_owner = array();
            $touched = array();
            foreach ($rows as $r) {
                $owner = $this->owner_of($owners, $r['device_pin'], substr($r['punch_at'], 0, 10));
                if ($owner === NULL) {
                    continue;
                }
                $by_owner[$owner][] = (int) $r['id'];
                if ( ! (int) $r['ignored']) {
                    $touched[$owner][] = $r['punch_at'];
                }
            }
            foreach ($by_owner as $owner => $ids) {
                $db->where_in('id', $ids)->where('employee_id IS NULL', NULL, FALSE)->update('attendance_punches', array('employee_id' => $owner));
                $stats['linked'] += $db->affected_rows();
            }
            $this->rebuild_touched($touched, $stats);
            return TRUE;
        });
        $stats['ok'] = $ok;
        $stats['message'] = $message;
        return $stats;
    }

    /**
     * Rebuild one employee/day from its non-ignored punches.
     * @return string rebuilt | empty | kept_manual | locked
     */
    public function rebuild_day($employee_id, $date, $force = FALSE)
    {
        $db = get_instance()->db;
        $employee_id = (int) $employee_id;
        $prev = date('Y-m-d', strtotime($date . ' -1 day'));
        $plan = $this->calc->plan(array($employee_id), $prev, $date);
        if ( ! isset($plan[$employee_id][$date])) {
            return 'empty';
        }
        $today_plan = $plan[$employee_id][$date];
        $prev_plan = $plan[$employee_id][$prev];
        list($start, $end) = $this->window($date, $prev_plan['shift'], $today_plan['shift']);

        $existing = $db->query('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? FOR UPDATE', array($employee_id, $date))->row_array();
        if ($existing && (int) $existing['locked'] === 1) {
            return 'locked';
        }
        if ($existing && in_array($existing['source'], array('manual', 'self'), TRUE) && ! $force) {
            return 'kept_manual';
        }
        $punches = $db->query(
            'SELECT punch_at, punch_state FROM attendance_punches WHERE employee_id = ? AND ignored = 0 AND punch_at BETWEEN ? AND ? ORDER BY punch_at, id',
            array($employee_id, $start, $end)
        )->result_array();
        if ( ! $punches) {
            if ($existing && ($existing['source'] === 'punch' || $force)) {
                $db->where('id', (int) $existing['id'])->delete('attendance');     // the day close fills it again if the day is closed
            }
            return 'empty';
        }

        $first = $punches[0]['punch_at'];
        $last = count($punches) > 1 ? $punches[count($punches) - 1]['punch_at'] : NULL;
        $shift = $today_plan['shift'];
        $off_day = $today_plan['day_type'] !== Shift_calculator::DAY_WORKING;
        $periods = NULL;
        if (Shift_calculator::is_split($shift)) {
            // a split shift (10:00-14:00 + 18:00-22:00): each duty period is measured on its own, the gap between them is not worked time
            $m = Shift_calculator::split_metrics($shift, $date, array_column($punches, 'punch_at'), array('off_day' => $off_day));
            $periods = $m['periods'];
            $check_in = $m['check_in'];
            $check_out = $m['check_out'];
            $break = $m['break_minutes'];
            $remarks = $m['incomplete'] > 0 ? self::REMARK_INCOMPLETE : NULL;
        } else {
            $m = Shift_calculator::metrics($shift, substr($first, 11), $last === NULL ? NULL : substr($last, 11), array('off_day' => $off_day));
            $check_in = substr($first, 11);
            $check_out = $last === NULL ? NULL : substr($last, 11);
            $break = self::break_minutes($punches);
            $remarks = NULL;
        }
        $row = array(
            'branch_id' => (int) $db->query('SELECT branch_id FROM employees WHERE id = ?', array($employee_id))->row()->branch_id,
            'shift_id' => $shift ? (int) $shift['id'] : NULL,
            'check_in' => $check_in, 'check_out' => $check_out,
            'first_punch_at' => $first, 'last_punch_at' => $last === NULL ? $first : $last,
            'status' => 'Present', 'approval_status' => 'Approved', 'approval_note' => NULL,
            'working_hours' => $m['working_hours'], 'break_minutes' => $break,
            'late_minutes' => $m['late_minutes'], 'early_leave_minutes' => $m['early_leave_minutes'],
            'overtime_hours' => $m['overtime_hours'], 'source' => 'punch', 'remarks' => $remarks, 'updated_at' => now_local(),
        );
        if ($existing) {
            $db->where('id', (int) $existing['id'])->update('attendance', $row);
            $attendance_id = (int) $existing['id'];
        } else {
            $db->insert('attendance', array_merge($row, array('employee_id' => $employee_id, 'attendance_date' => $date, 'created_at' => now_local())));
            $attendance_id = (int) $db->insert_id();
        }
        Attendance_periods::store($attendance_id, $periods);
        return 'rebuilt';
    }

    /**
     * Break minutes from OUT -> next IN pairs. With in/out states on every punch they decide; otherwise punches alternate
     * (in, out, in, out, ...) and the last punch is the check-out, never the start of a break.
     */
    public static function break_minutes(array $punches)
    {
        $n = count($punches);
        if ($n < 3) {
            return 0;
        }
        $states = array_map('intval', array_column($punches, 'punch_state'));
        $explicit = count(array_diff($states, array(0, 1))) === 0;
        $total = 0;
        for ($i = 0; $i < $n - 2; $i++) {
            $is_out = $explicit ? ($states[$i] === 1 && $states[$i + 1] === 0) : ($i % 2 === 1);
            if ($is_out) {
                $total += (int) round((strtotime($punches[$i + 1]['punch_at']) - strtotime($punches[$i]['punch_at'])) / 60);
            }
        }
        return min($total, 65535);
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** store the punches; returns employee_id => list of kept punch datetimes */
    protected function store(array $clean, $branch_id, $device_id, $source, $created_by, array &$stats)
    {
        $db = get_instance()->db;
        if ( ! $clean) {
            return array();
        }
        $debounce = max(0, (int) hr_setting('punch_debounce_seconds', 60));
        $pins = array_values(array_unique(array_filter(array_column($clean, 'device_pin'), 'strlen')));
        $owners = $pins ? $this->owners($branch_id, $pins) : array();

        // kept punches around the batch, per PIN (debounce looks at the previous kept punch)
        $kept = array();
        if ($pins && $debounce > 0) {
            $from = date('Y-m-d H:i:s', strtotime($clean[0]['punch_at']) - $debounce);
            $to = $clean[count($clean) - 1]['punch_at'];
            foreach ($db->select('device_pin, punch_at')->from('attendance_punches')->where('branch_id', $branch_id)->where_in('device_pin', $pins)
                        ->where('ignored', 0)->where('punch_at >=', $from)->where('punch_at <=', $to)->get()->result_array() as $k) {
                $kept[$k['device_pin']][] = strtotime($k['punch_at']);
            }
        }

        $touched = array();
        $now = now_local();
        foreach ($clean as $p) {
            $employee = $p['employee_id'];
            if ($employee === NULL) {
                $employee = $this->owner_of($owners, $p['device_pin'], substr($p['punch_at'], 0, 10));
            }
            $ignored = 0;
            $ts = strtotime($p['punch_at']);
            if ($p['device_pin'] !== NULL && $debounce > 0 && isset($kept[$p['device_pin']])) {
                $previous = NULL;
                foreach ($kept[$p['device_pin']] as $k) {
                    if ($k <= $ts && ($previous === NULL || $k > $previous)) {
                        $previous = $k;
                    }
                }
                if ($previous !== NULL && $ts - $previous < $debounce) {
                    $ignored = 1;
                }
            }
            if ($device_id === NULL && $employee !== NULL
                && $db->query('SELECT 1 FROM attendance_punches WHERE employee_id = ? AND punch_at = ? LIMIT 1', array($employee, $p['punch_at']))->num_rows()) {
                $stats['duplicates']++;                                  // a manual punch that is already there
                continue;
            }
            $db->query(
                'INSERT IGNORE INTO attendance_punches (branch_id, employee_id, device_pin, device_id, punch_at, punch_state, verify_mode, source, work_code, ignored, received_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array($branch_id, $employee, $p['device_pin'], $device_id, $p['punch_at'], $p['punch_state'], $p['verify_mode'], $source, $p['work_code'], $ignored, $now, $created_by)
            );
            if ($db->affected_rows() !== 1) {
                $stats['duplicates']++;                                  // re-sent punch: a no-op
                continue;
            }
            $stats['stored']++;
            if ($ignored) {
                $stats['ignored']++;
                continue;
            }
            if ($p['device_pin'] !== NULL) {
                $kept[$p['device_pin']][] = $ts;
            }
            if ($employee === NULL) {
                $stats['unmatched']++;
                continue;
            }
            $touched[$employee][] = $p['punch_at'];
        }
        return $touched;
    }

    /** rebuild every day touched by these punches (one plan() for all of them to place night-shift punches) */
    protected function rebuild_touched(array $touched, array &$stats)
    {
        if ( ! $touched) {
            return;
        }
        $all = array();
        foreach ($touched as $list) {
            foreach ($list as $at) {
                $all[] = substr($at, 0, 10);
            }
        }
        $plan = $this->calc->plan(array_keys($touched), date('Y-m-d', strtotime(min($all) . ' -1 day')), max($all));
        $days = array();
        foreach ($touched as $employee => $list) {
            foreach ($list as $at) {
                $days[$employee . '|' . $this->attendance_date($plan[$employee], $at)] = array($employee, $this->attendance_date($plan[$employee], $at));
            }
        }
        foreach ($days as $pair) {
            $result = $this->rebuild_day($pair[0], $pair[1]);
            if ($result === 'rebuilt') {
                $stats['rebuilt']++;
            } elseif ($result === 'kept_manual') {
                $stats['kept_manual']++;
            } elseif ($result === 'locked') {
                $stats['locked']++;
            }
        }
    }

    /** the attendance date of a punch: the previous day when it falls in the tail of the previous day's night shift */
    protected function attendance_date(array $employee_plan, $punch_at)
    {
        $date = substr($punch_at, 0, 10);
        $prev = date('Y-m-d', strtotime($date . ' -1 day'));
        if (isset($employee_plan[$prev]) && $employee_plan[$prev]['shift'] && (int) $employee_plan[$prev]['shift']['is_night'] === 1) {
            $tail = Shift_calculator::minutes($employee_plan[$prev]['shift']['end_time']) + self::NIGHT_TAIL_MINUTES;
            if (Shift_calculator::minutes(substr($punch_at, 11)) <= $tail) {
                return $prev;
            }
        }
        return $date;
    }

    /** [start, end] datetimes of the punches that belong to $date */
    protected function window($date, $prev_shift, $shift)
    {
        $start = $date . ' 00:00:00';
        if ($prev_shift && (int) $prev_shift['is_night'] === 1) {
            $tail = Shift_calculator::minutes($prev_shift['end_time']) + self::NIGHT_TAIL_MINUTES;
            $start = date('Y-m-d H:i:s', strtotime($date . ' 00:00:00') + $tail * 60 + 60);
        }
        $end = $date . ' 23:59:59';
        if ($shift && (int) $shift['is_night'] === 1) {
            $tail = Shift_calculator::minutes($shift['end_time']) + self::NIGHT_TAIL_MINUTES;
            $end = date('Y-m-d H:i:s', strtotime($date . ' 00:00:00') + 86400 + $tail * 60 + 59);
        }
        return array($start, $end);
    }

    /** current holders and history of the PINs in a branch (2 queries) */
    protected function owners($branch_id, array $pins)
    {
        $db = get_instance()->db;
        $out = array('current' => array(), 'history' => array());
        if ( ! $pins) {
            return $out;
        }
        foreach ($db->select('id, device_pin, device_pin_from')->from('employees')->where('branch_id', $branch_id)->where_in('device_pin', $pins)->get()->result_array() as $e) {
            $out['current'][$e['device_pin']] = $e;
        }
        foreach ($db->select('employee_id, device_pin, valid_from, valid_to')->from('employee_pin_history')->where('branch_id', $branch_id)->where_in('device_pin', $pins)->get()->result_array() as $h) {
            $out['history'][$h['device_pin']][] = $h;
        }
        return $out;
    }

    /** owner of $pin on $date from owners(); NULL = unmatched (same rule as Employees_model::pin_owner) */
    protected function owner_of(array $owners, $pin, $date)
    {
        if ($pin === NULL) {
            return NULL;
        }
        if (isset($owners['current'][$pin])) {
            $c = $owners['current'][$pin];
            if ($c['device_pin_from'] === NULL || $c['device_pin_from'] <= $date) {
                return (int) $c['id'];
            }
        }
        if (isset($owners['history'][$pin])) {
            foreach ($owners['history'][$pin] as $h) {
                if ($h['valid_from'] <= $date && $h['valid_to'] >= $date) {
                    return (int) $h['employee_id'];
                }
            }
        }
        return NULL;
    }
}
