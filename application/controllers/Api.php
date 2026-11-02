<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Device JSON API for the ZKTeco Windows Service (`ProFarjan/ZKTeco-Windows-Service`), route `api/v1/pull_attendance`.
 * Session-less, no CSRF. Authentication is the hashed device key (Device_auth); the branch of every punch is the key's branch,
 * a branch / store id inside the payload is ignored and logged (P3). Any failure to store punches answers HTTP 500, so the
 * service keeps its batch and sends it again (P16); a re-sent punch is a no-op because ingestion is idempotent.
 */
class Api extends Api_Controller
{
    protected $permission_map = array(
        'pull_from_machine' => '@device',
    );

    /** @var array|NULL device_keys row + branches row of the authenticated call */
    protected $auth = NULL;

    protected function authenticate_device()
    {
        $ip = client_ip();
        $result = Device_auth::authenticate_key(Device_auth::key_from_request($this->input), $ip);
        if ( ! $result['ok']) {
            Device_auth::log(NULL, NULL, 'api/v1/pull_attendance', $result['code'], 0, $result['message'], $ip);
            $this->finish(array('status' => 'error', 'message' => $result['message']), $result['code']);
        }
        $this->auth = $result;
        Device_auth::activate_branch($result['branch']);
    }

    public function pull_from_machine()
    {
        $ip = client_ip();
        $endpoint = 'api/v1/pull_attendance';
        $key_row = $this->auth['key'];
        if ($this->input->method() !== 'post') {
            Device_auth::log(NULL, $key_row['branch_id'], $endpoint, 405, 0, 'POST required', $ip);
            $this->finish(array('status' => 'error', 'message' => 'POST required.'), 405);
        }
        $body = $this->body(8 * 1024 * 1024);
        if ($body === NULL) {
            Device_auth::log(NULL, $key_row['branch_id'], $endpoint, 413, 0, 'body too large', $ip);
            $this->finish(array('status' => 'error', 'message' => 'The request is too large; send smaller batches.'), 413);
        }
        $payload = Service_payload::parse($body);
        if ( ! $payload['ok']) {
            Device_auth::log(NULL, $key_row['branch_id'], $endpoint, 400, 0, $payload['error'], $ip);
            $this->finish(array('status' => 'error', 'message' => $payload['error']), 400);
        }
        if ($payload['test']) {
            Device_auth::log(NULL, $key_row['branch_id'], $endpoint, 200, 0, 'connection test', $ip);
            $this->finish(array('status' => 'success', 'message' => 'Connection OK'), 200);
        }

        $resolved = Device_auth::service_device($key_row, $payload['device_name'], $ip);
        if ( ! $resolved['ok']) {
            Device_auth::log(NULL, $key_row['branch_id'], $endpoint, $resolved['code'], 0, $resolved['message'], $ip);
            $this->finish(array('status' => 'error', 'message' => $resolved['message']), $resolved['code']);
        }
        $device = $resolved['device'];
        $notes = array();
        $claimed = array_diff(Service_payload::claimed_branches($body), array((string) $device['branch_id']));
        if ($claimed) {
            $notes[] = 'payload branch/store ' . implode(',', array_slice($claimed, 0, 5)) . ' ignored, the key\'s branch is used';
        }

        try {
            foreach ($payload['command_results'] as $c) {
                Device_command_queue::complete($device['id'], $c['id'], $c['success'], array('message' => $c['message'], 'data' => $c['data']));
            }
            $branch_tz = $this->auth['branch']['timezone'];
            $punches = $payload['punches'];
            foreach ($punches as $i => $p) {
                $punches[$i]['punch_at'] = Device_auth::to_branch_time($p['punch_at'], $device['timezone'], $branch_tz);
            }
            $stats = array('stored' => 0, 'duplicates' => 0, 'unmatched' => 0);
            if ($punches) {
                $context = array('branch_id' => $device['branch_id'], 'device_id' => (int) $device['id'], 'source' => 'device_service', 'created_by' => NULL);
                $stats = (new Punch_ingestor())->ingest($punches, $context);
                if ( ! $stats['ok']) {
                    Device_auth::log($device, $device['branch_id'], $endpoint, 500, count($punches), 'storing failed: ' . $stats['message'], $ip);
                    $this->finish(array('status' => 'error', 'message' => 'The punches were not stored; send them again.'), 500);
                }
            }
            $extra = array();
            if ($punches) {
                $latest = max(array_column($punches, 'punch_at'));
                $extra['last_upload_at'] = now_local();
                $extra['last_punch_at'] = ($device['last_punch_at'] === NULL || $latest > $device['last_punch_at']) ? $latest : $device['last_punch_at'];
            }
            Device_auth::seen($device, $ip, $extra);
            $commands = array();
            foreach (Device_command_queue::deliver($device['id']) as $row) {
                $commands[] = Device_command_queue::for_service($row);
            }
        } catch (Throwable $e) {
            log_message('error', 'api/v1/pull_attendance: ' . $e->getMessage());
            Device_auth::log($device, $device['branch_id'], $endpoint, 500, count($payload['punches']), 'server error', $ip);
            $this->finish(array('status' => 'error', 'message' => 'Server error; send the batch again.'), 500);
        }

        $accepted = (int) $stats['stored'] + (int) $stats['duplicates'];
        $processed = $payload['total'] - $payload['invalid'];
        $summary = sprintf('%d record(s): %d new, %d already stored, %d invalid, %d unmatched PIN', $payload['total'], $stats['stored'], $stats['duplicates'], $payload['invalid'], $stats['unmatched']);
        Device_auth::log($device, $device['branch_id'], $endpoint, 200, $payload['total'], implode('; ', array_merge(array($summary), $notes)), $ip);
        $this->finish(array('status' => 'success', 'processed' => $processed, 'total' => $payload['total'], 'accepted' => $accepted,
            'invalid' => $payload['invalid'], 'unmatched' => (int) $stats['unmatched'], 'commands' => $commands), 200);
    }

    /** send the JSON answer now and stop (the device must get exactly one answer) */
    protected function finish(array $payload, $status)
    {
        $this->json($payload, $status);
        $this->output->_display();
        exit;
    }
}
