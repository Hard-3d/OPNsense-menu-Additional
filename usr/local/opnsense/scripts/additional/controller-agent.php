#!/usr/local/bin/php
<?php

declare(strict_types=1);

const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/controller_agent.json';
const STATUS_FILE = '/var/run/additional_controller_agent_status.json';
const LOCK_FILE = '/tmp/additional_controller_agent.lock';

function default_config(): array
{
    return [
        'version' => 1,
        'enabled' => '0',
        'server_url' => '',
        'device_uuid' => '',
        'device_secret' => '',
        'verify_tls' => '1',
        'poll_jobs' => '1',
    ];
}

function bool01($value): string
{
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
}

function load_config(): array
{
    $config = default_config();
    if (is_readable(CONFIG_FILE)) {
        $data = json_decode((string)file_get_contents(CONFIG_FILE), true);
        if (is_array($data)) {
            $config = array_replace($config, $data);
        }
    }
    $config['enabled'] = bool01($config['enabled'] ?? '0');
    $config['verify_tls'] = bool01($config['verify_tls'] ?? '1');
    $config['poll_jobs'] = bool01($config['poll_jobs'] ?? '1');
    $config['server_url'] = rtrim(trim((string)($config['server_url'] ?? '')), '/');
    $config['device_uuid'] = trim((string)($config['device_uuid'] ?? ''));
    $config['device_secret'] = trim((string)($config['device_secret'] ?? ''));
    return $config;
}

function save_config(array $config): void
{
    $config = array_replace(default_config(), $config);
    $config['enabled'] = bool01($config['enabled'] ?? '0');
    $config['verify_tls'] = bool01($config['verify_tls'] ?? '1');
    $config['poll_jobs'] = bool01($config['poll_jobs'] ?? '1');
    $config['server_url'] = rtrim(trim((string)($config['server_url'] ?? '')), '/');
    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Cannot encode config');
    }
    $dir = dirname(CONFIG_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (file_put_contents(CONFIG_FILE, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write ' . CONFIG_FILE);
    }
    chmod(CONFIG_FILE, 0600);
}

function write_status(array $status): void
{
    $status['timestamp'] = date('Y-m-d H:i:s');
    $json = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        file_put_contents(STATUS_FILE, $json . "\n", LOCK_EX);
        chmod(STATUS_FILE, 0644);
    }
}

function read_status(): array
{
    if (!is_readable(STATUS_FILE)) {
        return ['ok' => null, 'last_action' => '', 'timestamp' => '', 'message' => 'No status yet'];
    }
    $data = json_decode((string)file_get_contents(STATUS_FILE), true);
    return is_array($data) ? $data : ['ok' => false, 'last_action' => '', 'timestamp' => '', 'message' => 'Invalid status file'];
}

function arg_value(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $i => $arg) {
        if ($arg === $name && isset($argv[$i + 1])) {
            return (string)$argv[$i + 1];
        }
        if (strpos($arg, $name . '=') === 0) {
            return substr($arg, strlen($name) + 1);
        }
    }
    return $default;
}

function has_arg(array $argv, string $name): bool
{
    return in_array($name, $argv, true);
}

function json_out(array $data, int $exitCode = 0): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($exitCode);
}

function validate_server_url(string $url): string
{
    $url = rtrim(trim($url), '/');
    if ($url === '') {
        throw new RuntimeException('Server URL is required');
    }
    if (!preg_match('#^https?://#i', $url)) {
        throw new RuntimeException('Server URL must start with http:// or https://');
    }
    return $url;
}

