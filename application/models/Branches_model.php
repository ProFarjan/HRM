<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Branches: name, code, address (printed on payslips of the branch), phone, timezone (business dates of the branch),
 * weekly offs (CSV of ISO weekdays, NULL = use the global setting), status. Branches are never deleted, only deactivated.
 */
class Branches_model extends MY_Model
{
    public function list_page()
    {
        $dt = new Datatable_request($this->input, array('b.name', 'b.code', 'b.timezone', 'b.weekly_offs', 'users', 'b.status'));
        $total = (int) $this->db->query('SELECT COUNT(*) AS c FROM branches')->row()->c;
        $filter = function () use ($dt) {
            $this->db->from('branches b');
            if ($dt->search !== '') {
                $this->db->group_start()->like('b.name', $dt->search)->or_like('b.code', $dt->search)->group_end();
            }
        };
        $filter();
        $filtered = $this->db->count_all_results();
        $filter();
        $this->db->select('b.id, b.name, b.code, b.timezone, b.weekly_offs, b.status, (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id) AS users', FALSE);
        $dt->apply($this->db);
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array(
                'id'          => (int) $r['id'],
                'name'        => e($r['name']) . ((int) $r['id'] === (int) current_branch_id() ? ' <span class="label label-primary">' . e(t('branches_current')) . '</span>' : ''),
                'code'        => e($r['code']),
                'timezone'    => e($r['timezone']),
                'weekly_offs' => e($this->weekly_offs_label($r['weekly_offs'])),
                'users'       => (int) $r['users'],
                'status'      => $r['status'] === 'Active' ? '<span class="label label-success">' . e(t('status_active')) . '</span>' : '<span class="label label-default">' . e(t('status_inactive')) . '</span>',
            );
        }
        return $dt->response($total, $filtered, $rows);
    }

    public function get($id)
    {
        $row = $this->db->query('SELECT * FROM branches WHERE id = ?', array((int) $id))->row_array();
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
            if ($this->db->query('SELECT 1 FROM branches WHERE code = ? FOR UPDATE', array($data['code']))->num_rows() > 0) {
                return t('branches_code_taken');
            }
            $data['created_at'] = now_local();
            $this->db->insert('branches', $data);
            $new_id = (int) $this->db->insert_id();
            unset($data['created_at']);
            $this->audit('branches', $new_id, 'create', NULL, $data);
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
            $old = $this->db->query('SELECT name, code, address, phone, timezone, weekly_offs, status FROM branches WHERE id = ? FOR UPDATE', array($id))->row_array();
            if ( ! $old) {
                return t('not_found');
            }
            if ($this->db->query('SELECT 1 FROM branches WHERE code = ? AND id <> ?', array($data['code'], $id))->num_rows() > 0) {
                return t('branches_code_taken');
            }
            if ($old['status'] === 'Active' && $data['status'] === 'Inactive') {
                $user = current_user();
                if ($id === (int) $user['home_branch_id'] || $id === (int) current_branch_id()) {
                    return t('branches_not_own');
                }
                if ((int) $this->db->query("SELECT COUNT(*) AS c FROM branches WHERE status = 'Active' AND id <> ?", array($id))->row()->c === 0) {
                    return t('branches_last_active');
                }
            }
            $changes = array();
            foreach ($data as $k => $val) {
                if ((string) $old[$k] !== (string) $val || ($old[$k] === NULL) !== ($val === NULL)) {
                    $changes[$k] = $val;
                }
            }
            if ( ! $changes) {
                return TRUE;
            }
            $this->db->where('id', $id)->update('branches', array_merge($changes, array('updated_at' => now_local())));
            $this->audit('branches', $id, isset($changes['status']) ? ($changes['status'] === 'Active' ? 'activate' : 'deactivate') : 'update', array_intersect_key($old, $changes), $changes);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    /** the posted branch_id if it is an active branch (header switcher), else NULL */
    public function switch_target()
    {
        $v = new Input_validator($this->input);
        $id = $v->int('branch_id', 1);
        if ( ! $v->ok()) {
            return NULL;
        }
        $row = $this->db->query("SELECT id, name FROM branches WHERE id = ? AND status = 'Active'", array($id))->row_array();
        return $row ? $row : NULL;
    }

    /** "Fri, Sat" / "Global (Fri)" / "None" */
    public function weekly_offs_label($csv)
    {
        $names = array(1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun');
        $to_names = function ($value) use ($names) {
            $out = array();
            foreach (explode(',', (string) $value) as $d) {
                if (isset($names[(int) $d])) {
                    $out[] = $names[(int) $d];
                }
            }
            return $out ? implode(', ', $out) : t('weekly_offs_none');
        };
        if ($csv === NULL) {
            return t('weekly_offs_global') . ' (' . $to_names(hr_setting('weekly_offs', '5')) . ')';
        }
        return $to_names($csv);
    }

    /** validated form values, or an error message */
    protected function read_form()
    {
        $v = new Input_validator($this->input);
        $name = $v->text('name', 150, TRUE, 2);
        $code = $v->text('code', 20);
        $address = $v->text('address', 255, FALSE);
        $phone = $v->text('phone', 30, FALSE);
        $timezone = $v->enum('timezone', DateTimeZone::listIdentifiers());
        $mode = $v->enum('weekly_offs_mode', array('global', 'custom'));
        $days = $mode === 'custom' ? $v->int_list('weekly_offs', 7, FALSE) : array();
        $status = $v->enum('status', array('Active', 'Inactive'));
        if ( ! $v->ok()) {
            return $v->message();
        }
        $code = strtoupper($code);
        if ( ! preg_match('/^[A-Z0-9_-]{1,20}$/', $code)) {
            return t('branches_code_rule');
        }
        foreach ($days as $d) {
            if ($d < 1 || $d > 7) {
                return sprintf(t('v_enum'), 'Weekly offs');
            }
        }
        sort($days);
        return array(
            'name' => $name, 'code' => $code, 'address' => $address, 'phone' => $phone, 'timezone' => $timezone,
            'weekly_offs' => $mode === 'global' ? NULL : implode(',', $days), 'status' => $status,
        );
    }
}
