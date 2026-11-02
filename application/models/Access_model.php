<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * "Does this id exist, in my branch, inside my own-records scope?" - the single implementation behind
 * Input_validator::ref() and MY_Controller::belong_to(). Tables must be registered in config/hrm.php scoped_tables.
 */
class Access_model extends MY_Model
{
    /** @return bool */
    public function record_in_scope($table, $id)
    {
        $tables = (array) config_item('scoped_tables');
        $id = (int) $id;
        if ( ! is_string($table) || ! isset($tables[$table])) {
            log_message('error', 'Access_model: table not registered in scoped_tables: ' . (is_string($table) ? $table : gettype($table)));
            return FALSE;
        }
        if ($id < 1 || ! current_user()) {
            return FALSE;
        }
        $cfg = $tables[$table];

        $restrict_to_own = ! empty($cfg['own']) && ! empty($cfg['module']) && ! has_permission('show_all_' . $cfg['module']);
        $this->db->from($table)->where('id', $id);
        if ( ! empty($cfg['branch'])) {
            $branch_id = current_branch_id();
            $this->db->where($cfg['branch'], $branch_id ? (int) $branch_id : 0);
        }
        if ($restrict_to_own) {
            // the same rule as the lists: own rows, plus the employees of a department the user heads (scope_department)
            $this->db->where($this->department_scope_sql($table . '.' . $cfg['own'], current_employee_id()), NULL, FALSE);
        }
        return $this->db->count_all_results() > 0;
    }
}
