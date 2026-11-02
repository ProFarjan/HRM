<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** The command queue of the devices (list, queue by hand, enrol an employee) and the device call log. */
class Device_commands_model extends MY_Model
{
    const LOG_MAX_DAYS = 30;

    public static function type_labels()
    {
        $out = array();
        foreach (explode(',', Device_command_queue::ALL_TYPES) as $type) {
            $out[$type] = t('cmd_' . $type);
        }
        return $out;
    }

    public function commands_page()
    {
        $dt = new Datatable_request($this->input, array('c.id', 'd.name', 'c.command_type', NULL, 'c.status', 'c.created_at', 'c.done_at', NULL), 0, 'desc');
        $v = new Input_validator($this->input);
        $status = $v->enum('status', array('queued', 'sent', 'done', 'failed'), FALSE);
        $base = function () use ($dt, $status) {
            $this->db->from('device_commands c')->join('devices d', 'd.id = c.device_id');
            $this->scope_branch('d');
            if ($status !== NULL) {
                $this->db->where('c.status', $status);
            }
            if ($dt->search !== '') {
                $this->db->group_start()->like('d.name', $dt->search)->or_like('c.command_type', $dt->search)->group_end();
            }
        };
        $this->db->from('device_commands c')->join('devices d', 'd.id = c.device_id');
        $this->scope_branch('d');
        $total = $this->db->count_all_results();
        $base();
        $filtered = $this->db->count_all_results();
        $base();
        $this->db->select('c.*, d.name AS device_name');
        $dt->apply($this->db);
        $colors = array('queued' => 'default', 'sent' => 'info', 'done' => 'success', 'failed' => 'danger');
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $payload = json_decode((string) $r['payload'], TRUE);
            $result = json_decode((string) $r['result'], TRUE);
            $rows[] = array(
                'id'      => (int) $r['id'],
                'device'  => e($r['device_name']),
                'type'    => e(t('cmd_' . $r['command_type'])),
                'payload' => e(self::payload_summary(is_array($payload) ? $payload : array())),
                'status'  => '<span class="label label-' . $colors[$r['status']] . '">' . e(t('cmd_status_' . $r['status'])) . '</span>',
                'created' => e(show_date($r['created_at']) . ' ' . substr($r['created_at'], 11, 5)),
                'done'    => $r['done_at'] === NULL ? '' : e(show_date($r['done_at']) . ' ' . substr($r['done_at'], 11, 5)),
                'result'  => e(is_array($result) && isset($result['message']) ? (string) $result['message'] : ''),
            );
        }
        return $dt->response($total, $filtered, $rows);
    }

    /** a one-line description of a payload; the device password is never shown */
    public static function payload_summary(array $p)
    {
        $parts = array();
        if (isset($p['user_id'])) {
            $parts[] = 'PIN ' . $p['user_id'];
        }
        if (isset($p['name']) && $p['name'] !== '') {
            $parts[] = $p['name'];
        }
        if (isset($p['card_no']) && $p['card_no'] !== '') {
            $parts[] = 'card ' . $p['card_no'];
        }
        if (isset($p['datetime'])) {
            $parts[] = $p['datetime'];
        }
        return implode(' / ', $parts);
    }

    /**
     * Queue a command by hand (devices_commands). delete / create / update need an employee of the branch (the PIN and name come from
     * the employee, never from the form), the others only a device.
     */
    public function queue()
    {
        $v = new Input_validator($this->input);
        $device_id = $v->ref('device_id', 'devices');
        $type = $v->enum('command_type', explode(',', Device_command_queue::ALL_TYPES));
        $employee_id = in_array($type, array('create_user', 'update_user', 'delete_user'), TRUE) ? $v->ref('employee_id', 'employees') : NULL;
        if ( ! $v->ok()) {
            return $v->message();
        }
        $device = $this->db->query('SELECT * FROM devices WHERE id = ? AND branch_id = ?', array($device_id, (int) current_branch_id()))->row_array();
        $payload = array();
        if ($type === 'sync_time') {
            $payload = array('datetime' => now_local());
        }
        if ($employee_id !== NULL) {
            $employee = $this->db->query('SELECT first_name, last_name, device_pin FROM employees WHERE id = ? AND branch_id = ?', array($employee_id, (int) current_branch_id()))->row_array();
            if ( ! $employee || $employee['device_pin'] === NULL) {
                return t('dev_cmd_pin_required');
            }
            $payload = array('user_id' => $employee['device_pin'], 'name' => trim($employee['first_name'] . ' ' . $employee['last_name']));
        }
        $result = NULL;
        list($ok, $message) = Tx::run(function () use ($device, $type, $payload, &$result) {
            $result = Device_command_queue::enqueue($device, $type, $payload, current_user_id());
            if (is_string($result)) {
                return $result;
            }
            $this->audit('devices', (int) $device['id'], 'command', NULL, array('type' => $type, 'command_id' => $result));
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $result : $message;
    }

    /**
     * "Add employee -> enrol on device": queue create_user for the chosen devices (the employee's PIN, name, and the optional
     * device password / card number from the form).
     */
    public function enrol($employee_id)
    {
        $v = new Input_validator($this->input);
        $device_ids = $v->int_list('device_ids', 50);
        $password = $v->text('device_password', 8, FALSE);
        $card = $v->text('card_no', 12, FALSE);
        $admin = $v->flag('device_admin');
        if ($password !== NULL && $password !== '' && ! ctype_digit($password)) {
            $v->fail('device_password', t('dev_password_digits'));
        }
        if ($card !== NULL && $card !== '' && ! ctype_digit($card)) {
            $v->fail('card_no', t('dev_card_digits'));
        }
        if ( ! $v->ok()) {
            return $v->message();
        }
        $employee = $this->db->query('SELECT id, first_name, last_name, device_pin, status FROM employees WHERE id = ? AND branch_id = ?', array((int) $employee_id, (int) current_branch_id()))->row_array();
        if ( ! $employee) {
            return t('not_found');
        }
        if ($employee['device_pin'] === NULL) {
            return t('dev_cmd_pin_required');
        }
        $queued = 0;
        $errors = array();
        list($ok, $message) = Tx::run(function () use ($employee, $device_ids, $password, $card, $admin, &$queued, &$errors) {
            foreach ($device_ids as $id) {
                $device = $this->db->query('SELECT * FROM devices WHERE id = ? AND branch_id = ?', array($id, (int) current_branch_id()))->row_array();
                if ( ! $device) {
                    $errors[] = t('not_found');
                    continue;
                }
                $r = Device_command_queue::enqueue($device, 'create_user', array(
                    'user_id' => $employee['device_pin'], 'name' => trim($employee['first_name'] . ' ' . $employee['last_name']),
                    'password' => (string) $password, 'card_no' => (string) $card, 'role' => $admin ? 14 : 0,
                ), current_user_id());
                if (is_string($r)) {
                    $errors[] = $device['name'] . ': ' . $r;
                    continue;
                }
                $queued++;
            }
            if ($queued) {
                $this->audit('employees', (int) $employee['id'], 'enrol_device', NULL, array('devices' => array_values($device_ids), 'pin' => $employee['device_pin']));
            }
            return TRUE;
        });
        if ( ! $ok) {
            return $message;
        }
        if ($errors) {
            return $queued ? 'success<<<###>>>' . implode('; ', $errors) : implode('; ', $errors);
        }
        return 'success';
    }

    // ------------------------------------------------------------------------------------------------ call log

    public function logs_page()
    {
        $dt = new Datatable_request($this->input, array('l.created_at', 'd.name', 'l.direction', 'l.endpoint', 'l.http_code', 'l.records', 'l.ip', NULL), 0, 'desc');
        $v = new Input_validator($this->input);
        $from = $v->date('from_date', FALSE);
        $to = $v->date('to_date', FALSE);
        $device = $v->ref('device_id', 'devices', FALSE);
        $errors_only = $v->flag('errors_only');
        $to = $to !== NULL ? $to : today_local();
        $from = $from !== NULL ? $from : date('Y-m-d', strtotime($to . ' -2 days'));
        if ($from > $to) {
            $from = $to;
        }
        if (strtotime($to) - strtotime($from) > self::LOG_MAX_DAYS * 86400) {
            $from = date('Y-m-d', strtotime($to . ' -' . self::LOG_MAX_DAYS . ' days'));
        }
        $upper = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
        $base = function () use ($dt, $from, $upper, $device, $errors_only) {
            $this->db->from('device_logs l')->join('devices d', 'd.id = l.device_id', 'left');
            if (current_user()['is_system']) {
                $this->db->group_start()->where('l.branch_id', (int) current_branch_id())->or_where('l.branch_id IS NULL', NULL, FALSE)->group_end();
            } else {
                $this->scope_branch('l');
            }
            $this->db->where('l.created_at >=', $from . ' 00:00:00')->where('l.created_at <', $upper);
            if ($device !== NULL) {
                $this->db->where('l.device_id', $device);
            }
            if ($errors_only) {
                $this->db->where('l.http_code >=', 400);
            }
            if ($dt->search !== '') {
                $this->db->group_start()->like('l.message', $dt->search)->or_like('l.endpoint', $dt->search)->or_like('l.ip', $dt->search)->group_end();
            }
        };
        $base();
        $filtered = $this->db->count_all_results();
        $base();
        $this->db->select('l.*, d.name AS device_name');
        $dt->apply($this->db);
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array(
                'time'     => e(show_date($r['created_at']) . ' ' . substr($r['created_at'], 11, 8)),
                'device'   => e((string) $r['device_name']),
                'dir'      => e($r['direction']),
                'endpoint' => e($r['endpoint']),
                'code'     => '<span class="label label-' . ((int) $r['http_code'] >= 400 ? 'danger' : 'success') . '">' . (int) $r['http_code'] . '</span>',
                'records'  => (int) $r['records'],
                'ip'       => e((string) $r['ip']),
                'message'  => e((string) $r['message']),
            );
        }
        return $dt->response($filtered, $filtered, $rows);
    }
}
