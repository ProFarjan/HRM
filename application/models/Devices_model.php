<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Attendance devices of the working branch: register / edit / disable, the pending ADMS serial numbers HR approves, and the
 * health of each device (last seen, punches today, clock drift, possible lost punches).
 */
class Devices_model extends MY_Model
{
    const GREEN_MINUTES = 10;
    const AMBER_MINUTES = 60;
    const DRIFT_SECONDS = 300;

    public static function comm_modes()
    {
        return array('windows_service', 'adms_push');
    }

    // ------------------------------------------------------------------------------------------------ list + health

    public function list_page()
    {
        $dt = new Datatable_request($this->input, array('d.name', 'd.comm_mode', 'd.serial_number', 'd.status', 'd.last_seen_at', 'd.last_seen_at', 'd.last_punch_at', NULL, NULL));
        $base = function () use ($dt) {
            $this->db->from('devices d');
            $this->scope_branch('d');
            if ($dt->search !== '') {
                $this->db->group_start()->like('d.name', $dt->search)->or_like('d.serial_number', $dt->search)->or_like('d.ip', $dt->search)->group_end();
            }
        };
        $this->db->from('devices d');
        $this->scope_branch('d');
        $total = $this->db->count_all_results();
        $base();
        $filtered = $this->db->count_all_results();
        $base();
        $this->db->select('d.*');
        $dt->apply($this->db);
        $list = $this->db->get()->result_array();
        $today = $this->punches_today(array_column($list, 'id'));
        $rows = array();
        foreach ($list as $r) {
            $h = self::health($r);
            $flags = array();
            if ($h['drift']) {
                $flags[] = '<span class="label label-warning" title="' . e(t('dev_drift_help')) . '"><i class="fa fa-clock-o"></i> ' . e(sprintf(t('dev_drift'), round($r['clock_offset_seconds'] / 60))) . '</span>';
            }
            if ((int) $r['lost_punch_alert']) {
                $flags[] = '<span class="label label-danger" title="' . e(t('dev_lost_help')) . '"><i class="fa fa-exclamation-triangle"></i> ' . e(t('dev_lost')) . '</span>';
            }
            if ($r['blocked_ip'] !== NULL) {
                $flags[] = '<span class="label label-danger"><i class="fa fa-ban"></i> ' . e(sprintf(t('dev_blocked_ip'), $r['blocked_ip'])) . '</span>';
            }
            $rows[] = array(
                'id'        => (int) $r['id'],
                'name'      => e($r['name']),
                'mode'      => e(t('dev_mode_' . $r['comm_mode'])),
                'serial'    => e((string) $r['serial_number'] . ($r['ip'] ? ($r['serial_number'] ? ' / ' : '') . $r['ip'] : '')),
                'status'    => $r['status'] === 'active' ? '<span class="label label-success">' . e(t('dev_active')) . '</span>' : '<span class="label label-default">' . e(t('dev_disabled')) . '</span>',
                'health'    => '<span class="label label-' . array('green' => 'success', 'yellow' => 'warning', 'red' => 'danger')[$h['color']] . '">' . e(t('dev_health_' . $h['color'])) . '</span>',
                'last_seen' => $r['last_seen_at'] === NULL ? '<span class="text-muted">' . e(t('dev_never')) . '</span>' : e(show_date($r['last_seen_at']) . ' ' . substr($r['last_seen_at'], 11, 5)),
                'last_punch' => $r['last_punch_at'] === NULL ? '' : e(show_date($r['last_punch_at']) . ' ' . substr($r['last_punch_at'], 11, 5)),
                'today'     => (int) (isset($today[(int) $r['id']]) ? $today[(int) $r['id']] : 0),
                'flags'     => implode(' ', $flags),
                'blocked'   => $r['blocked_ip'] !== NULL,
            );
        }
        return $dt->response($total, $filtered, $rows);
    }

    /** green < 10 min since the last call, amber < 60 min, else red (never seen = red); drift above 5 minutes */
    public static function health(array $device, $now = NULL)
    {
        $now = $now !== NULL ? $now : time();
        $color = 'red';
        if ($device['last_seen_at'] !== NULL) {
            $age = $now - strtotime($device['last_seen_at']);
            $color = $age < self::GREEN_MINUTES * 60 ? 'green' : ($age < self::AMBER_MINUTES * 60 ? 'yellow' : 'red');
        }
        return array('color' => $color, 'drift' => $device['clock_offset_seconds'] !== NULL && abs((int) $device['clock_offset_seconds']) > self::DRIFT_SECONDS);
    }

