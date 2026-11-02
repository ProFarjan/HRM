<?php
/** Settings_model: every key validated, only changes saved and audited, cache refreshed. */
class Settings_test extends Hrm_test_case
{
    /** @var object */
    protected $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Settings_model');
        $this->settings = $this->CI->Settings_model;
        $this->act_as_new_user('boss', array(), TRUE);
    }

    public function test_current_merges_the_row_over_the_defaults()
    {
        $values = $this->settings->current();
        $this->assertSame(array_keys(config_item('hrm_defaults')), array_keys($values));
        $this->assertSame('Asia/Dhaka', $values['timezone']);
    }

    public function test_saving_the_unchanged_form_changes_nothing()
    {
        $this->post(settings_form($this->settings->current()));
        $this->assertSame('success', $this->settings->save());
        $this->assertCount(0, $this->audit_rows('settings'));
    }

    public function test_changes_are_saved_audited_and_visible_at_once()
    {
        hr_setting('ot_multiplier');                                       // cache warm with the old value
        $post = settings_form($this->settings->current());
        $post['company_name'] = 'Acme Garments Ltd';
        $post['ot_multiplier'] = '2';
        $post['weekly_offs'] = array('6', '5');
        unset($post['payslip_show_attendance']);                           // unticked checkbox = 0
        $post['email'] = 'HR@Acme.example';
        $this->post($post);
        $this->assertSame('success', $this->settings->save());

        $this->assertSame('Acme Garments Ltd', hr_setting('company_name'), 'cache refreshed');
        $this->assertEquals(2.0, hr_setting('ot_multiplier'));
        $this->assertSame('5,6', hr_setting('weekly_offs'));
        $this->assertSame('hr@acme.example', hr_setting('email'));
        $this->assertSame(0, (int) hr_setting('payslip_show_attendance'));

        $audit = $this->audit_rows('settings', 1, 'update');
        $this->assertCount(1, $audit);
        $new = json_decode($audit[0]['new_values'], TRUE);
        $old = json_decode($audit[0]['old_values'], TRUE);
        $this->assertEqualsCanonicalizing(array('company_name', 'ot_multiplier', 'weekly_offs', 'payslip_show_attendance', 'email'), array_keys($new));
        $this->assertSame('1.50', $old['ot_multiplier']);
        $row = $this->db->get_where('hr_settings', array('id' => 1))->row();
        $this->assertSame((string) $row->updated_by, (string) current_user_id());
    }

    public function test_every_field_is_validated_and_all_errors_come_back_together()
    {
        $post = settings_form($this->settings->current());
        $bad = array(
            'timezone' => 'Asia/Atlantis', 'date_format' => 'Y/d/m', 'currency_position' => 'middle', 'decimals' => '5',
            'idle_logout_minutes' => '-1', 'ot_min_minutes' => '999', 'late_days_per_absent' => 'x', 'overtime_source' => 'both',
            'overtime_basis' => 'net', 'ot_divisor_hours' => '0', 'ot_multiplier' => '11', 'overtime_mode' => 'magic',
            'late_deduction_mode' => '', 'absent_deduction_basis' => 'x', 'absent_deduction_mode' => 'x', 'punch_debounce_seconds' => '99999',
            'device_gap_hours' => '0', 'email' => 'not-an-email', 'company_name' => '<script>x</script>', 'weekly_offs' => array('9'),
        );
        $this->post(array_merge($post, $bad));
        $message = $this->settings->save();
        $this->assertNotSame('success', $message);
        $this->assertGreaterThanOrEqual(count($bad), count(explode("\n", $message)), $message);
        $this->assertCount(0, $this->audit_rows('settings'));
        $this->assertSame('Asia/Dhaka', $this->db->get_where('hr_settings', array('id' => 1))->row()->timezone, 'nothing saved');
    }

    public function test_magic_numbers_are_settings_that_can_change()
    {
        $post = settings_form($this->settings->current());
        $post['ot_divisor_hours'] = '240';
        $post['ot_multiplier'] = '2.25';
        $this->post($post);
        $this->assertSame('success', $this->settings->save());
        $this->assertEquals(240, hr_setting('ot_divisor_hours'));
        $this->assertEquals(2.25, hr_setting('ot_multiplier'));
    }

    public function test_date_format_options_are_valid_for_the_validator()
    {
        foreach (Settings_model::date_formats() as $format) {
            $this->assertNotFalse(parse_strict_date(date($format, mktime(0, 0, 0, 11, 5, 2026)), $format), $format);
        }
    }
}
