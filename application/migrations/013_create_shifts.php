<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Shifts of a branch. is_night = end_time < start_time (kept in step by Shifts_model). working_hours 0 = end - start. */
class Migration_Create_shifts extends Hrm_migration
{
	public function up()
	{
		$this->create_table('shifts', <<<'SQL'
CREATE TABLE `shifts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` INT UNSIGNED NOT NULL,
  `shift_name` VARCHAR(100) NOT NULL,
  `shift_code` VARCHAR(20) NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `break_duration` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `working_hours` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `grace_time` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `shift_allowance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `is_night` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shifts_code` (`branch_id`, `shift_code`),
  KEY `idx_shifts_branch_status` (`branch_id`, `status`),
  CONSTRAINT `fk_shifts_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_shifts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
	}

	public function down()
	{
		$this->drop_tables(array('shifts'));
	}
}
