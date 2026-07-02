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

function shell_lines(string $cmd): array
{
    $out = shell_exec($cmd . ' 2>/dev/null');
    if ($out === null || trim($out) === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', preg_split('/\R/', trim($out)) ?: []), static fn($v): bool => $v !== ''));
}

function freebsd_version(): ?string
{
    return shell_one('freebsd-version') ?: php_uname('r');
}

function collect_dns_servers(): array
{
    $lines = is_readable('/etc/resolv.conf') ? file('/etc/resolv.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $servers = [];
    foreach ($lines ?: [] as $line) {
        if (preg_match('/^\s*nameserver\s+([^\s#]+)/', $line, $m)) {
            $servers[] = $m[1];
        }
    }
    return array_values(array_unique($servers));
}

function collect_interfaces(): array
{
    $names = shell_lines('ifconfig -l');
    if (count($names) === 1 && strpos($names[0], ' ') !== false) {
        $names = preg_split('/\s+/', $names[0], -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $items = [];
    foreach ($names as $name) {
        if ($name === 'lo0' || $name === '') {
            continue;
        }
        $raw = shell_exec('ifconfig ' . escapeshellarg($name) . ' 2>/dev/null') ?: '';
        $ipv4 = [];
        $ipv6 = [];
        if (preg_match_all('/\sinet\s+([^\s]+)/', $raw, $m)) {
            $ipv4 = $m[1];
        }
        if (preg_match_all('/\sinet6\s+([^\s%]+)/', $raw, $m)) {
            $ipv6 = array_values(array_filter($m[1], static fn($ip): bool => $ip !== '::1'));
        }
        preg_match('/ether\s+([^\s]+)/', $raw, $mac);
        $items[] = [
            'name' => $name,
            'status' => str_contains($raw, 'status: active') ? 'active' : (preg_match('/status:\s*([^\n]+)/', $raw, $sm) ? trim($sm[1]) : ''),
            'mac' => $mac[1] ?? '',
            'ipv4' => $ipv4,
            'ipv6' => $ipv6,
        ];
    }
    return $items;
}

function collect_gateways(): array
{
    $lines = shell_lines("netstat -rn -f inet | awk 'NR>4 {print $1 \" \" $2 \" \" $4 \" \" $6}'");
    $items = [];
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', $line);
        if (!$parts || count($parts) < 2) {
            continue;
        }
        if ($parts[0] === 'default' || str_starts_with($parts[0], '0.0.0.0')) {
            $items[] = ['destination' => $parts[0], 'gateway' => $parts[1] ?? '', 'flags' => $parts[2] ?? '', 'interface' => $parts[3] ?? ''];
        }
    }
    return $items;
}

function collect_plugins(): array
{
    $lines = shell_lines("pkg info -x '^os-' | awk '{print $1}'");
    return $lines;
}

function collect_config_interfaces(): array
{
    $path = '/conf/config.xml';
    if (!is_readable($path)) {
        return [];
    }
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path);
    if (!$xml instanceof SimpleXMLElement || !isset($xml->interfaces)) {
        return [];
    }
    $items = [];
    foreach ($xml->interfaces->children() as $key => $iface) {
        $items[(string)$key] = [
            'key' => (string)$key,
            'if' => trim((string)($iface->if ?? '')),
            'descr' => trim((string)($iface->descr ?? '')),
            'ipaddr' => trim((string)($iface->ipaddr ?? '')),
            'subnet' => trim((string)($iface->subnet ?? '')),
            'ipaddrv6' => trim((string)($iface->ipaddrv6 ?? '')),
            'subnetv6' => trim((string)($iface->subnetv6 ?? '')),
            'gateway' => trim((string)($iface->gateway ?? '')),
            'gatewayv6' => trim((string)($iface->gatewayv6 ?? '')),
            'enable' => trim((string)($iface->enable ?? '')),
        ];
    }
    return $items;
}

function collect_config_gateways(): array
{
    $path = '/conf/config.xml';
    if (!is_readable($path)) {
        return [];
    }
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path);
    if (!$xml instanceof SimpleXMLElement) {
        return [];
    }
    $items = [];
    foreach (($xml->xpath('//gateway_item') ?: []) as $gw) {
        $name = trim((string)($gw->name ?? ''));
        if ($name === '') {
            continue;
        }
        $items[$name] = [
            'name' => $name,
            'interface' => trim((string)($gw->interface ?? '')),
            'gateway' => trim((string)($gw->gateway ?? '')),
            'ipprotocol' => trim((string)($gw->ipprotocol ?? '')),
            'descr' => trim((string)($gw->descr ?? $gw->description ?? '')),
        ];
    }
    return $items;
}

function collect_default_route_interfaces(): array
{
    $result = [];
    foreach (['inet', 'inet6'] as $family) {
        $out = shell_exec('/usr/bin/netstat -rn -f ' . $family . ' 2>/dev/null') ?: '';
        foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(Routing|Internet|Destination|Expire|Netif|Name|Use|Flags)/i', $line)) {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) < 4) {
                continue;
            }
            $destination = $parts[0];
            if ($destination !== 'default' && $destination !== '::/0' && !str_starts_with($destination, '0.0.0.0')) {
                continue;
            }
            $netif = $parts[3] ?? ($parts[count($parts) - 1] ?? '');
            if ($netif !== '' && !ctype_digit($netif)) {
                $result[$netif] = true;
            }
        }
    }
    return array_keys($result);
}

function first_wan_ipv4(array $wanInterfaces): ?string
{
    foreach ($wanInterfaces as $wan) {
        foreach (($wan['ipv4'] ?? []) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }
    }
    return null;
}

