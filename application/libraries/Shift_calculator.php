<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Shift resolution and daily metrics (CLAUDE.md "Shift resolution and daily metrics").
 *
 *  metrics($shift, $in, $out, $options)  - pure: the same inputs always give the same numbers (no database, no clock).
 *  plan($employee_ids, $from, $to)       - set-based: the shift and the day type (working / weekly off / holiday) of every
 *                                          employee and day in a range, in a fixed number of queries - 6, plus one for the periods when a split shift is involved (G5: no query per day
 *                                          or per employee). Attendance, the day close and payroll build on it.
 *
 *  Split shifts: a shift row may carry `segments` (the duty periods, loaded by plan()); is_split() tells. Then the day is measured per
 *  period by split_metrics() (punches) or span_metrics() (one in and one out typed by hand).
 *
 * Precedence:
 *  shift    = roster row of the day (when it names a shift) > employee_shifts row covering the day (latest effective_from)
 *             > hr_settings.default_shift_id (only a shift of the employee's branch) > none
 *  day type = active, non-optional holiday of the branch > roster row (is_working_day) > branch weekly_offs > global weekly_offs
 */
class Shift_calculator
{
    const DAY_WORKING = 'working';
    const DAY_WEEKLY_OFF = 'weekly_off';
    const DAY_HOLIDAY = 'holiday';
    /** most duty periods of one split shift */
    const MAX_PERIODS = 4;

    // ------------------------------------------------------------------------------------------------ pure helpers

    /** minutes since midnight of 'H:i' / 'H:i:s' */
    public static function minutes($time)
    {
        $parts = explode(':', (string) $time);
        return ((int) $parts[0]) * 60 + (isset($parts[1]) ? (int) $parts[1] : 0);
    }

    /** $a - $b in minutes, normalised to (-720, 720] so that a pair across midnight gives the short distance */
    public static function diff($a, $b)
    {
        $d = self::minutes($a) - self::minutes($b);
        if ($d > 720) {
            $d -= 1440;
        } elseif ($d <= -720) {
            $d += 1440;
        }
        return $d;
    }

    /** minutes the shift is expected to last: working_hours, or end - start when that is 0 (+1440 across midnight) */
    public static function expected_minutes(array $shift)
    {
        if ((float) $shift['working_hours'] > 0) {
            return (int) round((float) $shift['working_hours'] * 60);
        }
        $span = self::minutes($shift['end_time']) - self::minutes($shift['start_time']);
        return $span <= 0 ? $span + 1440 : $span;
    }

    /** settings used by metrics(): explicit values win, the rest from hr_setting() */
    public static function options(array $options = array())
    {
        foreach (array('deduct_break', 'ot_min_minutes', 'early_leave_counts_as_late') as $key) {
            if ( ! array_key_exists($key, $options)) {
                $options[$key] = (int) hr_setting($key, 0);        // only what was not given: explicit options = no database
            }
        }
        if ( ! array_key_exists('off_day', $options)) {
            $options['off_day'] = FALSE;
        }
        return $options;
    }

    /**
     * Daily metrics of one day.
     * @param array|null  $shift     shifts row (start_time, end_time, break_duration, working_hours, grace_time) or NULL
     * @param string|null $check_in  'H:i[:s]'
     * @param string|null $check_out 'H:i[:s]' (NULL = only a check-in so far)
     * @param array       $options   deduct_break, ot_min_minutes, early_leave_counts_as_late, off_day (holiday / weekly off)
     * @return array working_hours, late_minutes, early_leave_minutes, overtime_hours, expected_hours, worked_minutes
     */
    public static function metrics($shift, $check_in, $check_out, array $options = array())
    {
        $o = self::options($options);
        $out = array(
            'working_hours'       => 0.0,
            'worked_minutes'      => 0,
            'late_minutes'        => 0,
            'early_leave_minutes' => 0,
            'overtime_hours'      => 0.0,
            'expected_hours'      => $shift ? round(self::expected_minutes($shift) / 60, 2) : 0.0,
        );
        if ($check_in === NULL || $check_in === '') {
            return $out;
        }
        if ($shift && ! $o['off_day']) {
            $out['late_minutes'] = max(0, self::diff($check_in, $shift['start_time']) - (int) $shift['grace_time']);
        }
        if ($check_out === NULL || $check_out === '') {
            return $out;                                      // only a check-in: late is known, hours are not
        }

        $worked = self::minutes($check_out) - self::minutes($check_in);
        if ($worked < 0) {
            $worked += 1440;                                  // night shift: out the next morning
        }
        if ($shift && (int) $o['deduct_break'] === 1) {
            $worked = max(0, $worked - (int) $shift['break_duration']);
        }
        $out['worked_minutes'] = $worked;
        $out['working_hours'] = round($worked / 60, 2);

        if ($o['off_day']) {                                  // worked on a holiday / weekly off: all of it is overtime
            $out['late_minutes'] = 0;
            $out['overtime_hours'] = $out['working_hours'];
            return $out;
        }
        if ( ! $shift) {                                      // no shift: hours only
            return $out;
        }

        $out['early_leave_minutes'] = max(0, self::diff($shift['end_time'], $check_out));
        if ((int) $o['early_leave_counts_as_late'] === 1) {
            $out['late_minutes'] += $out['early_leave_minutes'];
        }
        $ot_minutes = max(0, $worked - self::expected_minutes($shift));
        $block = (int) $o['ot_min_minutes'];
        if ($block > 0) {
            $ot_minutes = (int) (floor($ot_minutes / $block) * $block);
        }
        $out['overtime_hours'] = round($ot_minutes / 60, 2);
        return $out;
    }


    // ------------------------------------------------------------------------------------------------ split shifts

    /** a shift with more than one duty period (plan() attaches `segments`; a shift row read otherwise is treated as one period) */
    public static function is_split($shift)
    {
        return is_array($shift) && ! empty($shift['is_split']) && isset($shift['segments']) && count($shift['segments']) > 1;
    }

    /**
     * The duty periods of a shift as minutes counted from midnight of the shift's day, growing without wrapping
     * (a period that ends after midnight has e > 1440; a period that starts before the previous one ended starts the next day).
     * @return array list of array(no, start (H:i:s), end (H:i:s), s, e)
     */
    public static function periods(array $shift)
    {
        $rows = self::is_split($shift) ? $shift['segments'] : array(array('segment_no' => 1, 'start_time' => $shift['start_time'], 'end_time' => $shift['end_time']));
        $out = array();
        $prev_end = NULL;
        foreach ($rows as $r) {
            $length = (self::minutes($r['end_time']) - self::minutes($r['start_time']) + 1440) % 1440;
            $length = $length === 0 ? 1440 : $length;
            $start = self::minutes($r['start_time']);
            while ($prev_end !== NULL && $start < $prev_end) {
                $start += 1440;
            }
            $out[] = array('no' => (int) $r['segment_no'], 'start' => $r['start_time'], 'end' => $r['end_time'], 's' => $start, 'e' => $start + $length);
            $prev_end = $start + $length;
        }
        return $out;
    }

    /** the 'H:i:s' clock time of a minute count from midnight (wraps after 24 h) */
    public static function clock($minutes)
    {
        $m = (($minutes % 1440) + 1440) % 1440;
        return sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60);
    }

    /**
     * Daily metrics of a split shift from the day's punches. A punch belongs to the period whose side of the middle of the gap it is on,
     * so a break of any length is classified without guessing in/out states; inside a period the first punch is the in and the last
     * the out (a lone punch is an in without an out). Worked time is the sum of the periods; the gap is never counted.
     *  late  = per period: in after (start + grace);  early leave = per period: out before the end
     *  overtime = worked - the sum of the periods (floored to ot_min_minutes); on a holiday / weekly off all worked time, no late
     * @param array  $shift       a shift row with `segments`
     * @param string $date        'Y-m-d' the shift's day
     * @param array  $punch_times 'Y-m-d H:i:s' (any order; ignored punches must not be passed)
     * @param array  $options     ot_min_minutes, early_leave_counts_as_late, off_day
     * @return array working_hours, worked_minutes, late_minutes, early_leave_minutes, overtime_hours, expected_hours, break_minutes, incomplete,
     *               check_in, check_out ('H:i:s' or NULL), periods (no, check_in, check_out, worked_minutes, late_minutes, early_leave_minutes, state)
     */
    public static function split_metrics(array $shift, $date, array $punch_times, array $options = array())
    {
        $periods = self::periods($shift);
        $midnight = strtotime($date . ' 00:00:00');
        $bounds = array();
        for ($i = 0; $i < count($periods) - 1; $i++) {
            $bounds[$i] = intdiv($periods[$i]['e'] + $periods[$i + 1]['s'], 2);
        }
        $points = array_fill(0, count($periods), array());
        foreach ($punch_times as $at) {
            $m = (int) round((strtotime($at) - $midnight) / 60);
            $index = count($periods) - 1;
            foreach ($bounds as $i => $b) {
                if ($m <= $b) {
                    $index = $i;
                    break;
                }
            }
            $points[$index][] = $m;
        }
        return self::period_metrics($shift, $periods, $points, $options);
    }

    /**
     * The same measurement for times typed per period: $pairs[i] = array(in, out) ('H:i[:s]' or NULL) for the i-th duty period. Nothing is
     * classified: what is typed in the first row belongs to the first period, even if it lies past the middle of the gap.
     */
    public static function pair_metrics(array $shift, array $pairs, array $options = array())
    {
        $periods = self::periods($shift);
        $points = array_fill(0, count($periods), array());
        foreach ($periods as $i => $p) {
            $in = isset($pairs[$i][0]) ? $pairs[$i][0] : NULL;
            $out = isset($pairs[$i][1]) ? $pairs[$i][1] : NULL;
            if ($in !== NULL && $in !== '') {
                $in_abs = $p['s'] + self::diff($in, $p['start']);
                $points[$i][] = $in_abs;
                if ($out !== NULL && $out !== '') {
                    $points[$i][] = $in_abs + ((self::minutes($out) - self::minutes($in) + 1440) % 1440);
                }
            }                                                 // an out without an in is refused by the form; here it is ignored
        }
        return self::period_metrics($shift, $periods, $points, $options);
    }

    /** shared by split_metrics() and pair_metrics(): $points[i] = minutes (from midnight of the day) of the punches of period i */
    protected static function period_metrics(array $shift, array $periods, array $points, array $options)
    {
        $o = self::options($options);
        $grace = (int) $shift['grace_time'];
        $rows = array();
        $worked = $late = $early = $expected = $incomplete = 0;
        $first_in = NULL;
        $last_complete_out = NULL;
        $last_state = NULL;
        $break = 0;
        $previous_out = NULL;
        foreach ($periods as $i => $p) {
            sort($points[$i]);
            $in = $points[$i] ? $points[$i][0] : NULL;
            $out = count($points[$i]) > 1 ? $points[$i][count($points[$i]) - 1] : NULL;
            $expected += $p['e'] - $p['s'];
            $row = array('no' => $p['no'], 'check_in' => $in === NULL ? NULL : self::clock($in), 'check_out' => $out === NULL ? NULL : self::clock($out),
                'worked_minutes' => 0, 'late_minutes' => 0, 'early_leave_minutes' => 0, 'state' => $in === NULL ? 'missed' : ($out === NULL ? 'open' : 'complete'));
            if ($in !== NULL) {
                $first_in = $first_in === NULL ? $in : $first_in;
                if ( ! $o['off_day']) {
                    $row['late_minutes'] = max(0, $in - ($p['s'] + $grace));
                }
                if ($previous_out !== NULL) {
                    $break += max(0, $in - $previous_out);
                }
            }
            if ($out !== NULL) {
                $row['worked_minutes'] = max(0, $out - $in);
                if ( ! $o['off_day']) {
                    $row['early_leave_minutes'] = max(0, $p['e'] - $out);
                }
                $previous_out = $out;
            } elseif ($in !== NULL) {
                $previous_out = NULL;
            }
            if ($row['state'] !== 'complete') {
                $incomplete++;
            }
            if ($in !== NULL) {
                $last_state = $row['state'];
                $last_complete_out = $out;
            }
            $worked += $row['worked_minutes'];
            $late += $row['late_minutes'];
            $early += $row['early_leave_minutes'];
            $rows[] = $row;
        }
        if ((int) $o['early_leave_counts_as_late'] === 1) {
            $late += $early;
        }
        $ot = $o['off_day'] ? $worked : max(0, $worked - $expected);
        $block = (int) $o['ot_min_minutes'];
        if ($block > 0 && ! $o['off_day']) {
            $ot = (int) (floor($ot / $block) * $block);
        }
        return array(
            'working_hours' => round($worked / 60, 2), 'worked_minutes' => $worked, 'late_minutes' => $o['off_day'] ? 0 : $late, 'early_leave_minutes' => $early,
            'overtime_hours' => round($ot / 60, 2), 'expected_hours' => round($expected / 60, 2), 'break_minutes' => $break, 'incomplete' => $incomplete,
            'check_in' => $first_in === NULL ? NULL : self::clock($first_in),
            'check_out' => ($last_state === 'complete' && $last_complete_out !== NULL) ? self::clock($last_complete_out) : NULL,
            'periods' => $rows,
        );
    }

    /**
     * A split shift with only one in and one out (typed by hand, bulk marking, self marking): the person is taken to have worked every
     * period between the two, so worked time is the overlap of [in, out] with each period. Late = in after the first start + grace; early
     * leave = out before the last end; overtime = time before the first start and after the last end. Same return shape as split_metrics().
     */
    public static function span_metrics(array $shift, $check_in, $check_out, array $options = array())
    {
        $o = self::options($options);
        $periods = self::periods($shift);
        $first = $periods[0];
        $last = $periods[count($periods) - 1];
        $in = $first['s'] + self::diff($check_in, $first['start']);
        $out = $check_out === NULL || $check_out === '' ? NULL : $in + ((self::minutes($check_out) - self::minutes($check_in) + 1440) % 1440);
        $grace = (int) $shift['grace_time'];
        $rows = array();
        $worked = $expected = $incomplete = 0;
        foreach ($periods as $p) {
            $expected += $p['e'] - $p['s'];
            $from = max($in, $p['s']);
            $to = $out === NULL ? $from : min($out, $p['e']);
            $overlap = $out !== NULL && $to > $from;
            $rows[] = array('no' => $p['no'], 'check_in' => $overlap ? self::clock($from) : NULL, 'check_out' => $overlap ? self::clock($to) : NULL,
                'worked_minutes' => $overlap ? $to - $from : 0, 'late_minutes' => 0, 'early_leave_minutes' => 0, 'state' => $overlap ? 'complete' : 'missed');
            $worked += $overlap ? $to - $from : 0;
            $incomplete += $overlap ? 0 : 1;
        }
        $late = $o['off_day'] ? 0 : max(0, $in - ($first['s'] + $grace));        // known with the check-in alone
        $early = ($o['off_day'] || $out === NULL) ? 0 : max(0, $last['e'] - $out);
        if ((int) $o['early_leave_counts_as_late'] === 1) {
            $late += $early;
        }
        $extra = $out === NULL ? 0 : max(0, $first['s'] - $in) + max(0, $out - $last['e']);
        $ot = $o['off_day'] ? $worked : $extra;
        $block = (int) $o['ot_min_minutes'];
        if ($block > 0 && ! $o['off_day']) {
            $ot = (int) (floor($ot / $block) * $block);
        }
        if ($o['off_day']) {                                  // holiday / weekly off: everything between in and out counts
            $worked = $out === NULL ? 0 : $out - $in;
            $ot = $worked;
        }
        return array(
            'working_hours' => round($worked / 60, 2), 'worked_minutes' => $worked, 'late_minutes' => $late, 'early_leave_minutes' => $early,
            'overtime_hours' => round($ot / 60, 2), 'expected_hours' => round($expected / 60, 2), 'break_minutes' => 0, 'incomplete' => $out === NULL ? count($periods) : $incomplete,
            'check_in' => self::clock($in), 'check_out' => $out === NULL ? NULL : self::clock($out), 'periods' => $out === NULL ? array() : $rows,
        );
    }

    /** ISO weekday numbers (1-7) from a CSV ('5,6'); '' = none */
    public static function weekday_set($csv)
    {
        $set = array();
        foreach (explode(',', (string) $csv) as $d) {
            $d = (int) trim($d);
            if ($d >= 1 && $d <= 7) {
                $set[$d] = TRUE;
            }
        }
        return $set;
    }

    // ------------------------------------------------------------------------------------------------ set-based plan

    /**
     * Shift and day type of every employee and day in [$from, $to] (Y-m-d).
     * @return array employee_id => date => array(shift => row|NULL, shift_source => roster|assignment|default|NULL,
     *               day_type => working|weekly_off|holiday, holiday_name => string|NULL, roster => bool, employed => bool)
     */
    public function plan(array $employee_ids, $from, $to)
    {
        $ids = array_values(array_unique(array_map('intval', $employee_ids)));
        if ( ! $ids || $from > $to) {
            return array();
        }
        $db = get_instance()->db;

        $employees = array();
        foreach ($db->select('id, branch_id, join_date, exit_date')->from('employees')->where_in('id', $ids)->get()->result_array() as $e) {
            $employees[(int) $e['id']] = $e;
        }
        if ( ! $employees) {
            return array();
        }
        $branch_ids = array_values(array_unique(array_map('intval', array_column($employees, 'branch_id'))));

        $branch_offs = array();
        $global_offs = self::weekday_set(hr_setting('weekly_offs', '5'));
        foreach ($db->select('id, weekly_offs')->from('branches')->where_in('id', $branch_ids)->get()->result_array() as $b) {
            $branch_offs[(int) $b['id']] = $b['weekly_offs'] === NULL ? $global_offs : self::weekday_set($b['weekly_offs']);
        }

        $rosters = array();
        $shift_ids = array();
        foreach ($db->select('employee_id, roster_date, shift_id, is_working_day')->from('shift_rosters')
                    ->where_in('employee_id', array_keys($employees))->where('roster_date >=', $from)->where('roster_date <=', $to)
                    ->get()->result_array() as $r) {
            $rosters[(int) $r['employee_id']][$r['roster_date']] = $r;
            if ($r['shift_id'] !== NULL) {
                $shift_ids[(int) $r['shift_id']] = TRUE;
            }
        }

        $assignments = array();
        foreach ($db->select('employee_id, shift_id, effective_from, effective_to')->from('employee_shifts')
                    ->where_in('employee_id', array_keys($employees))->where('effective_from <=', $to)
                    ->group_start()->where('effective_to IS NULL', NULL, FALSE)->or_where('effective_to >=', $from)->group_end()
                    ->order_by('employee_id')->order_by('effective_from')->get()->result_array() as $a) {
            $assignments[(int) $a['employee_id']][] = $a;
            $shift_ids[(int) $a['shift_id']] = TRUE;
        }

        $holidays = array();
        foreach ($db->select('branch_id, holiday_date, holiday_name')->from('holidays')->where_in('branch_id', $branch_ids)
                    ->where('holiday_date >=', $from)->where('holiday_date <=', $to)->where('status', 'Active')->where('is_optional', 0)
                    ->get()->result_array() as $h) {
            $holidays[(int) $h['branch_id']][$h['holiday_date']] = $h['holiday_name'];
        }

        $default_id = (int) hr_setting('default_shift_id', 0);
        if ($default_id > 0) {
            $shift_ids[$default_id] = TRUE;
        }
        $shifts = array();
        if ($shift_ids) {
            foreach ($db->from('shifts')->where_in('id', array_keys($shift_ids))->get()->result_array() as $s) {
                $shifts[(int) $s['id']] = $s;
            }
            $split_ids = array();
            foreach ($shifts as $id => $s) {
                if ((int) $s['is_split'] === 1) {
                    $split_ids[] = $id;
                }
            }
            if ($split_ids) {                                    // the periods: one more query, only when a split shift is involved
                foreach ($db->select('shift_id, segment_no, start_time, end_time')->from('shift_segments')->where_in('shift_id', $split_ids)
                            ->order_by('shift_id')->order_by('segment_no')->get()->result_array() as $seg) {
                    $shifts[(int) $seg['shift_id']]['segments'][] = $seg;
                }
            }
        }

        $plan = array();
        $days = array();
        for ($d = new DateTime($from); $d->format('Y-m-d') <= $to; $d->modify('+1 day')) {
            $days[$d->format('Y-m-d')] = (int) $d->format('N');
        }
        foreach ($employees as $eid => $emp) {
            $branch = (int) $emp['branch_id'];
            $offs = isset($branch_offs[$branch]) ? $branch_offs[$branch] : $global_offs;
            $default = ($default_id > 0 && isset($shifts[$default_id]) && (int) $shifts[$default_id]['branch_id'] === $branch) ? $shifts[$default_id] : NULL;
            $list = isset($assignments[$eid]) ? $assignments[$eid] : array();
            foreach ($days as $date => $weekday) {
                $roster = isset($rosters[$eid][$date]) ? $rosters[$eid][$date] : NULL;
                $shift = NULL;
                $source = NULL;
                if ($roster && $roster['shift_id'] !== NULL && isset($shifts[(int) $roster['shift_id']])) {
                    $shift = $shifts[(int) $roster['shift_id']];
                    $source = 'roster';
                } else {
                    for ($i = count($list) - 1; $i >= 0; $i--) {          // latest effective_from first
                        $a = $list[$i];
                        if ($a['effective_from'] <= $date && ($a['effective_to'] === NULL || $a['effective_to'] >= $date)) {
                            $shift = isset($shifts[(int) $a['shift_id']]) ? $shifts[(int) $a['shift_id']] : NULL;
                            $source = 'assignment';
                            break;
                        }
                    }
                    if ($shift === NULL && $default !== NULL) {
                        $shift = $default;
                        $source = 'default';
                    }
                }

                $holiday = isset($holidays[$branch][$date]) ? $holidays[$branch][$date] : NULL;
                if ($holiday !== NULL) {
                    $type = self::DAY_HOLIDAY;
                } elseif ($roster) {
                    $type = (int) $roster['is_working_day'] === 1 ? self::DAY_WORKING : self::DAY_WEEKLY_OFF;
                } else {
                    $type = isset($offs[$weekday]) ? self::DAY_WEEKLY_OFF : self::DAY_WORKING;
                }
                $plan[$eid][$date] = array(
                    'shift'        => $shift,
                    'shift_source' => $source,
                    'day_type'     => $type,
                    'holiday_name' => $holiday,
                    'roster'       => $roster !== NULL,
                    'employed'     => $emp['join_date'] <= $date && ($emp['exit_date'] === NULL || $emp['exit_date'] >= $date),
                );
            }
        }
        return $plan;
    }

    /** one employee, one day */
    public function day($employee_id, $date)
    {
        $plan = $this->plan(array($employee_id), $date, $date);
        return isset($plan[(int) $employee_id][$date]) ? $plan[(int) $employee_id][$date] : NULL;
    }
}
