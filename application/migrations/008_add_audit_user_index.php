<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Audit viewer filters by user within a date range: (user_id, created_at). */
class Migration_Add_audit_user_index extends Hrm_migration
{
	public function up()
	{
		if ( ! $this->index_exists('audit_log', 'idx_audit_user_time')) {
			$this->run('ALTER TABLE audit_log ADD INDEX idx_audit_user_time (user_id, created_at)');
		}
	}

	public function down()
	{
		if ($this->index_exists('audit_log', 'idx_audit_user_time')) {
			$this->run('ALTER TABLE audit_log DROP INDEX idx_audit_user_time');
		}
	}
}
