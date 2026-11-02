<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Base model: branch / own-records / department scoping for list queries, and the audit trail.
 *
 * All SQL lives in models. Query Builder or `?` bindings only; request values arrive through Input_validator.
 * Multi-row writes run inside Tx::run(). Methods return "success", "success<<<###>>>$id" or a human-readable error
 * string that the page JS shows with toastr.
 */
class MY_Model extends CI_Model
{
    /** module name used by scope_own(): the permission "show_all_<module>" lifts the own-records restriction */
    protected $module = '';

    public function __construct()
    {
        parent::__construct();
    }

    // ------------------------------------------------------------------------------------------------ scopes

    /** WHERE alias.branch_id = current branch. A request without a branch matches nothing. */
    protected function scope_branch($alias = '', $column = 'branch_id')
    {
        $branch_id = current_branch_id();
        $this->db->where($this->column($alias, $column), $branch_id ? (int) $branch_id : 0);
        return $this;
    }

    /**
     * "Own records only": unless the user holds show_all_<module>, rows are limited to current_employee_id().
     * A login without an employee link sees nothing.
     */
    protected function scope_own($alias = '', $column = 'employee_id', $permission = NULL)
    {
        if ($this->may_see_all($permission)) {
            return $this;
        }
        $employee_id = current_employee_id();
        $this->db->where($this->column($alias, $column), $employee_id ? $employee_id : 0);
        return $this;
    }

    /**
     * A department head additionally sees the employees of the departments they head.
     * (Adds the head's own rows too, so the result is "own + my department".)
     */
    protected function scope_department($alias = '', $column = 'employee_id', $permission = NULL)
    {
        if ($this->may_see_all($permission)) {
            return $this;
        }
        $this->db->where($this->department_scope_sql($this->column($alias, $column), current_employee_id()), NULL, FALSE);
        return $this;
    }

    /** SQL fragment used by scope_department() (also unit-tested on its own) */
    protected function department_scope_sql($qualified_column, $employee_id)
    {
        $employee_id = $employee_id ? (int) $employee_id : 0;
        return '(' . $qualified_column . ' = ' . $employee_id
            . ' OR ' . $qualified_column . ' IN (SELECT e.id FROM employees e WHERE e.department_id IN'
            . ' (SELECT d.id FROM departments d WHERE d.head_employee_id = ' . $employee_id . ')))';
    }

    protected function may_see_all($permission = NULL)
    {
        $permission = $permission !== NULL ? $permission : 'show_all_' . $this->module;
        if ($this->module === '' && $permission === 'show_all_') {
            throw new LogicException(get_class($this) . ': set $module (or pass a permission key) before using scope_own()/scope_department()');
        }
        $catalog = permission_catalog_keys();
        if ( ! isset($catalog[$permission])) {
            throw new LogicException('Unknown permission for own-records scope: ' . $permission);
        }
        return has_permission($permission);
    }

    /** "alias.column" with both parts checked as plain identifiers (never request data) */
    protected function column($alias, $column)
    {
        if ( ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $column) || ($alias !== '' && ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $alias))) {
            throw new InvalidArgumentException('Invalid column or alias');
        }
        return $alias === '' ? $column : $alias . '.' . $column;
    }

    // ------------------------------------------------------------------------------------------------ audit

    /**
     * G7 - audit_log row for approve, reject, pay, delete, close/reopen, settings change, salary-structure change,
     * role change, device key change and every manual override of a calculated payroll value
     * (old = calculated, new = saved, with the reason inside $new). Call it inside the caller's Tx::run() so a
     * rolled back action leaves no audit row.
     */
    protected function audit($module, $record_id, $action, $old = NULL, $new = NULL)
    {
        $this->db->insert('audit_log', array(
            'branch_id'  => current_branch_id(),
            'user_id'    => current_user_id(),
            'module'     => (string) $module,
            'record_id'  => $record_id === NULL ? NULL : (string) $record_id,
            'action'     => (string) $action,
            'old_values' => $old === NULL ? NULL : json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_values' => $new === NULL ? NULL : json_encode($new, JSON_UNESCAPED_UNICODE),
            'ip'         => (is_cli() ? NULL : client_ip()),
            'created_at' => now_local(),
        ));
        return $this->db->insert_id();
    }
}