function collect_wan_interfaces(): array
{
    $configIfs = collect_config_interfaces();
    $gatewayItems = collect_config_gateways();
    $defaultIfs = array_flip(collect_default_route_interfaces());
    $runtime = collect_interfaces();
    $runtimeByName = [];
    foreach ($runtime as $iface) {
        $runtimeByName[(string)($iface['name'] ?? '')] = $iface;
    }

    $items = [];
    foreach ($configIfs as $key => $iface) {
        $ifName = (string)($iface['if'] ?? '');
        if ($ifName === '') {
            continue;
        }
        $descr = (string)($iface['descr'] ?? '');
        $gwName = (string)($iface['gateway'] ?? '');
        $gw6Name = (string)($iface['gatewayv6'] ?? '');
        $isWan = strtolower((string)$key) === 'wan'
            || isset($defaultIfs[$ifName])
            || ($gwName !== '' && strtolower($gwName) !== 'none')
            || ($gw6Name !== '' && strtolower($gw6Name) !== 'none')
            || preg_match('/\b(wan|internet|isp|provider|uplink)\b/i', $key . ' ' . $descr);
        if (!$isWan) {
            continue;
        }
        $rt = $runtimeByName[$ifName] ?? [];
        $gw = $gatewayItems[$gwName] ?? null;
        $gw6 = $gatewayItems[$gw6Name] ?? null;
        $items[] = [
            'key' => (string)$key,
            'interface' => $ifName,
            'descr' => $descr !== '' ? $descr : strtoupper((string)$key),
            'status' => (string)($rt['status'] ?? ''),
            'mac' => (string)($rt['mac'] ?? ''),
            'ipv4' => is_array($rt['ipv4'] ?? null) ? $rt['ipv4'] : [],
            'ipv6' => is_array($rt['ipv6'] ?? null) ? $rt['ipv6'] : [],
            'configured_ipv4' => trim(($iface['ipaddr'] ?? '') . (($iface['subnet'] ?? '') !== '' ? '/' . $iface['subnet'] : '')),
            'configured_ipv6' => trim(($iface['ipaddrv6'] ?? '') . (($iface['subnetv6'] ?? '') !== '' ? '/' . $iface['subnetv6'] : '')),
            'gateway_name' => $gwName,
            'gateway_ip' => is_array($gw) ? (string)($gw['gateway'] ?? '') : '',
            'gatewayv6_name' => $gw6Name,
            'gatewayv6_ip' => is_array($gw6) ? (string)($gw6['gateway'] ?? '') : '',
            'default_route' => isset($defaultIfs[$ifName]),
            'enabled' => bool01($iface['enable'] ?? '1') === '1',
        ];
    }

    if (!$items) {
        $defaultIfsList = collect_default_route_interfaces();
        foreach ($defaultIfsList as $ifName) {
            $rt = $runtimeByName[$ifName] ?? [];
            $items[] = [
                'key' => '',
                'interface' => $ifName,
                'descr' => $ifName,
                'status' => (string)($rt['status'] ?? ''),
                'mac' => (string)($rt['mac'] ?? ''),
                'ipv4' => is_array($rt['ipv4'] ?? null) ? $rt['ipv4'] : [],
                'ipv6' => is_array($rt['ipv6'] ?? null) ? $rt['ipv6'] : [],
                'configured_ipv4' => '',
                'configured_ipv6' => '',
                'gateway_name' => '',
                'gateway_ip' => '',
                'gatewayv6_name' => '',
                'gatewayv6_ip' => '',
                'default_route' => true,
                'enabled' => true,
            ];
        }
    }

    return $items;
}

