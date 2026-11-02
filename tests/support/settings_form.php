<?php
/** the current settings values as the Settings page would post them (checkboxes absent when off) */
function settings_form(array $values)
{
    $post = array();
    foreach ($values as $k => $v) {
        if ($k === 'logo' || ($k === 'default_shift_id' && $v === NULL)) {
            continue;
        }
        if ($k === 'weekly_offs') {
            $post['weekly_offs'] = array_values(array_filter(explode(',', (string) $v), 'strlen'));
            continue;
        }
        if (in_array($k, array('deduct_break', 'early_leave_counts_as_late', 'allow_negative_net', 'allow_negative_account', 'self_approve_leave',
            'payslip_show_attendance', 'notify_leave_sms', 'notify_payslip_email', 'notify_absent_sms'), TRUE)) {
            if ((int) $v) {
                $post[$k] = '1';
            }
            continue;
        }
        $post[$k] = (string) $v;
    }
    return $post;
}
