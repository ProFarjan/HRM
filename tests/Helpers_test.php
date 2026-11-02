<?php
/** hrm_helper: settings with fallbacks, strict dates, month ranges, money rounding, escaping. Closes the settings-default parity check. */
class Helpers_test extends Hrm_test_case
{
    public function test_the_hr_settings_row_has_the_documented_defaults()
    {
        $row = $this->db->get_where('hr_settings', array('id' => 1))->row_array();
        $this->assertNotEmpty($row, 'row id 1 exists after migrating');
        foreach (config_item('hrm_defaults') as $key => $default) {
            $this->assertArrayHasKey($key, $row, "hr_settings has a column for $key");
            if ($default === NULL) {
                $this->assertNull($row[$key], $key);
            } elseif (is_numeric($default)) {
                $this->assertEquals($default, $row[$key], "$key: migration default equals config/hrm.php", 0.0001);
            } else {
                $this->assertSame($default, $row[$key], "$key: migration default equals config/hrm.php");
            }
        }
    }

    public function test_every_documented_setting_exists()
    {
        $documented = array('company_name', 'logo', 'address', 'phone', 'email', 'timezone', 'date_format', 'currency_symbol', 'currency_position',
            'decimals', 'weekly_offs', 'default_shift_id', 'deduct_break', 'ot_min_minutes', 'early_leave_counts_as_late', 'punch_debounce_seconds',
            'device_gap_hours', 'overtime_source', 'overtime_basis', 'ot_divisor_hours', 'ot_multiplier', 'overtime_mode', 'late_days_per_absent',
            'late_deduction_mode', 'absent_deduction_basis', 'absent_deduction_mode', 'allow_negative_net', 'allow_negative_account',
            'self_approve_leave', 'payslip_footer', 'payslip_show_attendance', 'notify_leave_sms', 'notify_payslip_email', 'notify_absent_sms',
            'idle_logout_minutes');
        $this->assertSame($documented, array_keys(config_item('hrm_defaults')), 'CLAUDE.md "HR settings" table');
    }

    public function test_hr_setting_reads_the_row_then_the_config_default_then_the_argument()
    {
        $this->assertSame('d-m-Y', hr_setting('date_format'));
        $this->db->where('id', 1)->update('hr_settings', array('company_name' => 'Acme Ltd', 'late_days_per_absent' => 5));
        hr_setting_reset_cache();
        $this->assertSame('Acme Ltd', hr_setting('company_name'));
        $this->assertEquals(5, hr_setting('late_days_per_absent'));
        $this->assertNull(hr_setting('no_such_setting'));
        $this->assertSame('fallback', hr_setting('no_such_setting', 'fallback'));
        $this->assertNull(hr_setting('default_shift_id'), 'NULL column and NULL default');
        $this->assertSame(7, hr_setting('default_shift_id', 7), 'NULL falls through to the argument');
    }

    public function test_hr_setting_survives_an_unmigrated_tenant_without_the_table()
    {
        $this->db->query('RENAME TABLE hr_settings TO hr_settings_gone');
        try {
            hr_setting_reset_cache();
            $this->assertSame('Asia/Dhaka', hr_setting('timezone'));
            $this->assertEquals(208, hr_setting('ot_divisor_hours'));
        } finally {
            $this->db->query('RENAME TABLE hr_settings_gone TO hr_settings');
            hr_setting_reset_cache();
        }
    }

    public function test_hr_setting_is_cached_for_the_request()
    {
        hr_setting('timezone');
        $before = $this->queries_before();
        for ($i = 0; $i < 20; $i++) {
            hr_setting('timezone');
            hr_setting('date_format');
        }
        $this->assertSame($before, $this->queries_before());
    }

    public function test_to_db_date_and_show_date_round_trip()
    {
        $this->assertSame('2026-11-05', to_db_date('05-11-2026'));
        $this->assertSame('2026-11-05', to_db_date('2026-11-05'));
        $this->assertFalse(to_db_date('31-04-2026'));
        $this->assertFalse(to_db_date(''));
        $this->assertSame('05-11-2026', show_date('2026-11-05'));
        $this->assertSame('05-11-2026', show_date('2026-11-05 13:45:00'));
        $this->assertSame('', show_date(NULL));
        $this->assertSame('', show_date('0000-00-00'));
    }

