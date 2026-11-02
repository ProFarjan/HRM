<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Every raw punch, never edited (P4). device_id is NULL for manual punches (the devices table and its foreign key arrive in
 * step 5); the unique key makes a re-sent device punch a no-op, manual duplicates are refused in code (NULL never collides).
 * employee_id NULL = unmatched PIN (it is set once the PIN is linked - the punch time and PIN themselves never change).
 */
class Migration_Create_attendance_punches extends Hrm_migration
{
	public function up()
	{
		$this->create_table('attendance_punches', <<<'SQL'
CREATE TABLE `attendance_punches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` INT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NULL,
  `device_pin` VARCHAR(9) NULL,
  `device_id` INT UNSIGNED NULL,
  `punch_at` DATETIME NOT NULL,
  `punch_state` TINYINT UNSIGNED NOT NULL DEFAULT 255,
  `verify_mode` TINYINT UNSIGNED NULL,
  `source` ENUM('device_push','device_service','manual','import','mobile') NOT NULL,
  `work_code` VARCHAR(20) NULL,
  `ignored` TINYINT(1) NOT NULL DEFAULT 0,
  `received_at` DATETIME NOT NULL,
  `created_by` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_punches_device` (`device_id`, `device_pin`, `punch_at`),
  KEY `idx_punches_branch_time` (`branch_id`, `punch_at`),
  KEY `idx_punches_employee_time` (`employee_id`, `punch_at`),
  KEY `idx_punches_branch_pin_time` (`branch_id`, `device_pin`, `punch_at`),
  CONSTRAINT `fk_punches_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_punches_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_punches_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
	}

	public function down()
	{
		$this->drop_tables(array('attendance_punches'));
	}
}
