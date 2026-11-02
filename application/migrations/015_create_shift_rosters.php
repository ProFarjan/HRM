<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Day-level roster: wins over employee_shifts; is_working_day = 0 makes the day a weekly off (shift_id may then be NULL). */
class Migration_Create_shift_rosters extends Hrm_migration
{
	public function up()
	{
		$this->create_table('shift_rosters', <<<'SQL'
CREATE TABLE `shift_rosters` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `shift_id` INT UNSIGNED NULL,
  `roster_date` DATE NOT NULL,
  `is_working_day` TINYINT(1) NOT NULL DEFAULT 1,
  `remarks` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rosters_employee_date` (`employee_id`, `roster_date`),
  KEY `idx_rosters_date` (`roster_date`),
  KEY `idx_rosters_shift` (`shift_id`),
  CONSTRAINT `fk_rosters_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rosters_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_rosters_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
	}

	public function down()
	{
		$this->drop_tables(array('shift_rosters'));
	}
}
