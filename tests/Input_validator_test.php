<?php
/**
 * G2 - typed input. Closes P11 on the input side: injection strings are accepted as data and stored verbatim
 * (never HTML-escaped, never concatenated into SQL), HTML tags are refused, ids are re-checked against the branch.
 */
class Input_validator_test extends Hrm_test_case
{
    protected function v(array $post, array $get = array(), $source = 'post')
    {
        return new Input_validator(new Fake_input($post, $get), $source);
    }

    // ---------------------------------------------------------------------------------- int

    public function test_int_accepts_whole_numbers_within_bounds()
    {
        $v = $this->v(array('a' => ' 42 ', 'b' => '-7', 'c' => '0'));
        $this->assertSame(42, $v->int('a', 1, 100));
        $this->assertSame(-7, $v->int('b', -10, 10));
        $this->assertSame(0, $v->int('c'));
        $this->assertTrue($v->ok());
    }

    public function test_int_rejects_junk_and_out_of_range()
    {
        foreach (array('1.5', '1e3', 'abc', '12abc', '0x10', '--1', '9999999999999999999999', '1 2') as $bad) {
            $v = $this->v(array('n' => $bad));
            $this->assertNull($v->int('n'), $bad);
            $this->assertFalse($v->ok(), $bad);
        }
        $v = $this->v(array('n' => '101'));
        $this->assertNull($v->int('n', 1, 100));
        $this->assertFalse($v->ok());
        $v = $this->v(array('n' => array('1', '2')));
        $this->assertNull($v->int('n'), 'arrays are not scalars');
        $this->assertFalse($v->ok());
    }

    public function test_required_and_optional_values()
    {
        $v = $this->v(array());
        $this->assertNull($v->int('missing'));
        $this->assertFalse($v->ok());
        $v = $this->v(array('x' => '   '));
        $this->assertNull($v->text('x', 10, FALSE));
        $this->assertTrue($v->ok(), 'optional + blank is fine');
        $this->assertNull($v->text('x', 10, TRUE));
        $this->assertFalse($v->ok(), 'required + blank is an error');
    }

    // ---------------------------------------------------------------------------------- text

    public function test_text_is_trimmed_and_length_checked_in_characters()
    {
        $v = $this->v(array('name' => "  রহিম উদ্দিন  ", 'long' => str_repeat('ক', 11)));
        $this->assertSame('রহিম উদ্দিন', $v->text('name', 11));
        $this->assertNull($v->text('long', 10), '11 characters > 10, even though bytes are many more');
        $this->assertFalse($v->ok());
        $this->assertStringContainsString('at most 10', $v->message());
    }

    public function test_text_minimum_length()
    {
        $v = $this->v(array('t' => 'ab'));
        $this->assertNull($v->text('t', 50, TRUE, 3));
        $this->assertFalse($v->ok());
    }

    public function test_html_tags_are_refused_but_ordinary_symbols_are_fine()
    {
        foreach (array('<script>alert(1)</script>', '<b>bold</b>', 'x </div>', '<!-- c -->', '<?php echo 1; ?>', '< a href=x>') as $bad) {
            $v = $this->v(array('t' => $bad));
            $this->assertNull($v->text('t'), $bad);
            $this->assertStringContainsString('HTML', $v->message(), $bad);
        }
        foreach (array('salary < 5000 & bonus > 100', "O'Brien & Sons \"Ltd\"", '50% off_', 'a<3', '5 <6') as $good) {
            $v = $this->v(array('t' => $good));
            $this->assertSame($good, $v->text('t'), $good);
        }
    }

    public function test_control_characters_and_bad_encoding_are_refused()
    {
        foreach (array("a\0b", "a\x07b", "a\x1Fb", "\xC3\x28 broken") as $bad) {
            $v = $this->v(array('t' => $bad));
            $this->assertNull($v->text('t'), bin2hex($bad));
            $this->assertFalse($v->ok());
        }
        $v = $this->v(array('t' => "line1\nline2\tTabbed"));
        $this->assertSame("line1\nline2\tTabbed", $v->text('t', 100), 'newline and tab are fine in notes');
    }

    public function test_injection_strings_are_accepted_as_data_and_stored_verbatim()
    {
        $strings = array(
            "'; DROP TABLE users; --",
            "Robert'); DROP TABLE students;--",
            "1' OR '1'='1",
            'x" OR ""="',
            "back\\slash \\' and %_ wildcards",
            "O'Brien & Sons \"Ltd\" &amp; co",
            'রহিম উদ্দিন\'s shop',
        );
        foreach ($strings as $s) {
            $v = $this->v(array('note' => $s));
            $value = $v->text('note', 255);
            $this->assertTrue($v->ok(), $s . ' / ' . $v->message());
            $this->assertSame($s, $value);

            $this->db->insert('tx_probe', array('val' => $value));          // Query Builder escapes; nothing is concatenated
            $stored = $this->db->get_where('tx_probe', array('id' => $this->db->insert_id()))->row()->val;
            $this->assertSame($s, $stored, 'stored exactly as entered (P11: no HTML-escaping on the way in)');
            $this->assertSame(htmlspecialchars($s, ENT_QUOTES, 'UTF-8'), e($stored), 'escaped on output only');
        }
        $this->assertSame(1, $this->db->query("SHOW TABLES LIKE 'users'")->num_rows(), 'the injection strings did not drop anything');
    }