function collect_status(): array
{
    $load = sys_getloadavg();
    $wanInterfaces = collect_wan_interfaces();
    $wireguard = null;
    try {
        $wireguard = wireguard_status_job();
    } catch (Throwable $e) {
        $wireguard = ['ok' => false, 'error' => $e->getMessage(), 'peers' => [], 'peer_count' => 0];
    }
    return [
        'hostname' => gethostname() ?: null,
        'opnsense_version' => opnsense_version(),
        'freebsd_version' => freebsd_version(),
        'cpu_load' => isset($load[0]) ? round((float)$load[0], 2) : null,
        'cpu_load_5' => isset($load[1]) ? round((float)$load[1], 2) : null,
        'cpu_load_15' => isset($load[2]) ? round((float)$load[2], 2) : null,
        'memory_used_percent' => memory_used_percent(),
        'disk_used_percent' => disk_used_percent(),
        'uptime_seconds' => uptime_seconds(),
        'wan_ip' => first_wan_ipv4($wanInterfaces) ?: wan_ip(),
        'wan_interfaces' => $wanInterfaces,
        'dns_servers' => collect_dns_servers(),
        'interfaces' => collect_interfaces(),
        'gateways' => collect_gateways(),
        'plugins' => collect_plugins(),
        'wireguard' => $wireguard,
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

function do_backup(array $config, string $backupType = 'agent', string $comment = 'Additional menu backup', ?int $sourceJobId = null): array
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
        'comment' => $comment,
        'backup_type' => $backupType,
        'original_filename' => 'config.xml',
        'source_job_id' => $sourceJobId,
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


function alias_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function xml_ensure_child(SimpleXMLElement $node, string $name): SimpleXMLElement
{
    if (!isset($node->{$name})) {
        return $node->addChild($name);
    }
    return $node->{$name};
}

function xml_set_child(SimpleXMLElement $node, string $name, string $value): void
{
    if (!isset($node->{$name})) {
        $node->addChild($name, htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        return;
    }
    $node->{$name} = $value;
}

function run_command_capture(string $command): array
{
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    return [
        'command' => $command,
        'exit_code' => $exitCode,
        'output' => trim(implode("\n", $output)),
    ];
}

function refresh_firewall_aliases(): array
{
    $commands = [];
    if (is_executable('/usr/local/sbin/configctl')) {
        $commands[] = '/usr/local/sbin/configctl filter refresh_aliases';
    }
    if (is_executable('/usr/local/opnsense/scripts/filter/update_tables.py')) {
        $python = is_executable('/usr/local/bin/python3') ? '/usr/local/bin/python3' : '/usr/bin/env python3';
        $commands[] = $python . ' /usr/local/opnsense/scripts/filter/update_tables.py';
    }

    $attempts = [];
    foreach ($commands as $cmd) {
        $result = run_command_capture($cmd);
        $attempts[] = $result;
        if ($result['exit_code'] === 0) {
            return ['status' => 'ok', 'attempts' => $attempts, 'used_command' => $cmd];
        }
    }
    return ['status' => 'skipped', 'message' => 'No successful alias refresh command', 'attempts' => $attempts];
}

function split_alias_items(string $content): array
{
    $items = preg_split('/[\s,;]+/u', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_map('trim', $items)));
}

function valid_alias_item(string $item, string $type): bool
{
    if ($item === '' || preg_match('/[\s"\'<>]/', $item)) {
        return false;
    }
    if ($type === 'host') {
        if (filter_var($item, FILTER_VALIDATE_IP)) {
            return true;
        }
        $host = str_starts_with($item, '*.') ? substr($item, 2) : $item;
        return (bool)preg_match('/^(?=.{1,253}$)([A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/', $host);
    }
    if ($type === 'network') {
        if (filter_var($item, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (strpos($item, '/') === false) {
            return false;
        }
        [$ip, $prefix] = explode('/', $item, 2);
        if (!ctype_digit($prefix)) {
            return false;
        }
        $p = (int)$prefix;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $p >= 0 && $p <= 32;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $p >= 0 && $p <= 128;
        }
    }
    return false;
}

function apply_alias_job(array $payload): array
{
    $name = trim((string)($payload['name'] ?? ''));
    $type = trim((string)($payload['alias_type'] ?? $payload['type'] ?? 'urljson'));
    $content = trim((string)($payload['content'] ?? ''));
    $sourceUrl = trim((string)($payload['source_url'] ?? ''));
    $pathExpression = trim((string)($payload['path_expression'] ?? ''));
    $description = trim((string)($payload['description'] ?? 'Managed by OPNsense Central Controller'));
    $updatefreq = trim((string)($payload['updatefreq'] ?? '1'));
    $proto = trim((string)($payload['proto'] ?? ''));

    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $name)) {
        throw new RuntimeException('Invalid alias name: ' . $name);
    }
    if (!in_array($type, ['urljson', 'urltable', 'host', 'network'], true)) {
        throw new RuntimeException('Unsupported alias type: ' . $type);
    }

    if (in_array($type, ['urljson', 'urltable'], true)) {
        if ($content === '') {
            $content = $sourceUrl;
        }
        if ($content === '' || !preg_match('#^https?://#i', $content)) {
            throw new RuntimeException('Invalid alias source URL');
        }
        if ($type === 'urljson' && $pathExpression === '') {
            throw new RuntimeException('Path expression is required for urljson alias');
        }
        if ($updatefreq === '') {
            $updatefreq = '1';
        }
        if (!preg_match('/^[0-9]+$/', $updatefreq)) {
            throw new RuntimeException('Invalid updatefreq: ' . $updatefreq);
        }
    } else {
        $items = split_alias_items($content);
        if (!$items) {
            throw new RuntimeException('Alias content is empty');
        }
        $bad = [];
        foreach ($items as $item) {
            if (!valid_alias_item($item, $type)) {
                $bad[] = $item;
            }
        }
        if ($bad) {
            throw new RuntimeException('Invalid alias items: ' . implode(', ', array_slice($bad, 0, 10)));
        }
        $content = implode("\n", $items);
        $pathExpression = '';
        $updatefreq = '';
    }

    if ($proto !== '' && !in_array($proto, ['IPv4', 'IPv6', 'IPv4,IPv6', 'IPv6,IPv4'], true)) {
        throw new RuntimeException('Invalid proto: ' . $proto);
    }

    $configPath = '/conf/config.xml';
    if (!is_readable($configPath) || !is_writable($configPath)) {
        throw new RuntimeException('Cannot read/write ' . $configPath);
    }

    $backupPath = '/conf/config.xml.controller_alias_' . date('Ymd_His') . '.bak';
    if (!copy($configPath, $backupPath)) {
        throw new RuntimeException('Cannot create config backup ' . $backupPath);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($configPath);
    if (!$xml instanceof SimpleXMLElement) {
        $errors = array_map(static fn($e) => trim($e->message), libxml_get_errors());
        throw new RuntimeException('Cannot parse config.xml: ' . implode('; ', $errors));
    }

    $opnsense = xml_ensure_child($xml, 'OPNsense');
    $firewall = xml_ensure_child($opnsense, 'Firewall');
    $aliasRoot = xml_ensure_child($firewall, 'Alias');
    $aliases = xml_ensure_child($aliasRoot, 'aliases');

    $entry = null;
    foreach ($aliases->children() as $child) {
        if ($child->getName() === 'alias' && (string)$child->name === $name) {
            $entry = $child;
            break;
        }
    }
    $created = false;
    if (!$entry instanceof SimpleXMLElement) {
        $entry = $aliases->addChild('alias');
        $entry->addAttribute('uuid', alias_uuid_v4());
        $created = true;
    }

    xml_set_child($entry, 'enabled', '1');
    xml_set_child($entry, 'name', $name);
    xml_set_child($entry, 'type', $type);
    xml_set_child($entry, 'path_expression', $type === 'urljson' ? $pathExpression : '');
    xml_set_child($entry, 'proto', $proto);
    xml_set_child($entry, 'counters', (string)($payload['counters'] ?? '0'));
    xml_set_child($entry, 'updatefreq', in_array($type, ['urljson', 'urltable'], true) ? ($updatefreq === '' ? '1' : $updatefreq) : '');
    xml_set_child($entry, 'content', $content);
    xml_set_child($entry, 'description', $description);

    $tmpPath = $configPath . '.controller_alias_tmp';
    if ($xml->asXML($tmpPath) === false) {
        throw new RuntimeException('Cannot write temporary config ' . $tmpPath);
    }
    chmod($tmpPath, 0600);
    if (!rename($tmpPath, $configPath)) {
        @unlink($tmpPath);
        throw new RuntimeException('Cannot replace ' . $configPath);
    }

    $refresh = refresh_firewall_aliases();
    return [
        'ok' => true,
        'alias_id' => $payload['alias_id'] ?? null,
        'name' => $name,
        'type' => $type,
        'created' => $created,
        'item_count' => in_array($type, ['host', 'network'], true) ? count(split_alias_items($content)) : null,
        'config_backup' => $backupPath,
        'refresh' => $refresh,
    ];
}


function validate_xml_string(string $content): void
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($content);
    if (!$xml instanceof SimpleXMLElement) {
        $errors = array_map(static fn($e) => trim($e->message), libxml_get_errors());
        throw new RuntimeException('Invalid XML: ' . implode('; ', $errors));
    }
}

function restore_config_job(array $config, array $payload, int $jobId): array
{
    $contentBase64 = (string)($payload['content_base64'] ?? '');
    if ($contentBase64 === '') {
        throw new RuntimeException('content_base64 is required');
    }
    $content = base64_decode($contentBase64, true);
    if ($content === false) {
        throw new RuntimeException('Invalid base64 restore payload');
    }
    $expectedSha = trim((string)($payload['backup_sha256'] ?? ''));
    $actualSha = hash('sha256', $content);
    if ($expectedSha !== '' && !hash_equals($expectedSha, $actualSha)) {
        throw new RuntimeException('Backup sha256 mismatch');
    }
    validate_xml_string($content);

    $configPath = '/conf/config.xml';
    if (!is_readable($configPath) || !is_writable($configPath)) {
        throw new RuntimeException('Cannot read/write ' . $configPath);
    }

    // Send a central backup of the current state before replacing config.xml.
    try {
        do_backup($config, 'before_restore', 'Automatic backup before config.restore job #' . $jobId, $jobId);
    } catch (Throwable $e) {
        // Keep going only after local backup succeeds below. Central upload failure should not block emergency rollback.
    }

    $localBackup = '/conf/config.xml.controller_restore_before_' . date('Ymd_His') . '.bak';
    if (!copy($configPath, $localBackup)) {
        throw new RuntimeException('Cannot create local pre-restore backup ' . $localBackup);
    }

    $tmpPath = $configPath . '.controller_restore_tmp';
    if (file_put_contents($tmpPath, $content, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write temporary restore config');
    }
    chmod($tmpPath, 0600);
    validate_xml_string((string)file_get_contents($tmpPath));
    if (!rename($tmpPath, $configPath)) {
        @unlink($tmpPath);
        throw new RuntimeException('Cannot replace ' . $configPath);
    }

    $reload = [];
    if (is_executable('/usr/local/sbin/configctl')) {
        $reload[] = run_command_capture('/usr/local/sbin/configctl filter reload');
    }
    return [
        'ok' => true,
        'restored_backup_id' => $payload['backup_id'] ?? null,
        'sha256' => $actualSha,
        'local_pre_restore_backup' => $localBackup,
        'reload' => $reload,
    ];
}

function dom_name_key(string $name): string
{
    return strtolower(str_replace(['_', '-'], '', $name));
}

function dom_direct_child(DOMElement $node, array $names): ?DOMElement
{
    $lookup = [];
    foreach ($names as $name) {
        $lookup[strtolower($name)] = true;
        $lookup[dom_name_key($name)] = true;
    }
    foreach ($node->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }
        $nodeName = strtolower($child->nodeName);
        if (isset($lookup[$nodeName]) || isset($lookup[dom_name_key($child->nodeName)])) {
            return $child;
        }
    }
    return null;
}

function dom_text(DOMElement $node, array $names, string $default = ''): string
{
    $child = dom_direct_child($node, $names);
    return $child instanceof DOMElement ? trim((string)$child->textContent) : $default;
}

function dom_set_text(DOMElement $node, string $name, string $value): void
{
    $child = dom_direct_child($node, [$name]);
    if (!$child instanceof DOMElement) {
        $child = $node->ownerDocument->createElement($name);
        $node->appendChild($child);
    }
    while ($child->firstChild) {
        $child->removeChild($child->firstChild);
    }
    $child->appendChild($node->ownerDocument->createTextNode($value));
}

function dom_node_path(DOMElement $node): string
{
    $parts = [];
    $current = $node;
    while ($current instanceof DOMElement) {
        $index = 1;
        $sibling = $current->previousSibling;
        while ($sibling !== null) {
            if ($sibling instanceof DOMElement && $sibling->nodeName === $current->nodeName) {
                $index++;
            }
            $sibling = $sibling->previousSibling;
        }
        array_unshift($parts, $current->nodeName . '[' . $index . ']');
        $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
    }
    return '/' . implode('/', $parts);
}

function safe_peer_id(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_.-]+/', '_', $value);
    $value = trim((string)$value, '_');
    return $value !== '' ? $value : 'peer';
}

