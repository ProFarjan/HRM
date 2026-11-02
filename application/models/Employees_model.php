<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Employees (separate from logins), device PIN + PIN history (P5), exit / re-hire, documents, photo, login link.
 *
 * Sensitive data (G6): bank_account_number, national_id and tin are masked to the last 4 characters unless the user holds
 * employees_sensitive; without it a save keeps the stored values (the masked text is never written back).
 * PIN rules: digits only, unique among current employees of the branch; a PIN that moves to the history keeps its
 * validity dates so late punches of a leaver still find their owner, and it may not be reused for dates its old owner
 * still covers.
 */
class Employees_model extends MY_Model
{
    const PHOTO_DIR = 'uploads/employees/photos/';
    const DOC_DIR = 'uploads/employees/docs/';
    const ACTIVE_STATUSES = 'Active,On Leave';

    public static function employment_types()
    {
        return array('Permanent', 'Contract', 'Temporary', 'Part-time', 'Probation');
    }

    public static function blood_groups()
    {
        return array('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-');
    }

    public static function sensitive_fields()
    {
        return array('bank_account_number', 'national_id', 'tin');
    }

    /** "******1234" - only the last 4 characters stay readable */
    public static function mask($value)
    {
        $value = (string) $value;
        $len = mb_strlen($value, 'UTF-8');
        if ($len === 0) {
            return '';
        }
        $keep = min(4, $len);
        return str_repeat('*', max(0, $len - $keep)) . mb_substr($value, $len - $keep, NULL, 'UTF-8');
    }

    /** the row with sensitive fields masked when the user lacks employees_sensitive */
    public function present(array $row)
    {
        if ( ! has_permission('employees_sensitive')) {
            foreach (self::sensitive_fields() as $f) {
                if (array_key_exists($f, $row)) {
                    $row[$f] = self::mask($row[$f]);
                }
            }
        }
        return $row;
    }

    // ------------------------------------------------------------------------------------------------ reads

    public function list_page()
    {
        $dt = new Datatable_request($this->input, array('e.employee_code', 'e.first_name', 'd.department_name', 'g.designation_name', 'e.employment_type', 'e.join_date', 'e.status', 'e.device_pin'));
        $v = new Input_validator($this->input);
        $department = $v->int('department_id', 1, NULL, FALSE);
        $designation = $v->int('designation_id', 1, NULL, FALSE);
        $status = $v->enum('status', array('Active', 'On Leave', 'Resigned', 'Terminated', 'current'), FALSE);
        $type = $v->enum('employment_type', self::employment_types(), FALSE);

        $this->db->from('employees e');
        $this->scope_branch('e');
        $total = $this->db->count_all_results();

        $filter = function () use ($dt, $department, $designation, $status, $type) {
            $this->db->from('employees e')
                ->join('departments d', 'd.id = e.department_id', 'left')
                ->join('designations g', 'g.id = e.designation_id', 'left');
            $this->scope_branch('e');
            if ($department !== NULL) {
                $this->db->where('e.department_id', $department);
            }
            if ($designation !== NULL) {
                $this->db->where('e.designation_id', $designation);
            }
            if ($status === 'current') {
                $this->db->where_in('e.status', explode(',', self::ACTIVE_STATUSES));
            } elseif ($status !== NULL) {
                $this->db->where('e.status', $status);
            }
            if ($type !== NULL) {
                $this->db->where('e.employment_type', $type);
            }
            if ($dt->search !== '') {
                $this->db->group_start()->like('e.employee_code', $dt->search)->or_like('e.first_name', $dt->search)
                    ->or_like('e.last_name', $dt->search)->or_like('e.mobile', $dt->search)->or_where('e.device_pin', $dt->search)->group_end();
            }
        };
        $filter();
        $filtered = $this->db->count_all_results();

        $filter();
        $this->db->select('e.id, e.employee_code, e.first_name, e.last_name, e.employment_type, e.join_date, e.status, e.device_pin, e.mobile, d.department_name, g.designation_name');
        $dt->apply($this->db);
        $labels = array('Active' => 'success', 'On Leave' => 'info', 'Resigned' => 'default', 'Terminated' => 'danger');
        $rows = array();
        foreach ($this->db->get()->result_array() as $r) {
            $rows[] = array(
                'id'          => (int) $r['id'],
                'code'        => e($r['employee_code']),
                'name'        => e(trim($r['first_name'] . ' ' . $r['last_name'])),
                'department'  => e((string) $r['department_name']),
                'designation' => e((string) $r['designation_name']),
                'type'        => e($r['employment_type']),
                'join_date'   => e(show_date($r['join_date'])),
                'status'      => '<span class="label label-' . $labels[$r['status']] . '">' . e(t('emp_status_' . strtolower(str_replace(' ', '_', $r['status'])))) . '</span>',
                'pin'         => e((string) $r['device_pin']),
            );
        }
        return $dt->response($total, $filtered, $rows);
    }