    public function test_output_escaping_neutralises_markup_that_reached_the_database()
    {
        $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', e('<img src=x onerror=alert(1)>'));
        $this->assertSame('&quot;x&quot; &amp; &#039;y&#039;', e('"x" & \'y\''));
        $this->assertSame('', e(NULL));
    }

    // ---------------------------------------------------------------------------------- enum, date, time, money

    public function test_enum_is_strict()
    {
        $allowed = array('Cash', 'Bank', 'Mobile Banking');
        $v = $this->v(array('t' => 'Bank', 'u' => 'bank', 'w' => 'Bank ', 'x' => 'Cheque', 'n' => '0'));
        $this->assertSame('Bank', $v->enum('t', $allowed));
        $this->assertSame('Bank', $v->enum('w', $allowed), 'trimmed');
        $this->assertNull($v->enum('u', $allowed), 'case matters');
        $this->assertNull($v->enum('x', $allowed));
        $this->assertNull($v->enum('n', $allowed), '"0" is not "Cash"');
        $this->assertCount(3, $v->errors(), 'case mismatch, unknown value and "0" are all refused');
    }

    public function test_date_accepts_ui_format_and_iso_and_refuses_impossible_dates()
    {
        $v = $this->v(array('a' => '05-11-2026', 'b' => '2026-11-05', 'c' => '29-02-2028'));
        $this->assertSame('2026-11-05', $v->date('a'));
        $this->assertSame('2026-11-05', $v->date('b'));
        $this->assertSame('2028-02-29', $v->date('c'));
        $this->assertTrue($v->ok());
        foreach (array('31-02-2026', '2026-02-30', '29-02-2027', '32-01-2026', '00-01-2026', '2026-13-01', 'tomorrow', '5-11-26', '2026/11/05', '11-05-2026x') as $bad) {
            $v = $this->v(array('d' => $bad));
            $this->assertNull($v->date('d'), $bad);
            $this->assertFalse($v->ok(), $bad);
        }
    }

    public function test_date_follows_the_date_format_setting()
    {
        $this->db->where('id', 1)->update('hr_settings', array('date_format' => 'd/m/Y'));
        hr_setting_reset_cache();
        $v = $this->v(array('a' => '05/11/2026', 'b' => '05-11-2026'));
        $this->assertSame('2026-11-05', $v->date('a'));
        $this->assertNull($v->date('b'), 'the old format is no longer the UI format');
        $this->assertSame('05/11/2026', show_date('2026-11-05'));
    }

    public function test_time()
    {
        $v = $this->v(array('a' => '08:30', 'b' => '23:59:59', 'c' => ' 00:00 '));
        $this->assertSame('08:30:00', $v->time('a'));
        $this->assertSame('23:59:59', $v->time('b'));
        $this->assertSame('00:00:00', $v->time('c'));
        foreach (array('24:00', '8:30', '12:60', '12:30:61', 'noon', '12.30') as $bad) {
            $v = $this->v(array('t' => $bad));
            $this->assertNull($v->time('t'), $bad);
        }
    }

    public function test_money_has_two_decimals_and_bounds()
    {
        $v = $this->v(array('a' => '12.5', 'b' => '0.01', 'c' => '99999999.99', 'd' => '1200'));
        $this->assertSame(12.5, $v->money('a', 0.01, 99999999.99));
        $this->assertSame(0.01, $v->money('b', 0.01, 99999999.99));
        $this->assertSame(99999999.99, $v->money('c', 0.01, 99999999.99));
        $this->assertSame(1200.0, $v->money('d'));
        $this->assertTrue($v->ok());
        foreach (array('12.345', '-1', '0', '100000000.00', 'abc', '1e3', '1,000', '.5', '5.', '') as $bad) {
            $v = $this->v(array('m' => $bad));
            $this->assertNull($v->money('m', 0.01, 99999999.99), "'$bad'");
            $this->assertFalse($v->ok(), "'$bad'");
        }
    }

    // ---------------------------------------------------------------------------------- ref

    public function test_ref_checks_existence_branch_and_registration()
    {
        $other_branch = $this->insert_branch('OB');
        $admin = $this->act_as_new_user('ref_admin', array(), TRUE);
        $mine = $admin['id'];
        $role_id = $this->insert_role('foreign_role');
        $foreign = $this->insert_user('foreign_user', $role_id, $other_branch);

        $v = $this->v(array('u' => (string) $mine, 'f' => (string) $foreign, 'z' => '999999', 'zero' => '0'));
        $this->assertSame($mine, $v->ref('u', 'users'));
        $this->assertTrue($v->ok());
        $this->assertNull($v->ref('f', 'users'), 'a row of another branch is not valid');
        $this->assertNull($v->ref('z', 'users'), 'unknown id');
        $this->assertNull($v->ref('zero', 'users'));
        $this->assertNull($v->ref('u', 'login_attempts'), 'a table that is not registered in scoped_tables is never accepted');
        $this->assertCount(4, $v->errors());
        $this->assertStringContainsString('not valid', $v->message());
    }

