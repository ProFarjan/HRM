<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Two review lists for HR (CLAUDE.md "Punch to day" 3 and 5):
 *  - Unmatched punches: punches whose PIN belongs to nobody yet, grouped by PIN, with the action that links the PIN to an employee
 *    (the PIN goes through the normal PIN rules, then every stored punch of it is processed);
 *  - Punches for closed days: punches a locked (month closed) day does not show, which the ingestor never applies.
 */
class Punch_review_model extends MY_Model
{
    const MAX_RANGE_DAYS = 92;

    public function unmatched_page()
    {
        $dt = new Datatable_request($this->input, array('p.device_pin', 'cnt', 'first_at', 'last_at', NULL, NULL), 3, 'desc');
        list($from, $to, $error) = $this->read_range(30);
        if ($error !== NULL) {
            return array('error' => $error);
        }
        $base = function () use ($from, $to, $dt) {
            $this->db->from('attendance_punches p')->join('devices d', 'd.id = p.device_id', 'left')
                ->where('p.employee_id IS NULL', NULL, FALSE)->where('p.device_pin IS NOT NULL', NULL, FALSE)->where('p.ignored', 0)
                ->where('p.punch_at >=', $from . ' 00:00:00')->where('p.punch_at <=', $to . ' 23:59:59');
            $this->scope_branch('p');
            if ($dt->search !== '') {
                $this->db->where('p.device_pin', $dt->search);
            }
        };
        $base();
        $this->db->select('COUNT(DISTINCT p.device_pin) AS c', FALSE);
        $filtered = (int) $this->db->get()->row()->c;
        $base();
        $this->db->select('p.device_pin, COUNT(*) AS cnt, MIN(p.punch_at) AS first_at, MAX(p.punch_at) AS last_at, GROUP_CONCAT(DISTINCT d.name ORDER BY d.name SEPARATOR \', \') AS devices', FALSE)
            ->group_by('p.device_pin');
        $dt->apply($this->db);
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array(
                'pin'     => e($r['device_pin']),
                'raw_pin' => $r['device_pin'],
                'count'   => (int) $r['cnt'],
                'first'   => e(show_date($r['first_at']) . ' ' . substr($r['first_at'], 11, 5)),
                'last'    => e(show_date($r['last_at']) . ' ' . substr($r['last_at'], 11, 5)),
                'devices' => e((string) $r['devices']),
            );
        }
        return $dt->response($filtered, $filtered, $rows);
    }

    /**
     * Link a PIN to an employee: the employee gets the PIN from the day of its first unmatched punch (or the join date when later),
     * then all unmatched punches of the PIN that the PIN rules now assign are given their owner and their days rebuilt.
     */
    public function link()
    {
        $v = new Input_validator($this->input);
        $pin = $v->text('device_pin', 9);
        $employee_id = $v->ref('employee_id', 'employees');
        if ($pin !== NULL && ! preg_match('/^[0-9]{1,9}$/', $pin)) {
            $v->fail('device_pin', t('emp_pin_rule'));
        }
        if ( ! $v->ok()) {
            return $v->message();
        }
        $branch = (int) current_branch_id();
        $first = $this->db->query('SELECT MIN(punch_at) AS m FROM attendance_punches WHERE branch_id = ? AND device_pin = ? AND employee_id IS NULL', array($branch, $pin))->row_array();
        if ( ! $first || $first['m'] === NULL) {
            return t('att_unmatched_none');
        }
        $employee = $this->db->query('SELECT join_date FROM employees WHERE id = ? AND branch_id = ?', array($employee_id, $branch))->row_array();
        $from = max($employee['join_date'], substr($first['m'], 0, 10));
        $CI =& get_instance();
        $CI->load->model('Employees_model');
        $result = $CI->Employees_model->assign_pin($employee_id, $pin, $from);
        if ($result !== 'success') {
            return $result;
        }
        $stats = (new Punch_ingestor())->reprocess_unmatched($branch, $pin);
        if ( ! $stats['ok']) {
            return $stats['message'];
        }
        list($ok, $message) = Tx::run(function () use ($employee_id, $pin, $stats) {
            $this->audit('attendance', $employee_id, 'link_pin', NULL, array('device_pin' => $pin, 'punches_linked' => $stats['linked']));
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . (int) $stats['linked'] : $message;
    }

    // ------------------------------------------------------------------------------------------------ closed days

    /** punches of employees whose day is locked and does not show them (outside its first / last punch) */
    public function closed_page()
    {
        $dt = new Datatable_request($this->input, array('p.punch_at', 'e.employee_code', 'p.device_pin', 'd.name', 'p.source', 'a.status'), 0, 'desc');
        list($from, $to, $error) = $this->read_range(30);
        if ($error !== NULL) {
            return array('error' => $error);
        }
        $base = function () use ($from, $to, $dt) {
            $this->db->from('attendance_punches p')
                ->join('attendance a', 'a.employee_id = p.employee_id AND a.locked = 1 AND p.punch_at >= a.attendance_date AND p.punch_at < DATE_ADD(a.attendance_date, INTERVAL 1 DAY)')
                ->join('employees e', 'e.id = p.employee_id')->join('devices d', 'd.id = p.device_id', 'left')
                ->where('p.ignored', 0)->where('p.punch_at >=', $from . ' 00:00:00')->where('p.punch_at <=', $to . ' 23:59:59')
                ->group_start()->where('a.first_punch_at IS NULL', NULL, FALSE)->or_where('p.punch_at < a.first_punch_at', NULL, FALSE)->or_where('p.punch_at > a.last_punch_at', NULL, FALSE)->group_end();
            $this->scope_branch('p');
            if ($dt->search !== '') {
                $this->db->group_start()->like('e.employee_code', $dt->search)->or_like('e.first_name', $dt->search)->or_where('p.device_pin', $dt->search)->group_end();
            }
        };
        $base();
        $filtered = $this->db->count_all_results();
        $base();
        $this->db->select('p.punch_at, p.device_pin, p.source, e.employee_code, e.first_name, e.last_name, d.name AS device_name, a.status, a.attendance_date');
        $dt->apply($this->db);
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array(
                'when'     => e(show_date($r['punch_at']) . substr($r['punch_at'], 10)),
                'employee' => e($r['employee_code'] . ' - ' . trim($r['first_name'] . ' ' . $r['last_name'])),
                'pin'      => e((string) $r['device_pin']),
                'device'   => e((string) $r['device_name']),
                'source'   => e($r['source']),
                'day'      => e(show_date($r['attendance_date']) . ' (' . t('att_status_' . strtolower(str_replace(array(' ', '-'), '_', $r['status']))) . ')'),
            );
        }
        return $dt->response($filtered, $filtered, $rows);
    }

    /** [from, to, error] from the posted from_date / to_date, default the last $default_days days, at most MAX_RANGE_DAYS */
    protected function read_range($default_days)
    {
        $v = new Input_validator($this->input);
        $from = $v->date('from_date', FALSE);
        $to = $v->date('to_date', FALSE);
        if ( ! $v->ok()) {
            return array(NULL, NULL, $v->message());
        }
        $to = $to !== NULL ? $to : today_local();
        $from = $from !== NULL ? $from : date('Y-m-d', strtotime($to . ' -' . ((int) $default_days - 1) . ' days'));
        if ($from > $to) {
            return array(NULL, NULL, t('audit_range_order'));
        }
        if ((strtotime($to) - strtotime($from)) / 86400 > self::MAX_RANGE_DAYS) {
            return array(NULL, NULL, sprintf(t('audit_range_too_long'), self::MAX_RANGE_DAYS));
        }
        return array($from, $to, NULL);
    }
}
