<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Attendance devices (build step 5): the registered devices, their hashed API keys (P17), unknown ADMS serial numbers waiting
 * for HR (devices_pending), the command queue and the call log. `devices.serial_number` is the ADMS SN (NULL for devices that
 * only talk through the Windows Service, which are matched by name); unique when set.
 */
class Migration_Create_devices extends Hrm_migration
{
	public function up()
	{
		$this->create_table('devices', <<<'SQL'
CREATE TABLE `devices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `serial_number` VARCHAR(40) NULL,
  `ip` VARCHAR(45) NULL,
  `port` SMALLINT UNSIGNED NOT NULL DEFAULT 4370,
  `protocol` ENUM('UDP','TCP') NOT NULL DEFAULT 'TCP',
  `comm_mode` ENUM('adms_push','windows_service') NOT NULL DEFAULT 'windows_service',
  `timezone` VARCHAR(64) NULL,
  `allowed_ips` VARCHAR(255) NULL,
  `last_ip` VARCHAR(45) NULL,
  `last_seen_at` DATETIME NULL,
  `last_punch_at` DATETIME NULL,
  `last_upload_at` DATETIME NULL,
  `clock_offset_seconds` INT NULL,
  `firmware` VARCHAR(60) NULL,
  `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `lost_punch_alert` TINYINT(1) NOT NULL DEFAULT 0,
  `health_checked_at` DATETIME NULL,
  `blocked_ip` VARCHAR(45) NULL,
  `blocked_at` DATETIME NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_devices_serial` (`serial_number`),
  UNIQUE KEY `uq_devices_branch_name` (`branch_id`, `name`),
  KEY `idx_devices_branch_status` (`branch_id`, `status`),
  CONSTRAINT `fk_devices_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_devices_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);

		// P17: only the sha256 of a key is stored; the key itself is shown once. A key belongs to a branch (and optionally one device).
		$this->create_table('device_keys', <<<'SQL'
CREATE TABLE `device_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `branch_id` INT UNSIGNED NOT NULL,
  `device_id` INT UNSIGNED NULL,
  `label` VARCHAR(100) NOT NULL,
  `key_hash` CHAR(64) NOT NULL,
  `key_hint` VARCHAR(8) NOT NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `last_used_at` DATETIME NULL,
  `revoked_at` DATETIME NULL,
  `revoked_by` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_keys_hash` (`key_hash`),
  KEY `idx_device_keys_branch` (`branch_id`, `revoked_at`),
  KEY `idx_device_keys_device` (`device_id`),
  CONSTRAINT `fk_device_keys_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_device_keys_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_device_keys_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_device_keys_revoked_by` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);

		// unknown ADMS serial numbers: capped at 50 rows in code, purged after 30 days; no punches are stored for them
		$this->create_table('devices_pending', <<<'SQL'
CREATE TABLE `devices_pending` (
  `serial_number` VARCHAR(40) NOT NULL,
  `ip` VARCHAR(45) NULL,
  `first_seen_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME NOT NULL,
  `hits` INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`serial_number`),
  KEY `idx_devices_pending_seen` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);

		$this->create_table('device_commands', <<<'SQL'
CREATE TABLE `device_commands` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id` INT UNSIGNED NOT NULL,
  `command_type` ENUM('create_user','update_user','delete_user','list_users','sync_time','clear_attlog','reboot') NOT NULL,
  `payload` LONGTEXT NULL,
  `status` ENUM('queued','sent','done','failed') NOT NULL DEFAULT 'queued',
  `result` LONGTEXT NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `sent_at` DATETIME NULL,
  `done_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_device_commands_queue` (`device_id`, `status`, `id`),
  CONSTRAINT `fk_device_commands_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_device_commands_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);

		// every device call (30 days, purged by cron housekeeping); device_id / branch_id are NULL for calls that failed authentication
		$this->create_table('device_logs', <<<'SQL'
CREATE TABLE `device_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id` INT UNSIGNED NULL,
  `branch_id` INT UNSIGNED NULL,
  `direction` ENUM('in','out') NOT NULL DEFAULT 'in',
  `endpoint` VARCHAR(60) NOT NULL,
  `http_code` SMALLINT UNSIGNED NOT NULL,
  `records` INT UNSIGNED NOT NULL DEFAULT 0,
  `ip` VARCHAR(45) NULL,
  `message` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_device_logs_created` (`created_at`),
  KEY `idx_device_logs_device` (`device_id`, `created_at`),
  KEY `idx_device_logs_branch` (`branch_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
	}

	public function down()
	{
		$this->drop_tables(array('device_logs', 'device_commands', 'devices_pending', 'device_keys', 'devices'));
	}
}