    public function test_ref_without_login_matches_nothing()
    {
        $role_id = $this->insert_role('r');
        $uid = $this->insert_user('someone', $role_id);
        $v = $this->v(array('u' => (string) $uid));
        $this->assertNull($v->ref('u', 'users'));
    }

    // ---------------------------------------------------------------------------------- the rest

    public function test_flag_secret_token_and_int_list()
    {
        $v = $this->v(array('a' => 'on', 'b' => '1', 'c' => 'true', 'd' => '0', 'e' => 'no', 'pw' => '  keep my spaces & <tags> ', 'ids' => array('3', '1', '3', '2')));
        $this->assertSame(1, $v->flag('a'));
        $this->assertSame(1, $v->flag('b'));
        $this->assertSame(1, $v->flag('c'));
        $this->assertSame(0, $v->flag('d'));
        $this->assertSame(0, $v->flag('e'));
        $this->assertSame(0, $v->flag('missing'));
        $this->assertSame('  keep my spaces & <tags> ', $v->secret('pw', 8, 100), 'passwords are not trimmed or filtered');
        $this->assertSame(array(3, 1, 2), $v->int_list('ids'));
        $this->assertTrue($v->ok());

        $v = $this->v(array('ids' => array('1', 'x')));
        $this->assertNull($v->int_list('ids'));
        $v = $this->v(array('ids' => array_map('strval', range(1, 11))));
        $this->assertNull($v->int_list('ids', 10), 'too many entries');
        $v = $this->v(array('request_token' => 'not-a-uuid'));
        $this->assertNull($v->token());
        $v = $this->v(array('request_token' => Idempotency::new_token()));
        $this->assertNotNull($v->token());
        $this->assertTrue($v->ok());
        $v = $this->v(array('pw' => 'short'));
        $this->assertNull($v->secret('pw', 8, 100));
    }

    public function test_all_errors_are_reported_together_one_per_line()
    {
        $v = $this->v(array('amount' => 'abc', 'from_date' => '31-02-2026'));
        $v->money('amount');
        $v->date('from_date');
        $v->text('reason', 255);
        $this->assertFalse($v->ok());
        $lines = explode("\n", $v->message());
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('Amount', $lines[0]);
        $this->assertStringContainsString('From date', $lines[1]);
        $this->assertStringContainsString('Reason', $lines[2]);
    }

    public function test_get_source_is_read_for_list_filters()
    {
        $v = $this->v(array('n' => '1'), array('n' => '2'), 'get');
        $this->assertSame(2, $v->int('n'));
    }

    public function test_email()
    {
        $v = $this->v(array('a' => ' HR@Acme.Example ', 'b' => 'not-an-email', 'c' => 'x@y'));
        $this->assertSame('hr@acme.example', $v->email('a'));
        $this->assertNull($v->email('b'));
        $this->assertNull($v->email('c'));
        $this->assertNull($v->email('missing', 120, FALSE));
        $this->assertCount(2, $v->errors());
    }

    public function test_enum_list_accepts_only_known_values()
    {
        $allowed = array('users_view', 'users_add', 'audit_view');
        $v = $this->v(array('p' => array('audit_view', 'users_view', 'audit_view'), 'bad' => array('users_view', 'payroll_pay'), 'nested' => array(array('x')), 'none' => ''));
        $this->assertSame(array('audit_view', 'users_view'), $v->enum_list('p', $allowed));
        $this->assertNull($v->enum_list('bad', $allowed));
        $this->assertNull($v->enum_list('nested', $allowed));
        $this->assertSame(array(), $v->enum_list('none', $allowed), 'nothing ticked');
        $this->assertNull($v->enum_list('none', $allowed, TRUE));
        $this->assertCount(3, $v->errors());
    }

    public function test_time_list_reads_the_times_of_a_repeated_field()
    {
        $post = array('seg_start' => array('10:00', '18:30:15'), 'seg_end' => array('14:00', ''), 'bad' => array('10:00', '25:00'), 'many' => array('01:00', '02:00', '03:00'), 'text' => 'x');
        $v = $this->v($post);
        $this->assertSame(array('10:00:00', '18:30:15'), $v->time_list('seg_start'));
        $this->assertTrue($v->ok());
        $this->assertSame(array('14:00:00', NULL), $v->time_list('seg_end', 4, TRUE, TRUE), 'a blank is NULL when allowed');
        $this->assertTrue($v->ok());
        $v = $this->v($post);
        $v->time_list('seg_end');
        $this->assertFalse($v->ok(), 'a blank is an error when not allowed');
        foreach (array(array('bad'), array('many', 2), array('text'), array('absent')) as $args) {
            $v = $this->v($post);
            call_user_func_array(array($v, 'time_list'), $args);
            $this->assertFalse($v->ok(), implode(',', $args));
        }
        $v = $this->v($post);
        $this->assertSame(array(), $v->time_list('absent', 4, FALSE));
        $this->assertTrue($v->ok(), 'optional and missing is fine');
    }
}
