<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Server-side DataTables 1.10 request (G2, G5): the only place the draw/start/length/search/order parameters are read.
 *
 *   $dt = new Datatable_request($this->input, array('u.username', 'r.role_name', 'u.status'));   // index => SQL column (whitelist)
 *   ... apply filters; if ($dt->search !== '') { group_start()->like(...)->or_like(...)->group_end(); }
 *   $dt->apply($this->db);                                  // ORDER BY whitelisted column + clamped LIMIT
 *   return $dt->response($total, $filtered, $rows);
 *
 * The sort column comes from the whitelist by index (never from the request text), the page size is clamped to
 * $max_length (the screen cap, G5), the search text is limited to 100 characters.
 */
class Datatable_request
{
    /** @var int */
    public $draw = 0;
    /** @var int */
    public $start = 0;
    /** @var int */
    public $length = 25;
    /** @var string */
    public $search = '';
    /** @var string whitelisted SQL column */
    public $order_by = '';
    /** @var string asc|desc */
    public $order_dir = 'asc';
    /** @var array */
    protected $columns = array();

    public function __construct($input, array $columns, $default_column = 0, $default_dir = 'asc', $max_length = 1000)
    {
        $this->columns = array_values($columns);
        $this->draw = $this->int_value($input->post('draw'), 0, 0, PHP_INT_MAX);
        $this->start = $this->int_value($input->post('start'), 0, 0, 10000000);
        $length = $this->int_value($input->post('length'), 25, -1, PHP_INT_MAX);
        $this->length = ($length < 1 || $length > $max_length) ? (int) $max_length : $length;

        $search = $input->post('search');
        $text = (is_array($search) && isset($search['value']) && is_string($search['value'])) ? $search['value'] : '';
        $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text);
        $this->search = trim(mb_substr((string) $text, 0, 100, 'UTF-8'));

        $index = (int) $default_column;
        $dir = $default_dir === 'desc' ? 'desc' : 'asc';
        $order = $input->post('order');
        if (is_array($order) && isset($order[0]) && is_array($order[0])) {
            $col = isset($order[0]['column']) ? $order[0]['column'] : NULL;
            if (is_string($col) && ctype_digit($col) && isset($this->columns[(int) $col]) && $this->columns[(int) $col] !== NULL) {
                $index = (int) $col;
            }
            if (isset($order[0]['dir']) && in_array($order[0]['dir'], array('asc', 'desc'), TRUE)) {
                $dir = $order[0]['dir'];
            }
        }
        if ( ! isset($this->columns[$index]) || $this->columns[$index] === NULL) {
            $index = 0;
        }
        $this->order_by = $this->columns[$index];
        $this->order_dir = $dir;
    }

    /** ORDER BY + LIMIT on the Query Builder */
    public function apply($db)
    {
        $db->order_by($this->order_by, $this->order_dir);
        $db->limit($this->length, $this->start);
        return $db;
    }

    public function response($total, $filtered, array $rows)
    {
        return array('draw' => $this->draw, 'recordsTotal' => (int) $total, 'recordsFiltered' => (int) $filtered, 'data' => $rows);
    }

    protected function int_value($raw, $default, $min, $max)
    {
        if ( ! is_string($raw) || ! preg_match('/^-?\d{1,9}$/', $raw)) {
            return $default;
        }
        return max($min, min($max, (int) $raw));
    }
}