function wg_split_list(string $value): array
{
    $parts = preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_map('trim', $parts)));
}

function wg_endpoint_info(DOMElement $node): array
{
    $combined = dom_text($node, ['endpoint', 'serverendpoint', 'peerendpoint'], '');
    $host = '';
    $port = '';
    if ($combined !== '') {
        if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $combined, $m)) {
            $host = $m[1];
            $port = $m[2];
        } elseif (preg_match('/^([^:]+):(\d+)$/', $combined, $m)) {
            $host = $m[1];
            $port = $m[2];
        } else {
            $host = $combined;
        }
    }
    $host = dom_text($node, ['serveraddress', 'server_address', 'server-address', 'endpointaddress', 'endpoint_address', 'endpoint-address', 'endpointhost', 'endpoint_host', 'endpoint-host'], $host);
    $port = dom_text($node, ['serverport', 'server_port', 'server-port', 'endpointport', 'endpoint_port', 'endpoint-port'], $port);
    $display = $host;
    if ($host !== '' && $port !== '') {
        $display = (strpos($host, ':') !== false && !str_starts_with($host, '[') ? '[' . $host . ']' : $host) . ':' . $port;
    }
    return ['host' => $host, 'port' => $port, 'display' => $display];
}

function wg_public_key(DOMElement $node): string
{
    return dom_text($node, ['publickey', 'public_key', 'public-key', 'pubkey', 'pub_key', 'pub-key'], '');
}

