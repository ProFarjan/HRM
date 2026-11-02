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
	array('label' => 'menu_admin', 'icon' => 'fa-cogs', 'children' => array(
		array('label' => 'menu_users', 'url' => 'users', 'permission' => 'users_view'),
		array('label' => 'menu_roles', 'url' => 'roles', 'permission' => 'roles_manage'),
		array('label' => 'menu_branches', 'url' => 'branches', 'permission' => 'settings_edit'),
		array('label' => 'menu_settings', 'url' => 'settings', 'permission' => 'settings_edit'),
		array('label' => 'menu_audit', 'url' => 'audit', 'permission' => 'audit_view'),
	)),
);
