<?php
/**
 * Guardrail scan (CLAUDE.md G8). Usage:  php tests/static_checks.php [project-root]
 * Prints one line per violation and a final "N violations"; exit status 1 when N > 0.
 * Also run by tests/Guardrail_static_checks_test.php and by the git pre-commit hook (tools/git-hooks/pre-commit).
 *
 * Plain PHP (tokenizer based, no CodeIgniter), so it runs before anything else works. Written for PHP 7.4+.
 * Scans <root>/application only; vendor/ and system/ are never looked at.
 *
 * rule id            catches
 * extract            extract(...) anywhere                                                   P11
 * sql-interpolation  ->query()/->where() SQL built by interpolation or concatenation          P11
 * controller-base    controller not extending MY_Controller/Ess_Controller (except Api, Iclock, Cron, Auth)   P7
 * permission-map     public controller method without a $permission_map key; unknown key/token; upper case name   P6, P18
 * self-outside-ess   '@self' in a controller that does not extend Ess_Controller              G1
 * my-controller-public  public function in core base controllers other than __construct/_remap   P18
 * date-fn-on-column  MONTH( YEAR( DATE( in a WHERE                                            P15
 * rand-code          rand( mt_rand( uniqid(                                                   P10
 * myisam-innodb      MyISAM, or CREATE TABLE without ENGINE=InnoDB and utf8mb4                storage
 * legacy-table       legacy pos-retails table / view names                                    P12
 * payroll-magic      literal 208 / 1.5 near overtime in libraries/Payroll_calculator.php      P13
 * branch-from-request  branch_id/store_id read from the request or a record in Api/Iclock/Punch_ingestor   P3
 * superglobal-in-model  $_POST/$_GET/$_REQUEST inside models/ (use Input_validator)           G2
 * php8-syntax        match, ?->, str_contains & co, typed properties, union types, named arguments, enums...  runtime PHP 7.4
 * dynamic-property   $this->x = ... where x is not declared (PHP 8.2 deprecation)             runtime PHP 8.2+
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

class Static_checks
{
    /** @var string */
    protected $root;
    /** @var array list of array(file, line, rule, message) */
    protected $violations = array();
    /** @var array name => true */
    protected $catalog = array();
    /** @var array token => true */
    protected $tokens_allowed = array();
    /** @var array class name => array of declared property names (parsed from application/ files) */
    protected $class_props = array();
    /** @var array class name => parent class name */
    protected $class_parent = array();

    const EXEMPT_CONTROLLERS = 'Api,Iclock,Cron,Auth';

    public function __construct($root)
    {
        $this->root = rtrim($root, '/\\');
    }

    public function run()
    {
        $this->load_permission_config();
        $files = $this->php_files($this->root . '/application');
        $parsed = array();
        foreach ($files as $file) {
            $code = file_get_contents($file);
            $tokens = token_get_all($code);
            $parsed[$file] = array($code, $tokens);
            $this->collect_classes($file, $tokens);
        }
        foreach ($parsed as $file => $pair) {
            $rel = $this->rel($file);
            list($code, $tokens) = $pair;
            $this->check_extract($rel, $tokens);
            $this->check_sql($rel, $tokens);
            $this->check_rand($rel, $tokens);
            $this->check_tables($rel, $tokens);
            $this->check_legacy($rel, $tokens);
            $this->check_php8($rel, $tokens, $code);
            $this->check_dynamic_properties($rel, $tokens);
            if (strpos($rel, 'application/controllers/') === 0) {
                $this->check_controller($rel, $tokens);
            }
            if (preg_match('#^application/core/(MY_Controller|Ess_Controller|Api_Controller)\.php$#', $rel)) {
                $this->check_base_controller_public($rel, $tokens);
            }
            if (strpos($rel, 'application/models/') === 0) {
                $this->check_model_superglobals($rel, $tokens);
            }
            if ($rel === 'application/libraries/Payroll_calculator.php') {
                $this->check_payroll_magic($rel, $code);
            }
            if (preg_match('#^application/(controllers/(Api|Iclock)|libraries/Punch_ingestor|core/Api_Controller)\.php$#', $rel)) {
                $this->check_branch_from_request($rel, $code);
            }
        }
        return $this->violations;
    }

    public function report()
    {
        $violations = $this->run();
        usort($violations, function ($a, $b) {
            return strcmp($a[0] . sprintf('%06d', $a[1]), $b[0] . sprintf('%06d', $b[1]));
        });
        foreach ($violations as $v) {
            echo sprintf("%s:%d [%s] %s\n", $v[0], $v[1], $v[2], $v[3]);
        }
        echo count($violations) . " violations\n";
        return count($violations);
    }

    // ------------------------------------------------------------------------------------------------ plumbing

    protected function add($file, $line, $rule, $message)
    {
        $this->violations[] = array($file, $line, $rule, $message);
    }

    protected function rel($file)
    {
        return ltrim(str_replace('\\', '/', substr($file, strlen($this->root))), '/');
    }

    protected function php_files($dir)
    {
        $out = array();
        if ( ! is_dir($dir)) {
            return $out;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && substr($f->getFilename(), -4) === '.php' && strpos(str_replace('\\', '/', $f->getPathname()), '/third_party/') === FALSE) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    protected function load_permission_config()
    {
        if ( ! defined('BASEPATH')) {
            define('BASEPATH', $this->root . '/system/');
        }
        $config = array();
        $file = $this->root . '/application/config/permissions.php';
        if (is_file($file)) {
            include $file;
        }
        if (isset($config['permission_catalog'])) {
            foreach ($config['permission_catalog'] as $keys) {
                foreach ($keys as $k) {
                    $this->catalog[$k] = TRUE;
                }
            }
        }
        $tokens = isset($config['permission_tokens']) ? $config['permission_tokens'] : array('@any', '@self', '@public', '@login', '@cli', '@device');
        foreach ($tokens as $t) {
            $this->tokens_allowed[$t] = TRUE;
        }
    }

    /** significant tokens only (no whitespace/comments), each as array(id|char, text, line) */
    protected function sig(array $tokens)
    {
        $line = 1;
        $result = array();
        foreach ($tokens as $t) {
            if (is_array($t)) {
                $line = $t[2];
                if ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    $line += substr_count($t[1], "\n");
                    continue;
                }
                $result[] = array($t[0], $t[1], $line);
                $line += substr_count($t[1], "\n");
            } else {
                $result[] = array($t, $t, $line);
            }
        }
        return $result;
    }

    /** index of the token that closes the bracket opened at $i, or -1 */
    protected function matching(array $sig, $i)
    {
        $open = $sig[$i][1];
        $close = array('(' => ')', '[' => ']', '{' => '}');
        if ( ! isset($close[$open])) {
            return -1;
        }
        $depth = 0;
        for ($n = count($sig), $j = $i; $j < $n; $j++) {
            $text = $sig[$j][1];
            if ($sig[$j][0] === T_CURLY_OPEN || $sig[$j][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $text = '{';
            }
            if ($text === $open) {
                $depth++;
            } elseif ($text === $close[$open]) {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }
        return -1;
    }

    protected function is_string_tok($t)
    {
        return in_array($t[0], array(T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE), TRUE);
    }

    protected function unquote($text)
    {
        $q = $text[0];
        if (($q === "'" || $q === '"') && substr($text, -1) === $q) {
            return substr($text, 1, -1);
        }
        return $text;
    }

    // ------------------------------------------------------------------------------------------------ rules

    protected function check_extract($rel, array $tokens)
    {
        $sig = $this->sig($tokens);
        foreach ($sig as $i => $t) {
            if ($t[0] === T_STRING && strtolower($t[1]) === 'extract' && isset($sig[$i + 1]) && $sig[$i + 1][1] === '('
                && ! ($i > 0 && in_array($sig[$i - 1][0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), TRUE))) {
                $this->add($rel, $t[2], 'extract', 'extract() is forbidden: read request values through Input_validator');
            }
        }
    }

    /** argument tokens of the call whose "(" is at $open: array of arg token lists */
    protected function call_args(array $sig, $open)
    {
        $close = $this->matching($sig, $open);
        if ($close < 0) {
            return array(array(), -1);
        }
        $args = array();
        $cur = array();
        $depth = 0;
        for ($j = $open + 1; $j < $close; $j++) {
            $text = $sig[$j][1];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
            }
            if ($text === ',' && $depth === 0) {
                $args[] = $cur;
                $cur = array();
                continue;
            }
            $cur[] = $sig[$j];
        }
        if ($cur) {
            $args[] = $cur;
        }
        return array($args, $close);
    }

    /** does this argument build a SQL string by interpolation / concatenation / sprintf? */
    protected function arg_is_dynamic_sql(array $arg)
    {
        $has_concat = FALSE;
        $only_literals = TRUE;
        foreach ($arg as $t) {
            if ($t[0] === T_STRING && strtolower($t[1]) === 'sprintf') {
                return TRUE;
            }
            if ($t[1] === '.' && $t[0] !== T_DNUMBER) {
                $has_concat = TRUE;
            } elseif ($t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                $only_literals = FALSE;
            }
        }
        // "'SELECT ...' . 'FROM ...'" (literals only) is just a long string; anything else joined with "." is not
        if ($has_concat && ! $only_literals) {
            return TRUE;
        }
        // double-quoted string with interpolation is tokenised as '"' ... T_VARIABLE ... '"'
        $in_quote = FALSE;
        foreach ($arg as $t) {
            if ($t[1] === '"') {
                $in_quote = ! $in_quote;
            } elseif ($in_quote && in_array($t[0], array(T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), TRUE)) {
                return TRUE;
            }
            if ($t[0] === T_START_HEREDOC && strpos($t[1], "'") === FALSE) {
                $in_quote = TRUE;
            }
            if ($t[0] === T_END_HEREDOC) {
                $in_quote = FALSE;
            }
        }
        return FALSE;
    }

    /** text of the string literals in an argument (used for the date-function rule) */
    protected function arg_text(array $arg)
    {
        $text = '';
        foreach ($arg as $t) {
            if ($this->is_string_tok($t)) {
                $text .= ' ' . $t[1];
            }
        }
        return $text;
    }

    protected function check_sql($rel, array $tokens)
    {
        $sig = $this->sig($tokens);
        $where_like = array('where', 'or_where', 'having', 'or_having', 'where_in', 'order_by');
        foreach ($sig as $i => $t) {
            if ($t[0] !== T_STRING || $i === 0 || $sig[$i - 1][0] !== T_OBJECT_OPERATOR || ! isset($sig[$i + 1]) || $sig[$i + 1][1] !== '(') {
                continue;
            }
            $name = strtolower($t[1]);
            if ($name !== 'query' && ! in_array($name, $where_like, TRUE)) {
                continue;
            }
            list($args) = $this->call_args($sig, $i + 1);
            if ( ! $args) {
                continue;
            }
            if ($name === 'query') {
                if ($this->arg_is_dynamic_sql($args[0])) {
                    $this->add($rel, $t[2], 'sql-interpolation', '->query() SQL is built with $ interpolation or concatenation: use ? bindings');
                }
                if (strpos($rel, '/migrations/') === FALSE) {
                    $sql = $this->arg_text($args[0]);
                    if (preg_match('/\bWHERE\b(.*)$/is', $sql, $m) && preg_match('/\b(MONTH|YEAR|DATE)\s*\(/i', $m[1])) {
                        $this->add($rel, $t[2], 'date-fn-on-column', 'MONTH()/YEAR()/DATE() in a WHERE defeats the index: use BETWEEN on the date column (month_range())');
                    }
                }
            } else {
                // where('raw sql ' . $x) with a single dynamic string argument
                if (count($args) === 1 && $this->arg_is_dynamic_sql($args[0]) && $name !== 'order_by') {
                    $this->add($rel, $t[2], 'sql-interpolation', '->' . $name . '() takes a string built with $ interpolation or concatenation: use the array form or bindings');
                }
                if (strpos($rel, '/migrations/') === FALSE && $name !== 'order_by' && preg_match('/\b(MONTH|YEAR|DATE)\s*\(/i', $this->arg_text($args[0]))) {
                    $this->add($rel, $t[2], 'date-fn-on-column', 'MONTH()/YEAR()/DATE() in a WHERE defeats the index: use BETWEEN on the date column (month_range())');
                }
            }
        }
    }

    protected function check_rand($rel, array $tokens)
    {
        $sig = $this->sig($tokens);
        foreach ($sig as $i => $t) {
            if ($t[0] === T_STRING && in_array(strtolower($t[1]), array('rand', 'mt_rand', 'uniqid'), TRUE)
                && isset($sig[$i + 1]) && $sig[$i + 1][1] === '('
                && ! ($i > 0 && in_array($sig[$i - 1][0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), TRUE))) {
                $this->add($rel, $t[2], 'rand-code', $t[1] . '() is forbidden: codes come from next_code() (sequences), random names from random_bytes()');
            }
        }
    }

    protected function check_tables($rel, array $tokens)
    {
        foreach ($tokens as $t) {
            if ( ! is_array($t) || ! in_array($t[0], array(T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE), TRUE)) {
                continue;
            }
            $text = $t[1];
            if (preg_match('/MyISAM/i', $text)) {
                $this->add($rel, $t[2], 'myisam-innodb', 'MyISAM is forbidden: every table is InnoDB');
            }
            if (preg_match('/\bCREATE\s+(TEMPORARY\s+)?TABLE\b/i', $text)
                && ( ! preg_match('/ENGINE\s*=\s*InnoDB/i', $text) || ! preg_match('/CHARSET\s*=\s*utf8mb4/i', $text))) {
                $this->add($rel, $t[2], 'myisam-innodb', 'CREATE TABLE must say ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 explicitly');
            }
        }
    }

    protected function check_legacy($rel, array $tokens)
    {
        if (preg_match('#^application/config/database(\.example)?\.php$#', $rel)) {
            return;
        }
        $pattern = '/(?<![A-Za-z0-9_])(hr_advances|hr_advance_repayments|hr_allowances|hr_payroll|db_[a-z][a-z0-9_]*|vw_[a-z][a-z0-9_]*)(?![A-Za-z0-9_])/';
        foreach ($tokens as $t) {
            if ( ! is_array($t) || ! in_array($t[0], array(T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE), TRUE)) {
                continue;
            }
            if (preg_match_all($pattern, $t[1], $m)) {
                foreach ($m[1] as $name) {
                    if ($name === 'db_debug') {
                        continue;
                    }
                    $this->add($rel, $t[2], 'legacy-table', "legacy pos-retails table/view name '$name' (P12): one table per concept, see the domain model");
                }
            }
        }
    }

    protected function check_payroll_magic($rel, $code)
    {
        $lines = preg_split('/\R/', $code);
        foreach ($lines as $n => $line) {
            $window = '';
            for ($k = max(0, $n - 2); $k <= min(count($lines) - 1, $n + 2); $k++) {
                $window .= ' ' . $lines[$k];
            }
            $code_line = preg_replace('#//.*$|/\*.*?\*/#', '', $line);
            if (preg_match('/(?<![\d.])(208|1\.5)(?![\d])/', $code_line) && preg_match('/overtime|\bot_|\$ot\b/i', $window)) {
                $this->add($rel, $n + 1, 'payroll-magic', 'literal 208 or 1.5 near overtime: use settings ot_divisor_hours / ot_multiplier (P13)');
            }
        }
    }

    protected function check_branch_from_request($rel, $code)
    {
        $allowed_vars = '(device|key|key_row|auth|branch_row)';
        $lines = preg_split('/\R/', $code);
        foreach ($lines as $n => $line) {
            $l = preg_replace('#^\s*(//|\*|/\*).*$#', '', $line);
            if (preg_match('/\$_(POST|GET|REQUEST)\b[^;]*\b(branch_id|store_id)\b/', $l)
                || preg_match('/->(post|get|get_post|input_stream)\(\s*[\'"](branch_id|store_id)[\'"]/', $l)
                || preg_match('/\$(?!' . $allowed_vars . '\b)[A-Za-z_][A-Za-z0-9_]*\[\s*[\'"](branch_id|store_id)[\'"]\s*\]/', $l)) {
                $this->add($rel, $n + 1, 'branch-from-request', 'branch_id/store_id must come from the authenticated key / device row, never from the request or a record (P3)');
            }
        }
    }

    protected function check_model_superglobals($rel, array $tokens)
    {
        foreach ($tokens as $t) {
            if (is_array($t) && $t[0] === T_VARIABLE && in_array($t[1], array('$_POST', '$_GET', '$_REQUEST'), TRUE)) {
                $this->add($rel, $t[2], 'superglobal-in-model', $t[1] . ' in a model: read request values through Input_validator');
            }
        }
    }

    protected function check_base_controller_public($rel, array $tokens)
    {
        $sig = $this->sig($tokens);
        foreach ($sig as $i => $t) {
            if ($t[0] !== T_FUNCTION || ! isset($sig[$i + 1])) {
                continue;
            }
            $name = $sig[$i + 1][0] === T_STRING ? $sig[$i + 1][1] : ($sig[$i + 1][1] === '&' && isset($sig[$i + 2]) ? $sig[$i + 2][1] : '');
            if ($name === '' || $this->visibility_before($sig, $i) !== 'public') {
                continue;
            }
            if ( ! in_array($name, array('__construct', '_remap'), TRUE)) {
                $this->add($rel, $t[2], 'my-controller-public', "public function $name() in a base controller is a routable URL (P18): make it protected");
            }
        }
    }

    /** visibility keyword in front of "function" at $i (defaults to public when none is written) */
    protected function visibility_before(array $sig, $i)
    {
        $vis = 'public';
        for ($k = $i - 1; $k >= 0; $k--) {
            if (in_array($sig[$k][0], array(T_PUBLIC, T_PROTECTED, T_PRIVATE), TRUE)) {
                return strtolower($sig[$k][1]);
            }
            if ( ! in_array($sig[$k][0], array(T_STATIC, T_ABSTRACT, T_FINAL), TRUE)) {
                break;
            }
        }
        return $vis;
    }

    protected function check_controller($rel, array $tokens)
    {
        $sig = $this->sig($tokens);
        $exempt = explode(',', self::EXEMPT_CONTROLLERS);
        $class = NULL;
        $parent = NULL;
        $class_line = 1;
        $body_start = -1;
        foreach ($sig as $i => $t) {
            if ($t[0] === T_CLASS && isset($sig[$i + 1]) && $sig[$i + 1][0] === T_STRING && ($i === 0 || $sig[$i - 1][0] !== T_DOUBLE_COLON)) {
                $class = $sig[$i + 1][1];
                $class_line = $t[2];
                if (isset($sig[$i + 2]) && $sig[$i + 2][0] === T_EXTENDS && isset($sig[$i + 3])) {
                    $parent = $sig[$i + 3][1];
                }
                for ($j = $i; $j < count($sig); $j++) {
                    if ($sig[$j][1] === '{') {
                        $body_start = $j;
                        break;
                    }
                }
                break;
            }
        }
        if ($class === NULL) {
            return;
        }
        $is_exempt = in_array($class, $exempt, TRUE);
        $extends_ess = ($parent === 'Ess_Controller');
        if ( ! $is_exempt && ! in_array($parent, array('MY_Controller', 'Ess_Controller'), TRUE)) {
            $this->add($rel, $class_line, 'controller-base', "controller $class must extend MY_Controller (or Ess_Controller); only Api, Iclock, Cron, Auth may not (P7)");
        }
        if ($class === 'Ess' && ! $extends_ess) {
            $this->add($rel, $class_line, 'controller-base', 'Ess must extend Ess_Controller');
        }
        $end = $this->matching($sig, $body_start);
        if ($body_start < 0 || $end < 0) {
            return;
        }

        // $permission_map keys and values
        $map = array();
        for ($i = $body_start; $i < $end; $i++) {
            if ($sig[$i][0] === T_VARIABLE && $sig[$i][1] === '$permission_map') {
                $j = $i + 1;
                if (isset($sig[$j + 1]) && $sig[$j][1] === '=' && ($sig[$j + 1][0] === T_ARRAY || $sig[$j + 1][1] === '[')) {
                    $open = $sig[$j + 1][1] === '[' ? $j + 1 : $j + 2;
                    $close = $this->matching($sig, $open);
                    for ($k = $open + 1; $k < $close; $k++) {
                        if ($sig[$k][0] === T_DOUBLE_ARROW && $this->is_string_tok($sig[$k - 1]) && isset($sig[$k + 1]) && $this->is_string_tok($sig[$k + 1])) {
                            $map[$this->unquote($sig[$k - 1][1])] = array($this->unquote($sig[$k + 1][1]), $sig[$k][2]);
                        }
                    }
                }
                break;
            }
        }
        foreach ($map as $method => $info) {
            list($value, $line) = $info;
            if ($method !== strtolower($method)) {
                $this->add($rel, $line, 'permission-map', "permission_map key '$method' must be lower case (the router lower-cases method names)");
            }
            if ($value === '@self') {
                if ( ! $extends_ess) {
                    $this->add($rel, $line, 'self-outside-ess', "'@self' is only allowed in controllers extending Ess_Controller");
                }
            } elseif ($value[0] === '@') {
                if ( ! isset($this->tokens_allowed[$value])) {
                    $this->add($rel, $line, 'permission-map', "unknown token '$value' in permission_map");
                }
            } elseif ( ! isset($this->catalog[$value])) {
                $this->add($rel, $line, 'permission-map', "permission key '$value' is not in config/permissions.php");
            }
        }

        // every public method needs a map entry
        $depth = 0;
        for ($i = $body_start + 1; $i < $end; $i++) {
            $text = $sig[$i][1];
            if ($sig[$i][0] === T_CURLY_OPEN || $sig[$i][0] === T_DOLLAR_OPEN_CURLY_BRACES || $text === '{') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            }
            if ($depth === 0 && $sig[$i][0] === T_FUNCTION && isset($sig[$i + 1])) {
                $k = $i + 1;
                if ($sig[$k][1] === '&') {
                    $k++;
                }
                if ($sig[$k][0] !== T_STRING) {
                    continue;
                }
                $name = $sig[$k][1];
                if ($this->visibility_before($sig, $i) !== 'public' || $name === '__construct' || $name === '_remap' || $name[0] === '_') {
                    continue;
                }
                if ( ! array_key_exists($name, $map)) {
                    $this->add($rel, $sig[$i][2], 'permission-map', "public method $class::$name() has no key in \$permission_map (P6/P18): add it or make the method protected");
                }
                if ($name !== strtolower($name)) {
                    $this->add($rel, $sig[$i][2], 'permission-map', "public method $class::$name() must have a lower case name");
                }
            }
        }
    }

    /** syntax that PHP 7.4 cannot parse or that changed meaning; the code base must run on 7.4 - 8.3 */
    protected function check_php8($rel, array $tokens, $code)
    {
        $sig = $this->sig($tokens);
        $banned_functions = array('str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list', 'get_debug_type', 'get_resource_id', 'fdiv', 'preg_last_error_msg', 'enum_exists', 'iterator_apply_', 'array_find', 'array_any', 'array_all');
        $type_words = array('mixed', 'never', 'null', 'false', 'true');
        foreach ($tokens as $raw) {
            if (is_array($raw) && $raw[0] === T_COMMENT && strpos($raw[1], '#[') === 0) {
                $this->add($rel, $raw[2], 'php8-syntax', 'attributes #[...] need PHP 8.0');
            }
        }
        $n = count($sig);
        for ($i = 0; $i < $n; $i++) {
            $t = $sig[$i];
            $prev = $i > 0 ? $sig[$i - 1] : array(0, '', 0);
            $next = $i + 1 < $n ? $sig[$i + 1] : array(0, '', 0);
            $after_member = in_array($prev[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), TRUE) || $prev[1] === '?->';

            if ($t[0] === T_STRING || (defined('T_MATCH') && $t[0] === T_MATCH) || (defined('T_ENUM') && $t[0] === T_ENUM)) {
                $lower = strtolower($t[1]);
                if ($lower === 'match' && $next[1] === '(' && ! $after_member && $prev[0] !== T_CLASS) {
                    $this->add($rel, $t[2], 'php8-syntax', 'match expression needs PHP 8.0: use switch or if/else');
                }
                if (in_array($lower, $banned_functions, TRUE) && $next[1] === '(' && ! $after_member) {
                    $this->add($rel, $t[2], 'php8-syntax', $t[1] . '() needs PHP 8: use strpos()/substr()');
                }
                if ($lower === 'enum' && $prev[0] !== T_OBJECT_OPERATOR && $prev[0] !== T_DOUBLE_COLON && $next[0] === T_STRING && in_array($sig[min($i + 2, $n - 1)][1], array('{', ':'), TRUE)) {
                    $this->add($rel, $t[2], 'php8-syntax', 'enum needs PHP 8.1');
                }
            }
            // nullsafe operator
            if ($t[1] === '?->' || ($t[1] === '?' && $next[0] === T_OBJECT_OPERATOR)) {
                $this->add($rel, $t[2], 'php8-syntax', 'nullsafe operator ?-> needs PHP 8.0');
            }
            // attributes #[...] are comments on 7.x but do something on 8: forbid
            if ($t[1] === '#[' || (defined('T_ATTRIBUTE') && $t[0] === T_ATTRIBUTE)) {
                $this->add($rel, $t[2], 'php8-syntax', 'attributes #[...] need PHP 8.0');
            }
            // typed / union-typed properties and constructor promotion
            if (in_array($t[0], array(T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR), TRUE)) {
                $k = $i + 1;
                while ($k < $n && in_array($sig[$k][0], array(T_STATIC, T_FINAL), TRUE)) {
                    $k++;
                }
                if ($k < $n && ! in_array($sig[$k][0], array(T_VARIABLE, T_FUNCTION, T_CONST, T_ABSTRACT, T_FINAL, T_STATIC), TRUE)) {
                    $word = strtolower($sig[$k][1]);
                    if ($word === 'readonly') {
                        $this->add($rel, $t[2], 'php8-syntax', 'readonly properties need PHP 8.1');
                    } else {
                        $this->add($rel, $t[2], 'php8-syntax', 'typed properties need PHP 7.4 only partly and union types need 8.0: declare properties untyped with a @var docblock');
                    }
                }
            }
            // union / intersection / mixed types in parameters and return types
            if ($t[0] === T_FUNCTION) {
                $k = $i + 1;
                if ($sig[$k][1] === '&') {
                    $k++;
                }
                if (isset($sig[$k + 1]) && $sig[$k + 1][1] === '(') {
                    $close = $this->matching($sig, $k + 1);
                    $paren_depth = 0;
                    for ($j = $k + 2; $j < $close; $j++) {
                        $x = $sig[$j];
                        if ($x[1] === '(' || $x[1] === '[') { $paren_depth++; }
                        if ($x[1] === ')' || $x[1] === ']') { $paren_depth--; }
                        if ($paren_depth === 0 && $x[1] === '|') {
                            $this->add($rel, $x[2], 'php8-syntax', 'union types need PHP 8.0');
                        }
                        if ($paren_depth === 0 && $x[0] === T_STRING && in_array(strtolower($x[1]), array('mixed', 'never'), TRUE)
                            && isset($sig[$j + 1]) && in_array($sig[$j + 1][0], array(T_VARIABLE, T_ELLIPSIS), TRUE) && $sig[$j - 1][0] !== T_DOUBLE_COLON) {
                            $this->add($rel, $x[2], 'php8-syntax', $x[1] . ' type needs PHP 8');
                        }
                        // constructor promotion: visibility keyword inside the parameter list
                        if ($paren_depth === 0 && in_array($x[0], array(T_PUBLIC, T_PROTECTED, T_PRIVATE), TRUE)) {
                            $this->add($rel, $x[2], 'php8-syntax', 'constructor property promotion needs PHP 8.0');
                        }
                        // trailing comma after the last parameter
                        if ($j === $close - 1 && $x[1] === ',') {
                            $this->add($rel, $x[2], 'php8-syntax', 'trailing comma in a parameter list needs PHP 8.0');
                        }
                    }
                    // return type: ): A|B  /  ): mixed / static
                    if (isset($sig[$close + 1]) && $sig[$close + 1][1] === ':') {
                        for ($j = $close + 2; $j < $n && ! in_array($sig[$j][1], array('{', ';'), TRUE); $j++) {
                            if ($sig[$j][1] === '|') {
                                $this->add($rel, $sig[$j][2], 'php8-syntax', 'union return types need PHP 8.0');
                            }
                            if ($sig[$j][0] === T_STRING && in_array(strtolower($sig[$j][1]), array('mixed', 'never'), TRUE)) {
                                $this->add($rel, $sig[$j][2], 'php8-syntax', $sig[$j][1] . ' return type needs PHP 8');
                            }
                            if ($sig[$j][0] === T_STATIC) {
                                $this->add($rel, $sig[$j][2], 'php8-syntax', 'static return type needs PHP 8.0');
                            }
                        }
                    }
                }
            }
            // named arguments:  name(arg, label: value)  /  name(label: value)
            if ($t[1] === ':' && $t[0] === ':' && $prev[0] === T_STRING && $i >= 2 && in_array($sig[$i - 2][1], array('(', ','), TRUE)
                && $this->inside_call_parens($sig, $i)) {
                $this->add($rel, $t[2], 'php8-syntax', 'named arguments need PHP 8.0');
            }
            // new in initializers / first-class callable syntax f(...)
            if ($t[0] === T_ELLIPSIS && $next[1] === ')' && $prev[1] === '(') {
                $this->add($rel, $t[2], 'php8-syntax', 'first-class callable syntax needs PHP 8.1');
            }
            // 0o octal prefix, never keyword etc. are rare; throw expression:  ?? throw
            if ($t[0] === T_THROW && in_array($prev[1], array('??', '?', ':', '||', '&&', 'or', 'and', '=>'), TRUE)) {
                $this->add($rel, $t[2], 'php8-syntax', 'throw as an expression needs PHP 8.0');
            }
        }
    }

    /** is the ":" at $i inside call parentheses (not a ternary, switch case, alternative syntax or return type)? */
    protected function inside_call_parens(array $sig, $i)
    {
        // walk back to the enclosing "(" and see what it follows
        $depth = 0;
        for ($k = $i - 1; $k >= 0; $k--) {
            $x = $sig[$k][1];
            if ($x === ')' || $x === ']' || $x === '}') {
                $depth++;
            } elseif ($x === '(' || $x === '[' || $x === '{') {
                if ($depth === 0) {
                    if ($x !== '(' || $k === 0) {
                        return FALSE;
                    }
                    $before = $sig[$k - 1];
                    // a function/method/class call: identifier or ")" in front; control structures are not calls
                    if (in_array($before[0], array(T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_CATCH, T_DECLARE), TRUE)) {
                        return FALSE;
                    }
                    return $before[0] === T_STRING || $before[0] === T_VARIABLE || $before[1] === ')' || $before[0] === T_NEW;
                }
                $depth--;
            } elseif ($x === ';') {
                return FALSE;
            }
        }
        return FALSE;
    }

    // ------------------------------------------------------------------------------------------------ dynamic properties

    protected function collect_classes($file, array $tokens)
    {
        $sig = $this->sig($tokens);
        $n = count($sig);
        for ($i = 0; $i < $n; $i++) {
            if (($sig[$i][0] === T_CLASS || $sig[$i][0] === T_TRAIT) && isset($sig[$i + 1]) && $sig[$i + 1][0] === T_STRING && ($i === 0 || $sig[$i - 1][0] !== T_DOUBLE_COLON)) {
                $name = $sig[$i + 1][1];
                $this->class_props[$name] = isset($this->class_props[$name]) ? $this->class_props[$name] : array();
                if (isset($sig[$i + 2]) && $sig[$i + 2][0] === T_EXTENDS) {
                    $this->class_parent[$name] = $sig[$i + 3][1];
                }
                $open = $i;
                while ($open < $n && $sig[$open][1] !== '{') { $open++; }
                $close = $this->matching($sig, $open);
                $depth = 0;
                for ($j = $open + 1; $j < $close; $j++) {
                    $text = $sig[$j][1];
                    if ($text === '{' || $sig[$j][0] === T_CURLY_OPEN || $sig[$j][0] === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; }
                    elseif ($text === '}') { $depth--; }
                    if ($depth === 0 && $sig[$j][0] === T_VARIABLE && $j > 0 && in_array($sig[$j - 1][0], array(T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_STATIC), TRUE)) {
                        $this->class_props[$name][ltrim($sig[$j][1], '$')] = TRUE;
                    }
                    // use TraitName;  (trait properties count)
                    if ($depth === 0 && $sig[$j][0] === T_USE && isset($sig[$j + 1]) && $sig[$j + 1][0] === T_STRING) {
                        $this->class_props[$name]['__uses__'][] = $sig[$j + 1][1];
                    }
                }
            }
        }
    }

    protected function declared_props($class)
    {
        $props = array();
        $guard = 0;
        while ($class !== NULL && $guard++ < 20) {
            if (isset($this->class_props[$class])) {
                foreach ($this->class_props[$class] as $k => $v) {
                    if ($k === '__uses__') {
                        foreach ($v as $trait) {
                            $props = array_merge($props, $this->declared_props($trait));
                        }
                    } else {
                        $props[$k] = TRUE;
                    }
                }
            }
            $class = isset($this->class_parent[$class]) ? $this->class_parent[$class] : NULL;
        }
        return $props;
    }

    protected function check_dynamic_properties($rel, array $tokens)
    {
        // CI_Controller / CI_Model give controllers and models these through __get / their constructor
        $ci_provided = array('load', 'input', 'db', 'session', 'config', 'output', 'uri', 'lang', 'security', 'router', 'utf8',
            'benchmark', 'hooks', 'dbforge', 'dbutil', 'email', 'form_validation', 'upload', 'pagination', 'cache', 'migration', 'unit', 'zip');
        $sig = $this->sig($tokens);
        $n = count($sig);
        $class = NULL;
        $class_end = -1;
        for ($i = 0; $i < $n; $i++) {
            if ($sig[$i][0] === T_CLASS && isset($sig[$i + 1]) && $sig[$i + 1][0] === T_STRING && ($i === 0 || $sig[$i - 1][0] !== T_DOUBLE_COLON)) {
                $class = $sig[$i + 1][1];
                $open = $i;
                while ($open < $n && $sig[$open][1] !== '{') { $open++; }
                $class_end = $this->matching($sig, $open);
            }
            if ($class === NULL || $i > $class_end) {
                continue;
            }
            $is_ci_model = $this->extends_ci($class, array('CI_Model', 'CI_Controller', 'MY_Model', 'MY_Controller'));
            if ($sig[$i][0] === T_VARIABLE && $sig[$i][1] === '$this' && isset($sig[$i + 3])
                && $sig[$i + 1][0] === T_OBJECT_OPERATOR && $sig[$i + 2][0] === T_STRING && $sig[$i + 3][1] === '=' ) {
                $prop = $sig[$i + 2][1];
                $declared = $this->declared_props($class);
                if ( ! isset($declared[$prop]) && ! ($is_ci_model && in_array($prop, $ci_provided, TRUE))) {
                    $this->add($rel, $sig[$i][2], 'dynamic-property', "\$this->$prop is assigned but not declared in $class (dynamic property, PHP 8.2 deprecation): declare it");
                }
            }
        }
    }

    protected function extends_ci($class, array $roots)
    {
        $guard = 0;
        while ($class !== NULL && $guard++ < 20) {
            if (in_array($class, $roots, TRUE)) {
                return TRUE;
            }
            $class = isset($this->class_parent[$class]) ? $this->class_parent[$class] : NULL;
        }
        return FALSE;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $root = isset($argv[1]) ? $argv[1] : dirname(__DIR__);
    $checker = new Static_checks($root);
    exit($checker->report() > 0 ? 1 : 0);
}
