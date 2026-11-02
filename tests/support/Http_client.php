<?php
/**
 * Minimal browser: cookie jar + CSRF token handling over curl, against a php -S server running the app on hrm_test.
 */
class Http_server
{
    /** @var resource|null */
    protected $proc;
    /** @var int */
    public $port;

    public function start()
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {            // a random port can be taken by someone else: try again with another
            $this->port = random_int(20000, 40000);
            $env = array_merge($_SERVER, array('CI_ENV' => 'testing', 'TENANT_DB' => 'hrm_test'));
            $env = array_filter($env, 'is_string');
            $this->proc = proc_open(
                array(PHP_BINARY, '-S', '127.0.0.1:' . $this->port, HRM_ROOT . '/tests/support/router.php'),
                array(0 => array('pipe', 'r'), 1 => array('file', HRM_ROOT . '/tests/_output/server.log', 'a'), 2 => array('file', HRM_ROOT . '/tests/_output/server.log', 'a')),
                $pipes, HRM_ROOT, $env
            );
            for ($i = 0; $i < 50; $i++) {
                $fp = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
                if ($fp) {
                    fclose($fp);
                    return;
                }
                usleep(100000);
            }
            $this->stop();
        }
        throw new RuntimeException('test web server did not start');
    }

    public function stop()
    {
        if ($this->proc) {
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = NULL;
        }
    }
}

class Http_client
{
    /** @var string */
    protected $base;
    /** @var string */
    protected $jar;

    public function __construct($port)
    {
        $this->base = 'http://127.0.0.1:' . $port . '/';
        $this->jar = tempnam(HRM_ROOT . '/tests/_output', 'jar');
    }

    public function __destruct()
    {
        if ($this->jar && is_file($this->jar)) {
            unlink($this->jar);
        }
    }

    /** @return array array(int status, string body, string headers) */
    public function request($method, $path, array $post = array(), array $headers = array())
    {
        $ch = curl_init($this->base . ltrim($path, '/'));
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => TRUE, CURLOPT_HEADER => TRUE, CURLOPT_FOLLOWLOCATION => FALSE,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        ));
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return array($status, (string) substr($raw, $size), (string) substr($raw, 0, $size));
    }

    public function get($path, array $headers = array())
    {
        return $this->request('GET', $path, array(), $headers);
    }

    /** POST with the CSRF cookie value as the token (fetches the login page first when there is no cookie yet) */
    public function post($path, array $post = array(), array $headers = array())
    {
        $post['hrm_csrf'] = $this->csrf_token();
        return $this->request('POST', $path, $post, $headers);
    }

    /** multipart POST (file uploads); $files = field => local path. Nested arrays are flattened to name[i]. */
    public function post_multipart($path, array $fields, array $files = array())
    {
        $flat = array('hrm_csrf' => $this->csrf_token());
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                foreach (array_values($v) as $i => $item) {
                    $flat[$k . '[' . $i . ']'] = $item;
                }
            } else {
                $flat[$k] = $v;
            }
        }
        foreach ($files as $k => $file) {
            $flat[$k] = new CURLFile($file, 'application/octet-stream', basename($file));
        }
        $ch = curl_init($this->base . ltrim($path, '/'));
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => TRUE, CURLOPT_HEADER => TRUE, CURLOPT_FOLLOWLOCATION => FALSE,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20,
            CURLOPT_POST => TRUE, CURLOPT_POSTFIELDS => $flat, CURLOPT_HTTPHEADER => array('X-Requested-With: XMLHttpRequest'),
        ));
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return array($status, (string) substr($raw, $size), (string) substr($raw, 0, $size));
    }

    /** request with a raw body and no CSRF token (the device endpoints: JSON from the service, plain text from ADMS) */
    public function raw($method, $path, $body = '', array $headers = array())
    {
        $ch = curl_init($this->base . ltrim($path, '/'));
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => TRUE, CURLOPT_HEADER => TRUE, CURLOPT_FOLLOWLOCATION => FALSE, CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
        ));
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return array($status, (string) substr($raw, $size), (string) substr($raw, 0, $size));
    }

    public function csrf_token()
    {
        if ($this->cookie('hrm_csrf_cookie') === NULL) {
            $this->get('auth/login');
        }
        return (string) $this->cookie('hrm_csrf_cookie');
    }

    public function cookie($name)
    {
        foreach (file($this->jar, FILE_IGNORE_NEW_LINES) as $line) {
            $line = preg_replace('/^#HttpOnly_/', '', $line);
            $parts = explode("\t", $line);
            if (count($parts) >= 7 && $parts[5] === $name) {
                return $parts[6];
            }
        }
        return NULL;
    }

    public function login($username, $password)
    {
        return $this->post('auth/login', array('username' => $username, 'password' => $password));
    }
}
