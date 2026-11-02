<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Attendance > Import punches (P16): the USB export of a ZKTeco device (`.dat`, tab separated) or a CSV is read, checked and shown
 * as a preview (counts, date range, PINs that match nobody, a sample); only "Import" writes. Nothing is written by the preview;
 * the parsed rows wait in a random-named file outside the web root's reach (uploads/ is denied by .htaccess) for one hour and
 * for the user and branch that uploaded them. Importing is idempotent: the rows go through Punch_ingestor with the device chosen
 * on the form, so a punch that is already stored (same device, PIN and time) is a no-op and the same file can be imported twice.
 */
class Punch_import_model extends MY_Model
{
    const DIR = 'uploads/import/';
    const CHUNK = 5000;
    const KEEP_SECONDS = 3600;

    /** @return array ok, message | ok, token, summary... */
    public function preview()
    {
        $v = new Input_validator($this->input);
        $device_id = $v->ref('device_id', 'devices');
        $file = $v->punch_file('punch_file');
        if ( ! $v->ok()) {
            return array('ok' => FALSE, 'message' => $v->message());
        }
        $device = $this->db->query("SELECT * FROM devices WHERE id = ? AND branch_id = ?", array($device_id, (int) current_branch_id()))->row_array();
        $parsed = Punch_file_parser::parse((string) file_get_contents($file['tmp']), $file['ext'], (string) hr_setting('date_format', 'd-m-Y'));
        if ( ! $parsed['punches']) {
            return array('ok' => FALSE, 'message' => t('imp_nothing'));
        }
        $branch_tz = $this->branch_timezone();
        $punches = array();
        foreach ($parsed['punches'] as $p) {
            $p['punch_at'] = Device_auth::to_branch_time($p['punch_at'], $device['timezone'], $branch_tz);
            $punches[] = $p;
        }
        usort($punches, function ($a, $b) { return strcmp($a['punch_at'], $b['punch_at']); });
        $first = $punches[0]['punch_at'];
        $last = $punches[count($punches) - 1]['punch_at'];

        // already stored (same device, PIN, time) - one range query
        $stored = array();
        foreach ($this->db->select('device_pin, punch_at')->from('attendance_punches')->where('device_id', (int) $device_id)
                    ->where('punch_at >=', $first)->where('punch_at <=', $last)->get()->result_array() as $r) {
            $stored[$r['device_pin'] . '|' . $r['punch_at']] = TRUE;
        }
        $seen = array();
        $new = 0;
        $already = 0;
        $pins = array();
        foreach ($punches as $p) {
            $key = $p['device_pin'] . '|' . $p['punch_at'];
            if (isset($stored[$key]) || isset($seen[$key])) {
                $already++;
            } else {
                $new++;
                $seen[$key] = TRUE;
            }
            $pins[$p['device_pin']] = isset($pins[$p['device_pin']]) ? $pins[$p['device_pin']] + 1 : 1;
        }
        $known = $this->known_pins(array_keys($pins));
        $unmatched = array();
        foreach ($pins as $pin => $count) {
            if ( ! isset($known[(string) $pin])) {
                $unmatched[] = array('pin' => (string) $pin, 'punches' => $count);
            }
        }
        $token = bin2hex(random_bytes(16));
        if ( ! $this->save_pending($token, array('user_id' => current_user_id(), 'branch_id' => (int) current_branch_id(), 'device_id' => (int) $device_id, 'punches' => $punches))) {
            return array('ok' => FALSE, 'message' => t('imp_store_failed'));
        }
        $sample = array();
        foreach (array_slice($punches, 0, 8) as $p) {
            $sample[] = array('pin' => $p['device_pin'], 'at' => show_date($p['punch_at']) . substr($p['punch_at'], 10));
        }
        return array('ok' => TRUE, 'token' => $token, 'device' => $device['name'], 'rows' => $parsed['rows'], 'valid' => count($punches), 'invalid' => $parsed['invalid'],
            'new' => $new, 'already' => $already, 'truncated' => $parsed['truncated'], 'from' => show_date($first) . substr($first, 10), 'to' => show_date($last) . substr($last, 10),
            'unmatched' => array_slice($unmatched, 0, 50), 'unmatched_total' => count($unmatched), 'sample' => $sample);
    }