function wg_allowed_ips(DOMElement $node): string
{
    return dom_text($node, ['allowedips', 'allowed_ips', 'allowed-ips', 'allowedip', 'allowed_ip', 'allowed-ip', 'tunneladdress', 'tunnel_address', 'tunnel-address', 'tunneladdresses', 'tunnel_addresses', 'tunnel-addresses'], '');
}

function wg_peer_id(DOMElement $node, string $path): string
{
    foreach (['uuid', 'id'] as $attr) {
        if ($node->hasAttribute($attr) && trim($node->getAttribute($attr)) !== '') {
            return safe_peer_id($node->getAttribute($attr));
        }
    }
    $childId = dom_text($node, ['uuid', 'id'], '');
    if ($childId !== '') {
        return safe_peer_id($childId);
    }
    $publicKey = wg_public_key($node);
    $name = dom_text($node, ['name', 'description', 'descr'], '');
    return 'peer_' . substr(sha1($path . '|' . $publicKey . '|' . $name), 0, 16);
}

function wg_is_peer_candidate(DOMElement $node): bool
{
    $tag = strtolower($node->nodeName);
    $publicKey = wg_public_key($node);
    $endpoint = wg_endpoint_info($node);
    $allowed = wg_allowed_ips($node);
    if ($publicKey === '' && $endpoint['display'] === '' && $allowed === '') {
        return false;
    }
    if (str_contains($tag, 'peer') || str_contains($tag, 'client') || str_contains($tag, 'endpoint')) {
        return true;
    }
    return $publicKey !== '' && ($endpoint['display'] !== '' || $allowed !== '');
}

function wg_collect_peer_nodes(DOMElement $node, array &$result): void
{
    if (wg_is_peer_candidate($node)) {
        $path = dom_node_path($node);
        $peerId = wg_peer_id($node, $path);
        if (!isset($result[$peerId])) {
            $endpoint = wg_endpoint_info($node);
            $enabledRaw = dom_text($node, ['enabled'], '1');
            $publicKey = wg_public_key($node);
            $name = dom_text($node, ['name', 'description', 'descr'], '');
            $allowed = wg_allowed_ips($node);
            $result[$peerId] = [
                '_node' => $node,
                'peer_id' => $peerId,
                'name' => $name !== '' ? $name : $peerId,
                'enabled' => bool01($enabledRaw) === '1',
                'public_key' => $publicKey,
                'public_key_short' => $publicKey !== '' ? substr($publicKey, 0, 8) . '...' . substr($publicKey, -6) : '',
                'endpoint' => $endpoint['display'],
                'endpoint_host' => $endpoint['host'],
                'endpoint_port' => $endpoint['port'],
                'allowed_ips' => wg_split_list($allowed),
                'config_path' => $path,
                'node_name' => $node->nodeName,
            ];
        }
        return;
    }
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement) {
            wg_collect_peer_nodes($child, $result);
        }
    }
}

function collect_wg_config_peers_with_nodes(): array
{
    $path = '/conf/config.xml';
    if (!is_readable($path)) {
        return [];
    }
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    libxml_use_internal_errors(true);
    if (!$dom->load($path)) {
        return [];
    }
    $peers = [];
    $wireguardNodes = $dom->getElementsByTagName('wireguard');
    if ($wireguardNodes->length > 0) {
        foreach ($wireguardNodes as $wireguard) {
            if ($wireguard instanceof DOMElement) {
                wg_collect_peer_nodes($wireguard, $peers);
            }
        }
    } elseif ($dom->documentElement instanceof DOMElement) {
        wg_collect_peer_nodes($dom->documentElement, $peers);
    }
    return $peers;
}

function collect_wg_config_peers(): array
{
    $peers = collect_wg_config_peers_with_nodes();
    foreach ($peers as &$peer) {
        unset($peer['_node']);
    }
    unset($peer);
    return array_values($peers);
}

function wg_binary(): string
{
    foreach (['/usr/local/bin/wg', '/usr/bin/wg', '/bin/wg'] as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }
    $found = trim((string)(shell_exec('command -v wg 2>/dev/null') ?: ''));
    return $found !== '' ? $found : 'wg';
}

function wg_dump_peers(): array
{
    $cmd = escapeshellcmd(wg_binary()) . ' show all dump';
    $lines = shell_lines($cmd);
    $map = [];
    foreach ($lines as $line) {
        $p = explode("\t", $line);
        if (count($p) < 9) {
            continue;
        }
        $publicKey = $p[1] ?? '';
        if ($publicKey === '' || $publicKey === '(none)') {
            continue;
        }
        $ts = ctype_digit((string)$p[5]) ? (int)$p[5] : 0;
        $map[$publicKey] = [
            'interface' => $p[0] ?? '',
            'endpoint_runtime' => $p[3] ?? '',
            'allowed_ips_runtime' => $p[4] ?? '',
            'latest_handshake_ts' => $ts,
            'latest_handshake' => $ts > 0 ? date('Y-m-d H:i:s', $ts) : '',
            'transfer_rx' => ctype_digit((string)($p[6] ?? '')) ? (int)$p[6] : 0,
            'transfer_tx' => ctype_digit((string)($p[7] ?? '')) ? (int)$p[7] : 0,
        ];
    }
    return $map;
}

