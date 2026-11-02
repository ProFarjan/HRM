<?php
/** Rosters_model: cells (shift / OFF / clear), branch checks, copy week (keep or overwrite), audit. Import is tested over HTTP. */
class Rosters_test extends Hrm_test_case
{
    /** @var int */
    protected $shift;
    /** @var int */
    protected $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Rosters_model');
        $this->act_as_new_user('boss', array(), TRUE);
        $this->db->insert('shifts', array('branch_id' => current_branch_id(), 'shift_name' => 'Morning', 'shift_code' => 'MOR', 'start_time' => '06:00:00',
            'end_time' => '14:00:00', 'status' => 'Active', 'created_at' => now_local()));
        $this->shift = (int) $this->db->insert_id();
        $this->emp = $this->insert_employee('R1');
    }

    protected function save(array $cells)
    {
        $this->post(array('cells' => $cells));
        return $this->CI->Rosters_model->save();
    }

    protected function roster($date)
    {
        return $this->db->get_where('shift_rosters', array('employee_id' => $this->emp, 'roster_date' => $date))->row_array();
    }

    public function test_cells_set_off_and_clear()
    {
        $e = $this->emp;
        $this->assertSame('success<<<###>>>2', $this->save(array("$e|2026-04-06|{$this->shift}", "$e|2026-04-07|OFF")));
        $this->assertSame(array((string) $this->shift, '1'), array($this->roster('2026-04-06')['shift_id'], $this->roster('2026-04-06')['is_working_day']));
        $this->assertSame(array(NULL, '0'), array($this->roster('2026-04-07')['shift_id'], $this->roster('2026-04-07')['is_working_day']));

        $calc = new Shift_calculator();
        $this->assertSame('weekly_off', $calc->day($e, '2026-04-07')['day_type']);
        $this->assertSame('MOR', $calc->day($e, '2026-04-06')['shift']['shift_code']);

        $this->assertSame('success<<<###>>>1', $this->save(array("$e|2026-04-07|{$this->shift}")), 'upsert');
        $this->assertSame('1', $this->roster('2026-04-07')['is_working_day']);
        $this->assertSame('success<<<###>>>1', $this->save(array("$e|2026-04-06|")));
        $this->assertNull($this->roster('2026-04-06'), 'empty = back to the normal rules');
        $this->assertNotEmpty($this->audit_rows('shift_rosters', NULL, 'save'));
    }

    public function test_bad_cells_are_refused_and_nothing_is_written()
    {
        $e = $this->emp;
        foreach (array("$e|2026-02-30|OFF", "$e|2026-04-06|NIGHT", "x|2026-04-06|OFF", "$e|06-04-2026|OFF") as $bad) {
            $this->assertSame(t('roster_bad_cell'), $this->save(array("$e|2026-04-08|OFF", $bad)), $bad);
        }
        $home = current_branch_id();
        set_current_branch_id($this->insert_branch('RB1'));
        $far_emp = $this->insert_employee('FAR');
        $this->db->insert('shifts', array('branch_id' => current_branch_id(), 'shift_name' => 'Far', 'shift_code' => 'FAR', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'status' => 'Active', 'created_at' => now_local()));
        $far_shift = (int) $this->db->insert_id();
        set_current_branch_id($home);
        $this->assertSame(sprintf(t('v_ref'), 'employee'), $this->save(array("$e|2026-04-08|OFF", "$far_emp|2026-04-08|OFF")));
        $this->assertSame(sprintf(t('v_ref'), 'shift'), $this->save(array("$e|2026-04-08|$far_shift")));
        $this->assertSame(0, $this->db->get_where('shift_rosters', array('employee_id' => $e))->num_rows());
        $this->assertSame(t('roster_nothing'), $this->save(array()));
    }

    public function test_copy_week_keeps_or_overwrites()
    {
        $e = $this->emp;
        $this->save(array("$e|2026-04-06|{$this->shift}", "$e|2026-04-07|OFF", "$e|2026-04-14|{$this->shift}"));
        $this->post(array('source_from' => '06-04-2026', 'target_from' => '13-04-2026'));
        $this->assertSame('success<<<###>>>1', $this->CI->Rosters_model->copy_week(), 'the 14th already had a row: kept');
        $this->assertSame('1', $this->roster('2026-04-14')['is_working_day']);
        $this->assertSame((string) $this->shift, $this->roster('2026-04-13')['shift_id']);

        $this->post(array('source_from' => '06-04-2026', 'target_from' => '13-04-2026', 'overwrite' => '1'));
        $this->assertSame('success<<<###>>>2', $this->CI->Rosters_model->copy_week());
        $this->assertSame('0', $this->roster('2026-04-14')['is_working_day'], 'overwritten with OFF');

        $this->post(array('source_from' => '01-01-2026', 'target_from' => '08-01-2026'));
        $this->assertSame(t('roster_copy_empty'), $this->CI->Rosters_model->copy_week());
        $this->post(array('source_from' => '06-04-2026', 'target_from' => '06-04-2026'));
        $this->assertSame(t('roster_copy_same'), $this->CI->Rosters_model->copy_week());
    }

    public function test_grid_contains_current_employees_and_the_plan()
    {
        $this->insert_employee('R0', array('status' => 'Resigned', 'exit_date' => '2025-01-01'));
        $g = $this->CI->Rosters_model->grid(NULL, '2026-04-06', '2026-04-12');
        $this->assertSame(array('R1'), array_column($g['employees'], 'employee_code'));
        $this->assertCount(7, $g['plan'][$this->emp]);
    }
}
