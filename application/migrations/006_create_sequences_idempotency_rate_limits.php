<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Create_sequences_idempotency_rate_limits extends Hrm_migration
{
	public function up()
	{
		// G4: `year` is the reset period (0 = never, 2026 = yearly, 202611 = monthly).
		$this->create_table('sequences', <<<'SQL'
CREATE TABLE `sequences` (
  `branch_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(40) NOT NULL,
  `year` INT UNSIGNED NOT NULL DEFAULT 0,
  `next_value` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`branch_id`, `name`, `year`),
  CONSTRAINT `fk_sequences_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);

		// G3: a double click or retry of a money form returns the first result.
		$this->create_table('idempotency_keys', <<<'SQL'
CREATE TABLE `idempotency_keys` (
  `token` VARCHAR(64) NOT NULL,
  `action` VARCHAR(60) NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `result_json` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`token`),
  KEY `idx_idempotency_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);

		// Fixed-window counters (device endpoints, build step 5). `key` is a reserved word: always quoted.
		$this->create_table('rate_limits', <<<'SQL'
CREATE TABLE `rate_limits` (
  `key` VARCHAR(190) NOT NULL,
  `window_start` DATETIME NOT NULL,
  `hits` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`key`, `window_start`),
  KEY `idx_rate_limits_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
	}

	public function down()
	{
		$this->drop_tables(array('rate_limits', 'idempotency_keys', 'sequences'));
	}
}
