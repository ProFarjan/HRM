<?php
/** Shifts_model with duty periods: validation, what is derived and stored, update / switch back / delete, listing, audit. */
class Split_shift_form_test extends Hrm_test_case
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Shifts_model');
        $this->act_as_new_user('boss', array(), TRUE);
    }

    protected function split_form(array $starts = array('10:00', '18:00'), array $ends = array('14:00', '22:00'), array $extra = array())
    {
        $this->post(array_merge(array('shift_name' => 'Evening duty', 'shift_code' => 'sp1', 'is_split' => '1', 'seg_start' => $starts, 'seg_end' => $ends,
            'grace_time' => '10', 'shift_allowance' => '0', 'status' => 'Active'), $extra));
    }

    protected function create_split(array $starts = array('10:00', '18:00'), array $ends = array('14:00', '22:00'), array $extra = array())
    {
        $this->split_form($starts, $ends, $extra);
        return $this->CI->Shifts_model->create();
    }

    public function test_a_split_shift_is_stored_with_derived_times_and_its_periods()
    {
        $result = $this->create_split();
        $this->assertStringStartsWith('success<<<###>>>', $result);
        $id = (int) substr($result, strlen('success<<<###>>>'));
        $row = $this->CI->Shifts_model->get($id);
        $this->assertSame(array('SP1', '10:00:00', '22:00:00', 1, 0, 0), array($row['shift_code'], $row['start_time'], $row['end_time'], (int) $row['is_split'], (int) $row['is_night'], (int) $row['break_duration']));
        $this->assertEquals(8.0, (float) $row['working_hours'], 'the sum of the periods, not 12 hours');
        $this->assertSame(array(array('1', '10:00:00', '14:00:00'), array('2', '18:00:00', '22:00:00')),
            array_map(function ($s) { return array((string) $s['segment_no'], $s['start_time'], $s['end_time']); }, $row['segments']));
        $audit = $this->audit_rows('shifts', $id, 'create');
        $this->assertCount(1, $audit);
        $this->assertSame('10:00-14:00 + 18:00-22:00', json_decode($audit[0]['new_values'], TRUE)['segments']);
        $this->assertSame('10:00-14:00 + 18:00-22:00', $this->CI->Shifts_model->period_texts(array($id))[$id]);
        $this->assertStringContainsString('Evening duty (10:00-14:00 + 18:00-22:00)', implode(' ', $this->CI->Shifts_model->active_list()));
    }

    public function test_the_form_is_checked()
    {
        $cases = array(
            'one period' => array(array('10:00'), array('14:00'), t('shift_periods_count')),
            'five periods' => array(array('06:00', '08:00', '10:00', '12:00', '14:00'), array('07:00', '09:00', '11:00', '13:00', '15:00'), 'too many'),
            'start = end' => array(array('10:00', '18:00'), array('10:00', '22:00'), sprintf(t('shift_period_empty'), 1)),
            'no gap' => array(array('10:00', '14:00'), array('14:00', '22:00'), sprintf(t('shift_period_gap'), 2)),
            'overlap' => array(array('10:00', '13:00'), array('14:00', '22:00'), sprintf(t('shift_period_gap'), 2)),
            'overlap, later period inside the first' => array(array('10:00', '11:00'), array('14:00', '22:00'), sprintf(t('shift_period_gap'), 2)),
            'longer than a day' => array(array('10:00', '18:00', '08:00'), array('14:00', '22:00', '12:00'), t('shift_periods_span')),
            'bad time' => array(array('10:00', '25:00'), array('14:00', '22:00'), 'not a valid time'),
            'unequal lists' => array(array('10:00', '18:00'), array('14:00'), t('shift_periods_count')),
        );
        foreach ($cases as $label => $c) {
            $this->assertStringContainsString($c[2], $this->create_split($c[0], $c[1]), $label);
        }
        $this->post(array('shift_name' => 'No periods', 'shift_code' => 'np', 'is_split' => '1', 'grace_time' => '0', 'status' => 'Active'));
        $this->assertStringContainsString('required', $this->CI->Shifts_model->create());
        $this->assertSame(0, $this->db->count_all('shift_segments'));
    }

    public function test_periods_may_run_past_midnight()
    {
        $id = (int) substr($this->create_split(array('18:00', '23:00'), array('22:00', '02:00')), strlen('success<<<###>>>'));
        $row = $this->CI->Shifts_model->get($id);
        $this->assertSame(array(1, '02:00:00', 7.0), array((int) $row['is_night'], $row['end_time'], (float) $row['working_hours']));
        $id2 = (int) substr($this->create_split(array('22:00', '03:00'), array('02:00', '07:00'), array('shift_code' => 'sp2')), strlen('success<<<###>>>'));
        $this->assertEquals(8.0, (float) $this->CI->Shifts_model->get($id2)['working_hours'], '22:00-02:00 and 03:00-07:00');
        $this->assertStringContainsString('too many', $this->create_split(array('06:00', '08:00', '10:00', '12:00', '14:00'), array('07:00', '09:00', '11:00', '13:00', '15:00'), array('shift_code' => 'sp3')));
    }

    public function test_a_change_of_periods_alone_is_saved_and_audited()
    {
        $id = (int) substr($this->create_split(array('10:00', '15:00'), array('14:00', '22:00')), strlen('success<<<###>>>'));   // 4 h + 7 h
        $before = $this->CI->Shifts_model->get($id);
        $this->split_form(array('10:00', '14:00'), array('13:00', '22:00'));                                                  // 3 h + 8 h: same start, end and 11 h in all
        $this->assertSame('success', $this->CI->Shifts_model->update($id));
        $after = $this->CI->Shifts_model->get($id);
        $this->assertSame(array($before['start_time'], $before['end_time'], (float) $before['working_hours']), array($after['start_time'], $after['end_time'], (float) $after['working_hours']));
        $this->assertSame('13:00:00', $after['segments'][0]['end_time'], 'the periods changed although no column of shifts did');
        $audit = $this->audit_rows('shifts', $id, 'update');
        $this->assertCount(1, $audit);
        $this->assertSame('10:00-13:00 + 14:00-22:00', json_decode($audit[0]['new_values'], TRUE)['segments']);
        $this->assertSame('10:00-14:00 + 15:00-22:00', json_decode($audit[0]['old_values'], TRUE)['segments']);
        $this->assertSame('success', $this->CI->Shifts_model->update($id), 'saving the same form again changes nothing');
        $this->assertCount(1, $this->audit_rows('shifts', $id, 'update'));
    }

    public function test_switching_back_to_an_ordinary_shift_drops_the_periods()
    {
        $id = (int) substr($this->create_split(), strlen('success<<<###>>>'));
        $this->post(array('shift_name' => 'Evening duty', 'shift_code' => 'sp1', 'start_time' => '09:00', 'end_time' => '18:00', 'break_duration' => '60', 'working_hours' => '8',
            'grace_time' => '10', 'shift_allowance' => '0', 'status' => 'Active'));
        $this->assertSame('success', $this->CI->Shifts_model->update($id));
        $row = $this->CI->Shifts_model->get($id);
        $this->assertSame(array(0, '09:00:00', 60, array()), array((int) $row['is_split'], $row['start_time'], (int) $row['break_duration'], $row['segments']));
        $this->assertSame(0, (int) $this->db->where('shift_id', $id)->count_all_results('shift_segments'));
        // and back
        $this->split_form();
        $this->assertSame('success', $this->CI->Shifts_model->update($id));
        $this->assertSame(1, (int) $this->CI->Shifts_model->get($id)['is_split']);
    }

    public function test_deleting_a_split_shift_removes_its_periods_unless_it_is_in_use()
    {
        $id = (int) substr($this->create_split(), strlen('success<<<###>>>'));
        $emp = $this->insert_employee('SF1');
        $this->assign_shift($emp, $id);
        $this->assertSame(t('shift_in_use'), $this->CI->Shifts_model->delete($id));
        $this->assertSame(2, (int) $this->db->where('shift_id', $id)->count_all_results('shift_segments'));
        $this->db->query('DELETE FROM employee_shifts');
        $this->assertSame('success', $this->CI->Shifts_model->delete($id));
        $this->assertSame(0, (int) $this->db->where('shift_id', $id)->count_all_results('shift_segments'));
    }

    public function test_the_ordinary_form_is_unchanged()
    {
        $this->post(array('shift_name' => 'General', 'shift_code' => 'gen', 'start_time' => '09:00', 'end_time' => '18:00', 'break_duration' => '60', 'working_hours' => '8',
            'grace_time' => '10', 'shift_allowance' => '0', 'status' => 'Active'));
        $id = (int) substr($this->CI->Shifts_model->create(), strlen('success<<<###>>>'));
        $row = $this->CI->Shifts_model->get($id);
        $this->assertSame(array(0, array(), 60), array((int) $row['is_split'], $row['segments'], (int) $row['break_duration']));
    }
}
