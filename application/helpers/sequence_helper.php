<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| Sequential business codes (G4): LV-2026-0001, LN-0001, PS-202611-0001, ADV-0001.
| Never rand(), never MAX(id)+1. Call inside the caller's Tx::run(): concurrent callers serialise on the
| sequences row lock until the caller commits, so numbers are unique and (because a rollback also rolls the counter
| back) gap-free.
*/

if ( ! function_exists('next_sequence_value'))
{
	/** next integer for (branch, name, period); period 0 = never resets, otherwise year (2026) or year+month (202611) */
	function next_sequence_value($name, $period = 0, $branch_id = NULL)
	{
		$db = get_instance()->db;
		$branch_id = $branch_id === NULL ? current_branch_id() : (int) $branch_id;
		if ( ! $branch_id || ! preg_match('/^[a-z][a-z0-9_]{0,39}$/', (string) $name))
		{
			throw new InvalidArgumentException('next_sequence_value(): branch and a valid sequence name are required');
		}
		$period = (int) $period;
		// One atomic statement. The older "INSERT IGNORE ... 0" followed by "UPDATE" deadlocks when several processes meet a
		// brand-new row: each waiter is granted a shared lock by the INSERT IGNORE and then all ask for an exclusive one.
		// ON DUPLICATE KEY UPDATE takes the exclusive lock straight away, so callers queue instead of deadlocking.
		$db->query(
			'INSERT INTO sequences (branch_id, name, year, next_value) VALUES (?, ?, ?, LAST_INSERT_ID(1))'
			. ' ON DUPLICATE KEY UPDATE next_value = LAST_INSERT_ID(next_value + 1)',
			array($branch_id, $name, $period)
		);
		$row = $db->query('SELECT LAST_INSERT_ID() AS v')->row_array();
		return (int) $row['v'];
	}
}

if ( ! function_exists('next_code'))
{
	/** formatted code for a name configured in config/hrm.php $config['sequences'] */
	function next_code($name, $branch_id = NULL, $date = NULL)
	{
		$formats = (array) config_item('sequences');
		if ( ! isset($formats[$name]))
		{
			throw new InvalidArgumentException('next_code(): unknown sequence ' . $name);
		}
		$format = $formats[$name];
		$ts = $date === NULL ? time() : strtotime($date);
		$period = 0;
		if (strpos($format, '{Ym}') !== FALSE)
		{
			$period = (int) date('Ym', $ts);
		}
		elseif (strpos($format, '{Y}') !== FALSE)
		{
			$period = (int) date('Y', $ts);
		}
		$n = next_sequence_value($name, $period, $branch_id);
		$pad = max(1, (int) config_item('sequence_pad'));
		return str_replace(
			array('{Ym}', '{Y}', '{n}'),
			array(date('Ym', $ts), date('Y', $ts), str_pad((string) $n, $pad, '0', STR_PAD_LEFT)),
			$format
		);
	}
}
