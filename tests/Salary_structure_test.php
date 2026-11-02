<?php
/** Salary structure history: computed gross/net, closing the previous one, only after the latest, delete-latest reopens, covering() by dates (P20 groundwork). */
class Salary_structure_test extends Hrm_test_case
{
    /** @var object */
    protected $salary;
    /** @var int */
    protected $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Salary_model');
        $this->salary = $this->CI->Salary_model;
        $this->act_as_new_user('boss', array(), TRUE);
        $this->emp = $this->insert_employee('S1');
    }

    protected function add($from, array $fields = array())
    {
        $this->post(array_merge(array('effective_from' => $from, 'basic_salary' => '20000', 'house_rent' => '8000', 'medical_allowance' => '1500',
            'transport_allowance' => '1000', 'other_allowances' => '500.50', 'pf_deduction' => '2000', 'tax_deduction' => '250.25',
            // values the browser might send for computed fields are ignored
            'gross_salary' => '999999', 'net_salary' => '999999'), $fields));
        return $this->salary->add($this->emp);
    }

    public function test_gross_and_net_are_computed_on_the_server()
    {
        $id = $this->id_of_result($this->add('01-01-2026'));
        $row = $this->db->get_where('salary_structures', array('id' => $id))->row_array();
        $this->assertEquals(31000.50, (float) $row['gross_salary']);
        $this->assertEquals(28750.25, (float) $row['net_salary']);
        $this->assertNull($row['effective_to']);
        $this->assertSame(1, (int) $row['is_active']);
        $this->assertCount(1, $this->audit_rows('salary_structures', $id, 'create'));
    }

    public function test_a_new_structure_closes_the_previous_one()
    {
        $first = $this->id_of_result($this->add('01-01-2026'));
        $second = $this->id_of_result($this->add('16-03-2026', array('basic_salary' => '24000')));
        $old = $this->db->get_where('salary_structures', array('id' => $first))->row_array();
        $this->assertSame('2026-03-15', $old['effective_to']);
        $this->assertSame(0, (int) $old['is_active']);
        $this->assertSame(1, (int) $this->db->get_where('salary_structures', array('id' => $second))->row()->is_active);
        $audit = $this->audit_rows('salary_structures', $second, 'create');
        $this->assertEquals(20000, json_decode($audit[0]['old_values'], TRUE)['basic_salary'], 'the audit shows the previous figures');
    }

    public function test_rules()
    {
        $this->id_of_result($this->add('01-03-2026'));
        $this->assertSame(sprintf(t('salary_after_latest'), '01-03-2026'), $this->add('01-03-2026'));
        $this->assertSame(sprintf(t('salary_after_latest'), '01-03-2026'), $this->add('01-01-2026'), 'no back-dated insert in the middle of the history');
        $this->assertSame(t('salary_negative_net'), $this->add('01-04-2026', array('tax_deduction' => '50000')));
        $this->assertSame(t('salary_basic_required'), $this->add('01-04-2026', array('basic_salary' => '0')));
        $this->assertStringContainsString('at most 2 decimals', $this->add('01-04-2026', array('house_rent' => '10.005')));
        $this->assertStringContainsString('Effective from', $this->add(''));
    }

    public function test_only_the_latest_can_be_removed_and_the_previous_reopens()
    {
        $first = $this->id_of_result($this->add('01-01-2026'));
        $second = $this->id_of_result($this->add('01-03-2026'));
        $this->assertSame(t('salary_only_latest'), $this->salary->delete_latest($this->emp, $first));
        $this->assertSame('success', $this->salary->delete_latest($this->emp, $second));
        $row = $this->db->get_where('salary_structures', array('id' => $first))->row_array();
        $this->assertNull($row['effective_to']);
        $this->assertSame(1, (int) $row['is_active']);
        $this->assertCount(1, $this->audit_rows('salary_structures', $second, 'delete'));
    }

    public function test_covering_returns_the_segments_of_a_month_by_dates_not_is_active()
    {
        $this->id_of_result($this->add('01-01-2026', array('basic_salary' => '20000')));
        $this->id_of_result($this->add('16-03-2026', array('basic_salary' => '24000')));
        $this->id_of_result($this->add('01-06-2026', array('basic_salary' => '30000')));

        $march = $this->salary->covering($this->emp, '2026-03-01', '2026-03-31');
        $this->assertCount(2, $march, 'mid-month raise = two segments');
        $this->assertSame(array(15, 16), array_column($march, 'segment_days'));
        $this->assertSame(array('20000.00', '24000.00'), array_column($march, 'basic_salary'));

        $feb = $this->salary->covering($this->emp, '2026-02-01', '2026-02-28');
        $this->assertCount(1, $feb);
        $this->assertSame('20000.00', $feb[0]['basic_salary'], 'a past month uses the structure of that month, not today\'s (P20)');
        $this->assertSame(0, (int) $feb[0]['is_active']);

        $this->assertSame(array(), $this->salary->covering($this->emp, '2025-12-01', '2025-12-31'));
        $this->assertSame(30, $this->salary->covering($this->emp, '2026-06-01', '2026-06-30')[0]['segment_days']);
    }

    public function test_other_branch_employee_is_out_of_reach()
    {
        set_current_branch_id($this->insert_branch('SB1'));
        $this->assertSame(t('not_found'), $this->add('01-01-2026'));
    }
}
