<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Create_audit_log extends Hrm_migration
{
	public function up()
	{
		// user_id has no foreign key on purpose: the trail must outlive the user. old/new values are JSON text
		// (LONGTEXT: portable between MySQL 5.7 and MariaDB 10.3).
		$this->create_table('audit_log', <<<'SQL'
CREATE TABLE `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` INT UNSIGNED NULL,
  `user_id` INT UNSIGNED NULL,
  `module` VARCHAR(40) NOT NULL,
  `record_id` VARCHAR(40) NULL,
  `action` VARCHAR(40) NOT NULL,
  `old_values` LONGTEXT NULL,
  `new_values` LONGTEXT NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_module_record` (`module`, `record_id`),
  KEY `idx_audit_branch_time` (`branch_id`, `created_at`),
  CONSTRAINT `fk_audit_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
	}

	public function down()
	{
		$this->drop_tables(array('audit_log'));
	}
}
