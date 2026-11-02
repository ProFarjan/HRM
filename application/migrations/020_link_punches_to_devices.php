<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * attendance_punches.device_id becomes a real foreign key now that `devices` exists. Before step 5 nothing could write a
 * non-NULL device_id (only the demo seed did), so any value without a device row is cleared first; NULL never collides in the
 * unique key.
 */
class Migration_Link_punches_to_devices extends Hrm_migration
{
	public function up()
	{
		if ( ! $this->fk_exists('attendance_punches', 'fk_punches_device')) {
			$this->run('UPDATE `attendance_punches` SET `device_id` = NULL WHERE `device_id` IS NOT NULL AND `device_id` NOT IN (SELECT `id` FROM `devices`)');
			$this->run('ALTER TABLE `attendance_punches` ADD CONSTRAINT `fk_punches_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE RESTRICT');
		}
	}

	public function down()
	{
		if ($this->fk_exists('attendance_punches', 'fk_punches_device')) {
			$this->run('ALTER TABLE `attendance_punches` DROP FOREIGN KEY `fk_punches_device`');
		}
	}
}
