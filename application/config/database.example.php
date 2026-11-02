<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| Copy this file to application/config/database.php (that copy is gitignored) and edit the host map.
|
| One database per client (tenant):
|   - web:  HTTP_HOST (port stripped, lower case) -> database, through get_database_domain_map()
|   - CLI:  TENANT_DB=<database>  selects one tenant; without it the first configured one is "current" and
|           Migrate / Cron loop over get_all_configured_database_names()
| An unknown host is refused (HTTP 404): there is no fallback tenant, so a request can never land in another client's data.
| Never hard-code a tenant or branch id anywhere else in the code (P3).
|
| Connection credentials come from the environment so the same file works on every machine:
|   HRM_DB_HOST (127.0.0.1)  HRM_DB_USER (root)  HRM_DB_PASS ('')  HRM_DB_PORT (3306)
*/

if ( ! function_exists('get_database_domain_map'))
{
	/** host name => database name. Edit this for your clients. */
	function get_database_domain_map()
	{
		return array(
			'localhost'       => 'hrm_dev',
			'127.0.0.1'       => 'hrm_dev',
			'hrm.example.com' => 'hrm_client_example',
		);
	}
}

if ( ! function_exists('get_all_configured_database_names'))
{
	/** every tenant database (what `php index.php migrate` and the cron jobs loop over) */
	function get_all_configured_database_names()
	{
		return array_values(array_unique(array_values(get_database_domain_map())));
	}
}

if ( ! function_exists('resolve_tenant_database'))
{
	function resolve_tenant_database()
	{
		$forced = getenv('TENANT_DB');           // operator-controlled; a browser cannot set environment variables
		if ($forced !== FALSE && $forced !== '')
		{
			return $forced;
		}
		if (PHP_SAPI === 'cli')
		{
			$all = get_all_configured_database_names();
			return $all ? $all[0] : '';
		}
		$host = isset($_SERVER['HTTP_HOST']) ? strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'])) : '';
		$map = get_database_domain_map();
		if ( ! isset($map[$host]))
		{
			if ( ! headers_sent())
			{
				header('HTTP/1.1 404 Not Found');
			}
			exit('Unknown host.');
		}
		return $map[$host];
	}
}

if ( ! function_exists('hrm_db_connection'))
{
	/** connection settings for one tenant database (also used by Tenant_migrator to open each tenant) */
	function hrm_db_connection($database)
	{
		return array(
			'dsn'          => '',
			'hostname'     => getenv('HRM_DB_HOST') ? getenv('HRM_DB_HOST') : '127.0.0.1',
			'username'     => getenv('HRM_DB_USER') ? getenv('HRM_DB_USER') : 'root',
			'password'     => getenv('HRM_DB_PASS') ? getenv('HRM_DB_PASS') : '',
			'port'         => getenv('HRM_DB_PORT') ? (int) getenv('HRM_DB_PORT') : 3306,
			'database'     => $database,
			'dbdriver'     => 'mysqli',
			'dbprefix'     => '',
			'pconnect'     => FALSE,
			'db_debug'     => (ENVIRONMENT === 'development'),
			'cache_on'     => FALSE,
			'cachedir'     => '',
			'char_set'     => 'utf8mb4',
			'dbcollat'     => 'utf8mb4_unicode_ci',
			'swap_pre'     => '',
			'encrypt'      => FALSE,
			'compress'     => FALSE,
			'stricton'     => TRUE,
			'failover'     => array(),
			'save_queries' => (ENVIRONMENT !== 'production'),
		);
	}
}

$active_group = 'default';
$query_builder = TRUE;

$db['default'] = hrm_db_connection(resolve_tenant_database());
