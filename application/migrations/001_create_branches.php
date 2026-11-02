<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Create_branches extends Hrm_migration
{
	public function up()
	{
		$this->create_table('branches', <<<'SQL'
CREATE TABLE `branches` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `address` VARCHAR(255) NULL,
  `phone` VARCHAR(30) NULL,
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Dhaka',
  `weekly_offs` VARCHAR(20) NULL COMMENT 'CSV of ISO weekdays 1-7; NULL = use the global setting',
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_branches_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);

		// First install needs one branch to attach users to. It is data, not code: nothing refers to its id.
		$this->run(
			"INSERT INTO branches (name, code, timezone, status, created_at)
			 SELECT 'Head Office', 'HO', 'Asia/Dhaka', 'Active', ? FROM DUAL
			 WHERE NOT EXISTS (SELECT 1 FROM branches)",
			array(date('Y-m-d H:i:s'))
		);
	}

	public function down()
	{
		$this->drop_tables(array('branches'));
	}
}
