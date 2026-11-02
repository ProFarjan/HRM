<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Attendance rows (one per employee per day): list, manual marking, own-day marking, edit, delete, approval, bulk marking,
 * rebuild from punches, manual punches, raw punches, calendar. Explicit permissions only (P14): attendance_mark_self marks the
 * user's own day as Pending; attendance_add marks anybody as Approved; attendance_edit / _delete / _approve do only that;
 * show_all_attendance lifts the own-records restriction (a department head also sees their department).
 */
class Attendance_model extends MY_Model
{
    protected $module = 'attendance';

    const STATUSES = 'Present,Absent,Half-day,Leave,Holiday,Weekly Off';
    const WORKED = 'Present,Half-day';
    const MAX_RANGE_DAYS = 92;

    // ------------------------------------------------------------------------------------------------ list

    public function list_page()
    {
        $dt = new Datatable_request($this->input, array('a.attendance_date', 'e.employee_code', 'e.first_name', 'd.department_name', 'a.status',
            'a.check_in', 'a.check_out', 'a.working_hours', 'a.late_minutes', 'a.overtime_hours', 'a.approval_status'), 0, 'desc');
        $v = new Input_validator($this->input);
        $from = $v->date('from_date', FALSE);
        $to = $v->date('to_date', FALSE);
        $department = $v->int('department_id', 1, NULL, FALSE);
        $status = $v->enum('status', explode(',', self::STATUSES), FALSE);
        $approval = $v->enum('approval_status', array('Pending', 'Approved', 'Rejected'), FALSE);
        $missing = $v->flag('missing_checkout');
        if ( ! $v->ok()) {
            return array('error' => $v->message());
        }
        list($from, $to, $error) = $this->range($from, $to);
        if ($error !== NULL) {
            return array('error' => $error);
        }
        $base = function () use ($from, $to) {
            $this->db->from('attendance a')->join('employees e', 'e.id = a.employee_id')
                ->where('a.attendance_date >=', $from)->where('a.attendance_date <=', $to);
            $this->scope_branch('a');
            $this->scope_department('a');
        };
        $base();
        $total = $this->db->count_all_results();
        $filter = function () use ($base, $dt, $department, $status, $approval, $missing) {
            $base();
            $this->db->join('departments d', 'd.id = e.department_id', 'left');
            if ($department !== NULL) {
                $this->db->where('e.department_id', $department);
            }
            if ($status !== NULL) {
                $this->db->where('a.status', $status);
            }
            if ($approval !== NULL) {
                $this->db->where('a.approval_status', $approval);
            }
            if ($missing) {
                $this->db->where('a.status', 'Present')->where('a.check_in IS NOT NULL', NULL, FALSE)->where('a.check_out IS NULL', NULL, FALSE)
                    ->where('a.attendance_date <', today_local());
            }
            if ($dt->search !== '') {
                $this->db->group_start()->like('e.employee_code', $dt->search)->or_like('e.first_name', $dt->search)->or_like('e.last_name', $dt->search)->group_end();
            }
        };
        $filter();
        $filtered = $this->db->count_all_results();
        $filter();
        $this->db->select('a.*, e.employee_code, e.first_name, e.last_name, d.department_name');
        $dt->apply($this->db);
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array(
                'id'         => (int) $r['id'],
                'date'       => e(show_date($r['attendance_date'])),
                'code'       => e($r['employee_code']),
                'name'       => e(trim($r['first_name'] . ' ' . $r['last_name'])),
                'department' => e((string) $r['department_name']),
                'status'     => self::status_label($r['status']) . ($r['remarks'] ? ' <small class="text-muted">' . e($r['remarks']) . '</small>' : ''),
                'in'         => e(substr((string) $r['check_in'], 0, 5)),
                'out'        => e(substr((string) $r['check_out'], 0, 5)),
                'hours'      => e(number_format((float) $r['working_hours'], 2)),
                'late'       => (int) $r['late_minutes'] ? '<span class="text-red">' . (int) $r['late_minutes'] . '</span>' : '0',
                'ot'         => e(number_format((float) $r['overtime_hours'], 2)),
                'approval'   => self::approval_label($r['approval_status']),
                'source'     => e($r['source']),
                'locked'     => (int) $r['locked'],
                'pending'    => $r['approval_status'] === 'Pending' ? 1 : 0,
                'own'        => (int) $r['employee_id'] === (int) current_employee_id() ? 1 : 0,
            );
        }
        return $dt->response($total, $filtered, $rows);
    }

    public static function status_label($status)
    {
        $colors = array('Present' => 'success', 'Absent' => 'danger', 'Half-day' => 'warning', 'Leave' => 'info', 'Holiday' => 'primary', 'Weekly Off' => 'default');
        return '<span class="label label-' . $colors[$status] . '">' . e(t('att_status_' . strtolower(str_replace(array(' ', '-'), '_', $status)))) . '</span>';
    }

    public static function approval_label($status)
    {
        $colors = array('Pending' => 'warning', 'Approved' => 'success', 'Rejected' => 'danger');
        return '<span class="label label-' . $colors[$status] . '">' . e(t('att_approval_' . strtolower($status))) . '</span>';
    }

    /** one row of the current branch with the employee, or NULL */
    public function get($id)
    {
        $this->db->select('a.*, e.employee_code, e.first_name, e.last_name')->from('attendance a')->join('employees e', 'e.id = a.employee_id')->where('a.id', (int) $id);
        $this->scope_branch('a');
        $row = $this->db->get()->row_array();
        return $row ? $row : NULL;
    }

    // ------------------------------------------------------------------------------------------------ marking

    /** attendance_add: any employee of the branch, Approved. A row from the day close may be replaced; others are edited instead. */
    public function mark()
    {
        $v = new Input_validator($this->input);
        $employee = $v->ref('employee_id', 'employees');
        $date = $v->date('attendance_date');
        $values = $this->read_day($v);
        if ( ! $v->ok()) {
            return $v->message();
        }
        if ($date > today_local()) {
            return t('att_future');
        }
        $new_id = 0;
        list($ok, $message) = Tx::run(function () use ($employee, $date, $values, &$new_id) {
            $existing = $this->db->query('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ? FOR UPDATE', array($employee, $date))->row_array();
            if ($existing && ((int) $existing['locked'] || $existing['source'] !== 'day_close')) {
                return (int) $existing['locked'] ? t('att_locked') : t('att_exists');
            }
            $row = $this->compute($employee, $date, $values);
            if (is_string($row)) {
                return $row;
            }
            $row = array_merge($row, array('source' => 'manual', 'approval_status' => 'Approved', 'approved_by' => current_user_id(), 'approved_at' => now_local()));
            if ($existing) {
                $this->db->where('id', (int) $existing['id'])->update('attendance', array_merge($row, array('updated_at' => now_local())));
                $new_id = (int) $existing['id'];
            } else {
                $this->db->insert('attendance', array_merge($row, array('employee_id' => $employee, 'attendance_date' => $date, 'created_by' => current_user_id(), 'created_at' => now_local())));
                $new_id = (int) $this->db->insert_id();
            }
            $this->audit('attendance', $new_id, 'mark', $existing ? array('status' => $existing['status'], 'source' => $existing['source']) : NULL, $this->audit_view($row, $employee, $date));
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $new_id : $message;
    }

    /** attendance_mark_self: the user's own employee, today only, Pending until somebody with attendance_approve approves it */
    public function mark_self()
    {
        $employee = current_employee_id();
        if ( ! $employee) {
            return t('att_no_employee');
        }
        $v = new Input_validator($this->input);
        $in = $v->time('check_in');
        $out = $v->time('check_out', FALSE);
        $remarks = $v->text('remarks', 255, FALSE);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $date = today_local();
        $new_id = 0;
        list($ok, $message) = Tx::run(function () use ($employee, $date, $in, $out, $remarks, &$new_id) {
            if ($this->db->query('SELECT 1 FROM attendance WHERE employee_id = ? AND attendance_date = ? FOR UPDATE', array($employee, $date))->num_rows()) {
                return t('att_exists');
            }
            $row = $this->compute($employee, $date, array('status' => 'Present', 'check_in' => $in, 'check_out' => $out, 'remarks' => $remarks));
            if (is_string($row)) {
                return $row;
            }
            $this->db->insert('attendance', array_merge($row, array('employee_id' => $employee, 'attendance_date' => $date, 'source' => 'self',
                'approval_status' => 'Pending', 'created_by' => current_user_id(), 'created_at' => now_local())));
            $new_id = (int) $this->db->insert_id();
            $this->audit('attendance', $new_id, 'mark_self', NULL, $this->audit_view($row, $employee, $date));
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $new_id : $message;
    }

    /** attendance_edit: change status / times / remarks of a row that is not locked; the row becomes source = manual */
    public function update($id)
    {
        $v = new Input_validator($this->input);
        $values = $this->read_day($v);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id, $values) {
            $old = $this->lock($id);
            if ( ! is_array($old)) {
                return $old;
            }
            $row = $this->compute((int) $old['employee_id'], $old['attendance_date'], $values);
            if (is_string($row)) {
                return $row;
            }
            $row['source'] = 'manual';
            $changes = array();
            foreach ($row as $k => $val) {
                if ((string) $old[$k] !== (string) $val || (($old[$k] === NULL) !== ($val === NULL))) {
                    $changes[$k] = $val;
                }
            }
            if ( ! $changes) {
                return TRUE;
            }
            $this->db->where('id', $id)->update('attendance', array_merge($changes, array('updated_at' => now_local())));
            $this->audit('attendance', $id, 'update', array_intersect_key($old, $changes), $changes);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    public function delete($id)
    {
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id) {
            $old = $this->lock($id);
            if ( ! is_array($old)) {
                return $old;
            }
            $this->db->where('id', $id)->delete('attendance');
            $this->audit('attendance', $id, 'delete', $old, NULL);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    /** attendance_approve: Pending -> Approved / Rejected (re-checked under the row lock); never your own row (except the Admin) */
    public function decide($id, $approve)
    {
        $v = new Input_validator($this->input);
        $note = $v->text('approval_note', 255, ! $approve);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id, $approve, $note) {
            $old = $this->lock($id);
            if ( ! is_array($old)) {
                return $old;
            }
            if ($old['approval_status'] !== 'Pending') {
                return t('att_not_pending');
            }
            $user = current_user();
            if ((int) $old['employee_id'] === (int) current_employee_id() && empty($user['is_system'])) {
                return t('att_not_own_approval');
            }
            $status = $approve ? 'Approved' : 'Rejected';
            $this->db->where('id', $id)->update('attendance', array('approval_status' => $status, 'approval_note' => $note,
                'approved_by' => current_user_id(), 'approved_at' => now_local(), 'updated_at' => now_local()));
            $this->audit('attendance', $id, $approve ? 'approve' : 'reject', array('approval_status' => 'Pending'), array('approval_status' => $status, 'note' => $note));
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    /**
     * attendance_bulk: one date + department (or all) -> a row per current employee employed that day. Existing rows are skipped,
     * or updated with `overwrite` (locked rows never). "success<<<###>>>created/updated/skipped".
     */
    public function bulk_mark()
    {
        $v = new Input_validator($this->input);
        $date = $v->date('attendance_date');
        $department = $v->ref('department_id', 'departments', FALSE);
        $values = $this->read_day($v);
        $overwrite = $v->flag('overwrite');
        if ( ! $v->ok()) {
            return $v->message();
        }
        if ($date > today_local()) {
            return t('att_future');
        }
        $this->db->select('id')->from('employees')->where('join_date <=', $date)
            ->group_start()->where('exit_date IS NULL', NULL, FALSE)->or_where('exit_date >=', $date)->group_end();
        $this->scope_branch();
        if ($department !== NULL) {
            $this->db->where('department_id', $department);
        }
        $ids = array_map('intval', array_column($this->db->get()->result_array(), 'id'));
        if ( ! $ids) {
            return t('shift_bulk_nobody');
        }
        $counts = array('created' => 0, 'updated' => 0, 'skipped' => 0);
        list($ok, $message) = Tx::run(function () use ($ids, $date, $values, $overwrite, &$counts) {
            $existing = array();
            foreach ($this->db->query('SELECT * FROM attendance WHERE attendance_date = ? AND employee_id IN ? FOR UPDATE', array($date, $ids))->result_array() as $r) {
                $existing[(int) $r['employee_id']] = $r;
            }
            $plan = (new Shift_calculator())->plan($ids, $date, $date);
            foreach ($ids as $id) {
                $old = isset($existing[$id]) ? $existing[$id] : NULL;
                if ($old && ( ! $overwrite || (int) $old['locked'])) {
                    $counts['skipped']++;
                    continue;
                }
                $row = $this->compute($id, $date, $values, $plan[$id][$date]);
                if (is_string($row)) {
                    return $row;
                }
                $row = array_merge($row, array('source' => 'manual', 'approval_status' => 'Approved', 'approved_by' => current_user_id(), 'approved_at' => now_local()));
                if ($old) {
                    $this->db->where('id', (int) $old['id'])->update('attendance', array_merge($row, array('updated_at' => now_local())));
                    $counts['updated']++;
                } else {
                    $this->db->insert('attendance', array_merge($row, array('employee_id' => $id, 'attendance_date' => $date, 'created_by' => current_user_id(), 'created_at' => now_local())));
                    $counts['created']++;
                }
            }
            $this->audit('attendance', NULL, 'bulk_mark', NULL, array_merge($counts, array('date' => $date, 'status' => $values['status'])));
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $counts['created'] . '/' . $counts['updated'] . '/' . $counts['skipped'] : $message;
    }

    /** "Rebuild from punches": the row is rebuilt from the punches even if HR edited it (never when locked) */
    public function rebuild($id)
    {
        $id = (int) $id;
        $result = NULL;
        list($ok, $message) = Tx::run(function () use ($id, &$result) {
            $old = $this->lock($id);
            if ( ! is_array($old)) {
                return $old;
            }
            $result = (new Punch_ingestor())->rebuild_day((int) $old['employee_id'], $old['attendance_date'], TRUE);
            $this->audit('attendance', $id, 'rebuild', array('source' => $old['source'], 'status' => $old['status']), array('result' => $result));
            return TRUE;
        });
        if ( ! $ok) {
            return $message;
        }
        return $result === 'empty' ? t('att_rebuild_no_punches') : 'success';
    }

    /** attendance_add: a punch typed in by HR (source manual), processed like a device punch */
    public function add_punch()
    {
        $v = new Input_validator($this->input);
        $employee = $v->ref('employee_id', 'employees');
        $date = $v->date('punch_date');
        $time = $v->time('punch_time');
        $state = $v->enum('punch_state', array('0', '1', '255'));
        if ( ! $v->ok()) {
            return $v->message();
        }
        if ($date . ' ' . $time > now_local()) {
            return t('att_future');
        }
        $result = (new Punch_ingestor())->ingest(
            array(array('employee_id' => $employee, 'device_pin' => NULL, 'punch_at' => $date . ' ' . $time, 'punch_state' => (int) $state)),
            array('branch_id' => (int) current_branch_id(), 'device_id' => NULL, 'source' => 'manual', 'created_by' => current_user_id())
        );
        if ( ! $result['ok']) {
            return $result['message'];
        }
        if ($result['duplicates']) {
            return t('att_punch_exists');
        }
        if ($result['kept_manual']) {
            return 'success<<<###>>>manual';
        }
        return 'success';
    }

    // ------------------------------------------------------------------------------------------------ punches, calendar

    public function punches_page()
    {
        $dt = new Datatable_request($this->input, array('p.punch_at', 'e.employee_code', 'p.device_pin', 'p.device_id', 'p.punch_state', 'p.source', 'p.ignored'), 0, 'desc');
        $v = new Input_validator($this->input);
        $from = $v->date('from_date', FALSE);
        $to = $v->date('to_date', FALSE);
        $employee = $v->int('employee_id', 1, NULL, FALSE);
        $unmatched = $v->flag('unmatched');
        if ( ! $v->ok()) {
            return array('error' => $v->message());
        }
        list($from, $to, $error) = $this->range($from, $to);
        if ($error !== NULL) {
            return array('error' => $error);
        }
        $base = function () use ($from, $to) {
            $this->db->from('attendance_punches p')->where('p.punch_at >=', $from . ' 00:00:00')->where('p.punch_at <=', $to . ' 23:59:59');
            $this->scope_branch('p');
        };
        $base();
        $total = $this->db->count_all_results();
        $filter = function () use ($base, $dt, $employee, $unmatched) {
            $base();
            $this->db->join('employees e', 'e.id = p.employee_id', 'left');
            if ($employee !== NULL) {
                $this->db->where('p.employee_id', $employee);
            }
            if ($unmatched) {
                $this->db->where('p.employee_id IS NULL', NULL, FALSE);
            }
            if ($dt->search !== '') {
                $this->db->group_start()->where('p.device_pin', $dt->search)->or_like('e.employee_code', $dt->search)->or_like('e.first_name', $dt->search)->group_end();
            }
        };
        $filter();
        $filtered = $this->db->count_all_results();
        $filter();
        $this->db->select('p.*, e.employee_code, e.first_name, e.last_name');
        $dt->apply($this->db);
        $states = array(0 => 'att_state_in', 1 => 'att_state_out', 4 => 'att_state_ot_in', 5 => 'att_state_ot_out', 255 => 'att_state_unknown');
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array(
                'when'     => e(show_date($r['punch_at']) . substr($r['punch_at'], 10)),
                'employee' => $r['employee_id'] === NULL ? '<span class="label label-warning">' . e(t('att_unmatched')) . '</span>' : e($r['employee_code'] . ' - ' . trim($r['first_name'] . ' ' . $r['last_name'])),
                'pin'      => e((string) $r['device_pin']),
                'device'   => $r['device_id'] === NULL ? '-' : (int) $r['device_id'],
                'state'    => e(t($states[(int) $r['punch_state']])),
                'source'   => e($r['source']),
                'ignored'  => (int) $r['ignored'] ? '<span class="label label-default">' . e(t('att_ignored')) . '</span>' : '',
            );
        }
        return $dt->response($total, $filtered, $rows);
    }

    /** fullcalendar events for one employee: attendance rows + (for days without a row) holidays and weekly offs from the plan */
    public function calendar_events($employee_id, $from, $to)
    {
        $events = array();
        $have = array();
        $colors = array('Present' => '#00a65a', 'Absent' => '#dd4b39', 'Half-day' => '#f39c12', 'Leave' => '#00c0ef', 'Holiday' => '#3c8dbc', 'Weekly Off' => '#aaaaaa');
        foreach ($this->db->query('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?', array((int) $employee_id, $from, $to))->result_array() as $r) {
            $title = t('att_status_' . strtolower(str_replace(array(' ', '-'), '_', $r['status'])));
            if ($r['check_in']) {
                $title .= ' ' . substr($r['check_in'], 0, 5) . ($r['check_out'] ? '-' . substr($r['check_out'], 0, 5) : '');
            }
            if ((int) $r['late_minutes']) {
                $title .= ' (' . sprintf(t('att_late_short'), (int) $r['late_minutes']) . ')';
            }
            if ($r['approval_status'] !== 'Approved') {
                $title .= ' [' . t('att_approval_' . strtolower($r['approval_status'])) . ']';
            }
            $events[] = array('title' => $title, 'start' => $r['attendance_date'], 'allDay' => TRUE, 'color' => $colors[$r['status']]);
            $have[$r['attendance_date']] = TRUE;
        }
        $plan = (new Shift_calculator())->plan(array((int) $employee_id), $from, $to);
        foreach (isset($plan[(int) $employee_id]) ? $plan[(int) $employee_id] : array() as $date => $p) {
            if (isset($have[$date]) || $p['day_type'] === Shift_calculator::DAY_WORKING || ! $p['employed']) {
                continue;
            }
            $events[] = array('title' => $p['day_type'] === Shift_calculator::DAY_HOLIDAY ? $p['holiday_name'] : t('roster_off'),
                'start' => $date, 'allDay' => TRUE, 'rendering' => 'background', 'color' => $p['day_type'] === Shift_calculator::DAY_HOLIDAY ? '#fdf2e0' : '#eeeeee');
        }
        return $events;
    }

    /** is the employee in a department the current user heads? */
    public function in_my_department($employee_id)
    {
        $head = current_employee_id();
        return $head && $this->db->query(
            'SELECT 1 FROM employees e JOIN departments d ON d.id = e.department_id WHERE e.id = ? AND d.head_employee_id = ?',
            array((int) $employee_id, $head)
        )->num_rows() > 0;
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** status, check_in, check_out, remarks from the form */
    protected function read_day(Input_validator $v)
    {
        $values = array(
            'status'    => $v->enum('status', explode(',', self::STATUSES)),
            'check_in'  => $v->time('check_in', FALSE),
            'check_out' => $v->time('check_out', FALSE),
            'remarks'   => $v->text('remarks', 255, FALSE),
        );
        if ($values['status'] !== NULL && in_array($values['status'], explode(',', self::WORKED), TRUE) && $values['check_in'] === NULL) {
            $v->fail('check_in', t('att_check_in_required'));
        }
        return $values;
    }

    /** the stored fields for a day: Absent/Leave/Holiday/Weekly Off clear the times; worked days get the shift metrics */
    protected function compute($employee_id, $date, array $values, $day = NULL)
    {
        if ($day === NULL) {
            $day = (new Shift_calculator())->day($employee_id, $date);
        }
        if ( ! $day || ! $day['employed']) {
            return t('att_not_employed');
        }
        $worked = in_array($values['status'], explode(',', self::WORKED), TRUE);
        $shift = $day['shift'];
        $row = array(
            'branch_id' => (int) current_branch_id(), 'shift_id' => $shift ? (int) $shift['id'] : NULL, 'status' => $values['status'],
            'check_in' => NULL, 'check_out' => NULL, 'first_punch_at' => NULL, 'last_punch_at' => NULL,
            'working_hours' => 0.0, 'break_minutes' => 0, 'late_minutes' => 0, 'early_leave_minutes' => 0, 'overtime_hours' => 0.0,
            'remarks' => $values['remarks'],
        );
        if ($worked) {
            $m = Shift_calculator::metrics($shift, $values['check_in'], $values['check_out'], array('off_day' => $day['day_type'] !== Shift_calculator::DAY_WORKING));
            $row['check_in'] = $values['check_in'];
            $row['check_out'] = $values['check_out'];
            $row['working_hours'] = $m['working_hours'];
            $row['late_minutes'] = $m['late_minutes'];
            $row['early_leave_minutes'] = $m['early_leave_minutes'];
            $row['overtime_hours'] = $m['overtime_hours'];
        }
        return $row;
    }

    /** the row locked for update (current branch, inside the user's scope), or an error */
    protected function lock($id)
    {
        $row = $this->db->query('SELECT * FROM attendance WHERE id = ? AND branch_id = ? FOR UPDATE', array((int) $id, (int) current_branch_id()))->row_array();
        if ( ! $row) {
            return t('not_found');
        }
        if ((int) $row['locked']) {
            return t('att_locked');
        }
        return $row;
    }

    protected function audit_view(array $row, $employee, $date)
    {
        return array('employee_id' => $employee, 'date' => $date, 'status' => $row['status'], 'check_in' => $row['check_in'], 'check_out' => $row['check_out']);
    }

    /** array(from, to, error): default the last 7 days, at most MAX_RANGE_DAYS */
    protected function range($from, $to)
    {
        $to = $to !== NULL ? $to : today_local();
        $from = $from !== NULL ? $from : date('Y-m-d', strtotime($to . ' -6 days'));
        if ($from > $to) {
            return array(NULL, NULL, t('audit_range_order'));
        }
        if ((strtotime($to) - strtotime($from)) / 86400 > self::MAX_RANGE_DAYS) {
            return array(NULL, NULL, sprintf(t('audit_range_too_long'), self::MAX_RANGE_DAYS));
        }
        return array($from, $to, NULL);
    }
}
