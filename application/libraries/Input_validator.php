<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * G2 - typed request input. Models read request values only through this class (never $_POST, never extract()).
 *
 *   $v = new Input_validator($this->input);              // POST; new Input_validator($this->input, 'get') for list filters
 *   $employee_id = $v->ref('employee_id', 'employees');  // exists, same branch, inside the user's own-records scope
 *   $from        = $v->date('from_date');                // UI format or Y-m-d  ->  'Y-m-d'
 *   $type        = $v->enum('payment_type', array('Cash', 'Bank', 'Mobile Banking'));
 *   $amount      = $v->money('amount', 0.01, 99999999.99);
 *   $reason      = $v->text('reason', 255, FALSE);       // optional: NULL when empty
 *   if ( ! $v->ok()) { return $v->message(); }           // all errors at once, one per line
 *
 * Every getter records an error and returns NULL when the value is missing (and required) or invalid; an optional
 * value that is absent also returns NULL. Text is returned as entered (trimmed): it is escaped on output with e(),
 * never HTML-escaped here (P11). Anything that looks like an HTML tag is refused: user HTML is not allowed.
 */
class Input_validator
{
    /** @var CI_Input */
    protected $input;
    /** @var string post|get */
    protected $source = 'post';
    /** @var array field => message */
    protected $errors = array();

    public function __construct($input, $source = 'post')
    {
        $this->input = $input;
        $this->source = ($source === 'get') ? 'get' : 'post';
    }

    // ---------------------------------------------------------------------------------------- results

    public function ok()
    {
        return count($this->errors) === 0;
    }

    /** all errors, one per line */
    public function message()
    {
        return implode("\n", array_values($this->errors));
    }

    public function errors()
    {
        return $this->errors;
    }

    /** record an error for a field (first error per field wins) */
    public function fail($name, $message)
    {
        if ( ! isset($this->errors[$name])) {
            $this->errors[$name] = $message;
        }
        return NULL;
    }

    // ---------------------------------------------------------------------------------------- getters

    /** integer within [$min, $max] */
    public function int($name, $min = NULL, $max = NULL, $required = TRUE)
    {
        $raw = $this->scalar($name, $required);
        if ($raw === NULL) {
            return NULL;
        }
        if ( ! preg_match('/^-?\d{1,18}$/', $raw)) {
            return $this->fail($name, sprintf($this->t('v_integer', '%s must be a whole number.'), $this->label($name)));
        }
        $n = (int) $raw;
        if (($min !== NULL && $n < $min) || ($max !== NULL && $n > $max)) {
            return $this->fail($name, sprintf($this->t('v_range', '%s is out of range.'), $this->label($name)));
        }
        return $n;
    }

    /** trimmed text, at most $max characters (match the column length), no HTML tags, no control characters */
    public function text($name, $max = 255, $required = TRUE, $min = 0)
    {
        $raw = $this->scalar($name, $required);
        if ($raw === NULL) {
            return NULL;
        }
        if ( ! mb_check_encoding($raw, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw)) {
            return $this->fail($name, sprintf($this->t('v_chars', '%s contains invalid characters.'), $this->label($name)));
        }
        if (preg_match('/<\s*[\/!?a-zA-Z]/', $raw)) {
            return $this->fail($name, sprintf($this->t('v_html', '%s must not contain HTML tags.'), $this->label($name)));
        }
        $len = mb_strlen($raw, 'UTF-8');
        if ($len > $max) {
            return $this->fail($name, sprintf($this->t('v_max_length', '%s must be at most %d characters.'), $this->label($name), $max));
        }
        if ($len < $min) {
            return $this->fail($name, sprintf($this->t('v_min_length', '%s must be at least %d characters.'), $this->label($name), $min));
        }
        return $raw;
    }

