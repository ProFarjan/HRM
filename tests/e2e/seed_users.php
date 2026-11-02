<?php
/**
 * Creates the no-permission user used by the browser check (idempotent):
 *   TENANT_DB=hrm_dev HRM_E2E_PASSWORD=... php tests/e2e/seed_users.php
 * Users: "nopriv" (no permission), "viewer" (only dashboard_hr), "clerk" (employees view/edit, no salary, no sensitive data).
 */
define('HRM_ROOT', dirname(__DIR__, 2));
chdir(HRM_ROOT);
$_SERVER['argv'] = array('index.php', 'cron', 'ping');
$_SERVER['argc'] = 3;
ob_start();
require HRM_ROOT . '/index.php';
ob_end_clean();
$CI =& get_instance();
$password = getenv('HRM_E2E_PASSWORD') ? getenv('HRM_E2E_PASSWORD') : 'E2e#Passw0rd';
$now = date('Y-m-d H:i:s');
$branch = $CI->db->query('SELECT id FROM branches ORDER BY id LIMIT 1')->row()->id;
foreach (array('nopriv' => array(), 'viewer' => array('dashboard_hr'), 'clerk' => array('employees_view', 'employees_edit', 'departments_view')) as $name => $permissions) {
    $CI->db->query('INSERT INTO roles (role_name, is_system, created_at) SELECT ?, 0, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = ?)', array('e2e_' . $name, $now, 'e2e_' . $name));
    $role = $CI->db->query('SELECT id FROM roles WHERE role_name = ?', array('e2e_' . $name))->row()->id;
    $CI->db->query('DELETE FROM role_permissions WHERE role_id = ?', array($role));
    foreach ($permissions as $key) {
        $CI->db->query('INSERT INTO role_permissions (role_id, permission_key) VALUES (?, ?)', array($role, $key));
    }
    $CI->db->query('DELETE FROM users WHERE username = ?', array($name));
    $CI->db->query('INSERT INTO users (username, password_hash, role_id, branch_id, status, must_change_password, created_at) VALUES (?, ?, ?, ?, ?, 0, ?)',
        array($name, password_hash($password, PASSWORD_DEFAULT), $role, $branch, 'Active', $now));
    echo "user $name ready\n";
}
