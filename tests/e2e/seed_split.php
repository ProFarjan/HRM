<?php
/**
 * Demo data for the split-shift browser check (idempotent): shift "Two-part duty" (10:00-14:00 + 18:00-22:00), four people on it (SPL-01..04),
 * two weeks of device punches through the real ingestor (a clean day, late back from the break, a missed second period, an open last
 * period, a double tap), then the day close. SPL-04 has no punches at all (the browser check marks a day for them by hand).
 *   TENANT_DB=hrm_dev php tests/e2e/seed_split.php
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

$shift = $db->query("SELECT id FROM shifts WHERE branch_id = ? AND shift_code = 'SPLITD'", array($branch))->row_array();
if ( ! $shift) {
    $db->insert('shifts', array('branch_id' => $branch, 'shift_name' => 'Two-part duty', 'shift_code' => 'SPLITD', 'start_time' => '10:00:00', 'end_time' => '22:00:00', 'break_duration' => 0,
        'working_hours' => 8, 'grace_time' => 10, 'is_split' => 1, 'status' => 'Active', 'created_at' => $now));
    $shift = array('id' => (int) $db->insert_id());
    $db->insert('shift_segments', array('shift_id' => $shift['id'], 'segment_no' => 1, 'start_time' => '10:00:00', 'end_time' => '14:00:00'));
    $db->insert('shift_segments', array('shift_id' => $shift['id'], 'segment_no' => 2, 'start_time' => '18:00:00', 'end_time' => '22:00:00'));
}
$shift = (int) $shift['id'];
$dept = $db->query("SELECT id FROM departments WHERE department_code = 'SEWD'")->row_array();
$names = array('Nasir Uddin', 'Mitu Rani', 'Rubel Hasan', 'Shirin Sultana');
$ids = array();
foreach ($names as $i => $name) {
    $code = sprintf('SPL-%02d', $i + 1);
    $row = $db->query('SELECT id FROM employees WHERE branch_id = ? AND employee_code = ?', array($branch, $code))->row_array();
    if ( ! $row) {
        list($first, $last) = explode(' ', $name, 2);
        $db->insert('employees', array('branch_id' => $branch, 'employee_code' => $code, 'first_name' => $first, 'last_name' => $last, 'department_id' => $dept ? (int) $dept['id'] : NULL,
            'employment_type' => 'Permanent', 'join_date' => '2025-01-01', 'status' => 'Active', 'device_pin' => (string) (700 + $i), 'device_pin_from' => '2025-01-01', 'created_at' => $now));
        $row = array('id' => (int) $db->insert_id());
        $db->insert('employee_shifts', array('employee_id' => (int) $row['id'], 'shift_id' => $shift, 'effective_from' => '2025-01-01', 'created_at' => $now));
    }
    $ids[$i] = (int) $row['id'];
}
// reset: the demo people's attendance and punches, then rebuild from the script below
$in = implode(',', array_map('intval', $ids));
$db->query("DELETE FROM attendance WHERE employee_id IN ($in)");
$db->query("DELETE FROM attendance_punches WHERE employee_id IN ($in) OR device_pin IN ('700','701','702','703')");
$db->query('DELETE FROM employee_shifts WHERE employee_id IN (' . $in . ') AND shift_id <> ?', array($shift));
$db->query("UPDATE employee_shifts SET effective_from = '2025-01-01', effective_to = NULL WHERE employee_id IN ($in)");
$db->query('DELETE FROM shift_rosters WHERE employee_id IN (' . $in . ')');

$device = $db->query('SELECT id FROM devices WHERE branch_id = ? ORDER BY id LIMIT 1', array($branch))->row_array();
if ( ! $device) {
    $db->insert('devices', array('branch_id' => $branch, 'name' => 'Split gate', 'comm_mode' => 'windows_service', 'status' => 'active', 'created_at' => $now));
    $device = array('id' => (int) $db->insert_id());
}
$punches = array();
$start = strtotime('-13 days', strtotime(date('Y-m-d')));
for ($d = 0; $d <= 13; $d++) {
    $day = date('Y-m-d', $start + $d * 86400);
    if (date('N', strtotime($day)) == 5) {
        continue;                                                            // Friday off
    }
    $yesterday = $day === date('Y-m-d', strtotime('-1 day'));
    foreach (array(0, 1, 2) as $i) {
        $pin = (string) (700 + $i);
        $late = ($i * 3 + $d) % 7 === 0 ? 25 : 0;                            // some come back late from the break
        $p = array($day . ' 09:5' . ($d % 5) . ':00', $day . ' 14:0' . ($d % 4) . ':00', $day . sprintf(' 17:5%d:00', 5 + ($i + $d) % 4) , $day . ' 22:0' . (($i + $d) % 6) . ':00');
        if ($late) {
            $p[2] = $day . ' 18:25:00';
        }
        if ($i === 1 && $d === 6) {
            $p = array($p[0], $p[1]);                                        // Mitu missed the second period
        }
        if ($i === 2 && $yesterday) {
            array_pop($p);                                                   // Rubel has not punched out of the second period yesterday
        }
        foreach ($p as $k => $at) {
            $punches[] = array('device_pin' => $pin, 'punch_at' => $at);
            if ($k === 1 && $i === 0) {
                $punches[] = array('device_pin' => $pin, 'punch_at' => substr($at, 0, 16) . ':20');          // a double tap
            }
        }
    }
}
$r = (new Punch_ingestor())->ingest($punches, array('branch_id' => $branch, 'device_id' => (int) $device['id'], 'source' => 'device_service'));
echo json_encode($r), "\n";
$CI->load->model('Day_close_model');
echo json_encode($CI->Day_close_model->close_range($branch, date('Y-m-d', $start), date('Y-m-d', strtotime('-1 day')))), "\n";
echo "split shift seeded\n";
