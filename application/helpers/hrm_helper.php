<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| HRM helper: request context, settings, dates, money, escaping.
| Everything here is procedural on purpose so models, libraries, views and cron can use it without a controller.
*/

// ---------------------------------------------------------------------------------------------
// Output escaping (P11): text is stored as entered and escaped on output only.
// ---------------------------------------------------------------------------------------------
if ( ! function_exists('e'))
{
	function e($value)
	{
		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
	}
}

if ( ! function_exists('t'))
{
	/** Language line, or the key itself when the line is missing (so a gap shows up on the page instead of a blank). */
	function t($key)
	{
		$line = get_instance()->lang->line($key, FALSE);
		return ($line === FALSE || $line === '') ? $key : $line;
	}
}

// ---------------------------------------------------------------------------------------------
// Request context: the logged-in user and the branch every query is scoped to.
// Web: filled by MY_Controller::load_global(). Device endpoints: set from the authenticated key / device row.
// ---------------------------------------------------------------------------------------------
if ( ! function_exists('hrm_context'))
{
	function &hrm_context()
	{
		static $ctx = array('user' => NULL, 'branch_id' => NULL);
		return $ctx;
	}
}

if ( ! function_exists('set_current_user'))
{
	/** array(id, username, role_id, is_system, branch_id, employee_id, ...) or NULL to clear. Also sets the branch. */
	function set_current_user($user)
	{
		$ctx =& hrm_context();
		$ctx['user'] = $user;
		$ctx['branch_id'] = is_array($user) && isset($user['branch_id']) ? (int) $user['branch_id'] : NULL;
		if (function_exists('has_permission_reset'))
		{
			has_permission_reset();
		}
	}
}

if ( ! function_exists('current_user'))
{
	function current_user()
	{
		$ctx =& hrm_context();
		return $ctx['user'];
	}
}

if ( ! function_exists('current_user_id'))
{
	function current_user_id()
	{
		$u = current_user();
		return $u ? (int) $u['id'] : NULL;
	}
}

if ( ! function_exists('current_employee_id'))
{
	function current_employee_id()
	{
		$u = current_user();
		return ($u && !empty($u['employee_id'])) ? (int) $u['employee_id'] : NULL;
	}
}

if ( ! function_exists('current_branch_id'))
{
	function current_branch_id()
	{
		$ctx =& hrm_context();
		return $ctx['branch_id'];
	}
}

if ( ! function_exists('set_current_branch_id'))
{
	/** Device endpoints only: the branch comes from the authenticated key / device, never from the request body (P3). */
	function set_current_branch_id($branch_id)
	{
		$ctx =& hrm_context();
		$ctx['branch_id'] = $branch_id === NULL ? NULL : (int) $branch_id;
	}
}

if ( ! function_exists('client_ip'))
{
	function client_ip()
	{
		return get_instance()->input->ip_address();
	}
}

// ---------------------------------------------------------------------------------------------
// Settings: hr_settings row id 1, read once per request, defaults from config/hrm.php.
// ---------------------------------------------------------------------------------------------
if ( ! function_exists('hrm_settings_row'))
{
	function hrm_settings_row($reset = FALSE)
	{
		static $row = NULL;
		if ($reset)
		{
			$row = NULL;
			return array();
		}
		if ($row === NULL)
		{
			$row = array();
			$db = get_instance()->db;
			$debug = $db->db_debug;
			$db->db_debug = FALSE;                       // an un-migrated tenant has no table yet: fall back to the defaults
			// raw query on purpose: Query Builder's get() would reset a half-built select/where of the caller
			$query = $db->query('SELECT * FROM hr_settings WHERE id = 1');
			$db->db_debug = $debug;
			if ($query && $query->num_rows() > 0)
			{
				$row = $query->row_array();
			}
		}
		return $row;
	}
}

if ( ! function_exists('hr_setting'))
{
	function hr_setting($key, $default = NULL)
	{
		$row = hrm_settings_row();
		if (isset($row[$key]) && $row[$key] !== NULL)
		{
			return $row[$key];
		}
		$defaults = config_item('hrm_defaults');
		if (is_array($defaults) && array_key_exists($key, $defaults) && $defaults[$key] !== NULL)
		{
			return $defaults[$key];
		}
		return $default;
	}
}