function wireguard_status_job(): array
{
    $peers = collect_wg_config_peers();
    $runtime = wg_dump_peers();
    $now = time();
    $seenRuntime = [];
    foreach ($peers as &$peer) {
        $pub = (string)($peer['public_key'] ?? '');
        if ($pub !== '' && isset($runtime[$pub])) {
            $peer = array_merge($peer, $runtime[$pub]);
            $seenRuntime[$pub] = true;
        }
        if (($peer['endpoint'] ?? '') === '' && ($peer['endpoint_runtime'] ?? '') !== '') {
            $peer['endpoint'] = $peer['endpoint_runtime'];
        }
        if (!$peer['allowed_ips'] && ($peer['allowed_ips_runtime'] ?? '') !== '') {
            $peer['allowed_ips'] = wg_split_list((string)$peer['allowed_ips_runtime']);
        }
        $ts = (int)($peer['latest_handshake_ts'] ?? 0);
        $peer['stale'] = !empty($peer['enabled']) && ($ts === 0 || ($now - $ts) > 1800);
    }
    unset($peer);

    foreach ($runtime as $publicKey => $rt) {
        if (isset($seenRuntime[$publicKey])) {
            continue;
        }
        $peerId = 'runtime_' . substr(sha1($publicKey), 0, 16);
        $ts = (int)($rt['latest_handshake_ts'] ?? 0);
        $peers[] = array_merge([
            'peer_id' => $peerId,
            'name' => ($rt['interface'] ?? 'wg') . ' ' . substr($publicKey, 0, 8) . '...' . substr($publicKey, -6),
            'enabled' => true,
            'public_key' => $publicKey,
            'public_key_short' => substr($publicKey, 0, 8) . '...' . substr($publicKey, -6),
            'endpoint' => $rt['endpoint_runtime'] ?? '',
            'allowed_ips' => wg_split_list((string)($rt['allowed_ips_runtime'] ?? '')),
            'config_path' => '',
            'node_name' => 'runtime',
        ], $rt, [
            'stale' => $ts === 0 || ($now - $ts) > 1800,
        ]);
    }

    usort($peers, static fn($a, $b): int => strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
    return [
        'ok' => true,
        'collected_at' => date(DATE_ATOM),
        'peer_count' => count($peers),
        'peers' => $peers,
        'wg_available' => trim((string)(shell_exec('command -v wg 2>/dev/null') ?: '')) !== '' || is_executable('/usr/local/bin/wg'),
    ];
}

function wireguard_set_peer_enabled_job(array $payload): array
{
    $peerId = safe_peer_id((string)($payload['peer_id'] ?? ''));
    $enabled = bool01($payload['enabled'] ?? '1');
    if ($peerId === '' || $peerId === 'peer' || str_starts_with($peerId, 'runtime_')) {
        throw new RuntimeException('Config peer_id is required');
    }
    $path = '/conf/config.xml';
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    libxml_use_internal_errors(true);
    if (!$dom->load($path)) {
        throw new RuntimeException('Cannot parse config.xml');
    }
    $peers = [];
    $wireguardNodes = $dom->getElementsByTagName('wireguard');
    if ($wireguardNodes->length > 0) {
        foreach ($wireguardNodes as $wireguard) {
            if ($wireguard instanceof DOMElement) {
                wg_collect_peer_nodes($wireguard, $peers);
            }
        }
    } elseif ($dom->documentElement instanceof DOMElement) {
        wg_collect_peer_nodes($dom->documentElement, $peers);
    }
    $target = isset($peers[$peerId]) && ($peers[$peerId]['_node'] ?? null) instanceof DOMElement ? $peers[$peerId]['_node'] : null;
    if (!$target instanceof DOMElement) {
        throw new RuntimeException('WireGuard peer not found: ' . $peerId);
    }
    $localBackup = '/conf/config.xml.controller_wg_' . date('Ymd_His') . '.bak';
    if (!copy($path, $localBackup)) {
        throw new RuntimeException('Cannot create local WireGuard backup');
    }
    dom_set_text($target, 'enabled', $enabled);
    $tmp = $path . '.controller_wg_tmp';
    if (!$dom->save($tmp)) {
        throw new RuntimeException('Cannot write temporary config');
    }
    chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot replace config.xml');
    }
    $reload = [];
    if (is_executable('/usr/local/sbin/configctl')) {
        foreach (['/usr/local/sbin/configctl wireguard reload', '/usr/local/sbin/configctl wireguard restart', '/usr/local/sbin/configctl filter reload'] as $cmd) {
            $reload[] = run_command_capture($cmd);
        }
    }
    return ['ok' => true, 'peer_id' => $peerId, 'enabled' => $enabled, 'local_backup' => $localBackup, 'reload' => $reload];
}

function controller_uuid_v4(): string
{
    return alias_uuid_v4();
}

function dom_ensure_path(DOMDocument $dom, DOMElement $root, array $names): DOMElement
{
    $node = $root;
    foreach ($names as $name) {
        $found = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === strtolower($name)) {
                $found = $child;
                break;
            }
        }
        if (!$found instanceof DOMElement) {
            $found = $dom->createElement($name);
            $node->appendChild($found);
        }
        $node = $found;
    }
    return $node;
}

function dom_remove_children(DOMElement $node): void
{
    while ($node->firstChild) {
        $node->removeChild($node->firstChild);
    }
}

function dom_bool_text(bool $value): string
{
    return $value ? '1' : '0';
}

function fw_rule_key(array $payload): string
{
    $name = trim((string)($payload['name'] ?? ''));
    if ($name === '') {
        $name = 'template_' . (int)($payload['template_id'] ?? 0);
    }
    return 'central:' . $name;
}

