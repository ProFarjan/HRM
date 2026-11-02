<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ZKTeco ADMS push protocol (`/iclock/cdata`, `/iclock/getrequest`, `/iclock/devicecmd`): the device calls the server.
 * Plain text, no session, no CSRF. A serial number is not a secret, so only serial numbers HR approved are served; an unknown one
 * is remembered in devices_pending (capped) and gets the normal handshake text but nothing it sends is stored (a refused upload
 * is HTTP 403, so the device keeps its data until the device is approved). The branch is devices.branch_id (P3, P17).
 */
class Iclock extends Api_Controller
{
    protected $permission_map = array(
        'cdata'      => '@device',
        'getrequest' => '@device',
        'devicecmd'  => '@device',
    );

    /** @var array|NULL result of Device_auth::adms() for this call */
    protected $adms = NULL;
    /** @var string */
    protected $serial = '';

    protected function authenticate_device()
    {
        $ip = client_ip();
        $sn = $this->input->get('SN');
        $this->serial = is_string($sn) ? trim($sn) : '';
        $endpoint = 'iclock/' . strtolower((string) $this->router->fetch_method());
        $this->adms = Device_auth::adms($this->serial, $ip);
        $state = $this->adms['state'];
        if ($state === 'invalid' || $state === 'rate') {
            Device_auth::log(NULL, NULL, $endpoint, $this->adms['code'], 0, $state === 'rate' ? 'rate limit' : 'bad serial number', $ip);
            $this->finish($state === 'rate' ? 'ERROR: rate limit' : 'ERROR: bad serial number', $this->adms['code']);
        }
        if ($this->adms['branch']) {
            Device_auth::activate_branch($this->adms['branch']);
        }
    }

    /** GET = handshake (options), POST = data upload (table=ATTLOG | OPERLOG | USERINFO | BIOPHOTO ...) */
    public function cdata()
    {
        $ip = client_ip();
        $device = $this->adms['device'];
        $state = $this->adms['state'];
        if ($this->input->method() !== 'post') {
            if ($state === 'unknown') {
                Device_auth::log(NULL, NULL, 'iclock/cdata', 200, 0, 'unknown serial ' . $this->serial . ' (handshake only)', $ip);
                $this->plain(Adms_protocol::handshake($this->serial));
                return;
            }
            $this->refuse_unless_ok('iclock/cdata', $ip);
            Device_auth::seen($device, $ip);
            Device_auth::log($device, $device['branch_id'], 'iclock/cdata', 200, 0, 'handshake', $ip, 'out');
            $this->plain(Adms_protocol::handshake($this->serial));
            return;
        }
        $this->refuse_unless_ok('iclock/cdata', $ip);
        $body = $this->body(Adms_protocol::MAX_BODY_BYTES);
        if ($body === NULL) {
            Device_auth::log($device, $device['branch_id'], 'iclock/cdata', 413, 0, 'body too large', $ip);
            $this->finish('ERROR: body too large', 413);
        }
        $table = strtoupper((string) $this->input->get('table'));
        if ($table !== 'ATTLOG') {
            Device_auth::seen($device, $ip);
            Device_auth::log($device, $device['branch_id'], 'iclock/cdata', 200, 0, 'table ' . mb_substr($table, 0, 20) . ' acknowledged, not stored', $ip);
            $this->plain('OK');
            return;
        }

        $parsed = Adms_protocol::parse_attlog($body);
        $punches = $parsed['punches'];
        try {
            foreach ($punches as $i => $p) {
                $punches[$i]['punch_at'] = Device_auth::to_branch_time($p['punch_at'], $device['timezone'], $this->adms['branch']['timezone']);
            }
            $stats = array('stored' => 0, 'duplicates' => 0, 'unmatched' => 0);
            if ($punches) {
                $context = array('branch_id' => $device['branch_id'], 'device_id' => (int) $device['id'], 'source' => 'device_push', 'created_by' => NULL);
                $stats = (new Punch_ingestor())->ingest($punches, $context);
                if ( ! $stats['ok']) {
                    Device_auth::log($device, $device['branch_id'], 'iclock/cdata', 500, count($punches), 'storing failed: ' . $stats['message'], $ip);
                    $this->finish('ERROR: not stored', 500);
                }
            }
            $extra = array();
            if ($punches) {
                $latest = max(array_column($punches, 'punch_at'));
                $extra['last_upload_at'] = now_local();
                $extra['last_punch_at'] = ($device['last_punch_at'] === NULL || $latest > $device['last_punch_at']) ? $latest : $device['last_punch_at'];
                if (count($punches) <= 3) {
                    // a realtime push: the latest punch time against the server clock is an estimate of the device's clock error
                    $offset = strtotime($latest) - time();
                    if (abs($offset) <= 6 * 3600) {
                        $extra['clock_offset_seconds'] = $offset;
                    }
                }
            }
            Device_auth::seen($device, $ip, $extra);
        } catch (Throwable $e) {
            log_message('error', 'iclock/cdata: ' . $e->getMessage());
            Device_auth::log($device, $device['branch_id'], 'iclock/cdata', 500, count($parsed['punches']), 'server error', $ip);
            $this->finish('ERROR: server error', 500);
        }
        Device_auth::log($device, $device['branch_id'], 'iclock/cdata', 200, $parsed['lines'],
            sprintf('%d line(s): %d new, %d already stored, %d junk, %d unmatched PIN', $parsed['lines'], $stats['stored'], $stats['duplicates'], $parsed['junk'], $stats['unmatched']), $ip);
        $this->plain('OK');
    }

