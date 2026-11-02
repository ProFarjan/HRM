<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Split shifts: a shift may have several duty periods in one day (10:00-14:00 and 18:00-22:00).
 *  - shifts.is_split = 1 when the shift has more than one period; shifts.start_time / end_time stay the first start and the last end,
 *    working_hours is the sum of the periods (the gap between them is not duty time), break_duration is 0.
 *  - shift_segments: the periods of a split shift (UNIQUE (shift_id, segment_no)).
 *  - attendance_segments: what an employee did in each period of a day (in, out, worked / late / early minutes). Deleted with the
 *    attendance row (ON DELETE CASCADE: the day is rebuilt or removed as a whole).
 */
class Migration_Create_split_shifts extends Hrm_migration
{
	public function up()
	{
		if ( ! $this->column_exists('shifts', 'is_split')) {
			$this->run('ALTER TABLE `shifts` ADD COLUMN `is_split` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_night`');
		}
		$this->create_table('shift_segments', <<<'SQL'
CREATE TABLE `shift_segments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shift_id` INT UNSIGNED NOT NULL,
  `segment_no` TINYINT UNSIGNED NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shift_segments_no` (`shift_id`, `segment_no`),
  CONSTRAINT `fk_shift_segments_shift` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
		$this->create_table('attendance_segments', <<<'SQL'
CREATE TABLE `attendance_segments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attendance_id` BIGINT UNSIGNED NOT NULL,
  `segment_no` TINYINT UNSIGNED NOT NULL,
  `check_in` TIME NULL,
  `check_out` TIME NULL,
  `worked_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `late_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `early_leave_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_segments_no` (`attendance_id`, `segment_no`),
  CONSTRAINT `fk_attendance_segments_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
		);
	}

	public function down()
	{
		$this->drop_tables(array('attendance_segments', 'shift_segments'));
		if ($this->column_exists('shifts', 'is_split')) {
			$this->run('ALTER TABLE `shifts` DROP COLUMN `is_split`');
		}
	}
}
