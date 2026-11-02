<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Salary structure history. A new structure closes the previous one (effective_to = new.effective_from - 1 day).
 * Payroll never reads is_active: it reads the structure(s) whose dates cover the month (P20).
 */
class Migration_Create_salary_structures extends Hrm_migration
{
	public function up()
	{
		$this->create_table('salary_structures', <<<'SQL'
CREATE TABLE `salary_structures` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `basic_salary` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `house_rent` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `medical_allowance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `transport_allowance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `other_allowances` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `gross_salary` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `pf_deduction` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `esi_deduction` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `tax_deduction` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `other_deductions` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `net_salary` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `effective_from` DATE NOT NULL,
  `effective_to` DATE NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_salary_employee_from` (`employee_id`, `effective_from`),
  KEY `idx_salary_employee_to` (`employee_id`, `effective_to`),
  CONSTRAINT `fk_salary_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_salary_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
	}

	public function down()
	{
		$this->drop_tables(array('salary_structures'));
	}
}
