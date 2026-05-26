#!/usr/local/bin/php
<?php

declare(strict_types=1);

const SCRIPT_NAME = 'additional-wireguard-peers';
const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/wireguard_peers.json';
const OPN_CONFIG_FILE = '/conf/config.xml';
const STATUS_FILE = '/var/run/additional_wireguard_peers_status.json';
const LOCK_FILE = '/tmp/additional_wireguard_peers.lock';

function bool01($value): string
{
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
}

function safe_id(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_.-]+/', '_', $value);
    $value = trim((string)$value, '_');
    return $value !== '' ? $value : 'default';
}

function default_config(): array
{
    return [
        'version' => 1,
        'peers' => [],
    ];
}

function load_config(): array
{
    $config = default_config();

    if (is_readable(CONFIG_FILE)) {
        $raw = file_get_contents(CONFIG_FILE);
        $data = json_decode((string)$raw, true);

        if (is_array($data)) {
            $config = array_replace_recursive($config, $data);
        }
    }

    if (!isset($config['peers']) || !is_array($config['peers'])) {
        $config['peers'] = [];
    }

    return $config;
}

function read_status(): array
{
    if (!is_readable(STATUS_FILE)) {
        return [
            'status' => 'unknown',
            'message' => 'Проверка ещё не выполнялась',
            'timestamp' => '',
            'peers' => [],
        ];
    }

    $raw = file_get_contents(STATUS_FILE);
    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        return [
            'status' => 'error',
            'message' => 'Файл статуса повреждён',
            'timestamp' => '',
            'peers' => [],
        ];
    }

    if (!isset($data['peers']) || !is_array($data['peers'])) {
        $data['peers'] = [];
    }

    return $data;
}

function write_status(array $status): void
{
    $status['timestamp'] = date('Y-m-d H:i:s');

    $json = json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json !== false) {
        @file_put_contents(STATUS_FILE, $json . "\n", LOCK_EX);
        @chmod(STATUS_FILE, 0644);
    }
}

function direct_child(DOMElement $node, array $names): ?DOMElement
{
    $lookup = [];
    foreach ($names as $name) {
        $lookup[strtolower($name)] = true;
    }

    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement && isset($lookup[strtolower($child->nodeName)])) {
            return $child;
        }
    }

    return null;
}

function direct_child_text(DOMElement $node, array $names, string $default = ''): string
{
    $child = direct_child($node, $names);
    if ($child === null) {
        return $default;
    }

    return trim((string)$child->textContent);
}

function set_direct_child_text(DOMElement $node, string $name, string $value): void
{
    $child = direct_child($node, [$name]);

    if ($child === null) {
        $child = $node->ownerDocument->createElement($name);
        $node->appendChild($child);
    }

    while ($child->firstChild !== null) {
        $child->removeChild($child->firstChild);
    }

    $child->appendChild($node->ownerDocument->createTextNode($value));
}

function node_enabled(DOMElement $node): bool
{
    $enabled = direct_child_text($node, ['enabled'], '');

    if ($enabled === '') {
        return true;
    }

    return bool01($enabled) === '1';
}

function node_attribute(DOMElement $node, array $names): string
{
    foreach ($names as $name) {
        if ($node->hasAttribute($name)) {
            return trim((string)$node->getAttribute($name));
        }
    }

    return '';
}

function endpoint_parse(string $value): array
{
    $value = trim($value);

    if ($value === '') {
        return ['host' => '', 'port' => ''];
    }

    if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $value, $m)) {
        return ['host' => $m[1], 'port' => $m[2]];
    }

    if (preg_match('/^([^:]+):(\d+)$/', $value, $m)) {
        return ['host' => $m[1], 'port' => $m[2]];
    }

    return ['host' => $value, 'port' => ''];
}

function endpoint_format(string $host, string $port): string
{
    $host = trim($host);
    $port = trim($port);

    if ($port === '') {
        return $host;
    }

    if (strpos($host, ':') !== false && !str_starts_with($host, '[')) {
        return '[' . $host . ']:' . $port;
    }

    return $host . ':' . $port;
}

