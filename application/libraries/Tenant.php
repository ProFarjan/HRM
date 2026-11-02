<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * One database per client. Used by Cron (migrate, housekeeping, ...) and the test bootstrap to run something for
 * every configured tenant. Web requests never switch tenant: it is fixed by HTTP_HOST in config/database.php.
 */
class Tenant
{
    /** tenant databases to work on: TENANT_DB when set, otherwise every configured one */
    public static function names()
    {
        $forced = getenv('TENANT_DB');
        if ($forced !== FALSE && $forced !== '') {
            return array($forced);
        }
        return get_all_configured_database_names();
    }

    /** the schema version this code base expects ($config['migration_version'] in config/migration.php) */
    public static function latest_version()
    {
        $CI =& get_instance();
        $CI->config->load('migration', TRUE);
        $settings = $CI->config->item('migration');
        return (int) $settings['migration_version'];
    }

    /** schema version of a tenant (0 when it was never migrated) */
    public static function version($database)
    {
        $db = self::switch_to($database);
        $debug = $db->db_debug;
        $db->db_debug = FALSE;
        $query = $db->query('SELECT version FROM migrations LIMIT 1');
        $db->db_debug = $debug;
        $row = $query ? $query->row() : NULL;
        return $row ? (int) $row->version : 0;
    }

    /** make $database the current connection ($CI->db) and clear every per-tenant request cache */
    public static function switch_to($database)
    {
        $CI =& get_instance();
        $previous = isset($CI->db) ? $CI->db : NULL;
        $config = hrm_db_connection($database);
        $wanted_debug = $config['db_debug'];
        $config['db_debug'] = FALSE;                    // a tenant that cannot be reached is reported, not fatal
        $conn = $CI->load->database($config, TRUE);
        if ( ! is_object($conn) || ! $conn->conn_id) {
            throw new RuntimeException('Cannot connect to database ' . $database);
        }
        $conn->db_debug = $wanted_debug;
        $CI->db = $conn;
        if ($previous && $previous !== $conn && $previous->conn_id) {
            $previous->close();
        }
        set_current_user(NULL);
        hr_setting_reset_cache();
        return $conn;
    }

    /**
     * Migrate one tenant to $target (NULL = $config['migration_version']).
     * @return array array(bool $ok, string $message, int $from, int $to)
     */
    public static function migrate($database, $target = NULL)
    {
        $CI =& get_instance();
        $db = self::switch_to($database);
        $db->db_debug = FALSE;

        // The migrations table is created here (not by CI) so that it is explicitly InnoDB utf8mb4 like every other table.
        $db->query('CREATE TABLE IF NOT EXISTS migrations (version BIGINT(20) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        if ((int) $db->count_all('migrations') === 0) {
            $db->insert('migrations', array('version' => 0));
        }
        $from = (int) $db->get('migrations')->row()->version;

        require_once BASEPATH . 'libraries/Migration.php';
        $CI->config->load('migration', TRUE);
        $settings = $CI->config->item('migration');
        $migration = new CI_Migration($settings);
        $target = ($target === NULL) ? (int) $settings['migration_version'] : (int) $target;

        try {
            $result = $migration->version($target);
        } catch (Throwable $e) {
            return array(FALSE, $e->getMessage(), $from, (int) $db->get('migrations')->row()->version);
        }
        $to = (int) $db->get('migrations')->row()->version;
        if ($result === FALSE) {
            return array(FALSE, $migration->error_string(), $from, $to);
        }
        return array(TRUE, 'ok', $from, $to);
    }
}
