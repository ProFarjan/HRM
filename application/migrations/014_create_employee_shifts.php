<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Shift assignment history: ranges of one employee never overlap; a new assignment closes the open one. */
class Migration_Create_employee_shifts extends Hrm_migration
{
	public function up()
	{
		$this->create_table('employee_shifts', <<<'SQL'
CREATE TABLE `employee_shifts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `shift_id` INT UNSIGNED NOT NULL,
  `effective_from` DATE NOT NULL,
  `effective_to` DATE NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_shifts_from` (`employee_id`, `effective_from`),
  KEY `idx_employee_shifts_range` (`employee_id`, `effective_to`),
  KEY `idx_employee_shifts_shift` (`shift_id`),
  CONSTRAINT `fk_employee_shifts_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_employee_shifts_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_employee_shifts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
	}

	public function down()
	{
		$this->drop_tables(array('employee_shifts'));
	}
}
