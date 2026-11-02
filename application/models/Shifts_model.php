<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Shifts of the current branch. is_night is derived (end < start); delete only while nothing uses the shift.
 * A split shift has 2 to MAX_PERIODS duty periods (shift_segments, e.g. 10:00-14:00 and 18:00-22:00): start / end are the first start and the
 * last end, working_hours the sum of the periods, break_duration 0 (the gap between periods is not duty time and never deducted).
 */
class Shifts_model extends MY_Model
{
    public function list_page()
    {
        $dt = new Datatable_request($this->input, array('s.shift_name', 's.shift_code', 's.start_time', 's.end_time', 's.working_hours', 's.grace_time', 'employees', 's.status'));
        $v = new Input_validator($this->input);
        $status = $v->enum('status', array('Active', 'Inactive'), FALSE);
        $this->db->from('shifts s');
        $this->scope_branch('s');
        $total = $this->db->count_all_results();
        $filter = function () use ($dt, $status) {
            $this->db->from('shifts s');
            $this->scope_branch('s');
            if ($status !== NULL) {
                $this->db->where('s.status', $status);
            }
            if ($dt->search !== '') {
                $this->db->group_start()->like('s.shift_name', $dt->search)->or_like('s.shift_code', $dt->search)->group_end();
            }
        };
        $filter();
        $filtered = $this->db->count_all_results();
        $filter();
        $this->db->select('s.*, (SELECT COUNT(*) FROM employee_shifts a WHERE a.shift_id = s.id AND a.effective_to IS NULL) AS employees', FALSE);
        $dt->apply($this->db);
        $rows = array();
        $list = $this->db->get()->result_array();
        $periods = $this->period_texts(array_column($list, 'id'));
        foreach ($list as $r) {
            $rows[] = array(
                'id'        => (int) $r['id'],
                'name'      => e($r['shift_name']) . ((int) $r['is_night'] ? ' <span class="label label-primary"><i class="fa fa-moon-o"></i> ' . e(t('shift_night')) . '</span>' : '')
                    . ((int) $r['is_split'] ? ' <span class="label label-info"><i class="fa fa-columns"></i> ' . e(t('shift_split')) . '</span><br><small class="text-muted">' . e(isset($periods[(int) $r['id']]) ? $periods[(int) $r['id']] : '') . '</small>' : ''),
                'code'      => e($r['shift_code']),
                'start'     => e(substr($r['start_time'], 0, 5)),
                'end'       => e(substr($r['end_time'], 0, 5)),
                'hours'     => e(number_format(Shift_calculator::expected_minutes($r) / 60, 2)),
                'grace'     => (int) $r['grace_time'],
                'employees' => (int) $r['employees'],
                'status'    => $r['status'] === 'Active' ? '<span class="label label-success">' . e(t('status_active')) . '</span>' : '<span class="label label-default">' . e(t('status_inactive')) . '</span>',
            );
        }
        return $dt->response($total, $filtered, $rows);
    }

    public function get($id)
    {
        $this->db->from('shifts')->where('id', (int) $id);
        $this->scope_branch();
        $row = $this->db->get()->row_array();
        if ($row) {
            $row['segments'] = $this->segments((int) $row['id']);
        }
        return $row ? $row : NULL;
    }

    /** the duty periods of a shift in order: list of array(segment_no, start_time, end_time) */
    public function segments($shift_id)
    {
        return $this->db->query('SELECT segment_no, start_time, end_time FROM shift_segments WHERE shift_id = ? ORDER BY segment_no', array((int) $shift_id))->result_array();
    }

    /** shift_id => "10:00-14:00 + 18:00-22:00" for the split shifts among $ids (one query) */
    public function period_texts(array $ids)
    {
        $out = array();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ( ! $ids) {
            return $out;
        }
        foreach ($this->db->select('shift_id, start_time, end_time')->from('shift_segments')->where_in('shift_id', $ids)->order_by('shift_id')->order_by('segment_no')->get()->result_array() as $r) {
            $out[(int) $r['shift_id']][] = substr($r['start_time'], 0, 5) . '-' . substr($r['end_time'], 0, 5);
        }
        return array_map(function ($parts) { return implode(' + ', $parts); }, $out);
    }

    /** id => "Name (08:00-17:00)" (a split shift: "Name (10:00-14:00 + 18:00-22:00)") of the active shifts of the current branch */
    public function active_list()
    {
        $rows = $this->db->query("SELECT id, shift_name, start_time, end_time, is_split FROM shifts WHERE branch_id = ? AND status = 'Active' ORDER BY start_time, shift_name", array((int) current_branch_id()))->result_array();
        return $this->labels($rows, FALSE);
    }

    /** every active shift of every branch, for the global default shift setting: id => "Branch: Name (..)" */
    public function all_active_list()
    {
        $rows = $this->db->query("SELECT s.id, s.shift_name, s.start_time, s.end_time, s.is_split, b.name AS branch FROM shifts s JOIN branches b ON b.id = s.branch_id WHERE s.status = 'Active' ORDER BY b.name, s.start_time")->result_array();
        return $this->labels($rows, TRUE);
    }

