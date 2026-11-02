<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Seeds the system Admin role and one admin user.
 * Password: $HRM_ADMIN_PASSWORD when set, otherwise a random one printed ONCE on the console (and the user must change
 * it at first login). Username: $HRM_ADMIN_USER or "admin". Nothing secret is committed.
 */
class Migration_Seed_admin extends Hrm_migration
{
	public function up()
	{
		$now = date('Y-m-d H:i:s');
		$this->run(
			"INSERT INTO roles (role_name, is_system, created_at)
			 SELECT 'Admin', 1, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE is_system = 1)",
			array($now)
		);

		$username = $this->admin_username();
		if ($this->db->query('SELECT 1 FROM users WHERE username = ?', array($username))->num_rows() > 0) {
			return;                                              // idempotent: never overwrite an existing login
		}
		$role = $this->db->query('SELECT id FROM roles WHERE is_system = 1 ORDER BY id LIMIT 1')->row_array();
		$branch = $this->db->query('SELECT id FROM branches ORDER BY id LIMIT 1')->row_array();
		if ( ! $role || ! $branch) {
			throw new RuntimeException('Cannot seed the admin user: no system role or branch.');
		}

		$password = (string) getenv('HRM_ADMIN_PASSWORD');
		$generated = ($password === '');
		if ($generated) {
			$password = bin2hex(random_bytes(8));
		}
		$this->run(
			'INSERT INTO users (username, password_hash, employee_id, role_id, branch_id, status, must_change_password, created_at)
			 VALUES (?, ?, NULL, ?, ?, ?, ?, ?)',
			array($username, password_hash($password, PASSWORD_DEFAULT), (int) $role['id'], (int) $branch['id'], 'Active', $generated ? 1 : 0, $now)
		);

		if ($generated && is_cli()) {
			echo "\n================================================================\n"
				. " Admin user created. This password is shown ONCE:\n"
				. "   username: " . $username . "\n"
				. "   password: " . $password . "\n"
				. " You will be asked to change it at the first login.\n"
				. "================================================================\n\n";
		}
	}

	public function down()
	{
		$this->run(
			'DELETE u FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_system = 1 AND u.username = ?',
			array($this->admin_username())
		);
		$this->run(
			'DELETE FROM roles WHERE is_system = 1 AND NOT EXISTS (SELECT 1 FROM users WHERE users.role_id = roles.id)'
		);
	}

	protected function admin_username()
	{
		$name = strtolower((string) getenv('HRM_ADMIN_USER'));
		return preg_match('/^[a-z0-9._-]{3,64}$/', $name) ? $name : 'admin';
	}
}
