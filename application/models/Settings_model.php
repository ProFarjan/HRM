<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * hr_settings row id 1 (Settings page). Every key is validated against its own rule; a save writes only the keys that
 * changed and audits them (old -> new). The logo is an uploaded PNG/JPEG stored under uploads/settings with a random
 * name and served by Settings::logo (uploads/ is never served directly).
 */
class Settings_model extends MY_Model
{
    const LOGO_DIR = 'uploads/settings/';
    const LOGO_MAX_KB = 512;

    /** date formats offered on the General tab */
    public static function date_formats()
    {
        return array('d-m-Y', 'd/m/Y', 'd.m.Y', 'Y-m-d', 'm/d/Y');
    }

    /** the stored row merged over the defaults */
    public function current()
    {
        $row = $this->db->query('SELECT * FROM hr_settings WHERE id = 1')->row_array();
        $values = (array) config_item('hrm_defaults');
        foreach ($values as $k => $default) {
            if (is_array($row) && array_key_exists($k, $row) && $row[$k] !== NULL) {
                $values[$k] = $row[$k];
            }
        }
        return $values;
    }

    /** validate and save the posted form (all tabs at once); "success" or the errors */
    public function save()
    {
        $v = new Input_validator($this->input);
        $new = array(
            // General
            'company_name'      => (string) $v->text('company_name', 150, FALSE),
            'address'           => (string) $v->text('address', 255, FALSE),
            'phone'             => (string) $v->text('phone', 30, FALSE),
            'email'             => (string) $v->email('email', 120, FALSE),
            'timezone'          => $v->enum('timezone', DateTimeZone::listIdentifiers()),
            'date_format'       => $v->enum('date_format', self::date_formats()),
            'currency_symbol'   => $v->text('currency_symbol', 10),
            'currency_position' => $v->enum('currency_position', array('left', 'right')),
            'decimals'          => $v->int('decimals', 0, 3),
            'idle_logout_minutes' => $v->int('idle_logout_minutes', 0, 1440),
            // Attendance
            'weekly_offs'       => NULL,
            'deduct_break'      => $v->flag('deduct_break'),
            'ot_min_minutes'    => $v->int('ot_min_minutes', 0, 240),
            'early_leave_counts_as_late' => $v->flag('early_leave_counts_as_late'),
            'late_days_per_absent' => $v->int('late_days_per_absent', 0, 31),
            // Payroll
            'overtime_source'   => $v->enum('overtime_source', array('attendance', 'approved_requests')),
            'overtime_basis'    => $v->enum('overtime_basis', array('basic_salary', 'gross_salary')),
            'ot_divisor_hours'  => $v->money('ot_divisor_hours', 1, 744),
            'ot_multiplier'     => $v->money('ot_multiplier', 0, 10),
            'overtime_mode'     => $v->enum('overtime_mode', array('auto', 'manual')),
            'late_deduction_mode' => $v->enum('late_deduction_mode', array('auto', 'manual')),
            'absent_deduction_basis' => $v->enum('absent_deduction_basis', array('basic_salary', 'gross_salary')),
            'absent_deduction_mode' => $v->enum('absent_deduction_mode', array('auto', 'manual')),
            'allow_negative_net' => $v->flag('allow_negative_net'),
            'allow_negative_account' => $v->flag('allow_negative_account'),
            'payslip_footer'    => (string) $v->text('payslip_footer', 500, FALSE),
            'payslip_show_attendance' => $v->flag('payslip_show_attendance'),
            // Leave
            'self_approve_leave' => $v->flag('self_approve_leave'),
            // Devices
            'punch_debounce_seconds' => $v->int('punch_debounce_seconds', 0, 3600),
            'device_gap_hours'  => $v->int('device_gap_hours', 1, 168),
            // Notifications
            'notify_leave_sms'  => $v->flag('notify_leave_sms'),
            'notify_payslip_email' => $v->flag('notify_payslip_email'),
            'notify_absent_sms' => $v->flag('notify_absent_sms'),
        );
        $days = $v->int_list('weekly_offs', 7, FALSE);
        foreach ((array) $days as $d) {
            if ($d < 1 || $d > 7) {
                $v->fail('weekly_offs', sprintf(t('v_enum'), 'Weekly offs'));
            }
        }
        $upload = $v->image('logo', self::LOGO_MAX_KB);
        $remove_logo = $v->flag('remove_logo');
        if ( ! $v->ok()) {
            return $v->message();
        }
        sort($days);
        $new['weekly_offs'] = implode(',', $days);

        if ($upload !== NULL) {
            $dir = FCPATH . self::LOGO_DIR;
            $name = bin2hex(random_bytes(16)) . '.' . $upload['ext'];         // random name, extension from the contents
            if (( ! is_dir($dir) && ! @mkdir($dir, 0775, TRUE)) || ! move_uploaded_file($upload['tmp'], $dir . $name)) {
                return t('settings_logo_failed');
            }
            $new['logo'] = $name;
        } elseif ($remove_logo) {
            $new['logo'] = '';
        }

        $old_file = NULL;
        list($ok, $message) = Tx::run(function () use ($new, &$old_file) {
            $this->db->query('INSERT INTO hr_settings (id) SELECT 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hr_settings WHERE id = 1)');
            $old = $this->db->query('SELECT * FROM hr_settings WHERE id = 1 FOR UPDATE')->row_array();
            $changes = array();
            foreach ($new as $k => $val) {
                if ( ! array_key_exists($k, $old) || (string) $old[$k] !== (string) $val) {
                    if (is_float($val) && is_numeric($old[$k]) && money_equal($old[$k], $val)) {
                        continue;
                    }
                    $changes[$k] = $val;
                }
            }
            if ( ! $changes) {
                return TRUE;
            }
            if (isset($changes['logo'])) {
                $old_file = $old['logo'];
            }
            $this->db->where('id', 1)->update('hr_settings', array_merge($changes, array('updated_at' => now_local(), 'updated_by' => current_user_id())));
            $this->audit('settings', 1, 'update', array_intersect_key($old, $changes), $changes);
            return TRUE;
        });
        if ( ! $ok) {
            if (isset($new['logo']) && $new['logo'] !== '') {
                @unlink(FCPATH . self::LOGO_DIR . $new['logo']);        // the new upload is not used
            }
            return $message;
        }
        if ($old_file) {
            @unlink(FCPATH . self::LOGO_DIR . basename($old_file));
        }
        hr_setting_reset_cache();
        return 'success';
    }

    /** absolute path of the current logo, or NULL */
    public function logo_path()
    {
        $name = basename((string) hr_setting('logo', ''));
        if ($name === '' || ! preg_match('/^[a-f0-9]{32}\.(png|jpg)$/', $name)) {
            return NULL;
        }
        $path = FCPATH . self::LOGO_DIR . $name;
        return is_file($path) ? $path : NULL;
    }
}