    protected function labels(array $rows, $with_branch)
    {
        $periods = $this->period_texts(array_column($rows, 'id'));
        $out = array();
        foreach ($rows as $s) {
            $span = isset($periods[(int) $s['id']]) ? $periods[(int) $s['id']] : substr($s['start_time'], 0, 5) . '-' . substr($s['end_time'], 0, 5);
            $out[(int) $s['id']] = ($with_branch ? $s['branch'] . ': ' : '') . $s['shift_name'] . ' (' . $span . ')';
        }
        return $out;
    }

    public function create()
    {
        $data = $this->read_form();
        if ( ! is_array($data)) {
            return $data;
        }
        $new_id = 0;
        list($ok, $message) = Tx::run(function () use ($data, &$new_id) {
            if ($this->db->query('SELECT 1 FROM shifts WHERE branch_id = ? AND shift_code = ? FOR UPDATE', array((int) current_branch_id(), $data['shift_code']))->num_rows()) {
                return t('org_code_taken');
            }
            $segments = $data['segments'];
            unset($data['segments']);
            $data = array_merge($data, array('branch_id' => (int) current_branch_id(), 'created_by' => current_user_id(), 'created_at' => now_local()));
            $this->db->insert('shifts', $data);
            $new_id = (int) $this->db->insert_id();
            $this->store_segments($new_id, $segments);
            $this->audit('shifts', $new_id, 'create', NULL, array_merge($data, array('segments' => $this->segment_text($segments))));
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $new_id : $message;
    }

    public function update($id)
    {
        $id = (int) $id;
        $data = $this->read_form();
        if ( ! is_array($data)) {
            return $data;
        }
        $segments = $data['segments'];
        unset($data['segments']);
        list($ok, $message) = Tx::run(function () use ($id, $data, $segments) {
            $old = $this->db->query('SELECT * FROM shifts WHERE id = ? AND branch_id = ? FOR UPDATE', array($id, (int) current_branch_id()))->row_array();
            if ( ! $old) {
                return t('not_found');
            }
            if ($this->db->query('SELECT 1 FROM shifts WHERE branch_id = ? AND shift_code = ? AND id <> ?', array((int) current_branch_id(), $data['shift_code'], $id))->num_rows()) {
                return t('org_code_taken');
            }
            if ($data['status'] === 'Inactive' && (int) hr_setting('default_shift_id', 0) === $id) {
                return t('shift_is_default');
            }
            $changes = array();
            foreach ($data as $k => $val) {
                if ((string) $old[$k] !== (string) $val && ! (is_float($val) && money_equal($old[$k], $val))) {
                    $changes[$k] = $val;
                }
            }
            $old_text = $this->segment_text($this->segments($id));
            $new_text = $this->segment_text($segments);
            if ($old_text !== $new_text) {
                $changes['segments'] = $new_text;                // the periods are compared as text: moving 10-14/18-22 to 10-13/19-22 changes none of the columns
                $this->store_segments($id, $segments);
            }
            if ( ! $changes) {
                return TRUE;
            }
            $columns = $changes;
            unset($columns['segments']);
            if ($columns) {
                $this->db->where('id', $id)->update('shifts', array_merge($columns, array('updated_at' => now_local())));
            }
            $before = array_intersect_key($old, $changes);
            if (isset($changes['segments'])) {
                $before['segments'] = $old_text;
            }
            $this->audit('shifts', $id, 'update', $before, $changes);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    public function delete($id)
    {
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id) {
            $old = $this->db->query('SELECT * FROM shifts WHERE id = ? AND branch_id = ? FOR UPDATE', array($id, (int) current_branch_id()))->row_array();
            if ( ! $old) {
                return t('not_found');
            }
            if ((int) hr_setting('default_shift_id', 0) === $id
                || $this->db->query('SELECT 1 FROM employee_shifts WHERE shift_id = ? LIMIT 1', array($id))->num_rows()
                || $this->db->query('SELECT 1 FROM shift_rosters WHERE shift_id = ? LIMIT 1', array($id))->num_rows()) {
                return t('shift_in_use');
            }
            $this->db->query('DELETE FROM shift_segments WHERE shift_id = ?', array($id));
            $this->db->where('id', $id)->delete('shifts');
            $this->audit('shifts', $id, 'delete', $old, NULL);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    /** replace the periods of a shift (none for an ordinary shift) */
    protected function store_segments($shift_id, array $segments)
    {
        $this->db->query('DELETE FROM shift_segments WHERE shift_id = ?', array((int) $shift_id));
        foreach ($segments as $i => $seg) {
            $this->db->insert('shift_segments', array('shift_id' => (int) $shift_id, 'segment_no' => $i + 1, 'start_time' => $seg[0], 'end_time' => $seg[1]));
        }
    }

    /** "10:00-14:00 + 18:00-22:00" of a list of array(start, end) / shift_segments rows ('' for none) */
    protected function segment_text(array $segments)
    {
        $parts = array();
        foreach ($segments as $seg) {
            $start = isset($seg['start_time']) ? $seg['start_time'] : $seg[0];
            $end = isset($seg['end_time']) ? $seg['end_time'] : $seg[1];
            $parts[] = substr($start, 0, 5) . '-' . substr($end, 0, 5);
        }
        return implode(' + ', $parts);
    }

    /**
     * The posted duty periods of a split shift: 2 to MAX_PERIODS, each at least a minute long, in time order with a gap of at least a
     * minute between them, all within 24 hours of the first start (the last one may run past midnight).
     * @return array|string list of array(start, end), or the error
     */
    protected function read_periods(Input_validator $v)
    {
        $starts = $v->time_list('seg_start', Shift_calculator::MAX_PERIODS);
        $ends = $v->time_list('seg_end', Shift_calculator::MAX_PERIODS);
        if ( ! $v->ok()) {
            return $v->message();
        }
        if (count($starts) !== count($ends) || count($starts) < 2) {
            return t('shift_periods_count');
        }
        $segments = array();
        $prev_end = NULL;
        $prev_start = NULL;
        $prev_length = 0;
        $first_start = NULL;
        foreach ($starts as $i => $start) {
            $s = Shift_calculator::minutes($start);
            $length = (Shift_calculator::minutes($ends[$i]) - $s + 1440) % 1440;
            if ($length === 0) {
                return sprintf(t('shift_period_empty'), $i + 1);
            }
            if ($prev_end !== NULL) {
                $gap = ($s - ($prev_end % 1440) + 1440) % 1440;
                $inside_previous = (($s - $prev_start + 1440) % 1440) < $prev_length;       // starts while the previous period is still running
                if ($gap === 0 || $inside_previous) {
                    return sprintf(t('shift_period_gap'), $i + 1);
                }
                $s = $prev_end + $gap;
            } else {
                $first_start = $s;
            }
            $prev_start = $s % 1440;
            $prev_length = $length;
            $prev_end = $s + $length;
            if ($prev_end - $first_start > 1440) {
                return t('shift_periods_span');
            }
            $segments[] = array($start, $ends[$i]);
        }
        return $segments;
    }

    protected function read_form()
    {
        $v = new Input_validator($this->input);
        $split = $v->flag('is_split');
        $data = array(
            'shift_name'      => $v->text('shift_name', 100, TRUE, 2),
            'shift_code'      => $v->text('shift_code', 20),
            'start_time'      => $split ? NULL : $v->time('start_time'),
            'end_time'        => $split ? NULL : $v->time('end_time'),
            'break_duration'  => $split ? 0 : (int) $v->int('break_duration', 0, 720, FALSE),
            'working_hours'   => $split ? 0.0 : (float) $v->money('working_hours', 0, 24, FALSE),
            'grace_time'      => (int) $v->int('grace_time', 0, 240, FALSE),
            'shift_allowance' => (float) $v->money('shift_allowance', 0, 99999999.99, FALSE),
            'status'          => $v->enum('status', array('Active', 'Inactive')),
        );
        $segments = $split ? $this->read_periods($v) : array();
        if ( ! $v->ok()) {
            return $v->message();
        }
        if (is_string($segments)) {
            return $segments;
        }
        $data['shift_code'] = strtoupper($data['shift_code']);
        if ( ! preg_match('/^[A-Z0-9_-]{1,20}$/', $data['shift_code'])) {
            return t('branches_code_rule');
        }
        if ($split) {
            $data['start_time'] = $segments[0][0];
            $data['end_time'] = $segments[count($segments) - 1][1];
            $minutes = 0;
            foreach ($segments as $seg) {
                $minutes += (Shift_calculator::minutes($seg[1]) - Shift_calculator::minutes($seg[0]) + 1440) % 1440;
            }
            $data['working_hours'] = round($minutes / 60, 2);
            $data['is_split'] = 1;
            $data['is_night'] = Shift_calculator::minutes($data['end_time']) <= Shift_calculator::minutes($data['start_time']) ? 1 : 0;
            $data['segments'] = $segments;
            return $data;
        }
        if ($data['start_time'] === $data['end_time']) {
            return t('shift_same_times');
        }
        $data['is_split'] = 0;
        $data['is_night'] = Shift_calculator::minutes($data['end_time']) < Shift_calculator::minutes($data['start_time']) ? 1 : 0;
        $span = Shift_calculator::expected_minutes(array_merge($data, array('working_hours' => 0)));
        if ($data['break_duration'] >= $span) {
            return t('shift_break_too_long');
        }
        if ($data['working_hours'] * 60 > $span) {
            return t('shift_hours_too_long');
        }
        $data['segments'] = array();
        return $data;
    }
}