    /** heartbeat and command delivery: `OK` when nothing is queued, else one `C:<id>:<COMMAND>` line per command */
    public function getrequest()
    {
        $ip = client_ip();
        $device = $this->adms['device'];
        if ($this->adms['state'] === 'unknown') {
            Device_auth::log(NULL, NULL, 'iclock/getrequest', 200, 0, 'unknown serial ' . $this->serial, $ip);
            $this->plain('OK');
            return;
        }
        $this->refuse_unless_ok('iclock/getrequest', $ip);
        $extra = array();
        $firmware = Adms_protocol::firmware($this->input->get('INFO'));
        if ($firmware !== NULL) {
            $extra['firmware'] = $firmware;
        }
        Device_auth::seen($device, $ip, $extra);
        $lines = array();
        foreach (Device_command_queue::deliver($device['id']) as $row) {
            $line = Adms_protocol::command_line($row);
            if ($line === NULL) {
                Device_command_queue::complete($device['id'], $row['id'], FALSE, array('message' => 'Command not supported over ADMS.'));
                continue;
            }
            $lines[] = $line;
        }
        if ($lines) {
            Device_auth::log($device, $device['branch_id'], 'iclock/getrequest', 200, count($lines), count($lines) . ' command(s) sent', $ip, 'out');
        } else {
            Device_auth::log($device, $device['branch_id'], 'iclock/getrequest', 200, 0, 'heartbeat', $ip);
        }
        $this->plain($lines ? implode("\n", $lines) . "\n" : 'OK');
    }

    /** the device's answers to commands: `ID=7&Return=0&CMD=DATA` */
    public function devicecmd()
    {
        $ip = client_ip();
        $device = $this->adms['device'];
        $this->refuse_unless_ok('iclock/devicecmd', $ip);
        $body = $this->body(Adms_protocol::MAX_BODY_BYTES);
        $results = $body === NULL ? array() : Adms_protocol::parse_command_results($body);
        foreach ($results as $r) {
            Device_command_queue::complete($device['id'], $r['id'], $r['success'], array('return' => $r['return']));
        }
        Device_auth::seen($device, $ip);
        Device_auth::log($device, $device['branch_id'], 'iclock/devicecmd', 200, count($results), count($results) . ' command result(s)', $ip);
        $this->plain('OK');
    }

    /** an unknown, disabled or not allowed device gets 403 (nothing it sends is kept), after the attempt is logged */
    protected function refuse_unless_ok($endpoint, $ip)
    {
        if ($this->adms['state'] === 'ok') {
            return;
        }
        $reasons = array('unknown' => 'device not registered', 'disabled' => 'device disabled', 'ip' => 'address not allowed: ' . $ip);
        $reason = isset($reasons[$this->adms['state']]) ? $reasons[$this->adms['state']] : 'refused';
        Device_auth::log($this->adms['device'], $this->adms['device'] ? $this->adms['device']['branch_id'] : NULL, $endpoint, 403, 0, $reason . ' (SN ' . $this->serial . ')', $ip);
        $this->finish('ERROR: ' . $reason, 403);
    }

    protected function finish($text, $status)
    {
        $this->plain($text, $status);
        $this->output->_display();
        exit;
    }
}
