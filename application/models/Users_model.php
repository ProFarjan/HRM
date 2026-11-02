<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Logins (users). Employees and their link to a login arrive in build step 2.
 *
 * Anti-escalation rules (CLAUDE.md "Users and roles"):
 *  - only the system Admin may assign the Admin role or manage a user who has it;
 *  - anyone else may assign / manage only roles whose permissions they hold themselves;
 *  - nobody changes their own role, branch or status, or deletes themselves;
 *  - the last active Admin cannot be deactivated, demoted or deleted.
 */
class Users_model extends MY_Model
{
    const USERNAME_PATTERN = '/^[a-z0-9._-]{3,64}$/';
    const STATUSES = 'Active,Inactive';

    // ------------------------------------------------------------------------------------------------ reads

    /** DataTables list of the current branch (POST: draw/start/length/search/order + filters role_id, status) */
    public function list_page()
    {
        $dt = new Datatable_request($this->input, array('u.username', 'r.role_name', 'u.status', 'u.last_login_at', 'u.created_at'));
        $v = new Input_validator($this->input);
        $status = $v->enum('status', explode(',', self::STATUSES), FALSE);
        $role_id = $v->int('role_id', 1, NULL, FALSE);

        $this->db->from('users u');
        $this->scope_branch('u');
        $total = $this->db->count_all_results();

        $filter = function () use ($dt, $status, $role_id) {
            $this->db->from('users u')->join('roles r', 'r.id = u.role_id');
            $this->scope_branch('u');
            if ($status !== NULL) {
                $this->db->where('u.status', $status);
            }
            if ($role_id !== NULL) {
                $this->db->where('u.role_id', $role_id);
            }
            if ($dt->search !== '') {
                $this->db->group_start()->like('u.username', $dt->search)->or_like('r.role_name', $dt->search)->group_end();
            }
        };
        $filter();
        $filtered = $this->db->count_all_results();

        $filter();
        $this->db->select('u.id, u.username, u.status, u.last_login_at, u.created_at, u.must_change_password, r.role_name, r.is_system');
        $dt->apply($this->db);
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array(
                'id'         => (int) $r['id'],
                'username'   => e($r['username']),
                'role'       => e($r['role_name']) . ($r['is_system'] ? ' <span class="label label-danger">Admin</span>' : ''),
                'status'     => $r['status'] === 'Active' ? '<span class="label label-success">' . e(t('status_active')) . '</span>' : '<span class="label label-default">' . e(t('status_inactive')) . '</span>',
                'last_login' => e($r['last_login_at'] ? show_date($r['last_login_at']) . substr($r['last_login_at'], 10, 6) : '-'),
                'created'    => e(show_date($r['created_at'])),
                'flags'      => $r['must_change_password'] ? '<span class="label label-warning">' . e(t('users_must_change')) . '</span>' : '',
            );
        }
        return $dt->response($total, $filtered, $rows);
    }

    /** one user of the current branch (with role) or NULL */
    public function get($id)
    {
        $this->db->select('u.id, u.username, u.role_id, u.branch_id, u.status, u.must_change_password, u.last_login_at, u.created_at, r.role_name, r.is_system')
            ->from('users u')->join('roles r', 'r.id = u.role_id')->where('u.id', (int) $id);
        $this->scope_branch('u');
        $row = $this->db->get()->row_array();
        return $row ? $row : NULL;
    }

    /** roles the current user may assign: id => name */
    public function assignable_roles()
    {
        $out = array();
        foreach ($this->db->query('SELECT id, role_name, is_system FROM roles ORDER BY is_system DESC, role_name')->result_array() as $r) {
            if ($this->role_within_actor((int) $r['id'], (int) $r['is_system'])) {
                $out[(int) $r['id']] = $r['role_name'];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------------ writes

    /** create a login from the posted form; "success<<<###>>>id" or an error */
    public function create()
    {
        $v = new Input_validator($this->input);
        $username = $v->text('username', 64);
        $password = $v->secret('password', 8, 255);
        $role_id = $v->int('role_id', 1);
        $status = $v->enum('status', explode(',', self::STATUSES));
        $must_change = $v->flag('must_change_password');
        $branch_id = $this->branch_from_form($v);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $username = mb_strtolower($username, 'UTF-8');
        if ( ! preg_match(self::USERNAME_PATTERN, $username)) {
            return t('users_username_rule');
        }
        if (mb_strtolower($password, 'UTF-8') === $username) {
            return t('password_same_as_username');
        }
        $role_error = $this->check_assignable($role_id);
        if ($role_error !== TRUE) {
            return $role_error;
        }

        $new_id = 0;
        list($ok, $message) = Tx::run(function () use ($username, $password, $role_id, $status, $must_change, $branch_id, &$new_id) {
            if ($this->db->query('SELECT 1 FROM users WHERE username = ? FOR UPDATE', array($username))->num_rows() > 0) {
                return t('users_username_taken');
            }
            $this->db->insert('users', array(
                'username'             => $username,
                'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
                'employee_id'          => NULL,
                'role_id'              => $role_id,
                'branch_id'            => $branch_id,
                'status'               => $status,
                'must_change_password' => $must_change,
                'created_at'           => now_local(),
            ));
            $new_id = (int) $this->db->insert_id();
            $this->audit('users', $new_id, 'create', NULL, array('username' => $username, 'role_id' => $role_id, 'branch_id' => $branch_id, 'status' => $status, 'must_change_password' => $must_change));
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $new_id : $message;
    }

    /** change role / branch / status of a user of the current branch */
    public function update($id)
    {
        $v = new Input_validator($this->input);
        $role_id = $v->int('role_id', 1);
        $status = $v->enum('status', explode(',', self::STATUSES));
        $branch_id = $this->branch_from_form($v);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id, $role_id, $status, $branch_id) {
            $old = $this->lock_target($id);
            if ( ! is_array($old)) {
                return $old;
            }
            $changes = array();
            foreach (array('role_id' => $role_id, 'status' => $status, 'branch_id' => $branch_id) as $k => $val) {
                if ((string) $old[$k] !== (string) $val) {
                    $changes[$k] = $val;
                }
            }
            if ( ! $changes) {
                return TRUE;
            }
            if ($id === current_user_id()) {
                return t('users_not_yourself');
            }
            if (isset($changes['role_id'])) {
                $role_error = $this->check_assignable($role_id);
                if ($role_error !== TRUE) {
                    return $role_error;
                }
            }
            $new_is_system = (int) $this->db->query('SELECT is_system FROM roles WHERE id = ?', array($role_id))->row()->is_system;
            if ($old['is_system'] && $old['status'] === 'Active' && ( ! $new_is_system || $status !== 'Active')) {
                $guard = $this->last_admin_guard($id);
                if ($guard !== TRUE) {
                    return $guard;
                }
            }
            $changes['updated_at'] = now_local();
            $this->db->where('id', $id)->update('users', $changes);
            unset($changes['updated_at']);
            $this->audit('users', $id, isset($changes['role_id']) ? 'role_change' : 'update', array_intersect_key($old, $changes), $changes);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    /** set a temporary password the user must change at the next login */
    public function reset_password($id)
    {
        $v = new Input_validator($this->input);
        $password = $v->secret('new_password', 8, 255);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id, $password) {
            $old = $this->lock_target($id);
            if ( ! is_array($old)) {
                return $old;
            }
            if (mb_strtolower($password, 'UTF-8') === $old['username']) {
                return t('password_same_as_username');
            }
            $this->db->where('id', $id)->update('users', array(
                'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'must_change_password' => 1, 'updated_at' => now_local(),
            ));
            $this->audit('users', $id, 'password_reset', NULL, array('must_change_password' => 1));   // never the password itself
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    /** Active <-> Inactive */
    public function toggle_status($id)
    {
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id) {
            $old = $this->lock_target($id);
            if ( ! is_array($old)) {
                return $old;
            }
            if ($id === current_user_id()) {
                return t('users_not_yourself');
            }
            $new = $old['status'] === 'Active' ? 'Inactive' : 'Active';
            if ($new === 'Inactive' && $old['is_system']) {
                $guard = $this->last_admin_guard($id);
                if ($guard !== TRUE) {
                    return $guard;
                }
            }
            $this->db->where('id', $id)->update('users', array('status' => $new, 'updated_at' => now_local()));
            $this->audit('users', $id, $new === 'Active' ? 'activate' : 'deactivate', array('status' => $old['status']), array('status' => $new));
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    public function delete($id)
    {
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id) {
            $old = $this->lock_target($id);
            if ( ! is_array($old)) {
                return $old;
            }
            if ($id === current_user_id()) {
                return t('users_not_yourself');
            }
            if ($old['is_system'] && $old['status'] === 'Active') {
                $guard = $this->last_admin_guard($id);
                if ($guard !== TRUE) {
                    return $guard;
                }
            }
            $this->db->where('id', $id)->delete('users');
            if ($this->db->affected_rows() !== 1) {
                return t('users_delete_has_records');           // a foreign key from later modules refused it
            }
            $this->audit('users', $id, 'delete', array('username' => $old['username'], 'role_id' => $old['role_id'], 'branch_id' => $old['branch_id'], 'status' => $old['status']), NULL);
            return TRUE;
        });
        return $ok ? 'success' : ($message === 'A database error occurred. Nothing was saved.' ? t('users_delete_has_records') : $message);
    }

    // ------------------------------------------------------------------------------------------------ rules

    /** the target row locked for update (current branch only), or an error message */
    protected function lock_target($id)
    {
        $branch_id = (int) current_branch_id();
        $row = $this->db->query(
            'SELECT u.id, u.username, u.role_id, u.branch_id, u.status, r.is_system FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? AND u.branch_id = ? FOR UPDATE',
            array($id, $branch_id)
        )->row_array();
        if ( ! $row) {
            return t('not_found');
        }
        $row['is_system'] = (int) $row['is_system'];
        if ( ! $this->role_within_actor((int) $row['role_id'], $row['is_system'])) {
            return t('users_target_above_you');
        }
        return $row;
    }

    /** TRUE when another active Admin login exists (locks them so two admins cannot demote each other at once) */
    protected function last_admin_guard($except_id)
    {
        $others = $this->db->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.is_system = 1 AND u.status = 'Active' AND u.id <> ? FOR UPDATE",
            array((int) $except_id)
        )->num_rows();
        return $others > 0 ? TRUE : t('users_last_admin');
    }

    /** may the current user assign this role? TRUE or an error message */
    public function check_assignable($role_id)
    {
        $role = $this->db->query('SELECT id, is_system FROM roles WHERE id = ?', array((int) $role_id))->row_array();
        if ( ! $role) {
            return sprintf(t('v_ref'), 'role');
        }
        if ( ! $this->role_within_actor((int) $role['id'], (int) $role['is_system'])) {
            return (int) $role['is_system'] ? t('users_admin_only') : t('users_role_above_you');
        }
        return TRUE;
    }

    /** the role does not exceed the current user: Admin role only for Admins, otherwise a subset of the actor's keys */
    protected function role_within_actor($role_id, $is_system)
    {
        $actor = current_user();
        if ( ! empty($actor['is_system'])) {
            return TRUE;
        }
        if ($is_system) {
            return FALSE;
        }
        $keys = array();
        foreach ($this->db->query('SELECT permission_key FROM role_permissions WHERE role_id = ?', array($role_id))->result_array() as $r) {
            $keys[] = $r['permission_key'];
        }
        return count(array_diff($keys, current_permission_keys())) === 0;
    }

    /** branch of the form: an active branch for the Admin, always the current branch for everyone else */
    protected function branch_from_form(Input_validator $v)
    {
        $actor = current_user();
        if (empty($actor['is_system'])) {
            return (int) current_branch_id();
        }
        $branch_id = $v->int('branch_id', 1);
        if ($branch_id !== NULL && $this->db->query("SELECT 1 FROM branches WHERE id = ? AND status = 'Active'", array($branch_id))->num_rows() === 0) {
            return $v->fail('branch_id', sprintf(t('v_ref'), 'branch'));
        }
        return $branch_id;
    }
}