    public function test_month_range_is_a_closed_date_range()
    {
        $this->assertSame(array('from' => '2026-02-01', 'to' => '2026-02-28', 'days' => 28), month_range(2, 2026));
        $this->assertSame(array('from' => '2028-02-01', 'to' => '2028-02-29', 'days' => 29), month_range(2, 2028));
        $this->assertSame(array('from' => '2026-12-01', 'to' => '2026-12-31', 'days' => 31), month_range('12', '2026'));
        foreach (array(array(0, 2026), array(13, 2026), array(5, 1800)) as $args) {
            try {
                month_range($args[0], $args[1]);
                $this->fail('expected an exception');
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(TRUE);
            }
        }
    }

    public function test_money_helpers()
    {
        $this->assertSame(10.01, money_round(10.005 + 0.0000001));
        $this->assertSame(0.3, money_round(0.1 + 0.2));
        $this->assertTrue(money_equal(0.1 + 0.2, 0.3));
        $this->assertFalse(money_equal(100.00, 100.01));
        $this->assertSame('৳ 12,500.50', money_fmt(12500.5));
        $this->db->where('id', 1)->update('hr_settings', array('currency_position' => 'right', 'decimals' => 0, 'currency_symbol' => 'Tk'));
        hr_setting_reset_cache();
        $this->assertSame('12,501 Tk', money_fmt(12500.5));
    }

    public function test_current_context_helpers()
    {
        $this->assertNull(current_user());
        $this->assertNull(current_branch_id());
        $user = $this->act_as_new_user('ctx', array());
        $this->assertSame($user['id'], current_user_id());
        $this->assertSame($user['branch_id'], current_branch_id());
        $this->assertNull(current_employee_id());
        set_current_branch_id(99);
        $this->assertSame(99, current_branch_id(), 'device endpoints set the branch from the key / device row');
        set_current_user(NULL);
        $this->assertNull(current_user_id());
    }

    public function test_uuid_is_v4()
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', hrm_uuid());
        $this->assertNotSame(hrm_uuid(), hrm_uuid());
    }

    public function test_language_helper_falls_back_to_the_key()
    {
        $this->assertSame('Dashboard', t('menu_dashboard'));
        $this->assertSame('no_such_label', t('no_such_label'));
    }

    public function test_bangla_and_english_have_the_same_labels()
    {
        $read = function ($language) {
            $lang = array();
            include APPPATH . 'language/' . $language . '/hrm_lang.php';
            return $lang;
        };
        $en = $read('english');
        $bn = $read('bangla');
        $this->assertSame(array(), array_values(array_diff(array_keys($en), array_keys($bn))), 'missing in bangla');
        $this->assertSame(array(), array_values(array_diff(array_keys($bn), array_keys($en))), 'only in bangla');
        foreach ($en as $key => $line) {
            $this->assertSame(substr_count($line, '%'), substr_count($bn[$key], '%'), "placeholders of $key");
        }
    }

    public function test_every_menu_label_and_permission_exists()
    {
        $catalog = permission_catalog_keys();
        foreach (config_item('menu') as $item) {
            $this->assertNotSame($item['label'], t($item['label']), $item['label']);
            foreach (isset($item['children']) ? $item['children'] : array($item) as $entry) {
                $this->assertNotSame($entry['label'], t($entry['label']), $entry['label']);
                $this->assertTrue($entry['permission'] === '@any' || isset($catalog[$entry['permission']]), $entry['permission']);
                $this->assertFileExists(APPPATH . 'controllers/' . ucfirst(explode('/', $entry['url'])[0]) . '.php', 'menu points to a real controller');
            }
        }
    }

    public function test_warnings_in_application_code_fail_tests()
    {
        // CodeIgniter's handler must not be the active one, or warnings would only be printed (see tests/bootstrap.php)
        $current = set_error_handler(function () { return FALSE; });
        restore_error_handler();
        $this->assertNotSame('_error_handler', $current);
        $caught = NULL;
        try {
            $empty = array();
            $value = $empty['missing'];
            unset($value);
        } catch (Throwable $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'an undefined array key must raise, not just print');
        $this->assertStringContainsString('missing', $caught->getMessage());
    }
}
