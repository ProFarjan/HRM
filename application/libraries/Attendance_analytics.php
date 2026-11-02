<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * The numbers behind the attendance reports, computed in PHP from the rows the model fetched (no query here, so no query in a loop - G5).
 *
 * Definitions (CLAUDE.md "Attendance reports"):
 *  - present   = Present x 1 + Half-day x 0.5;  absent = Absent x 1 + Half-day x 0.5;  leave / holiday / weekly off count days;
 *  - expected  = present + absent + leave (working days of the employee; holidays and weekly offs are not expected);
 *  - attendance % = present / expected (NULL when nothing was expected);
 *  - late day  = a Present / Half-day row with late_minutes > 0;  missing check-out = Present with a check-in and no check-out on a
 *    day before today (today the person may still be at work);
 *  - no record = a day of the employee's employment, before today, without any attendance row (the day close has not run for it);
 *  - absence streak = consecutive Absent rows; Holiday and Weekly Off rows between them do not break it, any other status does.
 */
class Attendance_analytics
{
    /**
     * @param array  $employees id => row (id, join_date, exit_date, ...)
     * @param array  $grid      employee_id => date => attendance row
     * @param string $from      Y-m-d
     * @param string $to        Y-m-d
     * @param string $today     Y-m-d in the branch's timezone
     * @param int    $late_limit setting late_days_per_absent (0 = off)
     * @return array employees (id => stats), totals, daily (date => present / absent / late / expected), insights
     */
    public static function summarise(array $employees, array $grid, $from, $to, $today, $late_limit)
    {
        $days = self::days($from, $to);
        $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
        $out = array();
        $daily = array();
        foreach ($days as $d) {
            $daily[$d] = array('present' => 0.0, 'absent' => 0.0, 'late' => 0, 'expected' => 0.0);
        }
        foreach ($employees as $id => $e) {
            $s = self::blank();
            $in_minutes = array();
            $out_minutes = array();
            $streak = 0;
            $rows = isset($grid[$id]) ? $grid[$id] : array();
            foreach ($days as $d) {
                $employed = $d >= $e['join_date'] && ($e['exit_date'] === NULL || $d <= $e['exit_date']);
                if ( ! isset($rows[$d])) {
                    if ($employed && $d <= $yesterday) {
                        $s['no_record']++;
                        $s['no_record_dates'][] = $d;
                    }
                    continue;
                }
                $r = $rows[$d];
                switch ($r['status']) {
                    case 'Present':
                        $s['present'] += 1;
                        $streak = 0;
                        break;
                    case 'Half-day':
                        $s['present'] += 0.5;
                        $s['absent'] += 0.5;
                        $s['half']++;
                        $s['absent_dates'][] = array('date' => $d, 'half' => TRUE);
                        $streak = 0;
                        break;
                    case 'Absent':
                        $s['absent'] += 1;
                        $s['absent_dates'][] = array('date' => $d, 'half' => FALSE);
                        $streak++;
                        $s['max_streak'] = max($s['max_streak'], $streak);
                        break;
                    case 'Leave':
                        $s['leave']++;
                        $streak = 0;
                        break;
                    case 'Holiday':
                        $s['holiday']++;
                        break;
                    default:
                        $s['weekly_off']++;
                }
                $worked = in_array($r['status'], array('Present', 'Half-day'), TRUE);
                if ($worked && (int) $r['late_minutes'] > 0) {
                    $s['late_days']++;
                    $s['late_minutes'] += (int) $r['late_minutes'];
                    $s['late_max'] = max($s['late_max'], (int) $r['late_minutes']);
                    $s['late_rows'][] = $r;
                    $daily[$d]['late']++;
                }
                $s['early_minutes'] += (int) $r['early_leave_minutes'];
                if ((float) $r['overtime_hours'] > 0) {
                    $s['ot'] += (float) $r['overtime_hours'];
                    $s['ot_days']++;
                    $s['ot_rows'][] = $r;
                }
                $s['hours'] += (float) $r['working_hours'];
                if ($r['status'] === 'Present' && $r['check_in'] !== NULL && $r['check_out'] === NULL && $d < $today) {
                    $s['missing_out']++;
                    $s['missing_rows'][] = $r;
                }
                if ($worked) {
                    if ($r['check_in'] !== NULL) {
                        $in_minutes[] = self::minutes($r['check_in']);
                    }
                    if ($r['check_out'] !== NULL) {
                        $out_minutes[] = self::minutes($r['check_out']);
                    }
                }
                if (in_array($r['status'], array('Present', 'Half-day', 'Absent', 'Leave'), TRUE)) {
                    $daily[$d]['expected'] += 1;
                    $daily[$d]['present'] += $r['status'] === 'Present' ? 1 : ($r['status'] === 'Half-day' ? 0.5 : 0);
                    $daily[$d]['absent'] += $r['status'] === 'Absent' ? 1 : ($r['status'] === 'Half-day' ? 0.5 : 0);
                }
            }
            $s['expected'] = $s['present'] + $s['absent'] + $s['leave'];
            $s['pct'] = $s['expected'] > 0 ? round($s['present'] / $s['expected'] * 100, 1) : NULL;
            $s['avg_in'] = $in_minutes ? self::clock((int) round(array_sum($in_minutes) / count($in_minutes))) : '';
            $s['avg_out'] = $out_minutes ? self::clock((int) round(array_sum($out_minutes) / count($out_minutes))) : '';
            $s['hours'] = round($s['hours'], 2);
            $s['ot'] = round($s['ot'], 2);
            $out[$id] = $s;
        }

        $totals = self::blank();
        unset($totals['absent_dates'], $totals['late_rows'], $totals['ot_rows'], $totals['missing_rows'], $totals['no_record_dates']);
        foreach ($out as $s) {
            foreach (array('present', 'absent', 'leave', 'holiday', 'weekly_off', 'half', 'late_days', 'late_minutes', 'early_minutes', 'ot', 'ot_days', 'hours', 'missing_out', 'no_record', 'expected') as $k) {
                $totals[$k] += $s[$k];
            }
        }
        $totals['pct'] = $totals['expected'] > 0 ? round($totals['present'] / $totals['expected'] * 100, 1) : NULL;
        return array('employees' => $out, 'totals' => $totals, 'daily' => $daily, 'insights' => self::insights($employees, $out, $totals, $daily, $late_limit));
    }