function peer_endpoint_info(DOMElement $node): array
{
    /*
     * OPNsense WireGuard client peer usually stores endpoint as:
     * serveraddress + serverport.
     * Other possible names are supported to keep the tool version tolerant.
     */
    $combinedNames = ['endpoint', 'serverendpoint', 'peerendpoint'];
    $addressNames = ['serveraddress', 'server_address', 'endpointaddress', 'endpoint_address', 'endpointhost', 'endpoint_host'];
    $portNames = ['serverport', 'server_port', 'endpointport', 'endpoint_port'];

    $combined = direct_child($node, $combinedNames);

    if ($combined !== null) {
        $parsed = endpoint_parse((string)$combined->textContent);
        $port = $parsed['port'];

        if ($port === '') {
            $port = direct_child_text($node, $portNames, '');
        }

        return [
            'type' => 'combined',
            'address_field' => $combined->nodeName,
            'port_field' => '',
            'host' => $parsed['host'],
            'port' => $port,
            'display' => endpoint_format($parsed['host'], $port),
        ];
    }

    $address = direct_child($node, $addressNames);
    if ($address === null) {
        return [
            'type' => 'none',
            'address_field' => '',
            'port_field' => '',
            'host' => '',
            'port' => '',
            'display' => '',
        ];
    }

    $parsed = endpoint_parse((string)$address->textContent);
    $portField = direct_child($node, $portNames);
    $port = $portField !== null ? trim((string)$portField->textContent) : $parsed['port'];

    return [
        'type' => 'separate',
        'address_field' => $address->nodeName,
        'port_field' => $portField !== null ? $portField->nodeName : '',
        'host' => $parsed['host'],
        'port' => $port,
        'display' => endpoint_format($parsed['host'], $port),
    ];
}

function peer_public_key(DOMElement $node): string
{
    return direct_child_text($node, ['publickey', 'public_key', 'pubkey', 'pub_key'], '');
}

function peer_name(DOMElement $node, string $fallback): string
{
    $name = direct_child_text($node, ['name', 'description', 'descr'], '');

    return $name !== '' ? $name : $fallback;
}

function node_path(DOMElement $node): string
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

function peer_id(DOMElement $node, string $path): string
{
    $uuid = node_attribute($node, ['uuid', 'id']);

    if ($uuid !== '') {
        return safe_id($uuid);
    }

    $publicKey = peer_public_key($node);
    $name = direct_child_text($node, ['name', 'description', 'descr'], '');

    return 'peer_' . substr(sha1($path . '|' . $publicKey . '|' . $name), 0, 16);
}

function is_wireguard_peer_candidate(DOMElement $node): bool
{
    $endpoint = peer_endpoint_info($node);

    if ($endpoint['type'] === 'none' || $endpoint['host'] === '') {
        return false;
    }

    $publicKey = peer_public_key($node);
    $tag = strtolower($node->nodeName);

    if ($publicKey !== '') {
        return true;
    }

    /*
     * Some OPNsense versions may not keep the public key in a field name we know.
     * If a node is under WireGuard and has an endpoint-like field, and its tag
     * looks like peer/client, show it but keep the id path-based.
     */
    return strpos($tag, 'peer') !== false || strpos($tag, 'client') !== false;
}

function collect_peer_nodes(DOMElement $node, array &$peers): void
{
    $path = node_path($node);

    if (is_wireguard_peer_candidate($node)) {
        $endpoint = peer_endpoint_info($node);
        $id = peer_id($node, $path);
        $pub = peer_public_key($node);

        $peers[] = [
            'id' => $id,
            'path' => $path,
            'name' => peer_name($node, $id),
            'node_name' => $node->nodeName,
            'config_enabled' => node_enabled($node),
            'public_key' => $pub,
            'public_key_short' => $pub !== '' ? substr($pub, 0, 8) . '…' . substr($pub, -6) : '',
            'endpoint' => $endpoint,
            '_node' => $node,
        ];
    }

    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement) {
            collect_peer_nodes($child, $peers);
        }
    }
}

