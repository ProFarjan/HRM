<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Fixed-window counters in `rate_limits` (device endpoints, P17). Runs outside any transaction: a counter that is rolled back
 * with a failed batch would let a flood through. `hit()` counts one event and returns the count inside the current window.
 */
class Rate_limiter
{
    /** @return int events counted in the current window, this one included */
    public static function hit($key, $window_seconds)
    {
        $db = get_instance()->db;
        $window_seconds = max(1, (int) $window_seconds);
        $start = date('Y-m-d H:i:s', intdiv(time(), $window_seconds) * $window_seconds);
        $key = strlen($key) > 190 ? hash('sha256', $key) : $key;
        $db->query('INSERT INTO rate_limits (`key`, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1', array($key, $start));
        $row = $db->query('SELECT hits FROM rate_limits WHERE `key` = ? AND window_start = ?', array($key, $start))->row_array();
        return $row ? (int) $row['hits'] : 1;
    }

    /** events counted so far in the current window, without counting a new one */
    public static function count($key, $window_seconds)
    {
        $db = get_instance()->db;
        $window_seconds = max(1, (int) $window_seconds);
        $start = date('Y-m-d H:i:s', intdiv(time(), $window_seconds) * $window_seconds);
        $key = strlen($key) > 190 ? hash('sha256', $key) : $key;
        $row = $db->query('SELECT hits FROM rate_limits WHERE `key` = ? AND window_start = ?', array($key, $start))->row_array();
        return $row ? (int) $row['hits'] : 0;
    }
}