    /** the plain-language findings of a period; each = type (success|info|warning|danger), icon, key (language key), args */
    public static function insights(array $employees, array $stats, array $totals, array $daily, $late_limit)
    {
        $name = function ($id) use ($employees) {
            return trim($employees[$id]['first_name'] . ' ' . $employees[$id]['last_name']);
        };
        $top = function ($field, $limit = 3, $min = 0) use ($stats, $name) {
            $ids = array_keys($stats);
            usort($ids, function ($a, $b) use ($stats, $field) {
                return $stats[$b][$field] <=> $stats[$a][$field] ?: $a <=> $b;
            });
            $parts = array();
            foreach (array_slice($ids, 0, $limit) as $id) {
                if ($stats[$id][$field] > $min) {
                    $parts[] = $name($id) . ' (' . rtrim(rtrim(number_format($stats[$id][$field], 2, '.', ''), '0'), '.') . ')';
                }
            }
            return implode(', ', $parts);
        };
        $items = array();
        if ($totals['pct'] !== NULL) {
            $items[] = array('type' => $totals['pct'] >= 90 ? 'success' : ($totals['pct'] >= 75 ? 'info' : 'warning'), 'icon' => 'fa-line-chart',
                'key' => 'ins_overall', 'args' => array(number_format($totals['pct'], 1), count($stats)));
        }
        if ($late_limit > 0) {
            $over = 0;
            foreach ($stats as $s) {
                $over += $s['late_days'] >= $late_limit ? 1 : 0;
            }
            if ($over > 0) {
                $items[] = array('type' => 'danger', 'icon' => 'fa-clock-o', 'key' => 'ins_late_limit', 'args' => array($over, $late_limit));
            }
        }
        $late = $top('late_days');
        if ($late !== '') {
            $items[] = array('type' => 'warning', 'icon' => 'fa-hourglass-end', 'key' => 'ins_top_late', 'args' => array($late));
        }
        $absent = $top('absent');
        if ($absent !== '') {
            $items[] = array('type' => 'warning', 'icon' => 'fa-user-times', 'key' => 'ins_top_absent', 'args' => array($absent));
        }
        $streaks = 0;
        foreach ($stats as $s) {
            $streaks += $s['max_streak'] >= 3 ? 1 : 0;
        }
        if ($streaks > 0) {
            $items[] = array('type' => 'danger', 'icon' => 'fa-exclamation-triangle', 'key' => 'ins_streak', 'args' => array($streaks));
        }
        $perfect = 0;
        foreach ($stats as $s) {
            $perfect += ($s['expected'] > 0 && $s['absent'] == 0 && $s['leave'] == 0 && $s['late_days'] == 0 && $s['no_record'] == 0) ? 1 : 0;
        }
        if ($perfect > 0) {
            $items[] = array('type' => 'success', 'icon' => 'fa-trophy', 'key' => 'ins_perfect', 'args' => array($perfect));
        }
        if ($totals['ot'] > 0) {
            $items[] = array('type' => 'info', 'icon' => 'fa-plus-circle', 'key' => 'ins_ot', 'args' => array(number_format($totals['ot'], 2), $top('ot', 3)));
        }
        if ($totals['missing_out'] > 0) {
            $items[] = array('type' => 'warning', 'icon' => 'fa-sign-out', 'key' => 'ins_missing', 'args' => array((int) $totals['missing_out']));
        }
        if ($totals['no_record'] > 0) {
            $items[] = array('type' => 'danger', 'icon' => 'fa-database', 'key' => 'ins_no_record', 'args' => array((int) $totals['no_record']));
        }
        $worst = NULL;
        $best = NULL;
        foreach ($daily as $d => $x) {
            if ($x['expected'] < 1) {
                continue;
            }
            $pct = $x['present'] / $x['expected'];
            if ($worst === NULL || $pct < $worst[1]) {
                $worst = array($d, $pct, $x);
            }
            if ($best === NULL || $pct > $best[1]) {
                $best = array($d, $pct, $x);
            }
        }
        if ($worst !== NULL && $best !== NULL && $worst[0] !== $best[0] && $worst[1] < 1) {
            $items[] = array('type' => 'info', 'icon' => 'fa-calendar', 'key' => 'ins_worst_day',
                'args' => array(show_date($worst[0]) . ' (' . date('D', strtotime($worst[0])) . ')', rtrim(rtrim(number_format($worst[2]['present'], 1, '.', ''), '0'), '.'), (int) $worst[2]['expected']));
        }
        return $items;
    }

