<?php
/**
 * Demo data for the step 5 browser check (idempotent; run seed_attendance.php first): three devices (online Windows Service PC,
 * a delayed ADMS device with a clock drift, an offline one with "possible lost punches"), an unknown ADMS serial number waiting for
 * approval, an employee without a PIN for linking PIN 777, a closed day with a late punch, and no leftover keys or commands.
 *   TENANT_DB=hrm_dev php tests/e2e/seed_devices.php
 */
define('HRM_ROOT', dirname(__DIR__, 2));
chdir(HRM_ROOT);
$_SERVER['argv'] = array('index.php', 'cron', 'ping');
$_SERVER['argc'] = 3;
ob_start();
require HRM_ROOT . '/index.php';
ob_end_clean();
$CI =& get_instance();
$db = $CI->db;
$branch = (int) $db->query("SELECT id FROM branches WHERE code = 'HO'")->row()->id;
set_current_branch_id($branch);
date_default_timezone_set('Asia/Dhaka');
$now = date('Y-m-d H:i:s');
$ago = function ($seconds) { return date('Y-m-d H:i:s', time() - $seconds); };

// devices (upsert by name); start from a clean slate for keys, commands, logs and the pending list
$db->query('DELETE FROM device_commands');
$db->query('DELETE FROM device_logs');
$db->query('DELETE FROM device_keys');
$db->query('DELETE FROM devices_pending');
$db->query("UPDATE devices SET name = 'Factory ADMS', serial_number = 'FACTORY0001' WHERE name = 'ADMS One'");
$db->query("DELETE FROM devices WHERE name IN ('Warehouse door', 'Gate 2') AND id NOT IN (SELECT device_id FROM attendance_punches WHERE device_id IS NOT NULL)");
$want = array(
    'Main Gate'    => array('comm_mode' => 'windows_service', 'serial_number' => NULL, 'last_seen_at' => $ago(120), 'last_upload_at' => $ago(120), 'clock_offset_seconds' => NULL, 'lost_punch_alert' => 0, 'status' => 'active', 'ip' => NULL),
    'Factory ADMS' => array('comm_mode' => 'adms_push', 'serial_number' => 'FACTORY0001', 'last_seen_at' => $ago(1800), 'last_upload_at' => $ago(1800), 'clock_offset_seconds' => 900, 'lost_punch_alert' => 0, 'status' => 'active', 'ip' => '192.168.1.60'),
    'Old Gate'     => array('comm_mode' => 'windows_service', 'serial_number' => NULL, 'last_seen_at' => $ago(3 * 86400), 'last_upload_at' => $ago(3 * 86400), 'clock_offset_seconds' => NULL, 'lost_punch_alert' => 1, 'status' => 'active', 'ip' => NULL),
);
foreach ($want as $name => $values) {
    $row = $db->query('SELECT id FROM devices WHERE branch_id = ? AND name = ?', array($branch, $name))->row_array();
    if ($row) {
        $db->where('id', (int) $row['id'])->update('devices', $values);
    } else {
        $db->insert('devices', array_merge($values, array('branch_id' => $branch, 'name' => $name, 'created_at' => $now)));
    }
}
$db->insert('devices_pending', array('serial_number' => 'PENDING9001', 'ip' => '192.168.1.77', 'first_seen_at' => $ago(7200), 'last_seen_at' => $ago(60), 'hits' => 41));

// an employee without a PIN: PIN 777 of the unmatched punches gets linked to her in the browser check
$exists = $db->query("SELECT id FROM employees WHERE branch_id = ? AND employee_code = 'ATT-07'", array($branch))->row_array();
if ( ! $exists) {
    $dept = (int) $db->query("SELECT id FROM departments WHERE department_code = 'SEWD'")->row()->id;
    $db->insert('employees', array('branch_id' => $branch, 'employee_code' => 'ATT-07', 'first_name' => 'Lina', 'last_name' => 'Akter', 'department_id' => $dept,
        'employment_type' => 'Permanent', 'join_date' => '2025-01-01', 'status' => 'Active', 'created_at' => $now));
    $exists = array('id' => (int) $db->insert_id());
}
$lina = (int) $exists['id'];
$db->query('DELETE FROM employee_pin_history WHERE employee_id = ?', array($lina));
$db->query('UPDATE employees SET device_pin = NULL, device_pin_from = NULL WHERE id = ?', array($lina));
$db->query('DELETE FROM attendance WHERE employee_id = ?', array($lina));
$db->query("UPDATE attendance_punches SET employee_id = NULL WHERE device_pin = '777'");

// a closed day with a late punch (ATT-01, about three days ago)
$day = date('Y-m-d', time() - 3 * 86400);
while (date('N', strtotime($day)) == 5) {                           // not the Friday weekly off: the day has real punches
    $day = date('Y-m-d', strtotime($day . ' -1 day'));
}
$db->query("UPDATE attendance SET locked = 0 WHERE employee_id IN (SELECT id FROM employees WHERE employee_code LIKE 'ATT-%')");
$att = $db->query("SELECT id FROM employees WHERE branch_id = ? AND employee_code = 'ATT-01'", array($branch))->row_array();
$main = (int) $db->query("SELECT id FROM devices WHERE branch_id = ? AND name = 'Main Gate'", array($branch))->row()->id;
// re-run: the late punch of an earlier run is removed and the day rebuilt without it before the day is locked again
$db->query("DELETE FROM attendance_punches WHERE device_pin = '500' AND punch_at = ?", array($day . ' 20:15:00'));
(new Punch_ingestor())->rebuild_day((int) $att['id'], $day, TRUE);
$db->query('UPDATE attendance SET locked = 1 WHERE employee_id = ? AND attendance_date = ?', array((int) $att['id'], $day));
$r = (new Punch_ingestor())->ingest(array(array('device_pin' => '500', 'punch_at' => $day . ' 20:15:00', 'punch_state' => 255)), array('branch_id' => $branch, 'device_id' => $main, 'source' => 'device_service'));
echo json_encode($r), "\n";

// a few log lines so the call log is not empty
foreach (array(array($main, 'api/v1/pull_attendance', 200, 12, 'connection test'), array($main, 'api/v1/pull_attendance', 200, 3, '3 record(s): 3 new, 0 already stored, 0 invalid, 0 unmatched PIN'),
        array(NULL, 'api/v1/pull_attendance', 401, 0, 'Invalid or revoked API key.')) as $i => $l) {
    $db->insert('device_logs', array('device_id' => $l[0], 'branch_id' => $l[0] ? $branch : NULL, 'direction' => 'in', 'endpoint' => $l[1], 'http_code' => $l[2], 'records' => $l[3], 'ip' => '192.168.1.10', 'message' => $l[4], 'created_at' => $ago(600 - $i * 100)));
}
echo "devices seeded\n";