    /** raw row of the current branch, or NULL */
    public function get($id)
    {
        $this->db->from('employees')->where('id', (int) $id);
        $this->scope_branch();
        $row = $this->db->get()->row_array();
        return $row ? $row : NULL;
    }

    /** row for the profile page: names joined, sensitive fields masked as permitted */
    public function profile($id)
    {
        $this->db->select("e.*, d.department_name, g.designation_name, CONCAT(m.first_name, ' ', COALESCE(m.last_name, '')) AS manager_name, u.username", FALSE)
            ->from('employees e')
            ->join('departments d', 'd.id = e.department_id', 'left')
            ->join('designations g', 'g.id = e.designation_id', 'left')
            ->join('employees m', 'm.id = e.reporting_manager_id', 'left')
            ->join('users u', 'u.id = e.user_id', 'left')
            ->where('e.id', (int) $id);
        $this->scope_branch('e');
        $row = $this->db->get()->row_array();
        return $row ? $this->present($row) : NULL;
    }

    /** id => "CODE - Name" of current (Active / On Leave) employees of the branch */
    public function active_list($except_id = NULL)
    {
        $this->db->select('id, employee_code, first_name, last_name')->from('employees')
            ->where('branch_id', (int) current_branch_id())->where_in('status', explode(',', self::ACTIVE_STATUSES))->order_by('first_name');
        if ($except_id !== NULL) {
            $this->db->where('id <>', (int) $except_id);
        }
        $out = array();
        foreach ($this->db->get()->result_array() as $r) {
            $out[(int) $r['id']] = $r['employee_code'] . ' - ' . trim($r['first_name'] . ' ' . $r['last_name']);
        }
        return $out;
    }

    public function pin_history($id)
    {
        return $this->db->query('SELECT device_pin, valid_from, valid_to FROM employee_pin_history WHERE employee_id = ? ORDER BY valid_from DESC', array((int) $id))->result_array();
    }

    /**
     * Who owned $pin in $branch_id on $date (Y-m-d)? The current holder (from device_pin_from on), else the history row
     * covering the date. NULL = unmatched. Used by the punch ingestor (step 4/5) - P5.
     */
    public function pin_owner($branch_id, $pin, $date)
    {
        $row = $this->db->query(
            'SELECT id FROM employees WHERE branch_id = ? AND device_pin = ? AND (device_pin_from IS NULL OR device_pin_from <= ?)',
            array((int) $branch_id, (string) $pin, $date)
        )->row_array();
        if ($row) {
            return (int) $row['id'];
        }
        $row = $this->db->query(
            'SELECT employee_id FROM employee_pin_history WHERE branch_id = ? AND device_pin = ? AND valid_from <= ? AND valid_to >= ? ORDER BY valid_from DESC LIMIT 1',
            array((int) $branch_id, (string) $pin, $date, $date)
        )->row_array();
        return $row ? (int) $row['employee_id'] : NULL;
    }

