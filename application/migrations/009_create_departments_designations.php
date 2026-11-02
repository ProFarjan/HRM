<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Create_departments_designations extends Hrm_migration
{
	public function up()
	{
		// head_employee_id gets its foreign key in 010, once `employees` exists.
		$this->create_table('departments', <<<'SQL'
CREATE TABLE `departments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` INT UNSIGNED NOT NULL,
  `department_name` VARCHAR(120) NOT NULL,
  `department_code` VARCHAR(20) NOT NULL,
  `head_employee_id` INT UNSIGNED NULL,
  `description` VARCHAR(255) NULL,
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_code` (`branch_id`, `department_code`),
  KEY `idx_departments_branch_status` (`branch_id`, `status`),
  KEY `idx_departments_head` (`head_employee_id`),
  CONSTRAINT `fk_departments_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_departments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
		$this->create_table('designations', <<<'SQL'
CREATE TABLE `designations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` INT UNSIGNED NOT NULL,
  `designation_name` VARCHAR(120) NOT NULL,
  `designation_code` VARCHAR(20) NOT NULL,
  `level` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `description` VARCHAR(255) NULL,
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_designations_code` (`branch_id`, `designation_code`),
  KEY `idx_designations_branch_status` (`branch_id`, `status`),
  CONSTRAINT `fk_designations_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_designations_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
	}

	public function down()
	{
		$this->drop_tables(array('designations', 'departments'));
	}
}
