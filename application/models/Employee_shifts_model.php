<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Shift assignments. Ranges of one employee never overlap: a new assignment must start after the latest one and closes
 * it (effective_to = new start - 1 day); only the latest can be removed (the previous one reopens).
 */
class Employee_shifts_model extends MY_Model
{
    /** current employees of the branch with the shift they have today (open assignment) */
    public function list_page()
    {
        $dt = new Datatable_request($this->input, array('e.employee_code', 'e.first_name', 'd.department_name', 's.shift_name', 'a.effective_from'));
        $v = new Input_validator($this->input);
        $department = $v->int('department_id', 1, NULL, FALSE);
        $shift = $v->enum('shift', array('none', 'any'), FALSE);
        $shift_id = $v->int('shift_id', 1, NULL, FALSE);

        $this->db->from('employees e')->where_in('e.status', array('Active', 'On Leave'));
        $this->scope_branch('e');
        $total = $this->db->count_all_results();

        $filter = function () use ($dt, $department, $shift, $shift_id) {
            $this->db->from('employees e')
                ->join('employee_shifts a', 'a.employee_id = e.id AND a.effective_to IS NULL', 'left')
                ->join('shifts s', 's.id = a.shift_id', 'left')
                ->join('departments d', 'd.id = e.department_id', 'left')
                ->where_in('e.status', array('Active', 'On Leave'));
            $this->scope_branch('e');
            if ($department !== NULL) {
                $this->db->where('e.department_id', $department);
            }
            if ($shift === 'none') {
                $this->db->where('a.id IS NULL', NULL, FALSE);
            }
            if ($shift_id !== NULL) {
                $this->db->where('a.shift_id', $shift_id);
            }
            if ($dt->search !== '') {
                $this->db->group_start()->like('e.employee_code', $dt->search)->or_like('e.first_name', $dt->search)->or_like('e.last_name', $dt->search)->group_end();
            }
        };
        $filter();
        $filtered = $this->db->count_all_results();
        $filter();
        $this->db->select('e.id, e.employee_code, e.first_name, e.last_name, d.department_name, s.shift_name, s.start_time, s.end_time, a.effective_from');
        $dt->apply($this->db);
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array(
                'id'         => (int) $r['id'],
                'code'       => e($r['employee_code']),
                'name'       => e(trim($r['first_name'] . ' ' . $r['last_name'])),
                'department' => e((string) $r['department_name']),
                'shift'      => $r['shift_name'] === NULL ? '<span class="text-muted">' . e(t('shift_none')) . '</span>' : e($r['shift_name'] . ' (' . substr($r['start_time'], 0, 5) . '-' . substr($r['end_time'], 0, 5) . ')'),
                'since'      => e(show_date($r['effective_from'])),
            );
        }
        return $dt->response($total, $filtered, $rows);
    }

    public function history($employee_id)
    {
        return $this->db->query(
            'SELECT a.id, a.effective_from, a.effective_to, s.shift_name, s.shift_code, s.start_time, s.end_time
             FROM employee_shifts a JOIN shifts s ON s.id = a.shift_id WHERE a.employee_id = ? ORDER BY a.effective_from DESC',
            array((int) $employee_id)
        )->result_array();
    }

    /** assign the posted shift to one employee from the posted date */
    public function assign($employee_id)
    {
        $v = new Input_validator($this->input);
        $shift_id = $this->read_shift($v);
        $from = $v->date('effective_from');
        if ( ! $v->ok()) {
            return $v->message();
        }
        $employee_id = (int) $employee_id;
        $new_id = 0;
        list($ok, $message) = Tx::run(function () use ($employee_id, $shift_id, $from, &$new_id) {
            $result = $this->assign_one($employee_id, $shift_id, $from);
            if ( ! is_int($result)) {
                return $result;
            }
            $new_id = $result;
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $new_id : $message;
    }

    /**
     * Assign a shift to every current employee of a department (or of the branch) from a date, in one transaction.
     * Employees whose latest assignment starts on/after the date are skipped and reported.
     * @return string "success<<<###>>>assigned/skipped" or an error
     */
    public function bulk_assign()
    {
        $v = new Input_validator($this->input);
        $shift_id = $this->read_shift($v);
        $from = $v->date('effective_from');
        $department = $v->ref('department_id', 'departments', FALSE);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $this->db->select('id')->from('employees')->where_in('status', array('Active', 'On Leave'))->where('join_date <=', $from);
        $this->scope_branch();
        if ($department !== NULL) {
            $this->db->where('department_id', $department);
        }
        $ids = array_map('intval', array_column($this->db->get()->result_array(), 'id'));
        if ( ! $ids) {
            return t('shift_bulk_nobody');
        }
        $assigned = 0;
        $skipped = 0;
        list($ok, $message) = Tx::run(function () use ($ids, $shift_id, $from, &$assigned, &$skipped) {
            foreach ($ids as $id) {
                $result = $this->assign_one($id, $shift_id, $from);
                if (is_int($result)) {
                    $assigned++;
                } elseif ($result === 'same') {
                    $skipped++;
                } else {
                    $skipped++;
                }
            }
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $assigned . '/' . $skipped : $message;
    }

    /** remove the latest assignment (a mistake) and reopen the previous one */
    public function remove_latest($employee_id, $assignment_id)
    {
        $employee_id = (int) $employee_id;
        $assignment_id = (int) $assignment_id;
        list($ok, $message) = Tx::run(function () use ($employee_id, $assignment_id) {
            if ( ! $this->db->query('SELECT id FROM employees WHERE id = ? AND branch_id = ? FOR UPDATE', array($employee_id, (int) current_branch_id()))->row_array()) {
                return t('not_found');
            }
            $rows = $this->db->query('SELECT * FROM employee_shifts WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 2 FOR UPDATE', array($employee_id))->result_array();
            if ( ! $rows || (int) $rows[0]['id'] !== $assignment_id) {
                return t('shift_only_latest');
            }
            $this->db->where('id', $assignment_id)->delete('employee_shifts');
            if (isset($rows[1])) {
                $this->db->where('id', (int) $rows[1]['id'])->update('employee_shifts', array('effective_to' => NULL, 'is_active' => 1));
            }
            $this->audit('employee_shifts', $assignment_id, 'delete', $rows[0], isset($rows[1]) ? array('reopened' => (int) $rows[1]['id']) : NULL);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    /** inside a transaction: new assignment id, 'same' (already on that shift), or an error message */
    protected function assign_one($employee_id, $shift_id, $from)
    {
        $emp = $this->db->query('SELECT id, join_date, status FROM employees WHERE id = ? AND branch_id = ? FOR UPDATE', array($employee_id, (int) current_branch_id()))->row_array();
        if ( ! $emp) {
            return t('not_found');
        }
        if ( ! in_array($emp['status'], array('Active', 'On Leave'), TRUE)) {
            return t('emp_exited_status');
        }
        if ($from < $emp['join_date']) {
            return t('shift_before_join');
        }
        $latest = $this->db->query('SELECT * FROM employee_shifts WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1 FOR UPDATE', array($employee_id))->row_array();
        if ($latest && (int) $latest['shift_id'] === $shift_id && $latest['effective_to'] === NULL) {
            return 'same';
        }
        if ($latest && $from <= $latest['effective_from']) {
            return sprintf(t('shift_after_latest'), show_date($latest['effective_from']));
        }
        if ($latest && $latest['effective_to'] === NULL) {
            $this->db->where('id', (int) $latest['id'])->update('employee_shifts', array('effective_to' => date('Y-m-d', strtotime($from . ' -1 day')), 'is_active' => 0));
        }
        $this->db->insert('employee_shifts', array('employee_id' => $employee_id, 'shift_id' => $shift_id, 'effective_from' => $from, 'effective_to' => NULL,
            'is_active' => 1, 'created_by' => current_user_id(), 'created_at' => now_local()));
        $id = (int) $this->db->insert_id();
        $this->audit('employee_shifts', $id, 'assign', $latest ? array('shift_id' => (int) $latest['shift_id'], 'effective_from' => $latest['effective_from']) : NULL,
            array('employee_id' => $employee_id, 'shift_id' => $shift_id, 'effective_from' => $from));
        return $id;
    }

    /** an active shift of the current branch */
    protected function read_shift(Input_validator $v)
    {
        $id = $v->int('shift_id', 1);
        if ($id !== NULL && ! $this->db->query("SELECT 1 FROM shifts WHERE id = ? AND branch_id = ? AND status = 'Active'", array($id, (int) current_branch_id()))->num_rows()) {
            return $v->fail('shift_id', sprintf(t('v_ref'), 'shift'));
        }
        return $id;
    }
}
