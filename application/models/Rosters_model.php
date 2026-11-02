<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Day-level roster (rosters_manage). A cell is a shift (working day on that shift), OFF (weekly off for that employee) or
 * empty (no roster row: the normal assignment / weekly-off rules apply). Grid, save, copy week and xlsx/csv import all
 * check that every employee and shift belongs to the current branch, and write in one transaction.
 */
class Rosters_model extends MY_Model
{
    const MAX_DAYS = 62;
    const MAX_CELLS = 5000;
    const MAX_WRITE_DAYS = 366;

    /** employees (current, branch, optional department) x days with the roster cell and what applies (Shift_calculator::plan) */
    public function grid($department_id, $from, $to)
    {
        $this->db->select('e.id, e.employee_code, e.first_name, e.last_name')->from('employees e')
            ->where_in('e.status', array('Active', 'On Leave'))->order_by('e.employee_code');
        $this->scope_branch('e');
        if ($department_id !== NULL) {
            $this->db->where('e.department_id', (int) $department_id);
        }
        $employees = $this->db->limit(500)->get()->result_array();
        $ids = array_map('intval', array_column($employees, 'id'));
        $calc = new Shift_calculator();
        return array('employees' => $employees, 'plan' => $calc->plan($ids, $from, $to));
    }

    /** POST cells[] = "employee_id|Y-m-d|value" with value = shift id, "OFF" or "" (clear). "success<<<###>>>n" or errors */
    public function save()
    {
        $raw = $this->input->post('cells');
        if ( ! is_array($raw) || ! $raw) {
            return t('roster_nothing');
        }
        if (count($raw) > self::MAX_CELLS) {
            return sprintf(t('roster_too_many'), self::MAX_CELLS);
        }
        $cells = array();
        foreach ($raw as $cell) {
            if ( ! is_string($cell) || ! preg_match('/^(\d{1,10})\|(\d{4}-\d{2}-\d{2})\|(\d{1,10}|OFF|)$/', $cell, $m) || parse_strict_date($m[2], 'Y-m-d') === FALSE) {
                return t('roster_bad_cell');
            }
            $cells[] = array('employee_id' => (int) $m[1], 'date' => $m[2], 'value' => $m[3]);
        }
        return $this->write($cells, 'save');
    }

    /** copy the roster rows of 7 days starting `source_from` to the 7 days starting `target_from` (department or branch) */
    public function copy_week()
    {
        $v = new Input_validator($this->input);
        $source = $v->date('source_from');
        $target = $v->date('target_from');
        $department = $v->ref('department_id', 'departments', FALSE);
        $overwrite = $v->flag('overwrite');
        if ( ! $v->ok()) {
            return $v->message();
        }
        if ($source === $target) {
            return t('roster_copy_same');
        }
        $offset = (int) round((strtotime($target) - strtotime($source)) / 86400);
        $this->db->select('r.employee_id, r.roster_date, r.shift_id, r.is_working_day')->from('shift_rosters r')
            ->join('employees e', 'e.id = r.employee_id')->where_in('e.status', array('Active', 'On Leave'))
            ->where('r.roster_date >=', $source)->where('r.roster_date <=', date('Y-m-d', strtotime($source . ' +6 days')));
        $this->scope_branch('e');
        if ($department !== NULL) {
            $this->db->where('e.department_id', $department);
        }
        $cells = array();
        foreach ($this->db->get()->result_array() as $r) {
            $cells[] = array('employee_id' => (int) $r['employee_id'], 'date' => date('Y-m-d', strtotime($r['roster_date'] . ' ' . ($offset >= 0 ? '+' : '') . $offset . ' days')),
                'value' => (int) $r['is_working_day'] === 0 ? 'OFF' : (string) $r['shift_id'], 'keep_existing' => ! $overwrite);
        }
        if ( ! $cells) {
            return t('roster_copy_empty');
        }
        return $this->write($cells, 'copy_week');
    }

