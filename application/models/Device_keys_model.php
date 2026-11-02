<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * API keys of the ZKTeco Windows Service (P17): created for the working branch (optionally for one device), shown ONCE, stored as
 * sha256 only, revocable. Creating and revoking are audited; the key itself never is.
 */
class Device_keys_model extends MY_Model
{
    public function list_page()
    {
        $dt = new Datatable_request($this->input, array('k.label', 'd.name', 'k.key_hint', 'k.created_at', 'k.last_used_at', 'k.revoked_at'), 3, 'desc');
        $base = function () use ($dt) {
            $this->db->from('device_keys k')->join('devices d', 'd.id = k.device_id', 'left');
            $this->scope_branch('k');
            if ($dt->search !== '') {
                $this->db->group_start()->like('k.label', $dt->search)->or_like('d.name', $dt->search)->group_end();
            }
        };
        $this->db->from('device_keys k');
        $this->scope_branch('k');
        $total = $this->db->count_all_results();
        $base();
        $filtered = $this->db->count_all_results();
        $base();
        $this->db->select('k.*, d.name AS device_name');
        $dt->apply($this->db);
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array(
                'id'        => (int) $r['id'],
                'label'     => e($r['label']),
                'device'    => $r['device_id'] === NULL ? '<span class="text-muted">' . e(t('key_any_device')) . '</span>' : e($r['device_name']),
                'hint'      => e('…' . $r['key_hint']),
                'created'   => e(show_date($r['created_at']) . ' ' . substr($r['created_at'], 11, 5)),
                'last_used' => $r['last_used_at'] === NULL ? '<span class="text-muted">' . e(t('dev_never')) . '</span>' : e(show_date($r['last_used_at']) . ' ' . substr($r['last_used_at'], 11, 5)),
                'status'    => $r['revoked_at'] === NULL ? '<span class="label label-success">' . e(t('key_active')) . '</span>' : '<span class="label label-default">' . e(t('key_revoked')) . '</span>',
                'revoked'   => $r['revoked_at'] !== NULL,
            );
        }
        return $dt->response($total, $filtered, $rows);
    }

    /** @return string "success<<<###>>>" + the new key (shown once) or an error message */
    public function create()
    {
        $v = new Input_validator($this->input);
        $label = $v->text('label', 100);
        $device = $v->ref('device_id', 'devices', FALSE);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $key = Device_auth::generate_key();
        list($ok, $message) = Tx::run(function () use ($label, $device, $key, &$new_id) {
            $this->db->insert('device_keys', array(
                'branch_id' => (int) current_branch_id(), 'device_id' => $device, 'label' => $label, 'key_hash' => Device_auth::hash_key($key),
                'key_hint' => substr($key, -4), 'created_by' => current_user_id(), 'created_at' => now_local(),
            ));
            $new_id = (int) $this->db->insert_id();
            $this->audit('device_keys', $new_id, 'create', NULL, array('label' => $label, 'device_id' => $device));
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $key : $message;
    }

    public function revoke($id)
    {
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id) {
            $row = $this->db->query('SELECT * FROM device_keys WHERE id = ? AND branch_id = ? FOR UPDATE', array($id, (int) current_branch_id()))->row_array();
            if ( ! $row) {
                return t('not_found');
            }
            if ($row['revoked_at'] !== NULL) {
                return TRUE;
            }
            $this->db->where('id', $id)->update('device_keys', array('revoked_at' => now_local(), 'revoked_by' => current_user_id()));
            $this->audit('device_keys', $id, 'revoke', array('label' => $row['label']), NULL);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }
}