    /** device_id => punches received for today (one range query) */
    protected function punches_today(array $ids)
    {
        if ( ! $ids) {
            return array();
        }
        $today = today_local();
        $out = array();
        foreach ($this->db->select('device_id, COUNT(*) AS c', FALSE)->from('attendance_punches')->where('branch_id', (int) current_branch_id())
                    ->where_in('device_id', array_map('intval', $ids))->where('punch_at >=', $today . ' 00:00:00')->where('punch_at <', date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00')
                    ->group_by('device_id')->get()->result_array() as $r) {
            $out[(int) $r['device_id']] = (int) $r['c'];
        }
        return $out;
    }

    public function get($id)
    {
        $this->db->from('devices')->where('id', (int) $id);
        $this->scope_branch();
        $row = $this->db->get()->row_array();
        return $row ? $row : NULL;
    }

    /** id => name of the active devices of the branch (selects) */
    public function options($comm_mode = NULL)
    {
        $this->db->select('id, name')->from('devices')->where('status', 'active');
        $this->scope_branch();
        if ($comm_mode !== NULL) {
            $this->db->where('comm_mode', $comm_mode);
        }
        $out = array();
        foreach ($this->db->order_by('name')->get()->result_array() as $r) {
            $out[(int) $r['id']] = $r['name'];
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------------ create / edit / delete

    public function create()
    {
        $data = $this->read_form(0);
        if ( ! is_array($data)) {
            return $data;
        }
        $new_id = 0;
        list($ok, $message) = Tx::run(function () use ($data, &$new_id) {
            $error = $this->unique_error($data, 0);
            if ($error !== NULL) {
                return $error;
            }
            $this->db->insert('devices', array_merge($data, array('branch_id' => (int) current_branch_id(), 'created_by' => current_user_id(), 'created_at' => now_local())));
            $new_id = (int) $this->db->insert_id();
            $this->audit('devices', $new_id, 'create', NULL, $data);
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $new_id : $message;
    }

    public function update($id)
    {
        $id = (int) $id;
        $data = $this->read_form($id);
        if ( ! is_array($data)) {
            return $data;
        }
        list($ok, $message) = Tx::run(function () use ($id, $data) {
            $old = $this->lock($id);
            if ( ! $old) {
                return t('not_found');
            }
            $error = $this->unique_error($data, $id);
            if ($error !== NULL) {
                return $error;
            }
            $changes = array();
            foreach ($data as $k => $v) {
                if ((string) $old[$k] !== (string) $v) {
                    $changes[$k] = $v;
                }
            }
            if ($changes) {
                $this->db->where('id', $id)->update('devices', array_merge($changes, array('updated_at' => now_local())));
                $this->audit('devices', $id, 'update', array_intersect_key($old, $changes), $changes);
            }
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    /** only a device that never delivered a punch and has no active key can be deleted; disable the others */
    public function delete($id)
    {
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id) {
            $old = $this->lock($id);
            if ( ! $old) {
                return t('not_found');
            }
            if ($this->db->query('SELECT 1 FROM attendance_punches WHERE device_id = ? LIMIT 1', array($id))->num_rows()) {
                return t('dev_has_punches');
            }
            if ($this->db->query('SELECT 1 FROM device_keys WHERE device_id = ? AND revoked_at IS NULL LIMIT 1', array($id))->num_rows()) {
                return t('dev_has_keys');
            }
            $this->db->query('DELETE FROM device_commands WHERE device_id = ?', array($id));
            $this->db->query('DELETE FROM device_logs WHERE device_id = ?', array($id));
            $this->db->query('DELETE FROM device_keys WHERE device_id = ?', array($id));
            $this->db->where('id', $id)->delete('devices');
            $this->audit('devices', $id, 'delete', array('name' => $old['name'], 'serial_number' => $old['serial_number']), NULL);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    /** HR confirms the address a device was refused from: it joins allowed_ips */
    public function allow_blocked_ip($id)
    {
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id) {
            $old = $this->lock($id);
            if ( ! $old) {
                return t('not_found');
            }
            if ($old['blocked_ip'] === NULL) {
                return TRUE;
            }
            $list = array_filter(array_map('trim', explode(',', (string) $old['allowed_ips'])), 'strlen');
            $list[] = $old['blocked_ip'];
            $new = mb_substr(implode(',', array_unique($list)), 0, 255);
            $this->db->where('id', $id)->update('devices', array('allowed_ips' => $new, 'blocked_ip' => NULL, 'blocked_at' => NULL, 'updated_at' => now_local()));
            $this->audit('devices', $id, 'allow_ip', array('allowed_ips' => $old['allowed_ips']), array('allowed_ips' => $new));
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    // ------------------------------------------------------------------------------------------------ pending ADMS devices

    /** unknown serial numbers that called the server (newest first) */
    public function pending()
    {
        return $this->db->query('SELECT * FROM devices_pending ORDER BY last_seen_at DESC LIMIT 50')->result_array();
    }

    /** branches a pending device may be approved into: all active ones for the Admin, else the working branch */
    public function approvable_branches()
    {
        $out = array();
        if (current_user()['is_system']) {
            foreach ($this->db->query("SELECT id, name FROM branches WHERE status = 'Active' ORDER BY name")->result_array() as $b) {
                $out[(int) $b['id']] = $b['name'];
            }
        } else {
            $row = $this->db->query("SELECT id, name FROM branches WHERE id = ? AND status = 'Active'", array((int) current_branch_id()))->row_array();
            if ($row) {
                $out[(int) $row['id']] = $row['name'];
            }
        }
        return $out;
    }

    /** serial number -> a registered ADMS device in the chosen branch; the address it called from becomes its allowed address */
    public function approve_pending()
    {
        $v = new Input_validator($this->input);
        $sn = $v->text('serial_number', 40);
        $name = $v->text('name', 100);
        $branch = $v->int('branch_id', 1, NULL);
        $timezone = $this->read_timezone($v);
        $restrict = $v->flag('restrict_ip');
        if ($sn !== NULL && ! Adms_protocol::valid_serial($sn)) {
            $v->fail('serial_number', t('dev_serial_invalid'));
        }
        if ( ! $v->ok()) {
            return $v->message();
        }
        if ( ! isset($this->approvable_branches()[$branch])) {
            return t('dev_branch_invalid');
        }
        list($ok, $message) = Tx::run(function () use ($sn, $name, $branch, $timezone, $restrict, &$new_id) {
            $pending = $this->db->query('SELECT * FROM devices_pending WHERE serial_number = ? FOR UPDATE', array($sn))->row_array();
            if ( ! $pending) {
                return t('dev_pending_gone');
            }
            $data = array('name' => $name, 'serial_number' => $sn, 'comm_mode' => 'adms_push', 'timezone' => $timezone);
            if ($this->db->query('SELECT 1 FROM devices WHERE serial_number = ?', array($sn))->num_rows()) {
                return t('dev_serial_taken');
            }
            if ($this->db->query('SELECT 1 FROM devices WHERE branch_id = ? AND name = ?', array($branch, $name))->num_rows()) {
                return t('dev_name_taken');
            }
            $this->db->insert('devices', array_merge($data, array(
                'branch_id' => $branch, 'ip' => $pending['ip'], 'last_ip' => $pending['ip'], 'protocol' => 'TCP', 'status' => 'active',
                'allowed_ips' => $restrict ? $pending['ip'] : NULL, 'created_by' => current_user_id(), 'created_at' => now_local(),
            )));
            $new_id = (int) $this->db->insert_id();
            $this->db->query('DELETE FROM devices_pending WHERE serial_number = ?', array($sn));
            $this->audit('devices', $new_id, 'approve_pending', NULL, array_merge($data, array('branch_id' => $branch, 'allowed_ips' => $restrict ? $pending['ip'] : NULL)));
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    public function dismiss_pending()
    {
        $v = new Input_validator($this->input);
        $sn = $v->text('serial_number', 40);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $this->db->query('DELETE FROM devices_pending WHERE serial_number = ?', array($sn));
        return 'success';
    }

    // ------------------------------------------------------------------------------------------------ cron: device_health

    /**
     * Health check of the active devices of one branch (cron every 15 minutes): "possible lost punches" = the device has uploaded
     * nothing for `device_gap_hours` and a shift of an employee of the branch began in that silence. Stores the flag and the check time.
     * @return array device name => array(alert bool, stale bool, drift bool, silent_hours float)
     */
    public function check_health($branch_id)
    {
        $branch_id = (int) $branch_id;
        $gap = max(1, (int) hr_setting('device_gap_hours', 12));
        $now = time();
        $devices = $this->db->query("SELECT * FROM devices WHERE branch_id = ? AND status = 'active' ORDER BY id", array($branch_id))->result_array();
        $report = array();
        if ( ! $devices) {
            return $report;
        }
        $today = date('Y-m-d', $now);
        $employee_ids = array_column($this->db->query("SELECT id FROM employees WHERE branch_id = ? AND status IN ('Active','On Leave') AND join_date <= ?", array($branch_id, $today))->result_array(), 'id');
        $oldest = date('Y-m-d', $now - 3 * 86400);
        $plan = $employee_ids ? (new Shift_calculator())->plan($employee_ids, $oldest, $today) : array();
        foreach ($devices as $d) {
            $ref = $d['last_upload_at'] !== NULL ? strtotime($d['last_upload_at']) : strtotime($d['created_at']);
            $silent = ($now - $ref) / 3600;
            $alert = FALSE;
            if ($silent >= $gap) {
                foreach ($plan as $days) {
                    foreach ($days as $day => $info) {
                        if ($info['day_type'] === 'working' && $info['employed'] && $info['shift'] !== NULL) {
                            $start = strtotime($day . ' ' . $info['shift']['start_time']);
                            if ($start > $ref && $start <= $now) {
                                $alert = TRUE;
                                break 2;
                            }
                        }
                    }
                }
            }
            $this->db->where('id', (int) $d['id'])->update('devices', array('lost_punch_alert' => $alert ? 1 : 0, 'health_checked_at' => date('Y-m-d H:i:s', $now)));
            $h = self::health($d, $now);
            $report[$d['name']] = array('alert' => $alert, 'stale' => $h['color'] === 'red', 'drift' => $h['drift'], 'silent_hours' => round($silent, 1));
        }
        return $report;
    }

    // ------------------------------------------------------------------------------------------------ internals

    protected function lock($id)
    {
        $row = $this->db->query('SELECT * FROM devices WHERE id = ? AND branch_id = ? FOR UPDATE', array((int) $id, (int) current_branch_id()))->row_array();
        return $row ? $row : NULL;
    }

    protected function read_timezone(Input_validator $v)
    {
        $tz = $v->text('timezone', 64, FALSE);
        if ($tz !== NULL && $tz !== '' && ! in_array($tz, timezone_identifiers_list(), TRUE)) {
            $v->fail('timezone', t('dev_timezone_invalid'));
        }
        return ($tz === NULL || $tz === '') ? NULL : $tz;
    }

    /** @return array|string column => value, or the validation message */
    protected function read_form($id)
    {
        $v = new Input_validator($this->input);
        $name = $v->text('name', 100);
        $mode = $v->enum('comm_mode', self::comm_modes());
        $serial = $v->text('serial_number', 40, FALSE);
        $ip = $v->text('ip', 45, FALSE);
        $port = $v->int('port', 1, 65535, FALSE);
        $protocol = $v->enum('protocol', array('UDP', 'TCP'), FALSE);
        $status = $v->enum('status', array('active', 'disabled'));
        $allowed = $v->text('allowed_ips', 255, FALSE);
        $timezone = $this->read_timezone($v);
        $serial = ($serial === NULL || $serial === '') ? NULL : $serial;
        if ($mode === 'adms_push' && $serial === NULL) {
            $v->fail('serial_number', t('dev_serial_required'));
        }
        if ($serial !== NULL && ! Adms_protocol::valid_serial($serial)) {
            $v->fail('serial_number', t('dev_serial_invalid'));
        }
        if ($ip !== NULL && $ip !== '' && ! filter_var($ip, FILTER_VALIDATE_IP)) {
            $v->fail('ip', t('dev_ip_invalid'));
        }
        $allowed = ($allowed === NULL || trim($allowed) === '') ? NULL : implode(',', array_filter(array_map('trim', explode(',', $allowed)), 'strlen'));
        if ($allowed !== NULL) {
            foreach (explode(',', $allowed) as $entry) {
                $plain = preg_replace('#/\d{1,2}$#', '', $entry);
                if ( ! filter_var($plain, FILTER_VALIDATE_IP) || ($plain !== $entry && ! filter_var($plain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))) {
                    $v->fail('allowed_ips', t('dev_allowed_invalid'));
                    break;
                }
            }
        }
        if ( ! $v->ok()) {
            return $v->message();
        }
        return array('name' => $name, 'comm_mode' => $mode, 'serial_number' => $serial, 'ip' => ($ip === NULL || $ip === '') ? NULL : $ip,
            'port' => $port !== NULL ? $port : 4370, 'protocol' => $protocol !== NULL ? $protocol : 'TCP', 'status' => $status,
            'allowed_ips' => $allowed, 'timezone' => $timezone);
    }

    /** NULL, or the message when the name / serial number is already used */
    protected function unique_error(array $data, $except_id)
    {
        if ($this->db->query('SELECT 1 FROM devices WHERE branch_id = ? AND name = ? AND id <> ?', array((int) current_branch_id(), $data['name'], (int) $except_id))->num_rows()) {
            return t('dev_name_taken');
        }
        if ($data['serial_number'] !== NULL && $this->db->query('SELECT 1 FROM devices WHERE serial_number = ? AND id <> ?', array($data['serial_number'], (int) $except_id))->num_rows()) {
            return t('dev_serial_taken');
        }
        return NULL;
    }
}
