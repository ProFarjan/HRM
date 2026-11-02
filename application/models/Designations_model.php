<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Designations of the current branch with a seniority level (0 = not ranked). */
class Designations_model extends Org_unit_model
{
    protected $table = 'designations';
    protected $prefix = 'designation';
    protected $lock_sql = 'SELECT * FROM designations WHERE id = ? AND branch_id = ? FOR UPDATE';

    protected function extra_fields(Input_validator $v, $id)
    {
        return array('level' => (int) $v->int('level', 0, 999, FALSE));
    }

    protected function extra_select()
    {
        return '';
    }

    protected function extra_cells(array $row)
    {
        return array('extra' => (int) $row['level']);
    }
}
