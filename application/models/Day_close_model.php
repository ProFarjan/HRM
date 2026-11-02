<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Day close (cron attendance_close; fixes P1). For every employee employed on a closed day (never today) without an
 * attendance row: Holiday > Weekly Off > Leave (approved leave covering the day - step 6) > Absent. Re-running fills gaps only.
 * A Present row with only a check-in gets the remark "missing check-out" (it stays Present and shows in the missing check-out list).
 * Set-based: one employee query, one existing-rows query, one Shift_calculator::plan(), then inserts.
 */
class Day_close_model extends MY_Model
{
    const REMARK_MISSING_OUT = 'missing check-out';

    /**
     * @return array ok, message, created => array(status => n), missing_checkout, already, employees
     */
    public function close_day($branch_id, $date, $dry_run = FALSE)
    {
        $result = array('ok' => FALSE, 'message' => '', 'created' => array(), 'missing_checkout' => 0, 'already' => 0, 'employees' => 0);
        $branch = $this->db->query('SELECT id, timezone FROM branches WHERE id = ?', array((int) $branch_id))->row_array();
        if ( ! $branch) {
            $result['message'] = 'Unknown branch.';
            return $result;
        }
        if (parse_strict_date((string) $date, 'Y-m-d') === FALSE) {
            $result['message'] = 'Invalid date.';
            return $result;
        }
        $today = self::today_in($branch['timezone']);
        if ($date >= $today) {
            $result['message'] = sprintf(t('close_not_closed'), show_date($date));
            return $result;
        }

        $employees = $this->db->query(
            'SELECT id FROM employees WHERE branch_id = ? AND join_date <= ? AND (exit_date IS NULL OR exit_date >= ?)',
            array((int) $branch_id, $date, $date)
        )->result_array();
        $ids = array_map('intval', array_column($employees, 'id'));
        $result['employees'] = count($ids);
        if ( ! $ids) {
            $result['ok'] = TRUE;
            return $result;
        }

        list($ok, $message) = Tx::run(function () use ($branch_id, $date, $ids, $dry_run, &$result) {
            $existing = array();
            foreach ($this->db->query('SELECT id, employee_id, status, check_in, check_out, remarks, locked FROM attendance WHERE branch_id = ? AND attendance_date = ? FOR UPDATE',
                        array((int) $branch_id, $date))->result_array() as $r) {
                $existing[(int) $r['employee_id']] = $r;
            }
            $missing = array_values(array_diff($ids, array_keys($existing)));
            $result['already'] = count($existing);
            $plan = $missing ? (new Shift_calculator())->plan($missing, $date, $date) : array();
            $leaves = $this->approved_leaves($missing, $date);
            $rows = array();
            foreach ($missing as $id) {
                $day = $plan[$id][$date];
                if ($day['day_type'] === Shift_calculator::DAY_HOLIDAY) {
                    $status = 'Holiday';
                } elseif ($day['day_type'] === Shift_calculator::DAY_WEEKLY_OFF) {
                    $status = 'Weekly Off';
                } elseif (isset($leaves[$id])) {
                    $status = 'Leave';
                } else {
                    $status = 'Absent';
                }
                $result['created'][$status] = isset($result['created'][$status]) ? $result['created'][$status] + 1 : 1;
                $rows[] = array(
                    'branch_id' => (int) $branch_id, 'employee_id' => $id, 'attendance_date' => $date, 'shift_id' => $day['shift'] ? (int) $day['shift']['id'] : NULL,
                    'status' => $status, 'leave_id' => isset($leaves[$id]) ? $leaves[$id] : NULL, 'approval_status' => 'Approved',
                    'remarks' => $status === 'Holiday' ? $day['holiday_name'] : NULL, 'source' => 'day_close', 'created_at' => now_local(),
                );
            }
            $flag = array();
            foreach ($existing as $r) {
                if ($r['status'] === 'Present' && $r['check_in'] !== NULL && $r['check_out'] === NULL && $r['remarks'] === NULL && ! (int) $r['locked']) {
                    $flag[] = (int) $r['id'];
                }
            }
            $result['missing_checkout'] = count($flag);
            if ($dry_run) {
                return TRUE;
            }
            if ($rows) {
                $this->db->insert_batch('attendance', $rows);
            }
            if ($flag) {
                $this->db->where_in('id', $flag)->update('attendance', array('remarks' => self::REMARK_MISSING_OUT, 'updated_at' => now_local()));
            }
            return TRUE;
        });
        $result['ok'] = $ok;
        $result['message'] = $ok ? '' : $message;
        return $result;
    }

    /** close every day of [$from, $to] (capped at yesterday); used by the cron catch-up and by payroll generation (step 9) */
    public function close_range($branch_id, $from, $to, $dry_run = FALSE)
    {
        $branch = $this->db->query('SELECT timezone FROM branches WHERE id = ?', array((int) $branch_id))->row_array();
        $yesterday = date('Y-m-d', strtotime(self::today_in($branch ? $branch['timezone'] : NULL) . ' -1 day'));
        $to = min($to, $yesterday);
        $totals = array('ok' => TRUE, 'days' => 0, 'created' => 0, 'message' => '');
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $r = $this->close_day($branch_id, $d, $dry_run);
            if ( ! $r['ok']) {
                return array('ok' => FALSE, 'days' => $totals['days'], 'created' => $totals['created'], 'message' => $r['message']);
            }
            $totals['days']++;
            $totals['created'] += array_sum($r['created']);
        }
        return $totals;
    }

    /** id, code, timezone of the active branches (the cron closes each in its own timezone) */
    public function active_branches()
    {
        return $this->db->query("SELECT id, code, timezone FROM branches WHERE status = 'Active' ORDER BY id")->result_array();
    }

    /** today in a branch's timezone (the cron runs in the server's timezone) */
    public static function today_in($timezone)
    {
        try {
            $tz = new DateTimeZone($timezone ? $timezone : (string) hr_setting('timezone', 'Asia/Dhaka'));
        } catch (Exception $e) {
            $tz = new DateTimeZone('Asia/Dhaka');
        }
        return (new DateTime('now', $tz))->format('Y-m-d');
    }

    /** employee_id => leave_id of approved leaves covering the day. The leaves table arrives in step 6; until then: none. */
    protected function approved_leaves(array $employee_ids, $date)
    {
        return array();
    }
}
