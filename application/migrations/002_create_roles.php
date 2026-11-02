<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Create_roles extends Hrm_migration
{
	public function up()
	{
		$this->create_table('roles', <<<'SQL'
CREATE TABLE `roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_name` VARCHAR(80) NOT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = the Admin role: passes every permission check',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);

		// A role's grants die with the role (the one ON DELETE CASCADE in the schema; everything else is RESTRICT).
		$this->create_table('role_permissions', <<<'SQL'
CREATE TABLE `role_permissions` (
  `role_id` INT UNSIGNED NOT NULL,
  `permission_key` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`role_id`, `permission_key`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
	}

	public function down()
	{
		$this->drop_tables(array('role_permissions', 'roles'));
	}
}
