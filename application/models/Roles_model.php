<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Roles = sets of permission keys from config/permissions.php. The system Admin role (is_system = 1) passes every check;
 * it is not edited or deleted here. Someone who is not an Admin may only create/edit roles within their own permissions.
 */
class Roles_model extends MY_Model
{
    public function list_page()
    {
        $dt = new Datatable_request($this->input, array('r.role_name', 'users', 'permissions', 'r.created_at'));
        $total = (int) $this->db->query('SELECT COUNT(*) AS c FROM roles')->row()->c;

        $filter = function () use ($dt) {
            $this->db->from('roles r');
            if ($dt->search !== '') {
                $this->db->like('r.role_name', $dt->search);
            }
        };
        $filter();
        $filtered = $this->db->count_all_results();

        $filter();
        $this->db->select('r.id, r.role_name, r.is_system, r.created_at,'
            . ' (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS users,'
            . ' (SELECT COUNT(*) FROM role_permissions p WHERE p.role_id = r.id) AS permissions', FALSE);
        $dt->apply($this->db);
        $catalog_size = count(permission_catalog_keys());
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array(
                'id'          => (int) $r['id'],
                'role_name'   => e($r['role_name']) . ($r['is_system'] ? ' <span class="label label-danger">' . e(t('roles_system')) . '</span>' : ''),
                'users'       => (int) $r['users'],
                'permissions' => $r['is_system'] ? e(t('roles_all_permissions')) : (int) $r['permissions'] . ' / ' . $catalog_size,
                'created'     => e(show_date($r['created_at'])),
                'is_system'   => (int) $r['is_system'],
            );
        }
        return $dt->response($total, $filtered, $rows);
    }

    /** role row + its keys, or NULL */
    public function get($id)
    {
        $role = $this->db->query('SELECT id, role_name, is_system, created_at FROM roles WHERE id = ?', array((int) $id))->row_array();
        if ( ! $role) {
            return NULL;
        }
        $role['permissions'] = $this->keys_of((int) $id);
        return $role;
    }

    public function create()
    {
        list($name, $keys, $error) = $this->read_form(NULL);
        if ($error !== NULL) {
            return $error;
        }
        $new_id = 0;
        list($ok, $message) = Tx::run(function () use ($name, $keys, &$new_id) {
            if ($this->db->query('SELECT 1 FROM roles WHERE role_name = ? FOR UPDATE', array($name))->num_rows() > 0) {
                return t('roles_name_taken');
            }
            $this->db->insert('roles', array('role_name' => $name, 'is_system' => 0, 'created_at' => now_local()));
            $new_id = (int) $this->db->insert_id();
            $this->write_keys($new_id, $keys);
            $this->audit('roles', $new_id, 'create', NULL, array('role_name' => $name, 'permissions' => $keys));
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $new_id : $message;
    }

    public function update($id)
    {
        $id = (int) $id;
        list($name, $keys, $error) = $this->read_form($id);
        if ($error !== NULL) {
            return $error;
        }
        list($ok, $message) = Tx::run(function () use ($id, $name, $keys) {
            $role = $this->db->query('SELECT id, role_name, is_system FROM roles WHERE id = ? FOR UPDATE', array($id))->row_array();
            if ( ! $role) {
                return t('not_found');
            }
            if ((int) $role['is_system']) {
                return t('roles_system_locked');
            }
            $old_keys = $this->keys_of($id);
            if ( ! $this->within_actor($old_keys)) {
                return t('roles_above_you');
            }
            if ($this->db->query('SELECT 1 FROM roles WHERE role_name = ? AND id <> ?', array($name, $id))->num_rows() > 0) {
                return t('roles_name_taken');
            }
            $this->db->where('id', $id)->update('roles', array('role_name' => $name));
            $this->db->where('role_id', $id)->delete('role_permissions');
            $this->write_keys($id, $keys);
            $added = array_values(array_diff($keys, $old_keys));
            $removed = array_values(array_diff($old_keys, $keys));
            if ($added || $removed || $role['role_name'] !== $name) {
                $this->audit('roles', $id, 'update',
                    array('role_name' => $role['role_name'], 'permissions' => $old_keys),
                    array('role_name' => $name, 'permissions' => $keys, 'added' => $added, 'removed' => $removed));
            }
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    public function delete($id)
    {
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id) {
            $role = $this->db->query('SELECT id, role_name, is_system FROM roles WHERE id = ? FOR UPDATE', array($id))->row_array();
            if ( ! $role) {
                return t('not_found');
            }
            if ((int) $role['is_system']) {
                return t('roles_system_locked');
            }
            $old_keys = $this->keys_of($id);
            if ( ! $this->within_actor($old_keys)) {
                return t('roles_above_you');
            }
            if ($this->db->query('SELECT 1 FROM users WHERE role_id = ? LIMIT 1', array($id))->num_rows() > 0) {
                return t('roles_in_use');
            }
            $this->db->where('id', $id)->delete('roles');             // role_permissions go with it (ON DELETE CASCADE)
            $this->audit('roles', $id, 'delete', array('role_name' => $role['role_name'], 'permissions' => $old_keys), NULL);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    /** keys the current user may grant (all for the Admin) */
    public function grantable_keys()
    {
        return current_permission_keys();
    }

    // ------------------------------------------------------------------------------------------------ internals

    /** array(name, keys, error|null) from the posted form */
    protected function read_form($id)
    {
        $v = new Input_validator($this->input);
        $name = $v->text('role_name', 80, TRUE, 2);
        $keys = $v->enum_list('permissions', array_keys(permission_catalog_keys()));
        if ( ! $v->ok()) {
            return array(NULL, NULL, $v->message());
        }
        sort($keys);
        if ( ! $this->within_actor($keys)) {
            return array(NULL, NULL, t('roles_grant_above_you'));
        }
        return array($name, $keys, NULL);
    }

    protected function within_actor(array $keys)
    {
        $actor = current_user();
        if ( ! empty($actor['is_system'])) {
            return TRUE;
        }
        return count(array_diff($keys, current_permission_keys())) === 0;
    }

    protected function keys_of($role_id)
    {
        $keys = array();
        foreach ($this->db->query('SELECT permission_key FROM role_permissions WHERE role_id = ? ORDER BY permission_key', array((int) $role_id))->result_array() as $r) {
            $keys[] = $r['permission_key'];
        }
        return $keys;
    }

    protected function write_keys($role_id, array $keys)
    {
        foreach ($keys as $key) {
            $this->db->insert('role_permissions', array('role_id' => $role_id, 'permission_key' => $key));
        }
    }
}
