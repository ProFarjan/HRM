<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Holidays of the current branch: one per date; optional holidays are not days off; copy last year's fixed-date holidays. */
class Holidays_model extends MY_Model
{
    public function list_page()
    {
        $dt = new Datatable_request($this->input, array('h.holiday_date', 'h.holiday_name', 'h.is_optional', 'h.is_fixed_date', 'h.status'));
        $v = new Input_validator($this->input);
        $year = $v->int('year', 1900, 2999, FALSE);
        $year = $year !== NULL ? $year : (int) date('Y');
        $base = function () use ($year) {
            $this->db->from('holidays h')->where('h.holiday_date >=', $year . '-01-01')->where('h.holiday_date <=', $year . '-12-31');
            $this->scope_branch('h');
        };
        $base();
        $total = $this->db->count_all_results();
        $base();
        if ($dt->search !== '') {
            $this->db->like('h.holiday_name', $dt->search);
        }
        $filtered = $this->db->count_all_results();
        $base();
        if ($dt->search !== '') {
            $this->db->like('h.holiday_name', $dt->search);
        }
        $this->db->select('h.*');
        $dt->apply($this->db);
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array(
                'id'       => (int) $r['id'],
                'date'     => e(show_date($r['holiday_date']) . ' (' . date('D', strtotime($r['holiday_date'])) . ')'),
                'name'     => e($r['holiday_name']),
                'optional' => (int) $r['is_optional'] ? '<span class="label label-info">' . e(t('holiday_optional')) . '</span>' : '',
                'fixed'    => (int) $r['is_fixed_date'] ? '<i class="fa fa-repeat" title="' . e(t('holiday_fixed')) . '"></i>' : '',
                'status'   => $r['status'] === 'Active' ? '<span class="label label-success">' . e(t('status_active')) . '</span>' : '<span class="label label-default">' . e(t('status_inactive')) . '</span>',
            );
        }
        return $dt->response($total, $filtered, $rows);
    }

    /** the year's holidays of the branch, for the year view: month (1-12) => rows */
    public function year_view($year)
    {
        $out = array_fill(1, 12, array());
        foreach ($this->db->query('SELECT * FROM holidays WHERE branch_id = ? AND holiday_date BETWEEN ? AND ? ORDER BY holiday_date',
                    array((int) current_branch_id(), $year . '-01-01', $year . '-12-31'))->result_array() as $r) {
            $out[(int) substr($r['holiday_date'], 5, 2)][] = $r;
        }
        return $out;
    }

    public function get($id)
    {
        $this->db->from('holidays')->where('id', (int) $id);
        $this->scope_branch();
        $row = $this->db->get()->row_array();
        return $row ? $row : NULL;
    }

    public function create()
    {
        $data = $this->read_form();
        if ( ! is_array($data)) {
            return $data;
        }
        $new_id = 0;
        list($ok, $message) = Tx::run(function () use ($data, &$new_id) {
            if ($this->date_taken($data['holiday_date'], 0)) {
                return t('holiday_date_taken');
            }
            $data = array_merge($data, array('branch_id' => (int) current_branch_id(), 'created_by' => current_user_id(), 'created_at' => now_local()));
            $this->db->insert('holidays', $data);
            $new_id = (int) $this->db->insert_id();
            $this->audit('holidays', $new_id, 'create', NULL, $data);
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
            $old = $this->db->query('SELECT * FROM holidays WHERE id = ? AND branch_id = ? FOR UPDATE', array($id, (int) current_branch_id()))->row_array();
            if ( ! $old) {
                return t('not_found');
            }
            if ($this->date_taken($data['holiday_date'], $id)) {
                return t('holiday_date_taken');
            }
            $changes = array();
            foreach ($data as $k => $val) {
                if ((string) $old[$k] !== (string) $val || (($old[$k] === NULL) !== ($val === NULL))) {
                    $changes[$k] = $val;
                }
            }
            if ($changes) {
                $this->db->where('id', $id)->update('holidays', array_merge($changes, array('updated_at' => now_local())));
                $this->audit('holidays', $id, 'update', array_intersect_key($old, $changes), $changes);
            }
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    public function delete($id)
    {
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id) {
            $old = $this->db->query('SELECT * FROM holidays WHERE id = ? AND branch_id = ? FOR UPDATE', array($id, (int) current_branch_id()))->row_array();
            if ( ! $old) {
                return t('not_found');
            }
            $this->db->where('id', $id)->delete('holidays');
            $this->audit('holidays', $id, 'delete', $old, NULL);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    /**
     * Copy the fixed-date holidays of `from_year` into `to_year` (same month and day). Dates that already have a holiday are
     * skipped, and 29 February is skipped in a year without it. "success<<<###>>>copied/skipped" or an error.
     */
    public function copy_year()
    {
        $v = new Input_validator($this->input);
        $from = $v->int('from_year', 1900, 2998);
        $to = $v->int('to_year', 1901, 2999);
        if ( ! $v->ok()) {
            return $v->message();
        }
        if ($from === $to) {
            return t('holiday_copy_same_year');
        }
        $copied = 0;
        $skipped = 0;
        list($ok, $message) = Tx::run(function () use ($from, $to, &$copied, &$skipped) {
            $rows = $this->db->query('SELECT * FROM holidays WHERE branch_id = ? AND is_fixed_date = 1 AND holiday_date BETWEEN ? AND ? ORDER BY holiday_date',
                array((int) current_branch_id(), $from . '-01-01', $from . '-12-31'))->result_array();
            foreach ($rows as $r) {
                $md = substr($r['holiday_date'], 5);
                if ( ! checkdate((int) substr($md, 0, 2), (int) substr($md, 3, 2), $to)) {
                    $skipped++;
                    continue;
                }
                $date = $to . '-' . $md;
                if ($this->date_taken($date, 0)) {
                    $skipped++;
                    continue;
                }
                $this->db->insert('holidays', array('branch_id' => (int) current_branch_id(), 'holiday_name' => $r['holiday_name'], 'holiday_date' => $date,
                    'year' => $to, 'is_optional' => (int) $r['is_optional'], 'is_fixed_date' => 1, 'description' => $r['description'], 'status' => $r['status'],
                    'created_by' => current_user_id(), 'created_at' => now_local()));
                $copied++;
            }
            if ($copied) {
                $this->audit('holidays', NULL, 'copy_year', array('from_year' => $from), array('to_year' => $to, 'copied' => $copied, 'skipped' => $skipped));
            }
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $copied . '/' . $skipped : $message;
    }

    protected function date_taken($date, $except_id)
    {
        return $this->db->query('SELECT 1 FROM holidays WHERE branch_id = ? AND holiday_date = ? AND id <> ? FOR UPDATE', array((int) current_branch_id(), $date, (int) $except_id))->num_rows() > 0;
    }

    protected function read_form()
    {
        $v = new Input_validator($this->input);
        $data = array(
            'holiday_name'  => $v->text('holiday_name', 150, TRUE, 2),
            'holiday_date'  => $v->date('holiday_date'),
            'is_optional'   => $v->flag('is_optional'),
            'is_fixed_date' => $v->flag('is_fixed_date'),
            'description'   => $v->text('description', 255, FALSE),
            'status'        => $v->enum('status', array('Active', 'Inactive')),
        );
        if ( ! $v->ok()) {
            return $v->message();
        }
        $data['year'] = (int) substr($data['holiday_date'], 0, 4);
        return $data;
    }
}