    /** one of $allowed (strict string comparison) */
    public function enum($name, array $allowed, $required = TRUE)
    {
        $raw = $this->scalar($name, $required);
        if ($raw === NULL) {
            return NULL;
        }
        foreach ($allowed as $candidate) {
            if ((string) $candidate === $raw) {
                return $candidate;
            }
        }
        return $this->fail($name, sprintf($this->t('v_enum', '%s has an invalid value.'), $this->label($name)));
    }

    /** a real calendar date in the UI format (setting date_format) or Y-m-d; returns 'Y-m-d' */
    public function date($name, $required = TRUE)
    {
        $raw = $this->scalar($name, $required);
        if ($raw === NULL) {
            return NULL;
        }
        $date = to_db_date($raw);
        if ($date === FALSE) {
            return $this->fail($name, sprintf($this->t('v_date', '%s is not a valid date.'), $this->label($name)));
        }
        return $date;
    }

    /** H:i or H:i:s; returns 'H:i:s' */
    public function time($name, $required = TRUE)
    {
        $raw = $this->scalar($name, $required);
        if ($raw === NULL) {
            return NULL;
        }
        if ( ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $raw, $m)) {
            return $this->fail($name, sprintf($this->t('v_time', '%s is not a valid time.'), $this->label($name)));
        }
        return $m[1] . ':' . $m[2] . ':' . (isset($m[4]) ? $m[4] : '00');
    }

    /** amount with at most 2 decimals within [$min, $max]; returned rounded to 2 places */
    public function money($name, $min = 0.0, $max = 99999999.99, $required = TRUE)
    {
        $raw = $this->scalar($name, $required);
        if ($raw === NULL) {
            return NULL;
        }
        if ( ! preg_match('/^-?\d{1,14}(\.\d{1,2})?$/', $raw)) {
            return $this->fail($name, sprintf($this->t('v_money', '%s must be an amount with at most 2 decimals.'), $this->label($name)));
        }
        $amount = round((float) $raw, 2);
        if ($amount < $min - 0.005 || $amount > $max + 0.005) {
            return $this->fail($name, sprintf($this->t('v_range', '%s is out of range.'), $this->label($name)));
        }
        return $amount;
    }

    /**
     * id of a row in $table that exists, belongs to the current branch and is inside the user's own-records scope.
     * $table must be registered in config/hrm.php $config['scoped_tables'].
     */
    public function ref($name, $table, $required = TRUE)
    {
        $id = $this->int($name, 1, NULL, $required);
        if ($id === NULL) {
            return NULL;
        }
        $CI =& get_instance();
        $CI->load->model('Access_model');
        if ( ! $CI->Access_model->record_in_scope($table, $id)) {
            return $this->fail($name, sprintf($this->t('v_ref', 'Selected %s is not valid.'), mb_strtolower($this->label($name), 'UTF-8')));
        }
        return $id;
    }

    /** checkbox / switch: 1 for 1|on|true|yes, otherwise 0. Never an error. */
    public function flag($name)
    {
        $raw = $this->read($name);
        return (is_string($raw) && in_array(strtolower(trim($raw)), array('1', 'on', 'true', 'yes'), TRUE)) ? 1 : 0;
    }

    /** a password: not trimmed, no tag/control filtering, only length checked */
    public function secret($name, $min = 1, $max = 255, $required = TRUE)
    {
        $raw = $this->read($name);
        if ( ! is_string($raw) || $raw === '') {
            return $required ? $this->fail($name, sprintf($this->t('v_required', '%s is required.'), $this->label($name))) : NULL;
        }
        $len = mb_strlen($raw, 'UTF-8');
        if ($len < $min || $len > $max) {
            return $this->fail($name, sprintf($this->t('v_secret_length', '%s must be %d to %d characters.'), $this->label($name), $min, $max));
        }
        return $raw;
    }

    /** the hidden request_token of a money form (uuid issued by Idempotency::new_token()) */
    public function token($name = 'request_token')
    {
        $raw = $this->scalar($name, TRUE);
        if ($raw === NULL) {
            return NULL;
        }
        if ( ! Idempotency::valid_token($raw)) {
            return $this->fail($name, $this->t('v_token', 'Invalid request token. Reload the page and try again.'));
        }
        return $raw;
    }

    /** list of strings that must each be one of $allowed (checkbox groups such as permission keys); de-duplicated */
    public function enum_list($name, array $allowed, $required = FALSE)
    {
        $raw = $this->read($name);
        if ($raw === NULL || $raw === '' || $raw === array()) {
            return $required ? $this->fail($name, sprintf($this->t('v_required', '%s is required.'), $this->label($name))) : array();
        }
        if ( ! is_array($raw) || count($raw) > count($allowed)) {
            return $this->fail($name, sprintf($this->t('v_enum', '%s has an invalid value.'), $this->label($name)));
        }
        $lookup = array();
        foreach ($allowed as $a) {
            $lookup[(string) $a] = $a;
        }
        $out = array();
        foreach ($raw as $item) {
            if ( ! is_string($item) || ! isset($lookup[$item])) {
                return $this->fail($name, sprintf($this->t('v_enum', '%s has an invalid value.'), $this->label($name)));
            }
            $out[$item] = $lookup[$item];
        }
        return array_values($out);
    }

    /** an e-mail address (FILTER_VALIDATE_EMAIL), at most $max characters, returned lower case */
    public function email($name, $max = 120, $required = TRUE)
    {
        $raw = $this->text($name, $max, $required);
        if ($raw === NULL) {
            return NULL;
        }
        if (filter_var($raw, FILTER_VALIDATE_EMAIL) === FALSE) {
            return $this->fail($name, sprintf($this->t('v_email', '%s is not a valid e-mail address.'), $this->label($name)));
        }
        return mb_strtolower($raw, 'UTF-8');
    }

    /**
     * An uploaded image (G6 uploads): the type is read from the file contents (getimagesize), never from its name or the
     * browser's MIME type; size capped. Returns array('tmp' => path, 'ext' => 'png'|'jpg') or NULL (absent / error).
     */
    public function image($name, $max_kb = 512, $required = FALSE)
    {
        $file = isset($_FILES[$name]) ? $_FILES[$name] : NULL;
        if ( ! is_array($file) || ! isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return $required ? $this->fail($name, sprintf($this->t('v_required', '%s is required.'), $this->label($name))) : NULL;
        }
        if ( ! is_array($file['error']) && in_array($file['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), TRUE)) {
            return $this->fail($name, sprintf($this->t('v_upload_size', '%s must be at most %d KB.'), $this->label($name), $max_kb));
        }
        if (is_array($file['error']) || $file['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file($file['tmp_name'])) {
            return $this->fail($name, sprintf($this->t('v_upload', '%s could not be uploaded.'), $this->label($name)));
        }
        if ((int) $file['size'] > $max_kb * 1024) {
            return $this->fail($name, sprintf($this->t('v_upload_size', '%s must be at most %d KB.'), $this->label($name), $max_kb));
        }
        $info = @getimagesize($file['tmp_name']);
        $types = array(IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg');
        if ( ! $info || ! isset($types[$info[2]])) {
            return $this->fail($name, sprintf($this->t('v_upload_type', '%s must be a PNG or JPEG image.'), $this->label($name)));
        }
        return array('tmp' => $file['tmp_name'], 'ext' => $types[$info[2]]);
    }

    /**
     * An uploaded document: PDF (checked by its "%PDF-" signature) or a PNG/JPEG image (getimagesize). Never trusts the
     * name or the browser's MIME type. Returns array('tmp', 'ext', 'name' => original file name, trimmed) or NULL.
     */
    public function document($name, $max_kb = 2048, $required = TRUE)
    {
        $file = isset($_FILES[$name]) ? $_FILES[$name] : NULL;
        if ( ! is_array($file) || ! isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return $required ? $this->fail($name, sprintf($this->t('v_required', '%s is required.'), $this->label($name))) : NULL;
        }
        if ( ! is_array($file['error']) && in_array($file['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), TRUE)) {
            return $this->fail($name, sprintf($this->t('v_upload_size', '%s must be at most %d KB.'), $this->label($name), $max_kb));
        }
        if (is_array($file['error']) || $file['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file($file['tmp_name'])) {
            return $this->fail($name, sprintf($this->t('v_upload', '%s could not be uploaded.'), $this->label($name)));
        }
        if ((int) $file['size'] > $max_kb * 1024) {
            return $this->fail($name, sprintf($this->t('v_upload_size', '%s must be at most %d KB.'), $this->label($name), $max_kb));
        }
        $ext = NULL;
        $head = (string) @file_get_contents($file['tmp_name'], FALSE, NULL, 0, 5);
        if ($head === '%PDF-') {
            $ext = 'pdf';
        } else {
            $info = @getimagesize($file['tmp_name']);
            $types = array(IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg');
            if ($info && isset($types[$info[2]])) {
                $ext = $types[$info[2]];
            }
        }
        if ($ext === NULL) {
            return $this->fail($name, sprintf($this->t('v_upload_doc_type', '%s must be a PDF, PNG or JPEG file.'), $this->label($name)));
        }
        $original = preg_replace('#[\x00-\x1F\x7F<>"/\\\\]#', '', (string) basename(str_replace('\\', '/', (string) $file['name'])));
        return array('tmp' => $file['tmp_name'], 'ext' => $ext, 'name' => mb_substr($original, 0, 150, 'UTF-8'));
    }

    /**
     * An uploaded spreadsheet for imports: .xlsx (a ZIP container, checked by its signature) or .csv (plain text, no NUL bytes).
     * Returns array('tmp', 'type' => 'Xlsx'|'Csv') - the PhpSpreadsheet reader to use - or NULL.
     */
    public function spreadsheet($name, $max_kb = 2048, $required = TRUE)
    {
        $file = isset($_FILES[$name]) ? $_FILES[$name] : NULL;
        if ( ! is_array($file) || ! isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return $required ? $this->fail($name, sprintf($this->t('v_required', '%s is required.'), $this->label($name))) : NULL;
        }
        if ( ! is_array($file['error']) && in_array($file['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), TRUE)) {
            return $this->fail($name, sprintf($this->t('v_upload_size', '%s must be at most %d KB.'), $this->label($name), $max_kb));
        }
        if (is_array($file['error']) || $file['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file($file['tmp_name'])) {
            return $this->fail($name, sprintf($this->t('v_upload', '%s could not be uploaded.'), $this->label($name)));
        }
        if ((int) $file['size'] > $max_kb * 1024) {
            return $this->fail($name, sprintf($this->t('v_upload_size', '%s must be at most %d KB.'), $this->label($name), $max_kb));
        }
        $head = (string) @file_get_contents($file['tmp_name'], FALSE, NULL, 0, 4096);
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (substr($head, 0, 4) === "PK\x03\x04" && $ext === 'xlsx') {
            return array('tmp' => $file['tmp_name'], 'type' => 'Xlsx');
        }
        if ($ext === 'csv') {
            $all = (string) file_get_contents($file['tmp_name']);           // whole file: a 4 KB cut could split a UTF-8 character
            if (strpos($all, "\0") === FALSE && mb_check_encoding(preg_replace('/^\xEF\xBB\xBF/', '', $all), 'UTF-8')) {
                return array('tmp' => $file['tmp_name'], 'type' => 'Csv');
            }
        }
        return $this->fail($name, sprintf($this->t('v_upload_sheet_type', '%s must be an .xlsx or .csv file.'), $this->label($name)));
    }

    /**
     * Upload of a device's punch export: a UTF-8 text file (.dat / .txt as the USB export of a ZKTeco device, or .csv), no NUL bytes,
     * at most $max_kb. Returns array('tmp' => path, 'ext' => dat|txt|csv).
     */
    public function punch_file($name, $max_kb = 8192, $required = TRUE)
    {
        $file = isset($_FILES[$name]) ? $_FILES[$name] : NULL;
        if ( ! is_array($file) || ! isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return $required ? $this->fail($name, sprintf($this->t('v_required', '%s is required.'), $this->label($name))) : NULL;
        }
        if ( ! is_array($file['error']) && in_array($file['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), TRUE)) {
            return $this->fail($name, sprintf($this->t('v_upload_size', '%s must be at most %d KB.'), $this->label($name), $max_kb));
        }
        if (is_array($file['error']) || $file['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file($file['tmp_name'])) {
            return $this->fail($name, sprintf($this->t('v_upload', '%s could not be uploaded.'), $this->label($name)));
        }
        if ((int) $file['size'] > $max_kb * 1024) {
            return $this->fail($name, sprintf($this->t('v_upload_size', '%s must be at most %d KB.'), $this->label($name), $max_kb));
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $all = in_array($ext, array('dat', 'txt', 'csv'), TRUE) ? (string) file_get_contents($file['tmp_name']) : NULL;
        if ($all !== NULL && strpos($all, "\0") === FALSE && mb_check_encoding(preg_replace('/^\xEF\xBB\xBF/', '', $all), 'UTF-8')) {
            return array('tmp' => $file['tmp_name'], 'ext' => $ext);
        }
        return $this->fail($name, sprintf($this->t('v_upload_punch_type', '%s must be a .dat, .txt or .csv text file.'), $this->label($name)));
    }

    /** list of ids (checkbox groups / bulk actions), at most $max_count entries, each >= 1, de-duplicated */
    public function int_list($name, $max_count = 1000, $required = TRUE)
    {
        $raw = $this->read($name);
        if ( ! is_array($raw) || count($raw) === 0) {
            return $required ? $this->fail($name, sprintf($this->t('v_required', '%s is required.'), $this->label($name))) : array();
        }
        if (count($raw) > $max_count) {
            return $this->fail($name, sprintf($this->t('v_too_many', '%s has too many entries.'), $this->label($name)));
        }
        $out = array();
        foreach ($raw as $item) {
            if ( ! is_string($item) || ! preg_match('/^\d{1,18}$/', $item) || (int) $item < 1) {
                return $this->fail($name, sprintf($this->t('v_integer', '%s must be a whole number.'), $this->label($name)));
            }
            $out[(int) $item] = (int) $item;
        }
        return array_values($out);
    }

    // ---------------------------------------------------------------------------------------- internals

    protected function read($name)
    {
        return $this->source === 'get' ? $this->input->get($name) : $this->input->post($name);
    }

    /** the raw value as a trimmed string; NULL when absent/empty (error recorded when required) or not a scalar */
    protected function scalar($name, $required)
    {
        $raw = $this->read($name);
        if (is_array($raw)) {
            return $this->fail($name, sprintf($this->t('v_chars', '%s contains invalid characters.'), $this->label($name)));
        }
        if ($raw !== NULL && ! is_string($raw)) {
            $raw = (string) $raw;
        }
        if ($raw !== NULL) {
            $raw = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $raw);
            if ($raw === NULL) {                      // invalid UTF-8 made the regex fail
                return $this->fail($name, sprintf($this->t('v_chars', '%s contains invalid characters.'), $this->label($name)));
            }
        }
        if ($raw === NULL || $raw === '') {
            if ($required) {
                $this->fail($name, sprintf($this->t('v_required', '%s is required.'), $this->label($name)));
            }
            return NULL;
        }
        return $raw;
    }

    protected function label($name)
    {
        $label = str_replace('_', ' ', preg_replace('/_id$/', '', (string) $name));
        return mb_strtoupper(mb_substr($label, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($label, 1, NULL, 'UTF-8');
    }

    protected function t($key, $fallback)
    {
        $line = get_instance()->lang->line($key, FALSE);
        return ($line === FALSE || $line === '') ? $fallback : $line;
    }
}
