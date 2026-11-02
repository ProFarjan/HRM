<?php
/** G6 - login rules in-process: password_hash, generic failure message, throttle (5 failures / 15 min per username+IP), password change. */
class Auth_test extends Hrm_test_case
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->CI->load->model('Auth_model');
        $_POST = array();
    }

    protected function tearDown(): void
    {
        $_POST = array();
        parent::tearDown();
    }

    protected function post(array $fields)
    {
        $_POST = $fields;
    }

    protected function login($username, $password)
    {
        $this->post(array('username' => $username, 'password' => $password));
        return $this->CI->Auth_model->authenticate();
    }

    protected function user($name, $password = 'Correct#Horse9', $extra = array(), $branch = NULL)
    {
        return $this->insert_user($name, $this->insert_role('role_' . $name), $branch, $password, $extra);
    }

    public function test_good_credentials_log_in_and_load_the_role_and_branch()
    {
        $id = $this->user('amina');
        list($ok, $message, $user) = $this->login('amina', 'Correct#Horse9');
        $this->assertTrue($ok, $message);
        $this->assertSame($id, $user['id']);
        $this->assertSame('Active', $user['branch_status']);
        $this->assertSame('Asia/Dhaka', $user['branch_timezone']);
        $this->assertSame(0, $user['is_system']);
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) AS c FROM login_attempts WHERE username = ? AND success = 1', array('amina'))->row()->c);
    }

    public function test_username_is_case_and_space_insensitive_on_input()
    {
        $this->user('rafiq');
        list($ok) = $this->login('  RaFiQ ', 'Correct#Horse9');
        $this->assertTrue($ok);
    }

    public function test_passwords_are_stored_hashed_never_in_plain_text()
    {
        $this->user('hashed', 'Correct#Horse9');
        $hash = $this->db->get_where('users', array('username' => 'hashed'))->row()->password_hash;
        $this->assertNotSame('Correct#Horse9', $hash);
        $this->assertGreaterThan(0, strlen($hash));
        $this->assertNotSame(0, password_get_info($hash)['algo'] === 0 ? 0 : 1, 'a real password_hash() value');
        $this->assertTrue(password_verify('Correct#Horse9', $hash));
        $this->assertFalse(password_verify('correct#horse9', $hash));
    }

    public function test_every_failure_gives_the_same_message_so_usernames_cannot_be_probed()
    {
        $this->user('real');
        $this->user('sleeper', 'Correct#Horse9', array('status' => 'Inactive'));
        $dead_branch = $this->insert_branch('DEAD');
        $this->db->where('id', $dead_branch)->update('branches', array('status' => 'Inactive'));
        $this->user('stranded', 'Correct#Horse9', array(), $dead_branch);

        $messages = array();
        foreach (array(array('real', 'wrong'), array('nobody', 'Correct#Horse9'), array('sleeper', 'Correct#Horse9'), array('stranded', 'Correct#Horse9')) as $pair) {
            list($ok, $message, $user) = $this->login($pair[0], $pair[1]);
            $this->assertFalse($ok, $pair[0]);
            $this->assertNull($user);
            $messages[] = $message;
        }
        $this->assertSame(array($messages[0]), array_values(array_unique($messages)), 'identical message for wrong password, unknown user, inactive user, inactive branch');
        $this->assertSame(t('login_invalid'), $messages[0]);
    }

    public function test_missing_fields_are_reported()
    {
        list($ok, $message) = $this->login('', '');
        $this->assertFalse($ok);
        $this->assertStringContainsString('required', $message);
    }

    public function test_five_failures_lock_the_username_ip_pair()
    {
        $this->user('victim');
        for ($i = 1; $i <= 5; $i++) {
            list($ok, $message) = $this->login('victim', 'wrong' . $i);
            $this->assertSame(t('login_invalid'), $message, "attempt $i is an ordinary failure");
        }
        list($ok, $message) = $this->login('victim', 'Correct#Horse9');
        $this->assertFalse($ok, 'even the right password is refused while locked');
        $this->assertMatchesRegularExpression('/Too many failed attempts.*\b(1[0-5]|[1-9])\b minute/', $message);
        $this->assertGreaterThan(0, $this->CI->Auth_model->locked_minutes('victim', '0.0.0.0'));
        $this->assertLessThanOrEqual(15, $this->CI->Auth_model->locked_minutes('victim', '0.0.0.0'));

        $this->assertSame(0, $this->CI->Auth_model->locked_minutes('victim', '10.9.9.9'), 'another IP is a different pair');
        $this->assertSame(0, $this->CI->Auth_model->locked_minutes('someone_else', '0.0.0.0'), 'another username is a different pair');
    }

    public function test_four_failures_do_not_lock_and_a_success_clears_them()
    {
        $this->user('careful');
        for ($i = 0; $i < 4; $i++) {
            $this->login('careful', 'nope');
        }
        list($ok) = $this->login('careful', 'Correct#Horse9');
        $this->assertTrue($ok);
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) AS c FROM login_attempts WHERE username = ? AND success = 0', array('careful'))->row()->c);
        for ($i = 0; $i < 4; $i++) {
            $this->login('careful', 'nope');
        }
        list($ok) = $this->login('careful', 'Correct#Horse9');
        $this->assertTrue($ok, 'the counter started over after the success');
    }

    public function test_old_failures_expire()
    {
        $old = date('Y-m-d H:i:s', time() - 16 * 60);
        for ($i = 0; $i < 5; $i++) {
            $this->db->insert('login_attempts', array('username' => 'stale', 'ip' => '0.0.0.0', 'success' => 0, 'attempted_at' => $old));
        }
        $this->assertSame(0, $this->CI->Auth_model->locked_minutes('stale', '0.0.0.0'));
        $recent = date('Y-m-d H:i:s', time() - 14 * 60);
        $this->db->update('login_attempts', array('attempted_at' => $recent), array('username' => 'stale'));
        $this->assertGreaterThan(0, $this->CI->Auth_model->locked_minutes('stale', '0.0.0.0'));
    }

    public function test_injection_in_the_username_is_just_a_failed_login()
    {
        $this->user('safe');
        list($ok) = $this->login("' OR '1'='1' -- ", 'x');
        $this->assertFalse($ok);
        $this->assertSame(1, $this->db->query("SELECT COUNT(*) AS c FROM users WHERE username = 'safe'")->row()->c + 0);
    }

    // ---------------------------------------------------------------------------------- change password

    protected function change($user_id, $current, $new, $confirm = NULL)
    {
        $this->post(array('current_password' => $current, 'new_password' => $new, 'confirm_password' => $confirm === NULL ? $new : $confirm));
        return $this->CI->Auth_model->change_password($user_id);
    }

    public function test_password_change_rules()
    {
        $id = $this->user('changer', 'Old#Passw0rd1', array('must_change_password' => 1));
        $this->act_as($id);
        $this->assertStringContainsString('8 to 255 characters', $this->change($id, 'Old#Passw0rd1', 'short'));
        $this->assertSame(t('password_mismatch'), $this->change($id, 'Old#Passw0rd1', 'New#Passw0rd2', 'New#Passw0rd3'));
        $this->assertSame(t('password_current_wrong'), $this->change($id, 'not-my-password', 'New#Passw0rd2'));
        $this->assertSame(t('password_unchanged'), $this->change($id, 'Old#Passw0rd1', 'Old#Passw0rd1'));
        $this->assertSame(1, (int) $this->db->get_where('users', array('id' => $id))->row()->must_change_password, 'nothing changed yet');

        $this->assertSame('success', $this->change($id, 'Old#Passw0rd1', 'New#Passw0rd2'));
        $row = $this->db->get_where('users', array('id' => $id))->row();
        $this->assertSame(0, (int) $row->must_change_password);
        $this->assertTrue(password_verify('New#Passw0rd2', $row->password_hash));
        $this->assertFalse(password_verify('Old#Passw0rd1', $row->password_hash));
        $audit = $this->db->get_where('audit_log', array('module' => 'users', 'record_id' => (string) $id, 'action' => 'password_change'))->num_rows();
        $this->assertSame(1, $audit, 'password changes are audited');
        $this->assertStringNotContainsString('Passw0rd', (string) $this->db->query("SELECT GROUP_CONCAT(COALESCE(new_values,'')) AS v FROM audit_log WHERE module = 'users'")->row()->v, 'the password never reaches the audit log');
    }

    public function test_password_may_not_equal_the_username()
    {
        $id = $this->user('longusername1', 'Old#Passw0rd1');
        $this->act_as($id);
        $this->assertSame(t('password_same_as_username'), $this->change($id, 'Old#Passw0rd1', 'LongUsername1'));
    }
}