    /**
     * Import an .xlsx / .csv sheet with the columns employee_code, date (UI format, Y-m-d or an Excel date), shift_code | OFF |
     * empty (= clear), first row = header. Every row is checked first; with any error nothing is written and the first
     * errors are returned (row numbers as in the sheet).
     */
    public function import()
    {
        $v = new Input_validator($this->input);
        $file = $v->spreadsheet('roster_file', 2048);
        if ( ! $v->ok()) {
            return $v->message();
        }
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($file['type']);
            $reader->setReadDataOnly(TRUE);
            $sheet = $reader->load($file['tmp'])->getActiveSheet();
            $rows = $sheet->toArray(NULL, FALSE, FALSE, FALSE);
        } catch (Exception $e) {
            return t('roster_import_unreadable');
        }
        array_shift($rows);                                          // header
        if (count($rows) > self::MAX_CELLS) {
            return sprintf(t('roster_too_many'), self::MAX_CELLS);
        }
        $codes = array();
        foreach ($this->db->query("SELECT id, employee_code FROM employees WHERE branch_id = ? AND status IN ('Active','On Leave')", array((int) current_branch_id()))->result_array() as $e) {
            $codes[strtoupper($e['employee_code'])] = (int) $e['id'];
        }
        $shift_codes = array();
        foreach ($this->db->query("SELECT id, shift_code FROM shifts WHERE branch_id = ? AND status = 'Active'", array((int) current_branch_id()))->result_array() as $s) {
            $shift_codes[strtoupper($s['shift_code'])] = (int) $s['id'];
        }
        $cells = array();
        $errors = array();
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $code = strtoupper(trim((string) (isset($row[0]) ? $row[0] : '')));
            $date_raw = isset($row[1]) ? $row[1] : '';
            $value = strtoupper(trim((string) (isset($row[2]) ? $row[2] : '')));
            if ($code === '' && trim((string) $date_raw) === '' && $value === '') {
                continue;                                            // blank line
            }
            if ( ! isset($codes[$code])) {
                $errors[] = sprintf(t('roster_import_bad_employee'), $line, $code);
                continue;
            }
            if (is_numeric($date_raw) && (float) $date_raw > 20000) {
                $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $date_raw)->format('Y-m-d');
            } else {
                $date = to_db_date((string) $date_raw);
            }
            if ($date === FALSE) {
                $errors[] = sprintf(t('roster_import_bad_date'), $line, (string) $date_raw);
                continue;
            }
            if ($value !== '' && $value !== 'OFF' && ! isset($shift_codes[$value])) {
                $errors[] = sprintf(t('roster_import_bad_shift'), $line, $value);
                continue;
            }
            $cells[] = array('employee_id' => $codes[$code], 'date' => $date, 'value' => ($value === '' || $value === 'OFF') ? $value : (string) $shift_codes[$value]);
        }
        if ($errors) {
            return implode("\n", array_slice($errors, 0, 20)) . (count($errors) > 20 ? "\n" . sprintf(t('roster_import_more_errors'), count($errors) - 20) : '');
        }
        if ( ! $cells) {
            return t('roster_nothing');
        }
        return $this->write($cells, 'import');
    }

    /**
     * Validate branch membership of employees and shifts, then upsert / delete in one transaction.
     * @param array $cells employee_id, date, value ('' | 'OFF' | shift id) [, keep_existing]
     */
    protected function write(array $cells, $action)
    {
        $employee_ids = array_values(array_unique(array_column($cells, 'employee_id')));
        $dates = array_column($cells, 'date');
        if ((strtotime(max($dates)) - strtotime(min($dates))) / 86400 >= self::MAX_WRITE_DAYS) {
            return t('roster_range_too_long');
        }
        $this->db->select('id')->from('employees')->where_in('id', $employee_ids)->where_in('status', array('Active', 'On Leave'));
        $this->scope_branch();
        $valid = array_flip(array_map('intval', array_column($this->db->get()->result_array(), 'id')));
        foreach ($employee_ids as $id) {
            if ( ! isset($valid[$id])) {
                return sprintf(t('v_ref'), 'employee');
            }
        }
        $shift_ids = array();
        foreach ($cells as $c) {
            if ($c['value'] !== '' && $c['value'] !== 'OFF') {
                $shift_ids[(int) $c['value']] = TRUE;
            }
        }
        if ($shift_ids) {
            $this->db->select('id')->from('shifts')->where_in('id', array_keys($shift_ids))->where('status', 'Active');
            $this->scope_branch();
            if ($this->db->count_all_results() !== count($shift_ids)) {
                return sprintf(t('v_ref'), 'shift');
            }
        }
        $written = 0;
        list($ok, $message) = Tx::run(function () use ($cells, $action, &$written) {
            $now = now_local();
            foreach ($cells as $c) {
                if ($c['value'] === '') {
                    $this->db->where('employee_id', $c['employee_id'])->where('roster_date', $c['date'])->delete('shift_rosters');
                    $written += $this->db->affected_rows();
                    continue;
                }
                $off = $c['value'] === 'OFF';
                $params = array($c['employee_id'], $off ? NULL : (int) $c['value'], $c['date'], $off ? 0 : 1, current_user_id(), $now);
                if ( ! empty($c['keep_existing'])) {
                    $this->db->query('INSERT IGNORE INTO shift_rosters (employee_id, shift_id, roster_date, is_working_day, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)', $params);
                } else {
                    $params[] = $now;
                    $this->db->query('INSERT INTO shift_rosters (employee_id, shift_id, roster_date, is_working_day, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id), is_working_day = VALUES(is_working_day), updated_at = ?', $params);
                }
                $written += $this->db->affected_rows() > 0 ? 1 : 0;
            }
            $this->audit('shift_rosters', NULL, $action, NULL, array('cells' => count($cells), 'changed' => $written,
                'from' => min(array_column($cells, 'date')), 'to' => max(array_column($cells, 'date'))));
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $written : $message;
    }
}
