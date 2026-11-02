<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| HRM defaults and static catalogs.
|
| hrm_defaults  : fallback for hr_setting('key'). A setting that is missing from the hr_settings row (an un-migrated
|                 tenant) never breaks a page: the default here is used. Keep in sync with CLAUDE.md "HR settings".
| scoped_tables : tables an id from the request may refer to (Input_validator::ref, MY_Controller::belong_to).
|                 branch = column holding the branch id, own = column holding the owning employee id (or NULL),
|                 module = module whose "show_all_<module>" permission lifts the own-records restriction.
|                 Later steps register their tables here (employees, attendance, leaves, ...).
| sequences     : code formats for next_code(). {Y} = year, {Ym} = year+month, {n} = zero padded counter.
*/

$config['hrm_defaults'] = array(
	'company_name'               => '',
	'logo'                       => '',
	'address'                    => '',
	'phone'                      => '',
	'email'                      => '',
	'timezone'                   => 'Asia/Dhaka',
	'date_format'                => 'd-m-Y',
	'currency_symbol'            => '৳',
	'currency_position'          => 'left',
	'decimals'                   => 2,
	'weekly_offs'                => '5',          // ISO weekday numbers, CSV. 5 = Friday
	'default_shift_id'           => NULL,
	'deduct_break'               => 0,
	'ot_min_minutes'             => 0,
	'early_leave_counts_as_late' => 0,
	'punch_debounce_seconds'     => 60,
	'device_gap_hours'           => 12,
	'overtime_source'            => 'attendance',
	'overtime_basis'             => 'basic_salary',
	'ot_divisor_hours'           => 208,
	'ot_multiplier'              => 1.5,
	'overtime_mode'              => 'auto',
	'late_days_per_absent'       => 3,
	'late_deduction_mode'        => 'auto',
	'absent_deduction_basis'     => 'gross_salary',
	'absent_deduction_mode'      => 'auto',
	'allow_negative_net'         => 0,
	'allow_negative_account'     => 0,
	'self_approve_leave'         => 0,
	'payslip_footer'             => '',
	'payslip_show_attendance'    => 1,
	'notify_leave_sms'           => 0,
	'notify_payslip_email'       => 0,
	'notify_absent_sms'          => 0,
	'idle_logout_minutes'        => 0,
);

$config['scoped_tables'] = array(
	'users'    => array('branch' => 'branch_id', 'own' => NULL, 'module' => NULL),
	'roles'    => array('branch' => NULL, 'own' => NULL, 'module' => NULL),         // company-wide
	'branches' => array('branch' => NULL, 'own' => NULL, 'module' => NULL),         // company-wide
	'departments'  => array('branch' => 'branch_id', 'own' => NULL, 'module' => NULL),
	'designations' => array('branch' => 'branch_id', 'own' => NULL, 'module' => NULL),
	'employees'    => array('branch' => 'branch_id', 'own' => NULL, 'module' => NULL),
	'shifts'       => array('branch' => 'branch_id', 'own' => NULL, 'module' => NULL),
	'holidays'     => array('branch' => 'branch_id', 'own' => NULL, 'module' => NULL),
	'attendance'   => array('branch' => 'branch_id', 'own' => 'employee_id', 'module' => 'attendance'),
);

$config['sequences'] = array(
	'leave'        => 'LV-{Y}-{n}',
	'loan'         => 'LN-{n}',
	'advance'      => 'ADV-{n}',
	'payslip'      => 'PS-{Ym}-{n}',
	'loan_payment' => 'LP-{n}',
	'employee'     => 'EMP-{n}',          // used when the employee code is left blank
);
$config['sequence_pad'] = 4;

// Login throttle (G6): this many failures in this many minutes lock the username+IP pair for the same window.
$config['login_max_failures'] = 5;
$config['login_window_minutes'] = 15;