function load_dom(): DOMDocument
{
    if (!is_readable(OPN_CONFIG_FILE)) {
        throw new RuntimeException('Не удалось прочитать ' . OPN_CONFIG_FILE);
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;

    libxml_use_internal_errors(true);
    $loaded = $dom->load(OPN_CONFIG_FILE);

    if (!$loaded) {
        $errors = [];

        foreach (libxml_get_errors() as $error) {
            $errors[] = trim($error->message);
        }

        libxml_clear_errors();
        throw new RuntimeException('Ошибка чтения config.xml: ' . implode('; ', $errors));
    }

    libxml_clear_errors();

    return $dom;
}

function load_wireguard_peers(?DOMDocument $dom = null): array
{
    $dom = $dom ?? load_dom();
    $peers = [];

    $wireguardNodes = $dom->getElementsByTagName('wireguard');

    if ($wireguardNodes->length > 0) {
        foreach ($wireguardNodes as $wireguard) {
            if ($wireguard instanceof DOMElement) {
                collect_peer_nodes($wireguard, $peers);
            }
        }
    } elseif ($dom->documentElement instanceof DOMElement) {
        collect_peer_nodes($dom->documentElement, $peers);
    }

    $seen = [];
    $result = [];

    foreach ($peers as $peer) {
        if (isset($seen[$peer['id']])) {
            continue;
        }

        $seen[$peer['id']] = true;
        $result[] = $peer;
    }

    usort($result, function ($a, $b) {
        return strnatcasecmp((string)$a['name'], (string)$b['name']);
    });

    return $result;
}

function peer_setting(array $config, string $peerId): array
{
    $item = is_array($config['peers'][$peerId] ?? null) ? $config['peers'][$peerId] : [];

    return [
        'peer_id' => $peerId,
        'enabled' => bool01($item['enabled'] ?? '0'),
        'check_enabled' => bool01($item['check_enabled'] ?? '0'),
        'ip1' => trim((string)($item['ip1'] ?? '')),
        'ip2' => trim((string)($item['ip2'] ?? '')),
        'active_ip' => trim((string)($item['active_ip'] ?? '')),
    ];
}

function status_by_peer_id(array $status): array
{
    $result = [];

    foreach (($status['peers'] ?? []) as $item) {
        if (is_array($item) && !empty($item['peer_id'])) {
            $result[(string)$item['peer_id']] = $item;
        }
    }

    return $result;
}

function public_peer(array $peer, array $setting, array $lastStatus = []): array
{
    unset($peer['_node']);

    $endpoint = $peer['endpoint'] ?? [];

    return [
        'id' => $peer['id'],
        'name' => $peer['name'],
        'node_name' => $peer['node_name'],
        'path' => $peer['path'],
        'config_enabled' => $peer['config_enabled'],
        'public_key_short' => $peer['public_key_short'],
        'current_endpoint' => $endpoint['display'] ?? '',
        'current_host' => $endpoint['host'] ?? '',
        'current_port' => $endpoint['port'] ?? '',
        'settings' => $setting,
        'last_status' => $lastStatus,
    ];
}

function status_payload(): array
{
    $dom = load_dom();
    $peers = load_wireguard_peers($dom);
    $config = load_config();
    $status = read_status();
    $last = status_by_peer_id($status);
    $public = [];

    foreach ($peers as $peer) {
        $setting = peer_setting($config, (string)$peer['id']);
        $public[] = public_peer($peer, $setting, $last[(string)$peer['id']] ?? []);
    }

    return [
        'status' => 'ok',
        'message' => 'OK',
        'timestamp' => date('Y-m-d H:i:s'),
        'scheduler_task' => 'wireguard_peers_check',
        'config' => $config,
        'runtime' => $status,
        'peers' => $public,
    ];
}

function validate_ip_or_empty(string $value): bool
{
    return $value === '' || filter_var($value, FILTER_VALIDATE_IP) !== false;
}

function ping_ip(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    $binary = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '/sbin/ping6' : '/sbin/ping';

    if (!is_executable($binary)) {
        $binary = '/sbin/ping';
    }

    $cmd = sprintf('%s -c 1 -W 1 %s >/dev/null 2>&1', escapeshellcmd($binary), escapeshellarg($ip));
    $output = [];
    $exitCode = 0;

    exec($cmd, $output, $exitCode);

    return $exitCode === 0;
}

function update_peer_endpoint(array $peer, string $newHost): void
{
    /** @var DOMElement $node */
    $node = $peer['_node'];
    $endpoint = $peer['endpoint'];

    if (($endpoint['type'] ?? '') === 'combined') {
        set_direct_child_text(
            $node,
            (string)$endpoint['address_field'],
            endpoint_format($newHost, (string)($endpoint['port'] ?? ''))
        );
        return;
    }

    if (($endpoint['type'] ?? '') === 'separate') {
        set_direct_child_text($node, (string)$endpoint['address_field'], $newHost);
        return;
    }

    throw new RuntimeException('Не удалось определить поле endpoint для peer ' . $peer['name']);
}

function backup_config(): string
{
    $backup = OPN_CONFIG_FILE . '.additional-wireguard-peers.' . date('YmdHis') . '.bak';

    if (!@copy(OPN_CONFIG_FILE, $backup)) {
        throw new RuntimeException('Не удалось создать backup config.xml: ' . $backup);
    }

    @chmod($backup, 0600);

    return $backup;
}

function save_dom(DOMDocument $dom): void
{
    if ($dom->save(OPN_CONFIG_FILE) === false) {
        throw new RuntimeException('Не удалось записать ' . OPN_CONFIG_FILE);
    }

    @chmod(OPN_CONFIG_FILE, 0600);
}

function run_command(string $command): array
{
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    return [
        'exit_code' => $exitCode,
        'output' => trim(implode("\n", $output)),
    ];
}

function restart_wireguard(): array
{
    $commands = [
        '/usr/local/sbin/configctl wireguard restart',
        '/usr/local/sbin/configctl wireguard reconfigure',
        '/usr/local/etc/rc.d/wireguard restart',
        '/usr/sbin/service wireguard restart',
    ];

    $attempts = [];

    foreach ($commands as $command) {
        $binary = preg_split('/\s+/', $command)[0] ?? '';

        if ($binary === '' || !is_executable($binary)) {
            $attempts[] = [
                'command' => $command,
                'exit_code' => 127,
                'output' => 'binary not executable',
            ];
            continue;
        }

        $result = run_command($command);
        $result['command'] = $command;
        $attempts[] = $result;

        if ((int)$result['exit_code'] === 0) {
            return [
                'ok' => true,
                'command' => $command,
                'attempts' => $attempts,
            ];
        }
    }

    return [
        'ok' => false,
        'command' => '',
        'attempts' => $attempts,
    ];
}

function check_peers(): array
{
    $lock = fopen(LOCK_FILE, 'c');

    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return [
            'status' => 'warning',
            'message' => 'Проверка уже выполняется',
            'peers' => [],
        ];
    }

    $dom = load_dom();
    $peers = load_wireguard_peers($dom);
    $config = load_config();

    $changed = false;
    $results = [];

    foreach ($peers as $peer) {
        $peerId = (string)$peer['id'];
        $setting = peer_setting($config, $peerId);
        $endpoint = $peer['endpoint'];
        $currentHost = (string)($endpoint['host'] ?? '');

        $result = [
            'peer_id' => $peerId,
            'name' => $peer['name'],
            'timestamp' => date('Y-m-d H:i:s'),
            'current_host' => $currentHost,
            'current_endpoint' => $endpoint['display'] ?? '',
            'ip1' => $setting['ip1'],
            'ip2' => $setting['ip2'],
            'ip1_ok' => null,
            'ip2_ok' => null,
            'target_ip' => '',
            'switched' => false,
            'state' => 'skipped',
            'message' => 'Проверка для peer выключена',
        ];

        if ($setting['enabled'] !== '1') {
            $results[] = $result;
            continue;
        }

        if ($setting['check_enabled'] !== '1') {
            $result['message'] = 'Режим проверки IP выключен';
            $results[] = $result;
            continue;
        }

        if (!$peer['config_enabled']) {
            $result['state'] = 'disabled_in_wireguard';
            $result['message'] = 'Peer выключен в WireGuard';
            $results[] = $result;
            continue;
        }

        if (!validate_ip_or_empty($setting['ip1']) || !validate_ip_or_empty($setting['ip2']) || $setting['ip1'] === '' || $setting['ip2'] === '') {
            $result['state'] = 'invalid_config';
            $result['message'] = 'Укажите два корректных IP';
            $results[] = $result;
            continue;
        }

        $ip1Ok = ping_ip($setting['ip1']);
        $ip2Ok = ping_ip($setting['ip2']);

        $result['ip1_ok'] = $ip1Ok;
        $result['ip2_ok'] = $ip2Ok;

        if ($ip1Ok) {
            $target = $setting['ip1'];
        } elseif ($ip2Ok) {
            $target = $setting['ip2'];
        } else {
            $target = '';
        }

        $result['target_ip'] = $target;

        if ($target === '') {
            $result['state'] = 'all_unreachable';
            $result['message'] = 'Оба IP недоступны, endpoint не изменён';
            $results[] = $result;
            continue;
        }

        if ($currentHost === $target) {
            $result['state'] = 'ok';
            $result['message'] = 'Endpoint уже установлен на доступный IP';
            $results[] = $result;
            continue;
        }

        update_peer_endpoint($peer, $target);
        $changed = true;

        $result['state'] = 'switched';
        $result['switched'] = true;
        $result['message'] = 'Endpoint переключён: ' . $currentHost . ' → ' . $target;
        $result['previous_host'] = $currentHost;
        $result['new_host'] = $target;
        $results[] = $result;

        $config['peers'][$peerId]['active_ip'] = $target;
    }

    $backup = '';
    $restart = null;

    if ($changed) {
        $backup = backup_config();
        save_dom($dom);
        $restart = restart_wireguard();
    }

    $status = [
        'status' => $changed ? 'changed' : 'ok',
        'message' => $changed ? 'Endpoint WireGuard peer обновлён' : 'Изменений endpoint не требуется',
        'changed' => $changed,
        'backup' => $backup,
        'restart' => $restart,
        'peers' => $results,
    ];

    write_status($status);

    return $status;
}

