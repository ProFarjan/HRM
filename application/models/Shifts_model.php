<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Shifts of the current branch. is_night is derived (end < start); delete only while nothing uses the shift. */
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
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array(
                'id'        => (int) $r['id'],
                'name'      => e($r['shift_name']) . ((int) $r['is_night'] ? ' <span class="label label-primary"><i class="fa fa-moon-o"></i> ' . e(t('shift_night')) . '</span>' : ''),
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
        return $row ? $row : NULL;
    }

    /** id => "Name (08:00-17:00)" of the active shifts of the current branch */
    public function active_list()
    {
        $out = array();
        foreach ($this->db->query("SELECT id, shift_name, start_time, end_time FROM shifts WHERE branch_id = ? AND status = 'Active' ORDER BY start_time, shift_name", array((int) current_branch_id()))->result_array() as $s) {
            $out[(int) $s['id']] = $s['shift_name'] . ' (' . substr($s['start_time'], 0, 5) . '-' . substr($s['end_time'], 0, 5) . ')';
        }
        return $out;
    }

    /** every active shift of every branch, for the global default shift setting: id => "Branch: Name (..)" */
    public function all_active_list()
    {
        $out = array();
        foreach ($this->db->query("SELECT s.id, s.shift_name, s.start_time, s.end_time, b.name AS branch FROM shifts s JOIN branches b ON b.id = s.branch_id WHERE s.status = 'Active' ORDER BY b.name, s.start_time")->result_array() as $s) {
            $out[(int) $s['id']] = $s['branch'] . ': ' . $s['shift_name'] . ' (' . substr($s['start_time'], 0, 5) . '-' . substr($s['end_time'], 0, 5) . ')';
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
            $data = array_merge($data, array('branch_id' => (int) current_branch_id(), 'created_by' => current_user_id(), 'created_at' => now_local()));
            $this->db->insert('shifts', $data);
            $new_id = (int) $this->db->insert_id();
            $this->audit('shifts', $new_id, 'create', NULL, $data);
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
        list($ok, $message) = Tx::run(function () use ($id, $data) {
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
            if ( ! $changes) {
                return TRUE;
            }
            $this->db->where('id', $id)->update('shifts', array_merge($changes, array('updated_at' => now_local())));
            $this->audit('shifts', $id, 'update', array_intersect_key($old, $changes), $changes);
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
            $this->db->where('id', $id)->delete('shifts');
            $this->audit('shifts', $id, 'delete', $old, NULL);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    protected function read_form()
    {
        $v = new Input_validator($this->input);
        $data = array(
            'shift_name'      => $v->text('shift_name', 100, TRUE, 2),
            'shift_code'      => $v->text('shift_code', 20),
            'start_time'      => $v->time('start_time'),
            'end_time'        => $v->time('end_time'),
            'break_duration'  => (int) $v->int('break_duration', 0, 720, FALSE),
            'working_hours'   => (float) $v->money('working_hours', 0, 24, FALSE),
            'grace_time'      => (int) $v->int('grace_time', 0, 240, FALSE),
            'shift_allowance' => (float) $v->money('shift_allowance', 0, 99999999.99, FALSE),
            'status'          => $v->enum('status', array('Active', 'Inactive')),
        );
        if ( ! $v->ok()) {
            return $v->message();
        }
        $data['shift_code'] = strtoupper($data['shift_code']);
        if ( ! preg_match('/^[A-Z0-9_-]{1,20}$/', $data['shift_code'])) {
            return t('branches_code_rule');
        }
        if ($data['start_time'] === $data['end_time']) {
            return t('shift_same_times');
        }
        $data['is_night'] = Shift_calculator::minutes($data['end_time']) < Shift_calculator::minutes($data['start_time']) ? 1 : 0;
        $span = Shift_calculator::expected_minutes(array_merge($data, array('working_hours' => 0)));
        if ($data['break_duration'] >= $span) {
            return t('shift_break_too_long');
        }
        if ($data['working_hours'] * 60 > $span) {
            return t('shift_hours_too_long');
        }
        return $data;
    }
}
