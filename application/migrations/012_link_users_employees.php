<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** users.employee_id -> employees.id (one login per employee; employees.user_id is the other side, kept in step by Employees_model). */
class Migration_Link_users_employees extends Hrm_migration
{
	public function up()
	{
		if ( ! $this->index_exists('users', 'uq_users_employee')) {
			$this->run('ALTER TABLE users ADD UNIQUE KEY uq_users_employee (employee_id)');
		}
		if ( ! $this->fk_exists('users', 'fk_users_employee')) {
			$this->run('ALTER TABLE users ADD CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees (id) ON DELETE RESTRICT');
		}
	}

	public function down()
	{
		if ($this->fk_exists('users', 'fk_users_employee')) {
			$this->run('ALTER TABLE users DROP FOREIGN KEY fk_users_employee');
		}
		if ($this->index_exists('users', 'uq_users_employee')) {
			$this->run('ALTER TABLE users DROP INDEX uq_users_employee');
		}
	}
}
