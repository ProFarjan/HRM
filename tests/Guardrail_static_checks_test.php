<?php
require_once __DIR__ . '/static_checks.php';

/**
 * G8 - the static checks themselves are tested: the real tree is clean, and every rule fires on a tree that
 * breaks it (so a rule that silently stopped matching is noticed). Covers P3, P6, P7, P10, P11, P12, P13, P15, P18.
 */
class Guardrail_static_checks_test extends \PHPUnit\Framework\TestCase
{
    /** @var array */
    protected $dirs = array();

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->dirs = array();
    }

    protected function rrmdir($dir)
    {
        if ( ! is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /** write files (relative path under application/ => php source) into a scratch project and scan it */
    protected function scan(array $files)
    {
        $root = HRM_ROOT . '/tests/_output/scan_' . bin2hex(random_bytes(4));
        $this->dirs[] = $root;
        mkdir($root . '/application/config', 0777, TRUE);
        copy(HRM_ROOT . '/application/config/permissions.php', $root . '/application/config/permissions.php');
        foreach ($files as $path => $code) {
            $full = $root . '/application/' . $path;
            if ( ! is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, TRUE);
            }
            file_put_contents($full, "<?php\n" . $code);
        }
        $checker = new Static_checks($root);
        $rules = array();
        foreach ($checker->run() as $v) {
            $rules[] = $v[2];
        }
        return $rules;
    }

    protected function assertFlags($rule, array $files, $message = '')
    {
        $this->assertContains($rule, $this->scan($files), $message ?: "rule '$rule' should fire");
    }

    protected function assertClean(array $files, $message = '')
    {
        $this->assertSame(array(), $this->scan($files), $message ?: 'no rule should fire');
    }

    // ---------------------------------------------------------------------------------- the real tree

    public function test_real_tree_has_zero_violations()
    {
        $checker = new Static_checks(HRM_ROOT);
        $found = $checker->run();
        $lines = array();
        foreach ($found as $v) {
            $lines[] = "$v[0]:$v[1] [$v[2]] $v[3]";
        }
        $this->assertSame(array(), $lines, implode("\n", $lines));
    }

    public function test_cli_prints_zero_violations_and_exits_0()
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(HRM_ROOT . '/tests/static_checks.php') . ' 2>&1', $out, $code);
        $this->assertSame('0 violations', end($out));
        $this->assertSame(0, $code);
    }

    public function test_cli_exits_1_on_a_violation()
    {
        $this->scan(array());                                     // make a scratch dir path available
        $root = HRM_ROOT . '/tests/_output/scan_cli_' . bin2hex(random_bytes(3));
        $this->dirs[] = $root;
        mkdir($root . '/application/models', 0777, TRUE);
        mkdir($root . '/application/config', 0777, TRUE);
        copy(HRM_ROOT . '/application/config/permissions.php', $root . '/application/config/permissions.php');
        file_put_contents($root . '/application/models/Bad_model.php', "<?php\nextract(\$_POST);\n");
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(HRM_ROOT . '/tests/static_checks.php') . ' ' . escapeshellarg($root) . ' 2>&1', $out, $code);
        $this->assertSame('2 violations', end($out), 'extract() and \$_POST in a model');
        $this->assertSame(1, $code);
    }

    // ---------------------------------------------------------------------------------- P11: SQL and extract()

    public function test_extract_is_flagged()
    {
        $this->assertFlags('extract', array('models/A_model.php' => "class A_model { function f() { extract(\$_POST); } }"));
    }

    public function test_method_named_extract_is_not_flagged()
    {
        $this->assertClean(array('models/A_model.php' => "class A_model { function g(\$z) { return \$z->extract(1); } }"));
    }

    public function test_interpolated_and_concatenated_sql_is_flagged()
    {
        $this->assertFlags('sql-interpolation', array('models/A_model.php' => "class A_model { function f(\$id) { \$this->db->query(\"SELECT * FROM t WHERE id='\$id'\"); } }"));
        $this->assertFlags('sql-interpolation', array('models/A_model.php' => "class A_model { function f(\$id) { \$this->db->query('SELECT * FROM t WHERE id=' . \$id); } }"));
        $this->assertFlags('sql-interpolation', array('models/A_model.php' => "class A_model { function f(\$id) { \$this->db->where(\"id = \$id\"); } }"));
        $this->assertFlags('sql-interpolation', array('models/A_model.php' => "class A_model { function f(\$id) { \$this->db->where('id = ' . \$id); } }"));
        $this->assertFlags('sql-interpolation', array('models/A_model.php' => "class A_model { function f(\$id) { \$this->db->query(sprintf('SELECT %d', \$id)); } }"));
    }

    public function test_bound_sql_is_clean()
    {
        $this->assertClean(array('models/A_model.php' =>
            "class A_model { function f(\$id) {\n"
            . " \$this->db->query('SELECT * FROM t WHERE id = ? AND price > 0.5', array(\$id));\n"
            . " \$this->db->where('id', \$id); \$this->db->where(array('a' => 1)); \$this->db->where('x >=', \$id);\n"
            . " \$this->db->query('SELECT DATE(created_at) AS d FROM t');\n"
            . " \$this->db->query('SELECT a ' . 'FROM t WHERE id = ?', array(\$id)); } }"), 'a long literal split over lines is fine');
    }

    // ---------------------------------------------------------------------------------- P7, P6, P18: controllers

    public function test_controller_not_extending_my_controller_is_flagged()
    {
        $this->assertFlags('controller-base', array('controllers/Leave_requests.php' => "class Leave_requests extends CI_Controller { }"));
    }

    public function test_exempt_controllers_may_skip_my_controller()
    {
        foreach (array('Api', 'Iclock', 'Cron', 'Auth') as $name) {
            $this->assertNotContains('controller-base', $this->scan(array("controllers/$name.php" => "class $name extends CI_Controller { protected \$permission_map = array(); }")), $name);
        }
    }

    public function test_public_method_without_permission_map_entry_is_flagged()
    {
        $this->assertFlags('permission-map', array('controllers/Shifts.php' =>
            "class Shifts extends MY_Controller { protected \$permission_map = array('index' => 'shifts_view'); public function index() {} public function ajax_list() {} }"));
    }

    public function test_every_mapped_public_method_is_clean()
    {
        $this->assertClean(array('controllers/Shifts.php' =>
            "class Shifts extends MY_Controller { protected \$permission_map = array('index' => 'shifts_view', 'ajax_list' => 'shifts_view', 'save' => 'shifts_add');"
            . " public function index() {} public function ajax_list() {} public function save() {} protected function helper() {} private function other() {} }"));
    }

    public function test_unknown_permission_key_and_upper_case_names_are_flagged()
    {
        $this->assertFlags('permission-map', array('controllers/Shifts.php' =>
            "class Shifts extends MY_Controller { protected \$permission_map = array('index' => 'shifts_veiw'); public function index() {} }"));
        $this->assertFlags('permission-map', array('controllers/Shifts.php' =>
            "class Shifts extends MY_Controller { protected \$permission_map = array('doIt' => 'shifts_view'); public function doIt() {} }"));
    }

    public function test_self_is_only_allowed_in_ess_controllers()
    {
        $this->assertFlags('self-outside-ess', array('controllers/Leaves.php' =>
            "class Leaves extends MY_Controller { protected \$permission_map = array('mine' => '@self'); public function mine() {} }"));
        $this->assertClean(array('controllers/Ess.php' =>
            "class Ess extends Ess_Controller { protected \$permission_map = array('mine' => '@self'); public function mine() {} }"));
    }

    public function test_public_helper_in_base_controller_is_flagged()
    {
        $this->assertFlags('my-controller-public', array('core/MY_Controller.php' =>
            "abstract class MY_Controller extends CI_Controller { public function __construct() {} public function _remap(\$m) {} public function permission_check() {} protected function ok() {} }"));
        $this->assertClean(array('core/MY_Controller.php' =>
            "abstract class MY_Controller extends CI_Controller { public function __construct() {} public function _remap(\$m) {} protected function load_info() {} }"));
    }

    // ---------------------------------------------------------------------------------- P15, P10, storage, P12, P13, P3

    public function test_date_functions_in_where_are_flagged()
    {
        $this->assertFlags('date-fn-on-column', array('models/A_model.php' => "class A_model { function f() { \$this->db->where('MONTH(attendance_date) =', 5); } }"));
        $this->assertFlags('date-fn-on-column', array('models/A_model.php' => "class A_model { function f() { \$this->db->query('SELECT 1 FROM attendance WHERE YEAR(attendance_date) = ?', array(2026)); } }"));
        $this->assertFlags('date-fn-on-column', array('models/A_model.php' => "class A_model { function f() { \$this->db->query('SELECT 1 FROM a WHERE x = 1 AND DATE(created_at) = ?', array(1)); } }"));
    }

    public function test_rand_and_uniqid_are_flagged()
    {
        $this->assertFlags('rand-code', array('models/A_model.php' => "class A_model { function f() { return 'LV-' . rand(1000, 9999); } }"));
        $this->assertFlags('rand-code', array('models/A_model.php' => "class A_model { function f() { return mt_rand(); } }"));
        $this->assertFlags('rand-code', array('models/A_model.php' => "class A_model { function f() { return uniqid('P'); } }"));
        $this->assertClean(array('models/A_model.php' => "class A_model { function f() { return bin2hex(random_bytes(8)); } }"));
    }

    public function test_myisam_and_create_table_without_innodb_are_flagged()
    {
        $this->assertFlags('myisam-innodb', array('migrations/001_x.php' => "\$sql = 'CREATE TABLE t (id INT) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4';"));
        $this->assertFlags('myisam-innodb', array('migrations/001_x.php' => "\$sql = 'CREATE TABLE t (id INT)';"));
        $this->assertFlags('myisam-innodb', array('migrations/001_x.php' => "\$sql = 'CREATE TABLE t (id INT) ENGINE=InnoDB';"));
        $this->assertClean(array('migrations/001_x.php' => "\$sql = 'CREATE TABLE t (id INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';"));
    }

    public function test_legacy_table_names_are_flagged()
    {
        foreach (array('hr_advances', 'hr_payroll', 'db_users', 'db_employee_loans', 'vw_leave_summary') as $name) {
            $this->assertFlags('legacy-table', array('models/A_model.php' => "class A_model { function f() { return \$this->db->get('$name'); } }"), $name);
        }
        $this->assertFlags('legacy-table', array('models/A_model.php' => "class A_model { function f() { return \$this->db->query('SELECT * FROM db_users WHERE id = ?', array(1)); } }"));
        $this->assertClean(array('models/A_model.php' => "class A_model { function f() { return \$this->db->get('advances'); } }", 'config/database.php' => "\$db['default']['db_debug'] = TRUE;"));
    }

    public function test_magic_overtime_numbers_in_the_calculator_are_flagged()
    {
        $this->assertFlags('payroll-magic', array('libraries/Payroll_calculator.php' =>
            "class Payroll_calculator { function ot(\$basic, \$hours) {\n \$overtime_rate = \$basic / 208 * 1.5;\n return \$overtime_rate * \$hours; } }"));
        $this->assertClean(array('libraries/Payroll_calculator.php' =>
            "class Payroll_calculator { function ot(\$basic, \$hours) {\n \$overtime_rate = \$basic / hr_setting('ot_divisor_hours') * hr_setting('ot_multiplier');\n return \$overtime_rate * \$hours; } }"));
    }

    public function test_branch_read_from_the_request_in_device_code_is_flagged()
    {
        $this->assertFlags('branch-from-request', array('controllers/Api.php' => "class Api extends CI_Controller { function f(\$record) { return \$record['store_id']; } }"));
        $this->assertFlags('branch-from-request', array('controllers/Iclock.php' => "class Iclock extends CI_Controller { function f() { return \$this->input->post('branch_id'); } }"));
        $this->assertFlags('branch-from-request', array('libraries/Punch_ingestor.php' => "class Punch_ingestor { function f() { return \$_POST['branch_id']; } }"));
        $this->assertClean(array('libraries/Punch_ingestor.php' => "class Punch_ingestor { function f(\$device) { return \$device['branch_id']; } }"));
    }

    public function test_superglobals_in_models_are_flagged()
    {
        $this->assertFlags('superglobal-in-model', array('models/A_model.php' => "class A_model { function f() { return \$_POST['x']; } }"));
    }

    // ---------------------------------------------------------------------------------- PHP 7.4 .. 8.3

    public function php8_constructs()
    {
        return array(
            'match'               => array("class A { function f(\$x) { return match(\$x) { 1 => 'a', default => 'b' }; } }"),
            'nullsafe'            => array("class A { function f(\$x) { return \$x?->y; } }"),
            'str_contains'        => array("class A { function f(\$x) { return str_contains(\$x, 'a'); } }"),
            'str_starts_with'     => array("class A { function f(\$x) { return str_starts_with(\$x, 'a'); } }"),
            'typed property'      => array("class A { public int \$n = 1; }"),
            'typed protected'     => array("class A { protected ?string \$n = null; }"),
            'union type param'    => array("class A { function f(int|string \$x) {} }"),
            'union return type'   => array("class A { function f(\$x): int|string {} }"),
            'mixed param'         => array("class A { function f(mixed \$x) {} }"),
            'mixed return'        => array("class A { function f(\$x): mixed {} }"),
            'named argument'      => array("class A { function f() { return in_array(needle: 1, haystack: array(1)); } }"),
            'named argument 2'    => array("class A { function f() { return in_array(1, array(1), strict: true); } }"),
            'promotion'           => array("class A { function __construct(private \$x) {} }"),
            'enum'                => array("enum Suit { case Hearts; }"),
            'readonly property'   => array("class A { public readonly \$x; }"),
            'static return type'  => array("class A { function f(): static { return new static; } }"),
            'throw expression'    => array("class A { function f(\$x) { return \$x ?? throw new Exception('x'); } }"),
            'attribute'           => array("#[Attr]\nclass A { }"),
            'trailing param comma'=> array("class A { function f(\$a, \$b,) {} }"),
        );
    }

    /** @dataProvider php8_constructs */
    public function test_php8_only_syntax_is_flagged($code)
    {
        $this->assertFlags('php8-syntax', array('models/A_model.php' => $code));
    }

    public function test_php74_compatible_code_is_not_flagged()
    {
        $this->assertClean(array('models/A_model.php' => <<<'PHP'
class A_model extends MY_Model {
    const X = 1;
    /** @var int */
    protected $n = 1;
    public static $s = 2;
    public function match($x, $strict = FALSE) { return $this->match($x); }
    public function f(?array $a = null, int $b = 0): ?string {
        $y = $a ? self::X : A_model::X;
        $z = foo($a ? M : N, 1);
        switch ($b) { case X: break; default: break; }
        $fn = function ($q) use ($y) { return $q . $y; };
        $arrow = fn($q) => $q + 1;
        if ($b): $y = 1; else: $y = 2; endif;
        $o = new stdClass; $o->match = 1; $v = $o->match;
        return $this->str_contains_nothing ?? null;
    }
}
PHP
        ));
    }

    // ---------------------------------------------------------------------------------- runtime PHP 8.2

    public function test_undeclared_property_assignment_is_flagged()
    {
        $this->assertFlags('dynamic-property', array('libraries/Thing.php' => "class Thing { public function __construct() { \$this->cache = array(); } }"));
        $this->assertClean(array('libraries/Thing.php' => "class Thing { protected \$cache; public function __construct() { \$this->cache = array(); } }"));
    }
}