function print_json(array $payload): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

function parse_args(array $argv): array
{
    $args = [
        'action' => 'status',
        'json' => false,
    ];

    foreach ($argv as $arg) {
        if ($arg === '--json') {
            $args['json'] = true;
        } elseif ($arg === '--check') {
            $args['action'] = 'check';
        } elseif ($arg === '--status') {
            $args['action'] = 'status';
        }
    }

    return $args;
}

$args = parse_args($argv);

try {
    if ($args['action'] === 'check') {
        $result = check_peers();

        if ($args['json']) {
            print_json($result);
        } else {
            echo '[' . SCRIPT_NAME . '] ' . ($result['message'] ?? 'Done') . PHP_EOL;
        }

        exit(($result['status'] ?? '') === 'error' ? 1 : 0);
    }

    $result = status_payload();

    if ($args['json']) {
        print_json($result);
    } else {
        echo '[' . SCRIPT_NAME . '] ' . ($result['message'] ?? 'OK') . PHP_EOL;
    }

    exit(0);
} catch (Throwable $e) {
    $result = [
        'status' => 'error',
        'message' => $e->getMessage(),
        'peers' => [],
    ];

    write_status($result);

    if ($args['json']) {
        print_json($result);
    } else {
        echo '[' . SCRIPT_NAME . '] ERROR: ' . $e->getMessage() . PHP_EOL;
    }

    exit(1);
}