    /** every date from..to (Y-m-d) */
    public static function days($from, $to)
    {
        $out = array();
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $out[] = $d;
        }
        return $out;
    }

    /** minutes since midnight of 'HH:MM[:SS]' */
    public static function minutes($time)
    {
        return ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
    }

    public static function clock($minutes)
    {
        $minutes = (($minutes % 1440) + 1440) % 1440;
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** 1.5 -> "1.5", 2.0 -> "2" */
    public static function plain($number, $decimals = 1)
    {
        return rtrim(rtrim(number_format((float) $number, $decimals, '.', ''), '0'), '.');
    }

    protected static function blank()
    {
        return array('present' => 0.0, 'absent' => 0.0, 'leave' => 0, 'holiday' => 0, 'weekly_off' => 0, 'half' => 0, 'late_days' => 0, 'late_minutes' => 0, 'late_max' => 0,
            'early_minutes' => 0, 'ot' => 0.0, 'ot_days' => 0, 'hours' => 0.0, 'missing_out' => 0, 'no_record' => 0, 'expected' => 0.0, 'pct' => NULL,
            'avg_in' => '', 'avg_out' => '', 'max_streak' => 0, 'absent_dates' => array(), 'late_rows' => array(), 'ot_rows' => array(), 'missing_rows' => array(), 'no_record_dates' => array());
    }
}
