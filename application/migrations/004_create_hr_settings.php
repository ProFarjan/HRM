<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** One row (id = 1). Column defaults equal config/hrm.php hrm_defaults (a test compares them). */
class Migration_Create_hr_settings extends Hrm_migration
{
	public function up()
	{
		$this->create_table('hr_settings', <<<'SQL'
CREATE TABLE `hr_settings` (
  `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `company_name` VARCHAR(150) NOT NULL DEFAULT '',
  `logo` VARCHAR(255) NOT NULL DEFAULT '',
  `address` VARCHAR(255) NOT NULL DEFAULT '',
  `phone` VARCHAR(30) NOT NULL DEFAULT '',
  `email` VARCHAR(120) NOT NULL DEFAULT '',
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Dhaka',
  `date_format` VARCHAR(20) NOT NULL DEFAULT 'd-m-Y',
  `currency_symbol` VARCHAR(10) NOT NULL DEFAULT '৳',
  `currency_position` ENUM('left','right') NOT NULL DEFAULT 'left',
  `decimals` TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `weekly_offs` VARCHAR(20) NOT NULL DEFAULT '5',
  `default_shift_id` INT UNSIGNED NULL,
  `deduct_break` TINYINT(1) NOT NULL DEFAULT 0,
  `ot_min_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `early_leave_counts_as_late` TINYINT(1) NOT NULL DEFAULT 0,
  `punch_debounce_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `device_gap_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  `overtime_source` ENUM('attendance','approved_requests') NOT NULL DEFAULT 'attendance',
  `overtime_basis` ENUM('basic_salary','gross_salary') NOT NULL DEFAULT 'basic_salary',
  `ot_divisor_hours` DECIMAL(6,2) NOT NULL DEFAULT 208.00,
  `ot_multiplier` DECIMAL(5,2) NOT NULL DEFAULT 1.50,
  `overtime_mode` ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  `late_days_per_absent` SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  `late_deduction_mode` ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  `absent_deduction_basis` ENUM('basic_salary','gross_salary') NOT NULL DEFAULT 'gross_salary',
  `absent_deduction_mode` ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  `allow_negative_net` TINYINT(1) NOT NULL DEFAULT 0,
  `allow_negative_account` TINYINT(1) NOT NULL DEFAULT 0,
  `self_approve_leave` TINYINT(1) NOT NULL DEFAULT 0,
  `payslip_footer` VARCHAR(500) NOT NULL DEFAULT '',
  `payslip_show_attendance` TINYINT(1) NOT NULL DEFAULT 1,
  `notify_leave_sms` TINYINT(1) NOT NULL DEFAULT 0,
  `notify_payslip_email` TINYINT(1) NOT NULL DEFAULT 0,
  `notify_absent_sms` TINYINT(1) NOT NULL DEFAULT 0,
  `idle_logout_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NULL,
  `updated_by` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_hr_settings_single_row` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);

		$this->run('INSERT INTO hr_settings (id) SELECT 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM hr_settings WHERE id = 1)');
	}

	public function down()
	{
		$this->drop_tables(array('hr_settings'));
	}
}
