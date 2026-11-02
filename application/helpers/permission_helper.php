<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| has_permission($key): one query per request. The system Admin role (roles.is_system = 1) passes every known key.
| Never infer "manager" from holding some edit/delete permission (P14): grant the explicit key.
*/

if ( ! function_exists('permission_catalog_keys'))
{
	/** flat list of every key in config/permissions.php */
	function permission_catalog_keys()
	{
		static $keys = NULL;
		if ($keys === NULL)
		{
			$keys = array();
			foreach ((array) config_item('permission_catalog') as $group_keys)
			{
				foreach ($group_keys as $k)
				{
					$keys[$k] = TRUE;
				}
			}
		}
		return $keys;
	}
}

if ( ! function_exists('hrm_permission_cache'))
{
	function &hrm_permission_cache()
	{
		static $cache = array('role_id' => NULL, 'keys' => NULL);
		return $cache;
	}
}

if ( ! function_exists('has_permission_reset'))
{
	function has_permission_reset()
	{
		$cache =& hrm_permission_cache();
		$cache = array('role_id' => NULL, 'keys' => NULL);
	}
}

if ( ! function_exists('current_permission_keys'))
{
	/** every catalog key the current user holds (all of them for the system Admin role) */
	function current_permission_keys()
	{
		$out = array();
		foreach (array_keys(permission_catalog_keys()) as $key) {
			if (has_permission($key)) {
				$out[] = $key;
			}
		}
		return $out;
	}
}

if ( ! function_exists('has_permission'))
{
	function has_permission($key)
	{
		$user = current_user();
		if ( ! $user)
		{
			return FALSE;
		}
		$catalog = permission_catalog_keys();
		if ( ! is_string($key) || ! isset($catalog[$key]))
		{
			log_message('error', 'has_permission(): unknown permission key ' . (is_string($key) ? $key : gettype($key)));
			return FALSE;                                   // a typo never grants access, not even to Admin
		}
		if ( ! empty($user['is_system']))
		{
			return TRUE;
		}
		$cache =& hrm_permission_cache();
		if ($cache['keys'] === NULL || $cache['role_id'] !== (int) $user['role_id'])
		{
			// raw query on purpose: Query Builder's get() would reset a half-built select/where of the caller
			$rows = get_instance()->db->query('SELECT permission_key FROM role_permissions WHERE role_id = ?', array((int) $user['role_id']))->result_array();
			$cache['role_id'] = (int) $user['role_id'];
			$cache['keys'] = array();
			foreach ($rows as $r)
			{
				$cache['keys'][$r['permission_key']] = TRUE;
			}
		}
		return isset($cache['keys'][$key]);
	}
}
