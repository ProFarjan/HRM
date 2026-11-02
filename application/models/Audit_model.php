<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Audit log viewer. Rows of the current branch; rows without a branch (CLI / system) only for the Admin role.
 * Date range predicates on created_at (default last 7 days, at most 366 days), whitelisted sort, page cap (G5).
 */
class Audit_model extends MY_Model
{
    const MAX_RANGE_DAYS = 366;

    public function list_page()
    {
        $dt = new Datatable_request($this->input, array('a.created_at', 'u.username', 'a.module', 'a.action', 'a.record_id', 'a.ip'), 0, 'desc');
        $v = new Input_validator($this->input);
        $from = $v->date('from_date', FALSE);
        $to = $v->date('to_date', FALSE);
        $module = $v->text('module', 40, FALSE);
        $action = $v->text('action', 40, FALSE);
        $user_id = $v->int('user_id', 1, NULL, FALSE);
        if ( ! $v->ok()) {
            return array('error' => $v->message());
        }
        list($from, $to, $error) = $this->range($from, $to);
        if ($error !== NULL) {
            return array('error' => $error);
        }

        $base = function () use ($from, $to) {
            $this->db->from('audit_log a')->where('a.created_at >=', $from . ' 00:00:00')->where('a.created_at <=', $to . ' 23:59:59');
            $this->scope_audit('a');
        };
        $base();
        $total = $this->db->count_all_results();

        $filter = function () use ($base, $dt, $module, $action, $user_id) {
            $base();
            $this->db->join('users u', 'u.id = a.user_id', 'left');
            if ($module !== NULL) {
                $this->db->where('a.module', $module);
            }
            if ($action !== NULL) {
                $this->db->where('a.action', $action);
            }
            if ($user_id !== NULL) {
                $this->db->where('a.user_id', $user_id);
            }
            if ($dt->search !== '') {
                $this->db->group_start()->like('u.username', $dt->search)->or_like('a.module', $dt->search)
                    ->or_like('a.action', $dt->search)->or_where('a.record_id', $dt->search)->group_end();
            }
        };
        $filter();
        $filtered = $this->db->count_all_results();

        $filter();
        $this->db->select('a.id, a.created_at, a.module, a.action, a.record_id, a.ip, u.username');
        $dt->apply($this->db);
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array(
                'id'        => (int) $r['id'],
                'when'      => e(show_date($r['created_at']) . substr($r['created_at'], 10)),
                'user'      => e($r['username'] !== NULL ? $r['username'] : '-'),
                'module'    => e($r['module']),
                'action'    => e($r['action']),
                'record_id' => e((string) $r['record_id']),
                'ip'        => e((string) $r['ip']),
            );
        }
        return $dt->response($total, $filtered, $rows);
    }

    /** one entry (in scope) with decoded values, or NULL */
    public function get($id)
    {
        $this->db->select('a.*, u.username')->from('audit_log a')->join('users u', 'u.id = a.user_id', 'left')->where('a.id', (int) $id);
        $this->scope_audit('a');
        $row = $this->db->get()->row_array();
        if ( ! $row) {
            return NULL;
        }
        $row['old'] = $row['old_values'] === NULL ? NULL : json_decode($row['old_values'], TRUE);
        $row['new'] = $row['new_values'] === NULL ? NULL : json_decode($row['new_values'], TRUE);
        return $row;
    }

    /** distinct modules for the filter (bounded by the index on module) */
    public function modules()
    {
        $out = array();
        foreach ($this->db->query('SELECT DISTINCT module FROM audit_log ORDER BY module LIMIT 100')->result_array() as $r) {
            $out[] = $r['module'];
        }
        return $out;
    }

    /** users of the current branch for the filter: id => username */
    public function users()
    {
        $out = array();
        foreach ($this->db->query('SELECT id, username FROM users WHERE branch_id = ? ORDER BY username', array((int) current_branch_id()))->result_array() as $r) {
            $out[(int) $r['id']] = $r['username'];
        }
        return $out;
    }

    protected function scope_audit($alias)
    {
        $branch_id = (int) current_branch_id();
        $user = current_user();
        if ( ! empty($user['is_system'])) {
            $this->db->group_start()->where($alias . '.branch_id', $branch_id)->or_where($alias . '.branch_id IS NULL', NULL, FALSE)->group_end();
        } else {
            $this->db->where($alias . '.branch_id', $branch_id);
        }
    }

    /** array(from, to, error): defaults to the last 7 days; at most MAX_RANGE_DAYS */
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
