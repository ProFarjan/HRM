<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Departments of the current branch; the head is an active employee of the same branch (scope_department uses it). */
class Departments_model extends Org_unit_model
{
    protected $table = 'departments';
    protected $prefix = 'department';
    protected $lock_sql = 'SELECT * FROM departments WHERE id = ? AND branch_id = ? FOR UPDATE';

    protected function extra_fields(Input_validator $v, $id)
    {
        $head = $v->ref('head_employee_id', 'employees', FALSE);
        if ($head !== NULL && $this->db->query("SELECT 1 FROM employees WHERE id = ? AND status IN ('Active','On Leave')", array($head))->num_rows() === 0) {
            $v->fail('head_employee_id', t('departments_head_inactive'));
        }
        return array('head_employee_id' => $head);
    }

    protected function extra_select()
    {
        return ", (SELECT CONCAT(h.first_name, ' ', COALESCE(h.last_name, '')) FROM employees h WHERE h.id = u.head_employee_id) AS head_name";
    }

    protected function extra_cells(array $row)
    {
        return array('extra' => e(trim((string) $row['head_name'])));
    }
}
