<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Base class of every application/migrations/NNN_name.php.
 *
 * Rules (CLAUDE.md "Migrations"): idempotent (guards against the live schema, not CI's cached table_exists()),
 * every table created `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` explicitly in the CREATE TABLE text, working down().
 * DDL auto-commits in MySQL, so a failed migration is repaired by fixing it and running it again (guards make that safe).
 */
abstract class Hrm_migration extends CI_Migration
{
    /** run SQL (with ? bindings); throws on failure so Tenant::migrate() reports it instead of half-finishing */
    protected function run($sql, $binds = FALSE)
    {
        $result = $this->db->query($sql, $binds);
        if ($result === FALSE) {
            $err = $this->db->error();
            throw new RuntimeException('Migration query failed: ' . $err['message']);
        }
        return $result;
    }

    protected function table_exists($table)
    {
        return $this->db->query('SHOW TABLES LIKE ?', array($table))->num_rows() > 0;
    }

    protected function column_exists($table, $column)
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            array($table, $column)
        )->num_rows() > 0;
    }

    protected function index_exists($table, $index)
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            array($table, $index)
        )->num_rows() > 0;
    }

    protected function fk_exists($table, $constraint)
    {
        return $this->db->query(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            array($table, $constraint)
        )->num_rows() > 0;
    }

    /** run the full CREATE TABLE statement unless the table already exists */
    protected function create_table($table, $create_sql)
    {
        if ($this->table_exists($table)) {
            return FALSE;
        }
        $this->run($create_sql);
        return TRUE;
    }

    /** drop tables (children first); foreign key checks are off so a partial schema can still be removed */
    protected function drop_tables(array $tables)
    {
        $this->run('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->dbforge->drop_table($table, TRUE);
        }
        $this->run('SET FOREIGN_KEY_CHECKS = 1');
    }
}