function normalize_pf_protocol(string $protocol): string
{
    $protocol = strtolower(trim($protocol));
    if ($protocol === 'tcp_udp') {
        return 'TCP/UDP';
    }
    return $protocol === 'any' ? 'any' : $protocol;
}

function fw_set_endpoint(DOMDocument $dom, DOMElement $parent, string $value, string $port): void
{
    dom_remove_children($parent);
    $value = trim($value) ?: 'any';
    $port = trim($port);
    if (strtolower($value) === 'any' || $value === '*') {
        $parent->appendChild($dom->createElement('any', '1'));
    } elseif (preg_match('/\s+net$/i', $value)) {
        $parent->appendChild($dom->createElement('network', $value));
    } else {
        $parent->appendChild($dom->createElement('address', $value));
    }
    if ($port !== '' && strtolower($port) !== 'any') {
        $parent->appendChild($dom->createElement('port', $port));
    }
}

function apply_firewall_rule_template_job(array $payload): array
{
    $name = trim((string)($payload['name'] ?? ''));
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_\-]{0,63}$/', $name)) {
        throw new RuntimeException('Invalid firewall template name: ' . $name);
    }
    $action = strtolower(trim((string)($payload['action'] ?? 'pass')));
    if (!in_array($action, ['pass', 'block', 'reject'], true)) {
        throw new RuntimeException('Unsupported firewall action: ' . $action);
    }
    $direction = strtolower(trim((string)($payload['direction'] ?? 'in')));
    if (!in_array($direction, ['in', 'out'], true)) {
        throw new RuntimeException('Unsupported firewall direction: ' . $direction);
    }
    $ipprotocol = strtolower(trim((string)($payload['ipprotocol'] ?? 'inet46')));
    if (!in_array($ipprotocol, ['inet', 'inet6', 'inet46'], true)) {
        throw new RuntimeException('Unsupported ipprotocol: ' . $ipprotocol);
    }
    $protocol = normalize_pf_protocol((string)($payload['protocol'] ?? 'any'));
    $interface = trim((string)($payload['interface'] ?? 'lan'));
    if ($interface === '' || preg_match('/[^A-Za-z0-9_.\-]/', $interface)) {
        throw new RuntimeException('Invalid interface: ' . $interface);
    }

    $configPath = '/conf/config.xml';
    if (!is_readable($configPath) || !is_writable($configPath)) {
        throw new RuntimeException('Cannot read/write ' . $configPath);
    }
    $localBackup = '/conf/config.xml.controller_firewall_' . date('Ymd_His') . '.bak';
    if (!copy($configPath, $localBackup)) {
        throw new RuntimeException('Cannot create local firewall backup');
    }

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    if (!$dom->load($configPath)) {
        throw new RuntimeException('Cannot parse config.xml');
    }
    $root = $dom->documentElement;
    if (!$root instanceof DOMElement) {
        throw new RuntimeException('Invalid config.xml root');
    }
    $filter = dom_ensure_path($dom, $root, ['filter']);
    $rule = null;
    foreach ($filter->childNodes as $child) {
        if ($child instanceof DOMElement && strtolower($child->nodeName) === 'rule') {
            $descr = dom_text($child, ['descr', 'description'], '');
            if (str_contains($descr, fw_rule_key($payload))) {
                $rule = $child;
                break;
            }
        }
    }
    $created = false;
    if (!$rule instanceof DOMElement) {
        $rule = $dom->createElement('rule');
        $rule->setAttribute('uuid', controller_uuid_v4());
        $filter->appendChild($rule);
        $created = true;
    }

    dom_set_text($rule, 'type', $action);
    dom_set_text($rule, 'interface', $interface);
    dom_set_text($rule, 'ipprotocol', $ipprotocol);
    dom_set_text($rule, 'statetype', 'keep state');
    dom_set_text($rule, 'direction', $direction);
    dom_set_text($rule, 'quick', dom_bool_text(!empty($payload['quick'])));
    dom_set_text($rule, 'protocol', $protocol);
    dom_set_text($rule, 'log', dom_bool_text(!empty($payload['log'])));
    if (empty($payload['enabled'])) {
        dom_set_text($rule, 'disabled', '1');
    } else {
        $disabled = dom_direct_child($rule, ['disabled']);
        if ($disabled instanceof DOMElement) {
            $rule->removeChild($disabled);
        }
    }
    $source = dom_direct_child($rule, ['source']);
    if (!$source instanceof DOMElement) {
        $source = $dom->createElement('source');
        $rule->appendChild($source);
    }
    $destination = dom_direct_child($rule, ['destination']);
    if (!$destination instanceof DOMElement) {
        $destination = $dom->createElement('destination');
        $rule->appendChild($destination);
    }
    fw_set_endpoint($dom, $source, (string)($payload['source'] ?? 'any'), (string)($payload['source_port'] ?? ''));
    fw_set_endpoint($dom, $destination, (string)($payload['destination'] ?? 'any'), (string)($payload['destination_port'] ?? ''));
    $descr = trim((string)($payload['description'] ?? 'Managed by OPNsense Central Controller'));
    dom_set_text($rule, 'descr', $descr . ' [' . fw_rule_key($payload) . ']');
    dom_set_text($rule, 'category', trim((string)($payload['category'] ?? 'Central Controller')) ?: 'Central Controller');

    $tmp = $configPath . '.controller_firewall_tmp';
    if (!$dom->save($tmp)) {
        throw new RuntimeException('Cannot write temporary config');
    }
    chmod($tmp, 0600);
    if (!rename($tmp, $configPath)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot replace config.xml');
    }
    $reload = [];
    if (is_executable('/usr/local/sbin/configctl')) {
        $reload[] = run_command_capture('/usr/local/sbin/configctl filter reload');
    }
    return ['ok' => true, 'template_id' => $payload['template_id'] ?? null, 'name' => $name, 'created' => $created, 'local_backup' => $localBackup, 'reload' => $reload];
}

