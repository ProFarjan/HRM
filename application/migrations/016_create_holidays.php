<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Holidays per branch. is_fixed_date* = the holiday falls on the same day every year (21 Feb, 26 Mar, 16 Dec ...):
 * "copy last year" copies only those; lunar holidays (Eid, Puja) are entered each year.
 */
class Migration_Create_holidays extends Hrm_migration
{
	public function up()
	{
		$this->create_table('holidays', <<<'SQL'
CREATE TABLE `holidays` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` INT UNSIGNED NOT NULL,
  `holiday_name` VARCHAR(150) NOT NULL,
  `holiday_date` DATE NOT NULL,
  `year` SMALLINT UNSIGNED NOT NULL,
  `is_optional` TINYINT(1) NOT NULL DEFAULT 0,
  `is_fixed_date` TINYINT(1) NOT NULL DEFAULT 0,
  `description` VARCHAR(255) NULL,
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_holidays_branch_date` (`branch_id`, `holiday_date`),
  KEY `idx_holidays_branch_year` (`branch_id`, `year`),
  CONSTRAINT `fk_holidays_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_holidays_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
	}

	public function down()
	{
		$this->drop_tables(array('holidays'));
	}
}
