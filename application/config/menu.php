<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| Sidebar catalog. An item is shown only when the user holds `permission` (or `permission` is '@any').
| A group is shown when at least one child is visible. `label` is a language key (the hrm_lang.php language files).
| Add an entry in the same step that adds the page.
*/
$config['menu'] = array(
	array('label' => 'menu_dashboard', 'icon' => 'fa-dashboard', 'url' => 'dashboard', 'permission' => 'dashboard_hr'),
);
