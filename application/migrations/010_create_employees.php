<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * employees (separate from logins), employee_pin_history (P5), employee_documents.
 * device_pin_from* = the date the current PIN started (becomes valid_from when the PIN moves to the history).
 * employee_pin_history.branch_id* lets the punch ingestor find a PIN's owner by branch + PIN + date with one index.
 */
class Migration_Create_employees extends Hrm_migration
{
	public function up()
	{
		$this->create_table('employees', <<<'SQL'
CREATE TABLE `employees` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` INT UNSIGNED NOT NULL,
  `employee_code` VARCHAR(30) NOT NULL,
  `first_name` VARCHAR(80) NOT NULL,
  `last_name` VARCHAR(80) NULL,
  `gender` ENUM('Male','Female','Other') NULL,
  `dob` DATE NULL,
  `mobile` VARCHAR(20) NULL,
  `email` VARCHAR(120) NULL,
  `photo` VARCHAR(64) NULL,
  `address` VARCHAR(255) NULL,
  `department_id` INT UNSIGNED NULL,
  `designation_id` INT UNSIGNED NULL,
  `reporting_manager_id` INT UNSIGNED NULL,
  `employment_type` ENUM('Permanent','Contract','Temporary','Part-time','Probation') NOT NULL DEFAULT 'Permanent',
  `join_date` DATE NOT NULL,
  `confirmation_date` DATE NULL,
  `exit_date` DATE NULL,
  `exit_reason` VARCHAR(255) NULL,
  `status` ENUM('Active','On Leave','Resigned','Terminated') NOT NULL DEFAULT 'Active',
  `blood_group` ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-') NULL,
  `emergency_contact_name` VARCHAR(120) NULL,
  `emergency_contact_number` VARCHAR(20) NULL,
  `bank_name` VARCHAR(100) NULL,
  `bank_account_number` VARCHAR(40) NULL,
  `bank_branch` VARCHAR(100) NULL,
  `national_id` VARCHAR(30) NULL,
  `tin` VARCHAR(30) NULL,
  `device_pin` VARCHAR(9) NULL,
  `device_pin_from` DATE NULL,
  `user_id` INT UNSIGNED NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employees_code` (`branch_id`, `employee_code`),
  UNIQUE KEY `uq_employees_device_pin` (`branch_id`, `device_pin`),
  UNIQUE KEY `uq_employees_user` (`user_id`),
  KEY `idx_employees_branch_status` (`branch_id`, `status`),
  KEY `idx_employees_department` (`department_id`),
  KEY `idx_employees_designation` (`designation_id`),
  KEY `idx_employees_manager` (`reporting_manager_id`),
  CONSTRAINT `fk_employees_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_employees_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_employees_designation` FOREIGN KEY (`designation_id`) REFERENCES `designations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_employees_manager` FOREIGN KEY (`reporting_manager_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_employees_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_employees_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
		$this->create_table('employee_pin_history', <<<'SQL'
CREATE TABLE `employee_pin_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` INT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL,
  `device_pin` VARCHAR(9) NOT NULL,
  `valid_from` DATE NOT NULL,
  `valid_to` DATE NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pin_history_lookup` (`branch_id`, `device_pin`, `valid_from`),
  KEY `idx_pin_history_employee` (`employee_id`),
  CONSTRAINT `fk_pin_history_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_pin_history_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
		// a document belongs to its employee: deleting the employee (only possible without other records) removes them
		$this->create_table('employee_documents', <<<'SQL'
CREATE TABLE `employee_documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `file` VARCHAR(64) NOT NULL,
  `original_name` VARCHAR(150) NULL,
  `expires_on` DATE NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_documents_employee` (`employee_id`),
  KEY `idx_documents_expiry` (`expires_on`),
  CONSTRAINT `fk_documents_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_documents_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
		if ( ! $this->fk_exists('departments', 'fk_departments_head')) {
			$this->run('ALTER TABLE departments ADD CONSTRAINT fk_departments_head FOREIGN KEY (head_employee_id) REFERENCES employees (id) ON DELETE RESTRICT');
		}
	}

	public function down()
	{
		if ($this->fk_exists('departments', 'fk_departments_head')) {
			$this->run('ALTER TABLE departments DROP FOREIGN KEY fk_departments_head');
		}
		$this->drop_tables(array('employee_documents', 'employee_pin_history', 'employees'));
	}
}
