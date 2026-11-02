<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| Permission catalog (CLAUDE.md "Permissions"). Nothing is auto-granted except to the system Admin role
| (roles.is_system = 1 passes every check). A key used in a controller's $permission_map must exist here;
| tests/static_checks.php and the permission coverage test enforce it.
|
| group label => list of permission keys
*/

$config['permission_catalog'] = array(
	'Employees' => array(
		'employees_view', 'employees_add', 'employees_edit', 'employees_delete', 'employees_salary', 'employees_sensitive',
	),
	'Organisation' => array(
		'departments_view', 'departments_add', 'departments_edit', 'departments_delete',
		'designations_view', 'designations_add', 'designations_edit', 'designations_delete',
	),
	'Time' => array(
		'shifts_view', 'shifts_add', 'shifts_edit', 'shifts_delete',
		'rosters_manage',
		'holidays_view', 'holidays_add', 'holidays_edit', 'holidays_delete',
	),
	'Attendance' => array(
		'attendance_view', 'attendance_mark_self', 'attendance_add', 'attendance_bulk', 'attendance_edit',
		'attendance_approve', 'attendance_delete', 'attendance_report', 'show_all_attendance',
		'attendance_punches', 'attendance_import',
	),
	'Leave' => array(
		'leave_types_view', 'leave_types_add', 'leave_types_edit', 'leave_types_delete',
		'leave_allocations_view', 'leave_allocations_add', 'leave_allocations_edit', 'leave_allocations_delete',
		'leaves_view', 'leaves_add', 'leaves_edit', 'leaves_delete', 'leaves_approve', 'leaves_report', 'show_all_leaves',
	),
	'Overtime' => array(
		'overtime_view', 'overtime_add', 'overtime_edit', 'overtime_delete', 'overtime_approve',
	),
	'Advances and loans' => array(
		'advances_view', 'advances_add', 'advances_edit', 'advances_delete', 'advances_approve', 'advances_pay',
		'loans_view', 'loans_add', 'loans_edit', 'loans_delete', 'loans_pay',
	),
	'Payroll' => array(
		'payroll_view', 'payroll_add', 'payroll_edit', 'payroll_delete', 'payroll_process', 'payroll_pay',
		'payroll_override', 'payroll_report', 'payroll_reopen', 'payslip_email',
	),
	'Accounts' => array(
		'accounts_view', 'accounts_add', 'accounts_edit',
	),
	'Devices' => array(
		'devices_view', 'devices_manage', 'devices_commands', 'devices_keys',
	),
	'Reports and system' => array(
		'reports_hr', 'dashboard_hr', 'export_hr', 'settings_edit',
		'users_view', 'users_add', 'users_edit', 'users_delete', 'roles_manage', 'audit_view',
	),
);

/*
| Non-permission tokens allowed as $permission_map values.
|   @any    any logged-in user              @self    Ess_Controller only (the model scopes to the employee)
|   @system the system Admin role only (roles.is_system = 1), e.g. switching the working branch
|   @public no login needed (Auth)          @login   logged in, checked by the controller itself (Auth)
|   @cli     command line only (Cron)      @device  authenticated by a device key / registered serial (Api, Iclock)
*/
$config['permission_tokens'] = array('@any', '@system', '@self', '@public', '@login', '@cli', '@device');
