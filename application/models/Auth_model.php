<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Login, password change and the per-request user lookup (G6). */
class Auth_model extends MY_Model
{
    /** a bcrypt hash of nothing, verified when the username does not exist so both paths cost the same time */
    const DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    /** @return array|null users + role + branch for the session, or NULL */
    public function session_user($user_id)
    {
        $row = $this->db->select('u.id, u.username, u.role_id, u.branch_id, u.employee_id, u.status, u.must_change_password, u.last_login_at,'
                . ' r.role_name, r.is_system, b.name AS branch_name, b.timezone AS branch_timezone, b.status AS branch_status')
            ->from('users u')
            ->join('roles r', 'r.id = u.role_id')
            ->join('branches b', 'b.id = u.branch_id')
            ->where('u.id', (int) $user_id)
            ->get()->row_array();
        if ( ! $row) {
            return NULL;
        }
        foreach (array('id', 'role_id', 'branch_id', 'is_system', 'must_change_password') as $k) {
            $row[$k] = (int) $row[$k];
        }
        $row['employee_id'] = $row['employee_id'] === NULL ? NULL : (int) $row['employee_id'];
        return $row;
    }

    /** minutes the pair username+IP stays locked (0 = not locked) */
    public function locked_minutes($username, $ip)
    {
        $window = (int) config_item('login_window_minutes');
        $since = date('Y-m-d H:i:s', time() - $window * 60);
        $rows = $this->db->select('attempted_at')->from('login_attempts')
            ->where('username', $username)->where('ip', $ip)->where('success', 0)->where('attempted_at >=', $since)
            ->order_by('attempted_at', 'DESC')->limit((int) config_item('login_max_failures'))->get()->result_array();
        if (count($rows) < (int) config_item('login_max_failures')) {
            return 0;
        }
        $oldest_counted = strtotime($rows[count($rows) - 1]['attempted_at']);
        return max(1, (int) ceil(($oldest_counted + $window * 60 - time()) / 60));
    }

    /**
     * Check the posted credentials. Reads username/password through Input_validator.
     * @return array array(bool $ok, string $message, array|null $user)
     */
    public function authenticate()
    {
        $v = new Input_validator($this->input);
        $username = $v->text('username', 64);
        $password = $v->secret('password', 1, 255);
        if ( ! $v->ok()) {
            return array(FALSE, $v->message(), NULL);
        }
        $username = mb_strtolower($username, 'UTF-8');
        $ip = client_ip();

        $locked = $this->locked_minutes($username, $ip);
        if ($locked > 0) {
            return array(FALSE, sprintf(t('login_locked'), $locked), NULL);
        }

        $user = $this->db->select('id, password_hash, status')->get_where('users', array('username' => $username))->row_array();
        $hash = $user ? $user['password_hash'] : self::DUMMY_HASH;
        $verified = password_verify($password, $hash);
        $good = $user && $verified && $user['status'] === 'Active';

        $this->record_attempt($username, $ip, $good);
        if ( ! $good) {
            return array(FALSE, t('login_invalid'), NULL);
        }
        $full = $this->session_user((int) $user['id']);
        if ( ! $full || $full['branch_status'] !== 'Active') {
            return array(FALSE, t('login_invalid'), NULL);
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->write_password((int) $user['id'], $password, NULL);
        }
        return array(TRUE, 'success', $full);
    }

    protected function record_attempt($username, $ip, $success)
    {
        Tx::run(function () use ($username, $ip, $success) {
            $this->db->insert('login_attempts', array(
                'username'     => $username,
                'ip'           => $ip,
                'success'      => $success ? 1 : 0,
                'attempted_at' => now_local(),
            ));
            if ($success) {                                   // a good login clears the failures of this pair
                $this->db->where('username', $username)->where('ip', $ip)->where('success', 0)->delete('login_attempts');
            }
            return TRUE;
        });
    }

    public function touch_login($user_id)
    {
        list($ok, $message) = Tx::run(function () use ($user_id) {
            $this->db->where('id', (int) $user_id)->update('users', array('last_login_at' => now_local()));
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    /**
     * Change the logged-in user's password from the posted form.
     * @return string "success" or an error message
     */
    public function change_password($user_id)
    {
        $v = new Input_validator($this->input);
        $current = $v->secret('current_password', 1, 255);
        $new = $v->secret('new_password', 8, 255);
        $confirm = $v->secret('confirm_password', 1, 255);
        if ( ! $v->ok()) {
            return $v->message();
        }
        if ($new !== $confirm) {
            return t('password_mismatch');
        }
        $user = $this->db->select('username, password_hash')->get_where('users', array('id' => (int) $user_id))->row_array();
        if ( ! $user || ! password_verify($current, $user['password_hash'])) {
            return t('password_current_wrong');
        }
        if (mb_strtolower($new, 'UTF-8') === $user['username']) {
            return t('password_same_as_username');
        }
        if ($new === $current) {
            return t('password_unchanged');
        }
        return $this->write_password((int) $user_id, $new, 0);
    }

    /** set a password hash; $must_change NULL leaves the flag alone */
    protected function write_password($user_id, $password, $must_change)
    {
        list($ok, $message) = Tx::run(function () use ($user_id, $password, $must_change) {
            $fields = array('password_hash' => password_hash($password, PASSWORD_DEFAULT), 'updated_at' => now_local());
            if ($must_change !== NULL) {
                $fields['must_change_password'] = (int) $must_change;
            }
            $this->db->where('id', $user_id)->update('users', $fields);
            if ($must_change !== NULL) {
                $this->audit('users', $user_id, 'password_change', NULL, array('by' => 'self'));
            }
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }
}
