<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * One row per employee per day. source*: punch (built from punches), manual (HR marked/edited: not overwritten by punches),
 * self (the employee marked their own day: Pending), day_close (created by the cron for a day without a row).
 * leave_id gets its foreign key in step 6 (leaves).
 */
class Migration_Create_attendance extends Hrm_migration
{
	public function up()
	{
		$this->create_table('attendance', <<<'SQL'
CREATE TABLE `attendance` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` INT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL,
  `attendance_date` DATE NOT NULL,
  `shift_id` INT UNSIGNED NULL,
  `check_in` TIME NULL,
  `check_out` TIME NULL,
  `first_punch_at` DATETIME NULL,
  `last_punch_at` DATETIME NULL,
  `status` ENUM('Present','Absent','Half-day','Leave','Holiday','Weekly Off') NOT NULL,
  `leave_id` INT UNSIGNED NULL,
  `approval_status` ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Approved',
  `approval_note` VARCHAR(255) NULL,
  `working_hours` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `break_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `late_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `early_leave_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `overtime_hours` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `source` ENUM('punch','manual','self','day_close') NOT NULL,
  `locked` TINYINT(1) NOT NULL DEFAULT 0,
  `remarks` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NULL,
  `approved_by` INT UNSIGNED NULL,
  `approved_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_employee_date` (`employee_id`, `attendance_date`),
  KEY `idx_attendance_branch_date_status` (`branch_id`, `attendance_date`, `status`),
  KEY `idx_attendance_branch_approval` (`branch_id`, `approval_status`),
  KEY `idx_attendance_shift` (`shift_id`),
  CONSTRAINT `fk_attendance_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_attendance_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_attendance_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_attendance_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_attendance_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
	}

	public function down()
	{
		$this->drop_tables(array('attendance'));
	}
}
