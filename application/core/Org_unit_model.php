<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Shared logic of departments and designations: one row per branch, code unique per branch, status toggle,
 * delete only while no employee uses it. Subclasses name the table and add their own fields.
 */
abstract class Org_unit_model extends MY_Model
{
    /** @var string table name */
    protected $table = '';
    /** @var string column prefix: department | designation */
    protected $prefix = '';
    /** @var string literal "SELECT * FROM <table> WHERE id = ? AND branch_id = ? FOR UPDATE" (Query Builder has no FOR UPDATE) */
    protected $lock_sql = '';

    /** extra validated fields of the subclass (array) or NULL; may call $v->fail() */
    abstract protected function extra_fields(Input_validator $v, $id);

    /** extra list cells of the subclass for one row */
    abstract protected function extra_cells(array $row);

    /** extra select expression for the list (or '') */
    abstract protected function extra_select();

    public function list_page()
    {
        $name = $this->prefix . '_name';
        $code = $this->prefix . '_code';
        $dt = new Datatable_request($this->input, array('u.' . $name, 'u.' . $code, 'extra', 'employees', 'u.status'));
        $v = new Input_validator($this->input);
        $status = $v->enum('status', array('Active', 'Inactive'), FALSE);

        $this->db->from($this->table . ' u');
        $this->scope_branch('u');
        $total = $this->db->count_all_results();

        $filter = function () use ($dt, $status, $name, $code) {
            $this->db->from($this->table . ' u');
            $this->scope_branch('u');
            if ($status !== NULL) {
                $this->db->where('u.status', $status);
            }
            if ($dt->search !== '') {
                $this->db->group_start()->like('u.' . $name, $dt->search)->or_like('u.' . $code, $dt->search)->group_end();
            }
        };
        $filter();
        $filtered = $this->db->count_all_results();

        $filter();
        $fk = $this->prefix . '_id';
        $this->db->select('u.*, (SELECT COUNT(*) FROM employees e WHERE e.' . $fk . ' = u.id) AS employees' . $this->extra_select(), FALSE);
        $dt->apply($this->db);
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array_merge(array(
                'id'        => (int) $r['id'],
                'name'      => e($r[$name]),
                'code'      => e($r[$code]),
                'employees' => (int) $r['employees'],
                'status'    => $r['status'] === 'Active' ? '<span class="label label-success">' . e(t('status_active')) . '</span>' : '<span class="label label-default">' . e(t('status_inactive')) . '</span>',
            ), $this->extra_cells($r));
        }
        return $dt->response($total, $filtered, $rows);
    }

    public function get($id)
    {
        $this->db->from($this->table)->where('id', (int) $id);
        $this->scope_branch();
        $row = $this->db->get()->row_array();
        return $row ? $row : NULL;
    }

    /** id => name of the active rows of the current branch (selects) */
    public function active_list()
    {
        $out = array();
        $rows = $this->db->select('id, ' . $this->prefix . '_name AS name')->from($this->table)
            ->where('branch_id', (int) current_branch_id())->where('status', 'Active')->order_by('name')->get()->result_array();
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r['name'];
        }
        return $out;
    }

    public function create()
    {
        $data = $this->read_form(NULL);
        if ( ! is_array($data)) {
            return $data;
        }
        $new_id = 0;
        list($ok, $message) = Tx::run(function () use ($data, &$new_id) {
            if ($this->code_taken($data[$this->prefix . '_code'], 0)) {
                return t('org_code_taken');
            }
            $data['branch_id'] = (int) current_branch_id();
            $data['created_by'] = current_user_id();
            $data['created_at'] = now_local();
            $this->db->insert($this->table, $data);
            $new_id = (int) $this->db->insert_id();
            $this->audit($this->table, $new_id, 'create', NULL, $data);
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $new_id : $message;
    }

    public function update($id)
    {
        $id = (int) $id;
        $data = $this->read_form($id);
        if ( ! is_array($data)) {
            return $data;
        }
        list($ok, $message) = Tx::run(function () use ($id, $data) {
            $old = $this->lock($id);
            if ( ! $old) {
                return t('not_found');
            }
            if ($this->code_taken($data[$this->prefix . '_code'], $id)) {
                return t('org_code_taken');
            }
            $changes = array();
            foreach ($data as $k => $val) {
                if ((string) $old[$k] !== (string) $val || (($old[$k] === NULL) !== ($val === NULL))) {
                    $changes[$k] = $val;
                }
            }
            if ( ! $changes) {
                return TRUE;
            }
            $this->db->where('id', $id)->update($this->table, array_merge($changes, array('updated_at' => now_local())));
            $action = isset($changes['status']) ? ($changes['status'] === 'Active' ? 'activate' : 'deactivate') : 'update';
            $this->audit($this->table, $id, $action, array_intersect_key($old, $changes), $changes);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    public function delete($id)
    {
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id) {
            $old = $this->lock($id);
            if ( ! $old) {
                return t('not_found');
            }
            $used = $this->db->from('employees')->where($this->prefix . '_id', $id)->count_all_results();
            if ($used) {
                return t('org_in_use');
            }
            $this->db->where('id', $id)->delete($this->table);
            $this->audit($this->table, $id, 'delete', $old, NULL);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    // ------------------------------------------------------------------------------------------------ internals

    protected function lock($id)
    {
        $row = $this->db->query($this->lock_sql, array((int) $id, (int) current_branch_id()))->row_array();
        return $row ? $row : NULL;
    }

    protected function code_taken($code, $except_id)
    {
        return $this->db->from($this->table)->where('branch_id', (int) current_branch_id())
            ->where($this->prefix . '_code', $code)->where('id <>', (int) $except_id)->count_all_results() > 0;
    }

    protected function read_form($id)
    {
        $v = new Input_validator($this->input);
        $data = array(
            $this->prefix . '_name' => $v->text($this->prefix . '_name', 120, TRUE, 2),
            $this->prefix . '_code' => $v->text($this->prefix . '_code', 20),
            'description'           => $v->text('description', 255, FALSE),
            'status'                => $v->enum('status', array('Active', 'Inactive')),
        );
        $extra = $this->extra_fields($v, $id);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $data[$this->prefix . '_code'] = strtoupper($data[$this->prefix . '_code']);
        if ( ! preg_match('/^[A-Z0-9_-]{1,20}$/', $data[$this->prefix . '_code'])) {
            return t('branches_code_rule');
        }
        return array_merge($data, (array) $extra);
    }
}
