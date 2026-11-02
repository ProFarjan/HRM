<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** `php index.php cron housekeeping`: purge short-lived technical rows of the current tenant. */
class Housekeeping_model extends MY_Model
{
    /**
     * @return array table => rows deleted
     */
    public function purge()
    {
        $deleted = array();
        $deleted['idempotency_keys'] = $this->delete_before('idempotency_keys', 'created_at', 7);
        $deleted['login_attempts'] = $this->delete_before('login_attempts', 'attempted_at', 2);
        $deleted['rate_limits'] = $this->delete_before('rate_limits', 'window_start', 1);
        $deleted['device_logs'] = $this->table_present('device_logs') ? $this->delete_before('device_logs', 'created_at', 30) : 0;
        $deleted['devices_pending'] = $this->table_present('devices_pending') ? $this->delete_before('devices_pending', 'last_seen_at', 30) : 0;
        return $deleted;
    }

    /** table is whitelisted by the calling method; $days is an int */
    protected function delete_before($table, $column, $days)
    {
        $cutoff = date('Y-m-d H:i:s', time() - ((int) $days) * 86400);
        $this->db->where($column . ' <', $cutoff)->delete($table);
        return (int) $this->db->affected_rows();
    }

    protected function table_present($table)
    {
        return $this->db->query('SHOW TABLES LIKE ?', array($table))->num_rows() > 0;
    }
}
