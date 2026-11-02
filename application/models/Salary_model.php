<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Salary structure history (needs employees_salary). Gross and net are computed here, never taken from the form.
 * A new structure starts after the latest one and closes it (effective_to = new.effective_from - 1 day). Only the latest
 * structure can be removed, which reopens the previous one. Payroll (step 9) reads covering() - the structures whose dates
 * cover the month - never is_active (P20).
 */
class Salary_model extends MY_Model
{
    const EARNINGS = 'basic_salary,house_rent,medical_allowance,transport_allowance,other_allowances';
    const DEDUCTIONS = 'pf_deduction,esi_deduction,tax_deduction,other_deductions';

    public function history($employee_id)
    {
        return $this->db->query('SELECT * FROM salary_structures WHERE employee_id = ? ORDER BY effective_from DESC', array((int) $employee_id))->result_array();
    }

    /**
     * Structures in force during [$from, $to] with the number of days each covers inside the range (a mid-month change
     * gives two segments). Ordered by date.
     */
    public function covering($employee_id, $from, $to)
    {
        $rows = $this->db->query(
            'SELECT * FROM salary_structures WHERE employee_id = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from',
            array((int) $employee_id, $to, $from)
        )->result_array();
        foreach ($rows as &$r) {
            $start = max($from, $r['effective_from']);
            $end = $r['effective_to'] === NULL ? $to : min($to, $r['effective_to']);
            $r['segment_from'] = $start;
            $r['segment_to'] = $end;
            $r['segment_days'] = (int) ((strtotime($end) - strtotime($start)) / 86400) + 1;
        }
        unset($r);
        return $rows;
    }

    /** "success<<<###>>>id" or an error */
    public function add($employee_id)
    {
        $v = new Input_validator($this->input);
        $values = array();
        foreach (array_merge(explode(',', self::EARNINGS), explode(',', self::DEDUCTIONS)) as $f) {
            $values[$f] = $v->money($f, 0, 99999999.99, $f === 'basic_salary');
            if ($values[$f] === NULL) {
                $values[$f] = 0.0;
            }
        }
        $from = $v->date('effective_from');
        if ( ! $v->ok()) {
            return $v->message();
        }
        if ($values['basic_salary'] <= 0) {
            return t('salary_basic_required');
        }
        $gross = 0.0;
        foreach (explode(',', self::EARNINGS) as $f) {
            $gross += $values[$f];
        }
        $deductions = 0.0;
        foreach (explode(',', self::DEDUCTIONS) as $f) {
            $deductions += $values[$f];
        }
        $values['gross_salary'] = money_round($gross);
        $values['net_salary'] = money_round($gross - $deductions);
        if ($values['net_salary'] < 0) {
            return t('salary_negative_net');
        }

        $employee_id = (int) $employee_id;
        $new_id = 0;
        list($ok, $message) = Tx::run(function () use ($employee_id, $values, $from, &$new_id) {
            $emp = $this->db->query('SELECT id FROM employees WHERE id = ? AND branch_id = ? FOR UPDATE', array($employee_id, (int) current_branch_id()))->row_array();
            if ( ! $emp) {
                return t('not_found');
            }
            $latest = $this->db->query('SELECT * FROM salary_structures WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 1 FOR UPDATE', array($employee_id))->row_array();
            if ($latest && $from <= $latest['effective_from']) {
                return sprintf(t('salary_after_latest'), show_date($latest['effective_from']));
            }
            if ($latest) {
                $this->db->where('id', (int) $latest['id'])->update('salary_structures', array(
                    'effective_to' => date('Y-m-d', strtotime($from . ' -1 day')), 'is_active' => 0,
                ));
            }
            $this->db->insert('salary_structures', array_merge($values, array(
                'employee_id' => $employee_id, 'effective_from' => $from, 'effective_to' => NULL, 'is_active' => 1,
                'created_by' => current_user_id(), 'created_at' => now_local(),
            )));
            $new_id = (int) $this->db->insert_id();
            $this->audit('salary_structures', $new_id, 'create', $latest ? $this->figures($latest) : NULL, array_merge($values, array('employee_id' => $employee_id, 'effective_from' => $from)));
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $new_id : $message;
    }

    /** remove the latest structure (a mistake) and reopen the previous one */
    public function delete_latest($employee_id, $structure_id)
    {
        $employee_id = (int) $employee_id;
        $structure_id = (int) $structure_id;
        list($ok, $message) = Tx::run(function () use ($employee_id, $structure_id) {
            $emp = $this->db->query('SELECT id FROM employees WHERE id = ? AND branch_id = ? FOR UPDATE', array($employee_id, (int) current_branch_id()))->row_array();
            if ( ! $emp) {
                return t('not_found');
            }
            $rows = $this->db->query('SELECT * FROM salary_structures WHERE employee_id = ? ORDER BY effective_from DESC LIMIT 2 FOR UPDATE', array($employee_id))->result_array();
            if ( ! $rows || (int) $rows[0]['id'] !== $structure_id) {
                return t('salary_only_latest');
            }
            $this->db->where('id', $structure_id)->delete('salary_structures');
            if (isset($rows[1])) {
                $this->db->where('id', (int) $rows[1]['id'])->update('salary_structures', array('effective_to' => NULL, 'is_active' => 1));
            }
            $this->audit('salary_structures', $structure_id, 'delete', $this->figures($rows[0]), isset($rows[1]) ? array('reopened' => (int) $rows[1]['id']) : NULL);
            return TRUE;
        });
        return $ok ? 'success' : ($message === 'A database error occurred. Nothing was saved.' ? t('salary_in_use') : $message);
    }

    protected function figures(array $row)
    {
        $keep = array_merge(explode(',', self::EARNINGS), explode(',', self::DEDUCTIONS), array('gross_salary', 'net_salary', 'effective_from', 'effective_to'));
        return array_intersect_key($row, array_flip($keep));
    }
}
