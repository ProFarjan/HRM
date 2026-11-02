<?php
/**
 * Demo data for the step 4 browser check (idempotent): 6 employees with PINs on a day shift, two weeks of device punches
 * fed through Punch_ingestor (late arrivals, a missing check-out, an unmatched PIN, a double tap) and the day close.
 *   TENANT_DB=hrm_dev php tests/e2e/seed_attendance.php
 */
define('HRM_ROOT', dirname(__DIR__, 2));
chdir(HRM_ROOT);
$_SERVER['argv'] = array('index.php', 'cron', 'ping');
$_SERVER['argc'] = 3;
ob_start();
require HRM_ROOT . '/index.php';
ob_end_clean();
$CI =& get_instance();
$branch = (int) $CI->db->query("SELECT id FROM branches WHERE code = 'HO'")->row()->id;
set_current_branch_id($branch);
date_default_timezone_set('Asia/Dhaka');
if ($CI->db->query("SELECT 1 FROM employees WHERE employee_code = 'ATT-01'")->num_rows()) {
    // re-run: undo what the browser check changed (manual punches and hand-made rows), then rebuild from the device punches
    $demo = "SELECT id FROM employees WHERE branch_id = ? AND employee_code LIKE 'ATT-%'";
    $CI->db->query("DELETE FROM attendance_punches WHERE source = 'manual' AND employee_id IN (" . $demo . ')', array($branch));
    $CI->db->query("DELETE FROM attendance WHERE employee_id IN (" . $demo . ')', array($branch));
    $days = $CI->db->query("SELECT DISTINCT employee_id, DATE_FORMAT(punch_at, '%Y-%m-%d') AS d FROM attendance_punches
        WHERE ignored = 0 AND employee_id IN (" . $demo . ')', array($branch))->result_array();
    $ingestor = new Punch_ingestor();
    foreach ($days as $row) {
        $ingestor->rebuild_day((int) $row['employee_id'], $row['d']);
    }
    $CI->load->model('Day_close_model');
    $close = $CI->Day_close_model->close_range($branch, date('Y-m-d', strtotime('-13 days')), date('Y-m-d', strtotime('-1 day')));
    echo 'reset: ', count($days), ' day(s) rebuilt; ', json_encode($close), "\n";
    exit(0);
}
$now = date('Y-m-d H:i:s');
$CI->db->insert('departments', array('branch_id' => $branch, 'department_name' => 'Sewing floor', 'department_code' => 'SEWD', 'status' => 'Active', 'created_at' => $now));
$dept = (int) $CI->db->insert_id();
$CI->db->insert('shifts', array('branch_id' => $branch, 'shift_name' => 'Day', 'shift_code' => 'DAYD', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
    'break_duration' => 60, 'working_hours' => 8, 'grace_time' => 10, 'status' => 'Active', 'created_at' => $now));
$shift = (int) $CI->db->insert_id();
$names = array('Rahim Uddin', 'Karima Begum', 'নুসরাত জাহান', 'Arif Hossain', 'Sumi Akter', 'Jamal Mia');
$ids = array();
foreach ($names as $i => $name) {
    list($first, $last) = explode(' ', $name, 2);
    $CI->db->insert('employees', array('branch_id' => $branch, 'employee_code' => sprintf('ATT-%02d', $i + 1), 'first_name' => $first, 'last_name' => $last,
        'department_id' => $dept, 'employment_type' => 'Permanent', 'join_date' => '2025-01-01', 'status' => 'Active', 'device_pin' => (string) (500 + $i),
        'device_pin_from' => '2025-01-01', 'created_at' => $now));
    $ids[$i] = (int) $CI->db->insert_id();
    $CI->db->insert('employee_shifts', array('employee_id' => $ids[$i], 'shift_id' => $shift, 'effective_from' => '2025-01-01', 'created_at' => $now));
}
$punches = array();
$start = strtotime('-13 days', strtotime(date('Y-m-d')));
for ($d = 0; $d <= 13; $d++) {
    $day = date('Y-m-d', $start + $d * 86400);
    if (date('N', strtotime($day)) == 5) {
        continue;                                                      // Friday off
    }
    foreach ($ids as $i => $id) {
        if ($i === 5 && $d % 3 === 0) {
            continue;                                                  // Jamal is often absent
        }
        $in = 8 * 60 + 45 + (($i * 7 + $d * 5) % 40);                  // 08:45 .. 09:24 -> some late
        $punches[] = array('device_pin' => (string) (500 + $i), 'punch_at' => $day . sprintf(' %02d:%02d:00', intdiv($in, 60), $in % 60));
        if ($i === 4 && $d === 12) {
            continue;                                                  // Sumi forgot to punch out
        }
        $punches[] = array('device_pin' => (string) (500 + $i), 'punch_at' => $day . ' 13:00:00', 'punch_state' => 255);
        $punches[] = array('device_pin' => (string) (500 + $i), 'punch_at' => $day . ' 13:00:20', 'punch_state' => 255);   // double tap
        $punches[] = array('device_pin' => (string) (500 + $i), 'punch_at' => $day . ' 13:50:00', 'punch_state' => 255);
        $out = 17 * 60 + 50 + (($i * 11 + $d * 3) % 70);
        $punches[] = array('device_pin' => (string) (500 + $i), 'punch_at' => $day . sprintf(' %02d:%02d:00', intdiv($out, 60), $out % 60));
    }
    $punches[] = array('device_pin' => '777', 'punch_at' => $day . ' 09:01:00');   // nobody has PIN 777
}
$r = (new Punch_ingestor())->ingest($punches, array('branch_id' => $branch, 'device_id' => 1, 'source' => 'device_service'));
echo json_encode($r), "\n";
$CI->load->model('Day_close_model');
$close = $CI->Day_close_model->close_range($branch, date('Y-m-d', $start), date('Y-m-d', strtotime('-1 day')));
echo json_encode($close), "\n";