function dns_override_key(array $payload): string
{
    $name = trim((string)($payload['name'] ?? ''));
    if ($name === '') {
        $name = 'override_' . (int)($payload['override_id'] ?? 0);
    }
    return 'central:' . $name;
}

function apply_dns_override_job(array $payload): array
{
    $name = trim((string)($payload['name'] ?? ''));
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_\-]{0,63}$/', $name)) {
        throw new RuntimeException('Invalid DNS override name: ' . $name);
    }
    $type = strtolower(trim((string)($payload['override_type'] ?? 'host')));
    if (!in_array($type, ['host', 'domain'], true)) {
        throw new RuntimeException('Unsupported DNS override type: ' . $type);
    }
    $rr = strtoupper(trim((string)($payload['rr'] ?? 'A')));
    $domain = strtolower(trim((string)($payload['domain'] ?? '')));
    $hostname = strtolower(trim((string)($payload['hostname'] ?? '')));
    $value = trim((string)($payload['value'] ?? ''));
    if ($domain === '' || $value === '') {
        throw new RuntimeException('domain and value are required');
    }

    $configPath = '/conf/config.xml';
    if (!is_readable($configPath) || !is_writable($configPath)) {
        throw new RuntimeException('Cannot read/write ' . $configPath);
    }
    $localBackup = '/conf/config.xml.controller_dns_' . date('Ymd_His') . '.bak';
    if (!copy($configPath, $localBackup)) {
        throw new RuntimeException('Cannot create local DNS backup');
    }

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    if (!$dom->load($configPath)) {
        throw new RuntimeException('Cannot parse config.xml');
    }
    $root = $dom->documentElement;
    if (!$root instanceof DOMElement) {
        throw new RuntimeException('Invalid config.xml root');
    }
    $opnsense = dom_ensure_path($dom, $root, ['OPNsense']);
    $unbound = dom_ensure_path($dom, $opnsense, ['unboundplus']);
    $container = dom_ensure_path($dom, $unbound, [$type === 'host' ? 'hosts' : 'domains']);

    $entryTag = $type === 'host' ? 'host' : 'domain';
    $entry = null;
    foreach ($container->childNodes as $child) {
        if ($child instanceof DOMElement && strtolower($child->nodeName) === $entryTag) {
            $desc = dom_text($child, ['description', 'descr'], '');
            if (str_contains($desc, dns_override_key($payload))) {
                $entry = $child;
                break;
            }
        }
    }
    $created = false;
    if (!$entry instanceof DOMElement) {
        $entry = $dom->createElement($entryTag);
        $entry->setAttribute('uuid', controller_uuid_v4());
        $container->appendChild($entry);
        $created = true;
    }

    dom_set_text($entry, 'enabled', dom_bool_text(!empty($payload['enabled'])));
    if ($type === 'host') {
        dom_set_text($entry, 'hostname', $hostname);
        dom_set_text($entry, 'domain', $domain);
        dom_set_text($entry, 'rr', $rr);
        if ($rr === 'MX') {
            dom_set_text($entry, 'mx', $value);
            dom_set_text($entry, 'mxprio', trim((string)($payload['mx_priority'] ?? '')) ?: '10');
            dom_set_text($entry, 'server', '');
        } else {
            dom_set_text($entry, 'server', $value);
            dom_set_text($entry, 'mx', '');
            dom_set_text($entry, 'mxprio', '');
        }
    } else {
        dom_set_text($entry, 'domain', $domain);
        dom_set_text($entry, 'server', $value);
    }
    $descr = trim((string)($payload['description'] ?? 'Managed DNS override'));
    dom_set_text($entry, 'description', $descr . ' [' . dns_override_key($payload) . ']');

    $tmp = $configPath . '.controller_dns_tmp';
    if (!$dom->save($tmp)) {
        throw new RuntimeException('Cannot write temporary config');
    }
    chmod($tmp, 0600);
    if (!rename($tmp, $configPath)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot replace config.xml');
    }
    $reload = [];
    if (is_executable('/usr/local/sbin/configctl')) {
        $reload[] = run_command_capture('/usr/local/sbin/configctl unbound reload');
        $reload[] = run_command_capture('/usr/local/sbin/configctl template reload OPNsense/Unbound');
    }
    return ['ok' => true, 'override_id' => $payload['override_id'] ?? null, 'name' => $name, 'type' => $type, 'created' => $created, 'local_backup' => $localBackup, 'reload' => $reload];
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
            if ($type === 'status.collect' || $type === 'system.info') {
                $result = collect_status();
                http_json('POST', $server . '/api/agent/heartbeat', $result, auth_headers($config), bool01($config['verify_tls'] ?? '1') === '1');
            } elseif ($type === 'config.backup') {
                $r = do_backup($config, 'manual', 'Backup requested by central controller', $jobId);
                $result = $r['response'] ?? $r;
            } elseif ($type === 'config.restore') {
                $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
                $result = restore_config_job($config, $payload, $jobId);
            } elseif ($type === 'wireguard.status') {
                $result = wireguard_status_job();
            } elseif ($type === 'wireguard.peer.set_enabled') {
                $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
                $result = wireguard_set_peer_enabled_job($payload);
                $result['status_after'] = wireguard_status_job();
            } elseif ($type === 'ping') {
                $result = ['pong' => true, 'time' => date(DATE_ATOM)];
            } elseif ($type === 'alias.apply') {
                $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
                $result = apply_alias_job($payload);
            } elseif ($type === 'firewall.rule_template.apply') {
                $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
                $result = apply_firewall_rule_template_job($payload);
            } elseif ($type === 'dns.override.apply') {
                $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
                $result = apply_dns_override_job($payload);
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