function http_json(string $method, string $url, array $payload = [], array $headers = [], bool $verifyTls = true): array
{
    $method = strtoupper($method);
    $headerLines = array_merge(['Accept: application/json'], $headers);
    $body = '';
    if ($method !== 'GET') {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headerLines[] = 'Content-Type: application/json';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyTls ? 2 : 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyTls);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('HTTP error: ' . $err);
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response: ' . (string)$raw);
        }
        if ($code < 200 || $code >= 300) {
            $msg = $decoded['error'] ?? $decoded['message'] ?? ('HTTP ' . $code);
            throw new RuntimeException((string)$msg);
        }
        $decoded['_http_code'] = $code;
        return $decoded;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $method === 'GET' ? '' : $body,
            'timeout' => 90,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => $verifyTls,
            'verify_peer_name' => $verifyTls,
        ],
    ]);
    $raw = file_get_contents($url, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException('HTTP request failed: ' . $url);
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response: ' . (string)$raw);
    }
    return $decoded;
}

function shell_one(string $cmd): ?string
{
    $out = shell_exec($cmd . ' 2>/dev/null');
    if ($out === null) {
        return null;
    }
    $out = trim($out);
    return $out === '' ? null : $out;
}

function opnsense_version(): ?string
{
    return shell_one('/usr/local/sbin/opnsense-version') ?: shell_one('opnsense-version') ?: php_uname('r');
}

function uptime_seconds(): ?int
{
    $boot = shell_one("sysctl -n kern.boottime | sed -E 's/.*sec = ([0-9]+).*/\\1/'");
    if ($boot !== null && ctype_digit($boot)) {
        return time() - (int)$boot;
    }
    return null;
}

function memory_used_percent(): ?float
{
    $phys = shell_one('sysctl -n hw.physmem');
    $free = shell_one('sysctl -n vm.stats.vm.v_free_count');
    $page = shell_one('sysctl -n hw.pagesize');
    if ($phys && $free && $page && ctype_digit($phys) && ctype_digit($free) && ctype_digit($page)) {
        $freeBytes = (int)$free * (int)$page;
        return round((1 - ($freeBytes / (int)$phys)) * 100, 2);
    }
    return null;
}

function disk_used_percent(): ?float
{
    $total = disk_total_space('/');
    $free = disk_free_space('/');
    if ($total && $free) {
        return round((1 - ($free / $total)) * 100, 2);
    }
    return null;
}

function wan_ip(): ?string
{
    return shell_one("route -n get default | awk '/interface:/ {print $2}' | xargs -I{} ifconfig {} | awk '/inet / {print $2; exit}'") ?: null;
}

function collect_status(): array
{
    $load = sys_getloadavg();
    return [
        'hostname' => gethostname() ?: null,
        'opnsense_version' => opnsense_version(),
        'cpu_load' => isset($load[0]) ? round((float)$load[0], 2) : null,
        'memory_used_percent' => memory_used_percent(),
        'disk_used_percent' => disk_used_percent(),
        'uptime_seconds' => uptime_seconds(),
        'wan_ip' => wan_ip(),
    ];
}

function auth_headers(array $config): array
{
    if (($config['device_uuid'] ?? '') === '' || ($config['device_secret'] ?? '') === '') {
        throw new RuntimeException('Device is not registered');
    }
    return [
        'X-Device-UUID: ' . $config['device_uuid'],
        'X-Device-Secret: ' . $config['device_secret'],
    ];
}

function require_registered(array $config): void
{
    if (($config['server_url'] ?? '') === '' || ($config['device_uuid'] ?? '') === '' || ($config['device_secret'] ?? '') === '') {
        throw new RuntimeException('Device is not registered');
    }
}

function do_ping(array $config): array
{
    $server = validate_server_url((string)$config['server_url']);
    return http_json('GET', $server . '/api/ping', [], [], bool01($config['verify_tls'] ?? '1') === '1');
}