    /** @return string "success<<<###>>>stored/duplicates/unmatched" or an error */
    public function commit()
    {
        $v = new Input_validator($this->input);
        $token = $v->text('token', 32);
        if ($token !== NULL && ! preg_match('/^[a-f0-9]{32}$/', $token)) {
            $v->fail('token', t('imp_expired'));
        }
        if ( ! $v->ok()) {
            return $v->message();
        }
        $pending = $this->load_pending($token);
        if ($pending === NULL || (int) $pending['user_id'] !== (int) current_user_id() || (int) $pending['branch_id'] !== (int) current_branch_id()) {
            return t('imp_expired');
        }
        $device = $this->db->query("SELECT id FROM devices WHERE id = ? AND branch_id = ?", array((int) $pending['device_id'], (int) current_branch_id()))->row_array();
        if ( ! $device) {
            return t('not_found');
        }
        $totals = array('stored' => 0, 'duplicates' => 0, 'unmatched' => 0, 'invalid' => 0);
        $context = array('branch_id' => (int) current_branch_id(), 'device_id' => (int) $pending['device_id'], 'source' => 'import', 'created_by' => current_user_id());
        $ingestor = new Punch_ingestor();
        foreach (array_chunk($pending['punches'], self::CHUNK) as $chunk) {
            $r = $ingestor->ingest($chunk, $context);
            if ( ! $r['ok']) {
                return sprintf(t('imp_partial'), $totals['stored'] + $totals['duplicates'], $r['message']);      // idempotent: run it again
            }
            foreach ($totals as $k => $_) {
                $totals[$k] += (int) $r[$k];
            }
        }
        $this->discard($token);
        list($ok, $message) = Tx::run(function () use ($pending, $totals) {
            $this->audit('attendance', (int) $pending['device_id'], 'import_punches', NULL, array('device_id' => (int) $pending['device_id'], 'rows' => count($pending['punches'])) + $totals);
            return TRUE;
        });
        return $ok ? 'success<<<###>>>' . $totals['stored'] . '/' . $totals['duplicates'] . '/' . $totals['unmatched'] : $message;
    }

    /** pin => TRUE for the PINs that belong to an employee of the branch now or in the past */
    protected function known_pins(array $pins)
    {
        $out = array();
        $pins = array_map('strval', $pins);
        foreach (array_chunk($pins, 500) as $chunk) {
            foreach ($this->db->select('device_pin')->from('employees')->where('branch_id', (int) current_branch_id())->where_in('device_pin', $chunk)->get()->result_array() as $r) {
                $out[(string) $r['device_pin']] = TRUE;
            }
            foreach ($this->db->select('device_pin')->from('employee_pin_history')->where('branch_id', (int) current_branch_id())->where_in('device_pin', $chunk)->get()->result_array() as $r) {
                $out[(string) $r['device_pin']] = TRUE;
            }
        }
        return $out;
    }

    protected function branch_timezone()
    {
        $row = $this->db->query('SELECT timezone FROM branches WHERE id = ?', array((int) current_branch_id()))->row_array();
        return $row ? $row['timezone'] : NULL;
    }

    protected function save_pending($token, array $data)
    {
        $dir = FCPATH . self::DIR;
        if ( ! is_dir($dir) && ! @mkdir($dir, 0775, TRUE)) {
            return FALSE;
        }
        $this->sweep($dir);
        return file_put_contents($dir . $token . '.json', json_encode($data)) !== FALSE;
    }

    protected function load_pending($token)
    {
        $path = FCPATH . self::DIR . $token . '.json';
        if ( ! is_file($path) || filemtime($path) < time() - self::KEEP_SECONDS) {
            return NULL;
        }
        $data = json_decode((string) file_get_contents($path), TRUE);
        return (is_array($data) && isset($data['punches'], $data['user_id'], $data['branch_id'], $data['device_id'])) ? $data : NULL;
    }

    protected function discard($token)
    {
        @unlink(FCPATH . self::DIR . $token . '.json');
    }

    /** previews nobody confirmed are removed after an hour */
    protected function sweep($dir)
    {
        foreach ((array) glob($dir . '*.json') as $file) {
            if (filemtime($file) < time() - self::KEEP_SECONDS) {
                @unlink($file);
            }
        }
    }
}