if ( ! function_exists('hr_setting_reset_cache'))
{
	function hr_setting_reset_cache()
	{
		hrm_settings_row(TRUE);
	}
}

// ---------------------------------------------------------------------------------------------
// Dates. Stored in the branch's local time; business dates come from PHP, never from MySQL NOW()/CURDATE().
// ---------------------------------------------------------------------------------------------
if ( ! function_exists('hrm_date_format'))
{
	function hrm_date_format()
	{
		$format = (string) hr_setting('date_format', 'd-m-Y');
		return preg_match('/^[dmYjnyMF][-\/. ][dmYjnyMF][-\/. ][dmYjnyMF]$/', $format) ? $format : 'd-m-Y';
	}
}

if ( ! function_exists('parse_strict_date'))
{
	/** strict parse of $value with $format; returns 'Y-m-d' or FALSE (31-02-2026 is refused, not rolled over) */
	function parse_strict_date($value, $format)
	{
		$dt = DateTime::createFromFormat('!' . $format, $value);
		if ( ! $dt || $dt->format($format) !== $value)
		{
			return FALSE;
		}
		return $dt->format('Y-m-d');
	}
}

if ( ! function_exists('to_db_date'))
{
	/** UI format (setting date_format) or Y-m-d  ->  'Y-m-d'; FALSE when it is not a real date */
	function to_db_date($value)
	{
		$value = trim((string) $value);
		if ($value === '')
		{
			return FALSE;
		}
		$date = parse_strict_date($value, 'Y-m-d');
		return $date !== FALSE ? $date : parse_strict_date($value, hrm_date_format());
	}
}

if ( ! function_exists('show_date'))
{
	/** 'Y-m-d' (or a DATETIME) -> UI format; empty / zero dates give '' */
	function show_date($db_date)
	{
		$db_date = substr(trim((string) $db_date), 0, 10);
		if ($db_date === '' || $db_date === '0000-00-00')
		{
			return '';
		}
		$dt = DateTime::createFromFormat('!Y-m-d', $db_date);
		return $dt ? $dt->format(hrm_date_format()) : '';
	}
}

if ( ! function_exists('today_local'))
{
	function today_local()
	{
		return date('Y-m-d');            // default timezone = branch timezone (set by load_global)
	}
}

if ( ! function_exists('now_local'))
{
	function now_local()
	{
		return date('Y-m-d H:i:s');
	}
}

if ( ! function_exists('month_range'))
{
	/** first and last day of a month, for `col BETWEEN ? AND ?` (never MONTH(col)/YEAR(col) in a WHERE, G5) */
	function month_range($month, $year)
	{
		$month = (int) $month;
		$year = (int) $year;
		if ($month < 1 || $month > 12 || $year < 1900 || $year > 2999)
		{
			throw new InvalidArgumentException('Invalid month/year');
		}
		$from = sprintf('%04d-%02d-01', $year, $month);
		return array('from' => $from, 'to' => date('Y-m-t', strtotime($from)), 'days' => (int) date('t', strtotime($from)));
	}
}

// ---------------------------------------------------------------------------------------------
// Money: DECIMAL(14,2); round at every persisted step; compare with a tolerance, never with ==.
// ---------------------------------------------------------------------------------------------
if ( ! function_exists('money_round'))
{
	function money_round($amount)
	{
		return round((float) $amount, 2);
	}
}

if ( ! function_exists('money_equal'))
{
	function money_equal($a, $b)
	{
		return abs((float) $a - (float) $b) < 0.005;
	}
}

if ( ! function_exists('money_fmt'))
{
	/** display string using the currency settings, e.g. ৳ 12,500.00 */
	function money_fmt($amount)
	{
		$decimals = max(0, min(4, (int) hr_setting('decimals', 2)));
		$text = number_format((float) $amount, $decimals, '.', ',');
		$symbol = (string) hr_setting('currency_symbol', '');
		if ($symbol === '')
		{
			return $text;
		}
		return hr_setting('currency_position', 'left') === 'right' ? $text . ' ' . $symbol : $symbol . ' ' . $text;
	}
}

// ---------------------------------------------------------------------------------------------
// Misc
// ---------------------------------------------------------------------------------------------
if ( ! function_exists('hrm_uuid'))
{
	/** random v4 uuid (request tokens); never used to build business codes - those come from sequences */
	function hrm_uuid()
	{
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
	}
}
