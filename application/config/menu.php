<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| Sidebar catalog. An item is shown only when the user holds `permission` (or `permission` is '@any').
| A group is shown when at least one child is visible. `label` is a language key (the hrm_lang.php language files).
| `url` doubles as the key a controller puts in $this->data['active_menu'] to highlight the entry.
| Add an entry in the same step that adds the page. The first visible entry is where Dashboard::home sends a user
| without the dashboard.
*/
$config['menu'] = array(
	array('label' => 'menu_dashboard', 'icon' => 'fa-dashboard', 'url' => 'dashboard', 'permission' => 'dashboard_hr'),
	array('label' => 'menu_organisation', 'icon' => 'fa-sitemap', 'children' => array(
		array('label' => 'menu_employees', 'url' => 'employees', 'permission' => 'employees_view'),
		array('label' => 'menu_departments', 'url' => 'departments', 'permission' => 'departments_view'),
		array('label' => 'menu_designations', 'url' => 'designations', 'permission' => 'designations_view'),
	)),
	array('label' => 'menu_time', 'icon' => 'fa-clock-o', 'children' => array(
		array('label' => 'menu_shifts', 'url' => 'shifts', 'permission' => 'shifts_view'),
		array('label' => 'menu_employee_shifts', 'url' => 'employee_shifts', 'permission' => 'shifts_view'),
		array('label' => 'menu_rosters', 'url' => 'rosters', 'permission' => 'rosters_manage'),
		array('label' => 'menu_holidays', 'url' => 'holidays', 'permission' => 'holidays_view'),
	)),
	array('label' => 'menu_attendance', 'icon' => 'fa-calendar-check-o', 'children' => array(
		array('label' => 'menu_attendance_list', 'url' => 'attendance', 'permission' => 'attendance_view'),
		array('label' => 'att_mark_self', 'url' => 'attendance/my_day', 'permission' => 'attendance_mark_self'),
		array('label' => 'att_mark', 'url' => 'attendance/mark', 'permission' => 'attendance_add'),
		array('label' => 'att_bulk', 'url' => 'attendance/bulk', 'permission' => 'attendance_bulk'),
		array('label' => 'att_punches', 'url' => 'attendance/punches', 'permission' => 'attendance_punches'),
		array('label' => 'att_unmatched_page', 'url' => 'attendance/unmatched', 'permission' => 'attendance_punches'),
		array('label' => 'att_closed_page', 'url' => 'attendance/closed_punches', 'permission' => 'attendance_punches'),
		array('label' => 'att_import', 'url' => 'attendance/import', 'permission' => 'attendance_import'),
	)),
	array('label' => 'menu_reports', 'icon' => 'fa-bar-chart', 'children' => array(
		array('label' => 'rep_daily', 'url' => 'reports/daily', 'permission' => 'attendance_report'),
		array('label' => 'rep_monthly', 'url' => 'reports/monthly', 'permission' => 'attendance_report'),
		array('label' => 'rep_inout', 'url' => 'reports/inout', 'permission' => 'attendance_report'),
		array('label' => 'rep_employee', 'url' => 'reports/employee', 'permission' => 'attendance_report'),
		array('label' => 'rep_summary', 'url' => 'reports/summary', 'permission' => 'attendance_report'),
		array('label' => 'rep_late', 'url' => 'reports/late', 'permission' => 'attendance_report'),
		array('label' => 'rep_absent', 'url' => 'reports/absent', 'permission' => 'attendance_report'),
		array('label' => 'rep_overtime', 'url' => 'reports/overtime', 'permission' => 'attendance_report'),
		array('label' => 'rep_missing', 'url' => 'reports/missing', 'permission' => 'attendance_report'),
	)),
	array('label' => 'menu_devices_group', 'icon' => 'fa-hdd-o', 'children' => array(
		array('label' => 'menu_devices', 'url' => 'devices', 'permission' => 'devices_view'),
		array('label' => 'dev_keys', 'url' => 'devices/keys', 'permission' => 'devices_keys'),
		array('label' => 'dev_commands', 'url' => 'devices/commands', 'permission' => 'devices_commands'),
		array('label' => 'dev_logs', 'url' => 'devices/logs', 'permission' => 'devices_view'),
	)),
	array('label' => 'menu_admin', 'icon' => 'fa-cogs', 'children' => array(
		array('label' => 'menu_users', 'url' => 'users', 'permission' => 'users_view'),
		array('label' => 'menu_roles', 'url' => 'roles', 'permission' => 'roles_manage'),
		array('label' => 'menu_branches', 'url' => 'branches', 'permission' => 'settings_edit'),
		array('label' => 'menu_settings', 'url' => 'settings', 'permission' => 'settings_edit'),
		array('label' => 'menu_audit', 'url' => 'audit', 'permission' => 'audit_view'),
	)),
);
