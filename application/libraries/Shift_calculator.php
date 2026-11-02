<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Shift resolution and daily metrics (CLAUDE.md "Shift resolution and daily metrics").
 *
 *  metrics($shift, $in, $out, $options)  - pure: the same inputs always give the same numbers (no database, no clock).
 *  plan($employee_ids, $from, $to)       - set-based: the shift and the day type (working / weekly off / holiday) of every
 *                                          employee and day in a range, in a fixed number of queries (G5: no query per day
 *                                          or per employee). Attendance, the day close and payroll build on it.
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