    /** unlinked active logins of the current branch: id => username */
    public function linkable_users()
    {
        $out = array();
        foreach ($this->db->query("SELECT id, username FROM users WHERE branch_id = ? AND employee_id IS NULL AND status = 'Active' ORDER BY username", array((int) current_branch_id()))->result_array() as $r) {
            $out[(int) $r['id']] = $r['username'];
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------------ create / update / delete

    public function create()
    {
        $v = new Input_validator($this->input);
        $data = $this->read_form($v, NULL);
        $pin = $this->read_pin($v, 'device_pin');
        $photo = $v->image('photo', 1024);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $error = $this->check_rules($data, NULL);
        if ($error !== NULL) {
            return $error;
        }
        $photo_name = $photo ? $this->store_upload($photo['tmp'], self::PHOTO_DIR, $photo['ext']) : NULL;
        if ($photo && ! $photo_name) {
            return t('upload_failed');
        }
        $new_id = 0;
        list($ok, $message) = Tx::run(function () use ($data, $pin, $photo_name, &$new_id) {
            if ($data['employee_code'] === '') {
                $data['employee_code'] = next_code('employee');
            }
            if ($this->code_taken($data['employee_code'], 0)) {
                return t('emp_code_taken');
            }
            if ($pin !== NULL) {
                $conflict = $this->pin_conflict($pin, $data['join_date'], 0);
                if ($conflict !== NULL) {
                    return $conflict;
                }
                $data['device_pin'] = $pin;
                $data['device_pin_from'] = $data['join_date'];
            }
            $data['photo'] = $photo_name;
            $data['branch_id'] = (int) current_branch_id();
            $data['status'] = 'Active';
            $data['created_by'] = current_user_id();
            $data['created_at'] = now_local();
            $this->db->insert('employees', $data);
            $new_id = (int) $this->db->insert_id();
            $this->audit('employees', $new_id, 'create', NULL, $this->audit_safe($data));
            return TRUE;
        });
        if ( ! $ok && $photo_name) {
            @unlink(FCPATH . self::PHOTO_DIR . $photo_name);
        }
        return $ok ? 'success<<<###>>>' . $new_id : $message;
    }

    public function update($id)
    {
        $id = (int) $id;
        $v = new Input_validator($this->input);
        $data = $this->read_form($v, $id);
        $status = $v->enum('status', explode(',', self::ACTIVE_STATUSES), FALSE);
        $photo = $v->image('photo', 1024);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $error = $this->check_rules($data, $id);
        if ($error !== NULL) {
            return $error;
        }
        $photo_name = $photo ? $this->store_upload($photo['tmp'], self::PHOTO_DIR, $photo['ext']) : NULL;
        if ($photo && ! $photo_name) {
            return t('upload_failed');
        }
        $old_photo = NULL;
        list($ok, $message) = Tx::run(function () use ($id, $data, $status, $photo_name, &$old_photo) {
            $old = $this->lock($id);
            if ( ! $old) {
                return t('not_found');
            }
            if ($data['employee_code'] === '') {
                $data['employee_code'] = $old['employee_code'];
            }
            if ($this->code_taken($data['employee_code'], $id)) {
                return t('emp_code_taken');
            }
            if ( ! has_permission('employees_sensitive')) {
                foreach (self::sensitive_fields() as $f) {
                    unset($data[$f]);                                 // keep the stored values
                }
            }
            if ($status !== NULL) {
                if ( ! in_array($old['status'], explode(',', self::ACTIVE_STATUSES), TRUE)) {
                    return t('emp_exited_status');
                }
                $data['status'] = $status;
            }
            if ($old['exit_date'] !== NULL && $data['join_date'] > $old['exit_date']) {
                return t('emp_join_after_exit');
            }
            if ($photo_name) {
                $data['photo'] = $photo_name;
                $old_photo = $old['photo'];
            }
            $changes = array();
            foreach ($data as $k => $val) {
                if ((string) $old[$k] !== (string) $val || (($old[$k] === NULL) !== ($val === NULL))) {
                    $changes[$k] = $val;
                }
            }
            if ( ! $changes) {
                return TRUE;
            }
            $this->db->where('id', $id)->update('employees', array_merge($changes, array('updated_at' => now_local())));
            $this->audit('employees', $id, 'update', $this->audit_safe(array_intersect_key($old, $changes)), $this->audit_safe($changes));
            return TRUE;
        });
        if ( ! $ok && $photo_name) {
            @unlink(FCPATH . self::PHOTO_DIR . $photo_name);
        }
        if ($ok && $old_photo) {
            @unlink(FCPATH . self::PHOTO_DIR . basename($old_photo));
        }
        return $ok ? 'success' : $message;
    }

    /** only an employee without other records (salary, PIN history, login, subordinates, ...); documents go with it */
    public function delete($id)
    {
        $id = (int) $id;
        $files = array();
        list($ok, $message) = Tx::run(function () use ($id, &$files) {
            $old = $this->lock($id);
            if ( ! $old) {
                return t('not_found');
            }
            if ($old['user_id'] !== NULL) {
                return t('emp_delete_has_login');
            }
            foreach (array(
                'SELECT 1 FROM salary_structures WHERE employee_id = ? LIMIT 1',
                'SELECT 1 FROM employee_pin_history WHERE employee_id = ? LIMIT 1',
                'SELECT 1 FROM employees WHERE reporting_manager_id = ? LIMIT 1',
                'SELECT 1 FROM departments WHERE head_employee_id = ? LIMIT 1',
            ) as $sql) {
                if ($this->db->query($sql, array($id))->num_rows() > 0) {
                    return t('emp_delete_has_records');
                }
            }
            foreach ($this->db->query('SELECT file FROM employee_documents WHERE employee_id = ?', array($id))->result_array() as $d) {
                $files[] = FCPATH . self::DOC_DIR . $d['file'];
            }
            if ($old['photo']) {
                $files[] = FCPATH . self::PHOTO_DIR . $old['photo'];
            }
            $this->db->where('id', $id)->delete('employees');
            if ($this->db->affected_rows() !== 1) {
                return t('emp_delete_has_records');
            }
            $this->audit('employees', $id, 'delete', $this->audit_safe($old), NULL);
            return TRUE;
        });
        if ($ok) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
        return $ok ? 'success' : ($message === 'A database error occurred. Nothing was saved.' ? t('emp_delete_has_records') : $message);
    }

    // ------------------------------------------------------------------------------------------------ exit / re-hire

    /** exit date + reason + Resigned/Terminated; the PIN moves to the history; the linked login is deactivated */
    public function record_exit($id)
    {
        $v = new Input_validator($this->input);
        $exit_date = $v->date('exit_date');
        $status = $v->enum('exit_status', array('Resigned', 'Terminated'));
        $reason = $v->text('exit_reason', 255);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id, $exit_date, $status, $reason) {
            $old = $this->lock($id);
            if ( ! $old) {
                return t('not_found');
            }
            if ( ! in_array($old['status'], explode(',', self::ACTIVE_STATUSES), TRUE)) {
                return t('emp_already_exited');
            }
            if ($exit_date < $old['join_date']) {
                return t('emp_exit_before_join');
            }
            if ($old['device_pin_from'] !== NULL && $exit_date < $old['device_pin_from']) {
                return t('emp_exit_before_pin');
            }
            if ($old['user_id'] !== NULL) {
                $error = $this->deactivate_login((int) $old['user_id']);
                if ($error !== NULL) {
                    return $error;
                }
            }
            if ($old['device_pin'] !== NULL) {
                $this->to_history($old, $exit_date);
            }
            $changes = array('status' => $status, 'exit_date' => $exit_date, 'exit_reason' => $reason, 'device_pin' => NULL, 'device_pin_from' => NULL, 'updated_at' => now_local());
            $this->db->where('id', $id)->update('employees', $changes);
            unset($changes['updated_at']);
            $this->audit('employees', $id, 'exit', array('status' => $old['status'], 'device_pin' => $old['device_pin']), $changes);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    /** a leaver comes back: new join date (after the exit), exit cleared, Active. The login stays inactive until HR reactivates it. */
    public function rehire($id)
    {
        $v = new Input_validator($this->input);
        $join = $v->date('rehire_date');
        if ( ! $v->ok()) {
            return $v->message();
        }
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id, $join) {
            $old = $this->lock($id);
            if ( ! $old) {
                return t('not_found');
            }
            if (in_array($old['status'], explode(',', self::ACTIVE_STATUSES), TRUE)) {
                return t('emp_not_exited');
            }
            if ($join <= $old['exit_date']) {
                return t('emp_rehire_after_exit');
            }
            $changes = array('status' => 'Active', 'join_date' => $join, 'confirmation_date' => NULL, 'exit_date' => NULL, 'exit_reason' => NULL);
            $this->db->where('id', $id)->update('employees', array_merge($changes, array('updated_at' => now_local())));
            $this->audit('employees', $id, 'rehire', array_intersect_key($old, $changes), $changes);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    // ------------------------------------------------------------------------------------------------ device PIN

    /**
     * New PIN (or empty = none) from `effective_from` (default today). The old PIN goes to the history with
     * valid_to = effective_from - 1 day; a change effective on or before the old PIN's own start is a correction (no history).
     */
    public function change_pin($id)
    {
        $v = new Input_validator($this->input);
        $pin = $this->read_pin($v, 'new_pin');
        $from = $v->date('effective_from', FALSE);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $from = $from !== NULL ? $from : today_local();
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id, $pin, $from) {
            $old = $this->lock($id);
            if ( ! $old) {
                return t('not_found');
            }
            if ( ! in_array($old['status'], explode(',', self::ACTIVE_STATUSES), TRUE)) {
                return t('emp_exited_pin');
            }
            if ($pin === $old['device_pin']) {
                return TRUE;
            }
            if ($from < $old['join_date']) {
                return t('emp_pin_before_join');
            }
            if ($pin !== NULL) {
                $conflict = $this->pin_conflict($pin, $from, $id);
                if ($conflict !== NULL) {
                    return $conflict;
                }
            }
            $corrected = $old['device_pin'] !== NULL && $old['device_pin_from'] !== NULL && $from <= $old['device_pin_from'];
            if ($old['device_pin'] !== NULL && ! $corrected) {
                $this->to_history($old, date('Y-m-d', strtotime($from . ' -1 day')));
            }
            $this->db->where('id', $id)->update('employees', array('device_pin' => $pin, 'device_pin_from' => $pin === NULL ? NULL : $from, 'updated_at' => now_local()));
            $this->audit('employees', $id, 'pin_change', array('device_pin' => $old['device_pin'], 'device_pin_from' => $old['device_pin_from']),
                array('device_pin' => $pin, 'effective_from' => $from, 'correction' => $corrected));
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    // ------------------------------------------------------------------------------------------------ login link

    /** link an unlinked active login of the branch to this employee (both sides: users.employee_id and employees.user_id) */
    public function link_login($id)
    {
        $v = new Input_validator($this->input);
        $user_id = $v->ref('user_id', 'users');
        if ( ! $v->ok()) {
            return $v->message();
        }
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id, $user_id) {
            $emp = $this->lock($id);
            if ( ! $emp) {
                return t('not_found');
            }
            if ($emp['user_id'] !== NULL) {
                return t('emp_already_linked');
            }
            $user = $this->db->query('SELECT id, employee_id, status FROM users WHERE id = ? FOR UPDATE', array($user_id))->row_array();
            if ( ! $user || $user['employee_id'] !== NULL) {
                return t('emp_login_taken');
            }
            $this->load->model('Users_model');
            $allowed = $this->Users_model->check_manageable($user_id);
            if ($allowed !== TRUE) {
                return $allowed;
            }
            $this->db->where('id', $user_id)->update('users', array('employee_id' => $id, 'updated_at' => now_local()));
            $this->db->where('id', $id)->update('employees', array('user_id' => $user_id, 'updated_at' => now_local()));
            $this->audit('employees', $id, 'link_login', NULL, array('user_id' => $user_id));
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    public function unlink_login($id)
    {
        $id = (int) $id;
        list($ok, $message) = Tx::run(function () use ($id) {
            $emp = $this->lock($id);
            if ( ! $emp) {
                return t('not_found');
            }
            if ($emp['user_id'] === NULL) {
                return TRUE;
            }
            $this->load->model('Users_model');
            $allowed = $this->Users_model->check_manageable((int) $emp['user_id']);
            if ($allowed !== TRUE) {
                return $allowed;
            }
            $this->db->where('id', (int) $emp['user_id'])->update('users', array('employee_id' => NULL, 'updated_at' => now_local()));
            $this->db->where('id', $id)->update('employees', array('user_id' => NULL, 'updated_at' => now_local()));
            $this->audit('employees', $id, 'unlink_login', array('user_id' => (int) $emp['user_id']), NULL);
            return TRUE;
        });
        return $ok ? 'success' : $message;
    }

    // ------------------------------------------------------------------------------------------------ documents and photo

    public function documents($id)
    {
        return $this->db->query('SELECT id, title, original_name, expires_on, created_at FROM employee_documents WHERE employee_id = ? ORDER BY created_at DESC', array((int) $id))->result_array();
    }

    /** a document of an employee of the current branch, or NULL */
    public function document($doc_id)
    {
        $row = $this->db->query(
            'SELECT d.*, e.employee_code FROM employee_documents d JOIN employees e ON e.id = d.employee_id WHERE d.id = ? AND e.branch_id = ?',
            array((int) $doc_id, (int) current_branch_id())
        )->row_array();
        return $row ? $row : NULL;
    }

    public function add_document($id)
    {
        $v = new Input_validator($this->input);
        $title = $v->text('title', 150);
        $expires = $v->date('expires_on', FALSE);
        $file = $v->document('document', 2048);
        if ( ! $v->ok()) {
            return $v->message();
        }
        $name = $this->store_upload($file['tmp'], self::DOC_DIR, $file['ext']);
        if ( ! $name) {
            return t('upload_failed');
        }
        $id = (int) $id;
        $new_id = 0;
        list($ok, $message) = Tx::run(function () use ($id, $title, $expires, $file, $name, &$new_id) {
            if ( ! $this->lock($id)) {
                return t('not_found');
            }
            $this->db->insert('employee_documents', array('employee_id' => $id, 'title' => $title, 'file' => $name, 'original_name' => $file['name'],
                'expires_on' => $expires, 'created_by' => current_user_id(), 'created_at' => now_local()));
            $new_id = (int) $this->db->insert_id();
            $this->audit('employees', $id, 'document_add', NULL, array('document_id' => $new_id, 'title' => $title));
            return TRUE;
        });
        if ( ! $ok) {
            @unlink(FCPATH . self::DOC_DIR . $name);
        }
        return $ok ? 'success<<<###>>>' . $new_id : $message;
    }

    public function delete_document($doc_id)
    {
        $doc = $this->document($doc_id);
        if ( ! $doc) {
            return t('not_found');
        }
        list($ok, $message) = Tx::run(function () use ($doc) {
            $this->db->where('id', (int) $doc['id'])->delete('employee_documents');
            $this->audit('employees', (int) $doc['employee_id'], 'document_delete', array('document_id' => (int) $doc['id'], 'title' => $doc['title']), NULL);
            return TRUE;
        });
        if ($ok) {
            @unlink(FCPATH . self::DOC_DIR . basename($doc['file']));
        }
        return $ok ? 'success' : $message;
    }

    /** absolute path of a stored file name, only for names we generate */
    public static function stored_path($dir, $name)
    {
        $name = basename((string) $name);
        if ( ! preg_match('/^[a-f0-9]{32}\.(png|jpg|pdf)$/', $name)) {
            return NULL;
        }
        $path = FCPATH . $dir . $name;
        return is_file($path) ? $path : NULL;
    }

    // ------------------------------------------------------------------------------------------------ internals

    protected function lock($id)
    {
        $row = $this->db->query('SELECT * FROM employees WHERE id = ? AND branch_id = ? FOR UPDATE', array((int) $id, (int) current_branch_id()))->row_array();
        return $row ? $row : NULL;
    }

    protected function code_taken($code, $except_id)
    {
        return $this->db->query('SELECT 1 FROM employees WHERE branch_id = ? AND employee_code = ? AND id <> ?', array((int) current_branch_id(), $code, (int) $except_id))->num_rows() > 0;
    }

    /** NULL when $pin may start on $from for employee $except_id, else the reason */
    protected function pin_conflict($pin, $from, $except_id)
    {
        $branch = (int) current_branch_id();
        $holder = $this->db->query('SELECT employee_code FROM employees WHERE branch_id = ? AND device_pin = ? AND id <> ? FOR UPDATE', array($branch, $pin, (int) $except_id))->row_array();
        if ($holder) {
            return sprintf(t('emp_pin_taken'), $holder['employee_code']);
        }
        $past = $this->db->query(
            'SELECT h.valid_to, e.employee_code FROM employee_pin_history h JOIN employees e ON e.id = h.employee_id
             WHERE h.branch_id = ? AND h.device_pin = ? AND h.valid_to >= ? AND h.employee_id <> ? ORDER BY h.valid_to DESC LIMIT 1',
            array($branch, $pin, $from, (int) $except_id)
        )->row_array();
        if ($past) {
            return sprintf(t('emp_pin_history_overlap'), $past['employee_code'], show_date($past['valid_to']));
        }
        return NULL;
    }

    protected function to_history(array $employee, $valid_to)
    {
        $valid_from = $employee['device_pin_from'] !== NULL ? $employee['device_pin_from'] : $employee['join_date'];
        if ($valid_to < $valid_from) {
            $valid_to = $valid_from;
        }
        $this->db->insert('employee_pin_history', array(
            'branch_id' => (int) $employee['branch_id'], 'employee_id' => (int) $employee['id'], 'device_pin' => $employee['device_pin'],
            'valid_from' => $valid_from, 'valid_to' => $valid_to, 'created_at' => now_local(),
        ));
    }

    /** NULL or an error: the employee's login is deactivated on exit (never your own; never the last Admin) */
    protected function deactivate_login($user_id)
    {
        if ($user_id === current_user_id()) {
            return t('users_not_yourself');
        }
        $user = $this->db->query('SELECT u.status, r.is_system FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? FOR UPDATE', array($user_id))->row_array();
        if ( ! $user || $user['status'] !== 'Active') {
            return NULL;
        }
        if ((int) $user['is_system'] && $this->db->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_system = 1 AND u.status = 'Active' AND u.id <> ? FOR UPDATE", array($user_id))->num_rows() === 0) {
            return t('users_last_admin');
        }
        $this->db->where('id', $user_id)->update('users', array('status' => 'Inactive', 'updated_at' => now_local()));
        $this->audit('users', $user_id, 'deactivate', array('status' => 'Active'), array('status' => 'Inactive', 'reason' => 'employee exit'));
        return NULL;
    }

    /** the posted PIN (digits, 1-9) or NULL when empty; records an error when invalid */
    protected function read_pin(Input_validator $v, $field)
    {
        $pin = $v->text($field, 9, FALSE);
        if ($pin !== NULL && ! preg_match('/^[0-9]{1,9}$/', $pin)) {
            $v->fail($field, t('emp_pin_rule'));
            return NULL;
        }
        return $pin;
    }

    /** the employee form (personal, job, bank) */
    protected function read_form(Input_validator $v, $id)
    {
        $data = array(
            'employee_code'            => (string) $v->text('employee_code', 30, FALSE),
            'first_name'               => $v->text('first_name', 80),
            'last_name'                => $v->text('last_name', 80, FALSE),
            'gender'                   => $v->enum('gender', array('Male', 'Female', 'Other'), FALSE),
            'dob'                      => $v->date('dob', FALSE),
            'mobile'                   => $v->text('mobile', 20, FALSE),
            'email'                    => $v->email('email', 120, FALSE),
            'address'                  => $v->text('address', 255, FALSE),
            'blood_group'              => $v->enum('blood_group', self::blood_groups(), FALSE),
            'emergency_contact_name'   => $v->text('emergency_contact_name', 120, FALSE),
            'emergency_contact_number' => $v->text('emergency_contact_number', 20, FALSE),
            'department_id'            => $v->ref('department_id', 'departments', FALSE),
            'designation_id'           => $v->ref('designation_id', 'designations', FALSE),
            'reporting_manager_id'     => $v->ref('reporting_manager_id', 'employees', FALSE),
            'employment_type'          => $v->enum('employment_type', self::employment_types()),
            'join_date'                => $v->date('join_date'),
            'confirmation_date'        => $v->date('confirmation_date', FALSE),
            'bank_name'                => $v->text('bank_name', 100, FALSE),
            'bank_account_number'      => $v->text('bank_account_number', 40, FALSE),
            'bank_branch'              => $v->text('bank_branch', 100, FALSE),
            'national_id'              => $v->text('national_id', 30, FALSE),
            'tin'                      => $v->text('tin', 30, FALSE),
        );
        foreach (array('mobile', 'emergency_contact_number') as $f) {
            if ($data[$f] !== NULL && ! preg_match('/^\+?[0-9 ()-]{3,20}$/', $data[$f])) {
                $v->fail($f, sprintf(t('v_phone'), $f === 'mobile' ? 'Mobile' : 'Emergency contact number'));
            }
        }
        if ($data['employee_code'] !== '' && ! preg_match('#^[A-Za-z0-9._/-]{1,30}$#', $data['employee_code'])) {
            $v->fail('employee_code', t('emp_code_rule'));
        }
        return $data;
    }

    /** cross-field rules; NULL when fine */
    protected function check_rules(array $data, $id)
    {
        if ($data['dob'] !== NULL && $data['dob'] >= $data['join_date']) {
            return t('emp_dob_after_join');
        }
        if ($data['confirmation_date'] !== NULL && $data['confirmation_date'] < $data['join_date']) {
            return t('emp_confirm_before_join');
        }
        if ($data['reporting_manager_id'] !== NULL) {
            if ($id !== NULL && $data['reporting_manager_id'] === (int) $id) {
                return t('emp_manager_self');
            }
            // walking up from the new manager must never reach this employee (no reporting cycles)
            $seen = array();
            $cursor = $data['reporting_manager_id'];
            while ($cursor !== NULL && ! isset($seen[$cursor]) && count($seen) < 100) {
                if ($id !== NULL && $cursor === (int) $id) {
                    return t('emp_manager_cycle');
                }
                $seen[$cursor] = TRUE;
                $row = $this->db->query('SELECT reporting_manager_id FROM employees WHERE id = ?', array($cursor))->row_array();
                $cursor = ($row && $row['reporting_manager_id'] !== NULL) ? (int) $row['reporting_manager_id'] : NULL;
            }
        }
        return NULL;
    }

    /** sensitive values never go into the audit log in clear */
    protected function audit_safe(array $values)
    {
        foreach (self::sensitive_fields() as $f) {
            if (array_key_exists($f, $values) && $values[$f] !== NULL) {
                $values[$f] = self::mask($values[$f]);
            }
        }
        return $values;
    }

    /** move an uploaded file to $dir with a random name; the name or NULL */
    protected function store_upload($tmp, $dir, $ext)
    {
        $full = FCPATH . $dir;
        if ( ! is_dir($full) && ! @mkdir($full, 0775, TRUE)) {
            return NULL;
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        return move_uploaded_file($tmp, $full . $name) ? $name : NULL;
    }
}
