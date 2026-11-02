<?php
/** Holidays_model: one per date and branch, year from the date, year view, copy fixed-date holidays (29 Feb), audit. */
class Holidays_test extends Hrm_test_case
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Holidays_model');
        $this->act_as_new_user('boss', array(), TRUE);
    }

    protected function add($name, $date, array $f = array())
    {
        $this->post(array_merge(array('holiday_name' => $name, 'holiday_date' => $date, 'status' => 'Active'), $f));
        return $this->CI->Holidays_model->create();
    }

    public function test_create_update_delete()
    {
        $id = $this->id_of_result($this->add('Victory Day', '16-12-2026', array('is_fixed_date' => '1')));
        $row = $this->CI->Holidays_model->get($id);
        $this->assertSame(array('2026-12-16', '2026', '1', '0'), array($row['holiday_date'], (string) $row['year'], (string) $row['is_fixed_date'], (string) $row['is_optional']));
        $this->assertSame(t('holiday_date_taken'), $this->add('Duplicate', '16-12-2026'));
        $this->post(array('holiday_name' => 'Victory Day', 'holiday_date' => '17-12-2026', 'status' => 'Active'));
        $this->assertSame('success', $this->CI->Holidays_model->update($id));
        $this->assertSame('2026-12-17', $this->CI->Holidays_model->get($id)['holiday_date']);
        $this->assertSame('success', $this->CI->Holidays_model->delete($id));
        $this->assertCount(1, $this->audit_rows('holidays', $id, 'delete'));
        $this->assertStringContainsString('valid date', $this->add('Bad', '30-02-2026'));
    }

    public function test_the_same_date_is_fine_in_another_branch()
    {
        $this->id_of_result($this->add('Language Day', '21-02-2026'));
        set_current_branch_id($this->insert_branch('HB1'));
        $this->id_of_result($this->add('Language Day', '21-02-2026'));
    }

    public function test_copy_fixed_date_holidays()
    {
        $this->id_of_result($this->add('Language Day', '21-02-2026', array('is_fixed_date' => '1')));
        $this->id_of_result($this->add('Eid', '20-03-2026'));                                  // lunar: not copied
        $this->id_of_result($this->add('Leap day', '29-02-2028', array('is_fixed_date' => '1')));
        $this->id_of_result($this->add('Victory Day', '16-12-2026', array('is_fixed_date' => '1', 'is_optional' => '1')));
        $this->id_of_result($this->add('Already there', '16-12-2027'));

        $this->post(array('from_year' => '2026', 'to_year' => '2027'));
        $this->assertSame('success<<<###>>>1/1', $this->CI->Holidays_model->copy_year());
        $copied = $this->db->get_where('holidays', array('branch_id' => current_branch_id(), 'holiday_date' => '2027-02-21'))->row_array();
        $this->assertSame(array('Language Day', '2027', '1'), array($copied['holiday_name'], (string) $copied['year'], (string) $copied['is_fixed_date']));
        $this->assertSame(0, $this->db->get_where('holidays', array('holiday_date' => '2027-03-20'))->num_rows());

        $this->post(array('from_year' => '2028', 'to_year' => '2029'));
        $this->assertSame('success<<<###>>>0/1', $this->CI->Holidays_model->copy_year(), '29 Feb does not exist in 2029');
        $this->post(array('from_year' => '2026', 'to_year' => '2026'));
        $this->assertSame(t('holiday_copy_same_year'), $this->CI->Holidays_model->copy_year());
    }

    public function test_year_view_and_list()
    {
        $this->add('Language Day', '21-02-2026');
        $this->add('Independence Day', '26-03-2026');
        $this->add('Next year', '01-01-2027');
        $view = $this->CI->Holidays_model->year_view(2026);
        $this->assertSame(array('Language Day'), array_column($view[2], 'holiday_name'));
        $this->assertSame(array(), $view[1]);
        $this->post(array('draw' => '1', 'year' => '2026'));
        $out = $this->CI->Holidays_model->list_page();
        $this->assertSame(2, $out['recordsTotal']);
        $this->assertStringStartsWith('21-02-2026', $out['data'][0]['date']);
    }
}