function do_register(array $argv): array
{
    $server = validate_server_url((string)arg_value($argv, '--server', ''));
    $uuid = trim((string)arg_value($argv, '--uuid', ''));
    $token = trim((string)arg_value($argv, '--token', ''));
    $verifyTls = bool01(arg_value($argv, '--verify-tls', '1')) === '1';
    if ($uuid === '' || $token === '') {
        throw new RuntimeException('UUID and token are required');
    }
    $resp = http_json('POST', $server . '/api/agent/register', [
        'device_uuid' => $uuid,
        'registration_token' => $token,
        'hostname' => gethostname() ?: '',
        'opnsense_version' => opnsense_version(),
    ], [], $verifyTls);
    if (empty($resp['ok']) || empty($resp['device_secret'])) {
        throw new RuntimeException('Registration failed: ' . json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $config = load_config();
    $config['enabled'] = '1';
    $config['server_url'] = $server;
    $config['device_uuid'] = $uuid;
    $config['device_secret'] = (string)$resp['device_secret'];
    $config['verify_tls'] = $verifyTls ? '1' : '0';
    $config['poll_jobs'] = bool01($config['poll_jobs'] ?? '1');
    save_config($config);
    write_status(['ok' => true, 'last_action' => 'register', 'message' => 'Registered successfully', 'response' => ['device_uuid' => $uuid]]);
    return ['status' => 'ok', 'message' => 'Registered successfully', 'response' => ['device_uuid' => $uuid]];
}

function do_heartbeat(array $config): array
{
    require_registered($config);
    $server = validate_server_url((string)$config['server_url']);
    $status = collect_status();
    $resp = http_json('POST', $server . '/api/agent/heartbeat', $status, auth_headers($config), bool01($config['verify_tls'] ?? '1') === '1');
    write_status(['ok' => true, 'last_action' => 'heartbeat', 'message' => 'Heartbeat sent', 'status_payload' => $status, 'response' => $resp]);
    return ['status' => 'ok', 'message' => 'Heartbeat sent', 'status_payload' => $status, 'response' => $resp];
}

function do_backup(array $config): array
{
    require_registered($config);
    $path = '/conf/config.xml';
    if (!is_readable($path)) {
        throw new RuntimeException('Cannot read ' . $path);
    }
    $server = validate_server_url((string)$config['server_url']);
    $content = (string)file_get_contents($path);
    $resp = http_json('POST', $server . '/api/agent/backup', [
        'content_base64' => base64_encode($content),
        'comment' => 'Additional menu backup',
    ], auth_headers($config), bool01($config['verify_tls'] ?? '1') === '1');
    write_status(['ok' => true, 'last_action' => 'backup', 'message' => 'Backup uploaded', 'response' => $resp]);
    return ['status' => 'ok', 'message' => 'Backup uploaded', 'response' => $resp];
}

function do_job_result(array $config, int $jobId, string $status, array $result, ?string $error): array
{
    $server = validate_server_url((string)$config['server_url']);
    return http_json('POST', $server . '/api/agent/jobs/result', [
        'job_id' => $jobId,
        'status' => $status,
        'result' => $result,
        'error' => $error,
    ], auth_headers($config), bool01($config['verify_tls'] ?? '1') === '1');
}

function do_poll(array $config): array
{
    require_registered($config);
    $server = validate_server_url((string)$config['server_url']);
    $jobsResp = http_json('POST', $server . '/api/agent/jobs/poll', [], auth_headers($config), bool01($config['verify_tls'] ?? '1') === '1');
    $processed = [];
    foreach (($jobsResp['jobs'] ?? []) as $job) {
        $jobId = (int)($job['id'] ?? 0);
        $type = (string)($job['type'] ?? '');
        $status = 'done';
        $result = ['ok' => true];
        $error = null;
        try {
            if ($type === 'status.collect') {
                $result = collect_status();
                http_json('POST', $server . '/api/agent/heartbeat', $result, auth_headers($config), bool01($config['verify_tls'] ?? '1') === '1');
            } elseif ($type === 'config.backup') {
                $r = do_backup($config);
                $result = $r['response'] ?? $r;
            } elseif ($type === 'ping') {
                $result = ['pong' => true, 'time' => date(DATE_ATOM)];
            } else {
                $status = 'failed';
                $error = 'Unknown job type: ' . $type;
                $result = ['ok' => false];
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            $result = ['ok' => false];
        }
        if ($jobId > 0) {
            do_job_result($config, $jobId, $status, $result, $error);
        }
        $processed[] = ['id' => $jobId, 'type' => $type, 'status' => $status, 'error' => $error];
    }
    $count = count($processed);
    write_status(['ok' => true, 'last_action' => 'poll', 'message' => 'Jobs processed: ' . $count, 'processed' => $processed]);
    return ['status' => 'ok', 'message' => 'Jobs processed: ' . $count, 'jobs' => $processed, 'poll_response' => $jobsResp];
}

function do_run_once(array $config): array
{
    if (bool01($config['enabled'] ?? '0') !== '1') {
        write_status(['ok' => true, 'last_action' => 'run-once', 'message' => 'Agent disabled']);
        return ['status' => 'ok', 'message' => 'Agent disabled'];
    }
    $heartbeat = do_heartbeat($config);
    $poll = null;
    if (bool01($config['poll_jobs'] ?? '1') === '1') {
        $poll = do_poll($config);
    }
    write_status(['ok' => true, 'last_action' => 'run-once', 'message' => 'Run once completed', 'heartbeat' => $heartbeat, 'poll' => $poll]);
    return ['status' => 'ok', 'message' => 'Run once completed', 'heartbeat' => $heartbeat, 'poll' => $poll];
}

function public_config(array $config): array
{
    $secret = (string)($config['device_secret'] ?? '');
    $config['registered'] = $secret !== '' ? '1' : '0';
    $config['device_secret_masked'] = $secret !== '' ? substr($secret, 0, 6) . '...' . substr($secret, -6) : '';
    unset($config['device_secret']);
    return $config;
}

$cmd = $argv[1] ?? 'status';
$json = has_arg($argv, '--json');

try {
    $lock = fopen(LOCK_FILE, 'c');
    if ($lock === false) {
        throw new RuntimeException('Cannot open lock file');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        json_out(['status' => 'locked', 'message' => 'Agent is already running', 'agent_status' => read_status()], 0);
    }

    $config = load_config();
    if ($cmd === 'status') {
        $result = ['status' => 'ok', 'config' => public_config($config), 'agent_status' => read_status()];
    } elseif ($cmd === 'register') {
        $result = do_register($argv);
        $result['config'] = public_config(load_config());
        $result['agent_status'] = read_status();
    } elseif ($cmd === 'ping') {
        $result = ['status' => 'ok', 'message' => 'Server ping ok', 'response' => do_ping($config)];
        write_status(['ok' => true, 'last_action' => 'ping', 'message' => 'Server ping ok', 'response' => $result['response']]);
    } elseif ($cmd === 'heartbeat') {
        $result = do_heartbeat($config);
        $result['agent_status'] = read_status();
    } elseif ($cmd === 'backup') {
        $result = do_backup($config);
        $result['agent_status'] = read_status();
    } elseif ($cmd === 'poll') {
        $result = do_poll($config);
        $result['agent_status'] = read_status();
    } elseif ($cmd === 'run-once') {
        $result = do_run_once($config);
        $result['agent_status'] = read_status();
    } else {
        throw new RuntimeException('Unknown command: ' . $cmd);
    }
    if ($json) {
        json_out($result, ($result['status'] ?? 'error') === 'error' ? 1 : 0);
    }
    echo ($result['message'] ?? 'OK') . "\n";
    exit(0);
} catch (Throwable $e) {
    write_status(['ok' => false, 'last_action' => $cmd, 'message' => $e->getMessage()]);
    $result = ['status' => 'error', 'message' => $e->getMessage(), 'agent_status' => read_status()];
    if ($json) {
        json_out($result, 1);
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
