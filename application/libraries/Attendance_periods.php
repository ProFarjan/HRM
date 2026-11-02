<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * `attendance_segments`: what an employee did in each duty period of a split-shift day. Written by the punch rebuild and by manual
 * marking (always the whole set of a day: delete + insert, inside the caller's transaction); read set-based for lists and reports.
 */
class Attendance_periods
{
    /** replace the periods of an attendance row ($periods = Shift_calculator::split_metrics()['periods'], or NULL / array() for none) */
    public static function store($attendance_id, $periods)
    {
        $db = get_instance()->db;
        $db->query('DELETE FROM attendance_segments WHERE attendance_id = ?', array((int) $attendance_id));
        foreach ((array) $periods as $p) {
            $db->insert('attendance_segments', array(
                'attendance_id' => (int) $attendance_id, 'segment_no' => (int) $p['no'], 'check_in' => $p['check_in'], 'check_out' => $p['check_out'],
                'worked_minutes' => (int) $p['worked_minutes'], 'late_minutes' => (int) $p['late_minutes'], 'early_leave_minutes' => (int) $p['early_leave_minutes'],
            ));
        }
    }

    /**
     * The periods of many attendance rows: attendance_id => list of rows (segment_no order). One query; the `state` of a row is derived
     * (complete = in and out, open = in only, missed = neither).
     */
    public static function for_rows(array $attendance_ids)
    {
        $out = array();
        $ids = array_values(array_unique(array_map('intval', $attendance_ids)));
        if ( ! $ids) {
            return $out;
        }
        $db = get_instance()->db;
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($db->select('attendance_id, segment_no, check_in, check_out, worked_minutes, late_minutes, early_leave_minutes')->from('attendance_segments')
                        ->where_in('attendance_id', $chunk)->order_by('attendance_id')->order_by('segment_no')->get()->result_array() as $r) {
                $r['state'] = $r['check_in'] === NULL ? 'missed' : ($r['check_out'] === NULL ? 'open' : 'complete');
                $out[(int) $r['attendance_id']][] = $r;
            }
        }
        return $out;
    }

    /** "10:02-14:00 / 18:05-22:00" (a missing time is "?") for display and Excel */
    public static function text(array $periods)
    {
        $parts = array();
        foreach ($periods as $p) {
            $parts[] = $p['check_in'] === NULL ? '-' : substr($p['check_in'], 0, 5) . '-' . ($p['check_out'] === NULL ? '?' : substr($p['check_out'], 0, 5));
        }
        return implode(' / ', $parts);
    }
}
