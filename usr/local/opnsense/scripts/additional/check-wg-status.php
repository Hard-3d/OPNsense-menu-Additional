#!/usr/local/bin/php
<?php

declare(strict_types=1);

const SCRIPT_NAME = 'additional-check-wg-status';
const VARIABLES_FILE = '/usr/local/opnsense/scripts/variables';
const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/check_status.json';
const OPN_CONFIG_FILE = '/conf/config.xml';
const STATUS_FILE = '/var/run/additional_check_status_wireguard.json';
const LOCK_FILE = '/tmp/additional_check_wg_status.lock';

function out_msg(string $message, bool $silent = false): void
{
    if (!$silent) {
        echo '[' . SCRIPT_NAME . '] ' . $message . PHP_EOL;
    }
}

function write_status(array $data): void
{
    $data['timestamp'] = date('Y-m-d H:i:s');
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json !== false) {
        @file_put_contents(STATUS_FILE, $json . "\n", LOCK_EX);
        @chmod(STATUS_FILE, 0644);
    }
}

function load_json_config(): array
{
    if (!is_readable(CONFIG_FILE)) {
        return ['wireguard_check_ping' => ''];
    }

    $raw = file_get_contents(CONFIG_FILE);
    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        return ['wireguard_check_ping' => ''];
    }

    return $data;
}

function load_bash_vars(string $path): array
{
    $result = [];

    if (!is_readable($path)) {
        return $result;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim((string)$line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }

        $value = trim($m[2]);
        $value = preg_replace('/^export\s+/', '', $value);
        $value = trim((string)$value);

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $result[$m[1]] = $value;
    }

    return $result;
}

function split_ip_list(string $value): array
{
    $result = [];

    foreach (preg_split('/[,\s]+/', $value) as $ip) {
        $ip = trim((string)$ip);

        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $result[] = $ip;
        }
    }

    return array_values(array_unique($result));
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

function load_config_dom(): DOMDocument
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

function dom_direct_child(DOMElement $node, array $names): ?DOMElement
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

function dom_direct_child_text(DOMElement $node, array $names, string $default = ''): string
{
    $child = dom_direct_child($node, $names);

    if ($child === null) {
        return $default;
    }

    return trim((string)$child->textContent);
}

function dom_set_direct_child_text(DOMElement $node, string $name, string $value): void
{
    $child = dom_direct_child($node, [$name]);

    if ($child === null) {
        $child = $node->ownerDocument->createElement($name);
        $node->appendChild($child);
    }

    while ($child->firstChild !== null) {
        $child->removeChild($child->firstChild);
    }

    $child->appendChild($node->ownerDocument->createTextNode($value));
}

function dom_node_enabled(DOMElement $node): bool
{
    $enabled = dom_direct_child_text($node, ['enabled'], '');

    if ($enabled === '') {
        return true;
    }

    $value = strtolower(trim($enabled));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
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

function dom_node_uuid(DOMElement $node): string
{
    foreach (['uuid', 'id'] as $attr) {
        if ($node->hasAttribute($attr)) {
            $value = trim((string)$node->getAttribute($attr));

            if ($value !== '') {
                return $value;
            }
        }
    }

    return dom_direct_child_text($node, ['uuid', 'id'], '');
}

function endpoint_parse_host(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $value, $m)) {
        return $m[1];
    }

    if (preg_match('/^([^:]+):(\d+)$/', $value, $m)) {
        return $m[1];
    }

    return $value;
}

function dom_endpoint_host(DOMElement $node): string
{
    $serverAddress = dom_direct_child_text($node, [
        'serveraddress',
        'server_address',
        'endpointaddress',
        'endpoint_address',
        'endpointhost',
        'endpoint_host',
    ], '');

    if ($serverAddress !== '') {
        return endpoint_parse_host($serverAddress);
    }

    $endpoint = dom_direct_child_text($node, [
        'endpoint',
        'serverendpoint',
        'peerendpoint',
    ], '');

    return endpoint_parse_host($endpoint);
}

function dom_endpoint_port(DOMElement $node): string
{
    $port = dom_direct_child_text($node, [
        'serverport',
        'server_port',
        'endpointport',
        'endpoint_port',
    ], '');

    if ($port !== '') {
        return $port;
    }

    $endpoint = dom_direct_child_text($node, [
        'endpoint',
        'serverendpoint',
        'peerendpoint',
    ], '');

    if ($endpoint !== '' && preg_match('/:(\d+)$/', $endpoint, $m)) {
        return $m[1];
    }

    return '';
}

function dom_peer_allowed_ips(DOMElement $node): string
{
    return dom_direct_child_text($node, [
        'allowedips',
        'allowed_ips',
        'allowedip',
        'allowed_ip',
        'tunneladdress',
        'tunnel_address',
    ], '');
}

function dom_peer_name(DOMElement $node, string $fallback): string
{
    $name = dom_direct_child_text($node, ['name', 'description', 'descr'], '');

    return $name !== '' ? $name : $fallback;
}

function dom_peer_public_key(DOMElement $node): string
{
    return dom_direct_child_text($node, ['publickey', 'public_key', 'pubkey', 'pub_key'], '');
}

function is_wireguard_peer_candidate(DOMElement $node): bool
{
    $allowed = dom_peer_allowed_ips($node);

    if ($allowed === '') {
        return false;
    }

    $tag = strtolower($node->nodeName);
    $endpoint = dom_endpoint_host($node);
    $publicKey = dom_peer_public_key($node);

    if (strpos($tag, 'peer') !== false || strpos($tag, 'client') !== false || strpos($tag, 'endpoint') !== false) {
        return true;
    }

    if ($endpoint !== '' && $publicKey !== '') {
        return true;
    }

    return false;
}

function collect_wireguard_peers_recursive(DOMElement $node, array &$peers): void
{
    if (is_wireguard_peer_candidate($node)) {
        $path = dom_node_path($node);
        $uuid = dom_node_uuid($node);
        $publicKey = dom_peer_public_key($node);
        $id = $uuid !== '' ? $uuid : substr(sha1($path . '|' . $publicKey), 0, 16);

        $peers[] = [
            'node' => $node,
            'path' => $path,
            'uuid' => $uuid,
            'id' => $id,
            'enabled' => dom_node_enabled($node),
            'name' => dom_peer_name($node, $id),
            'node_name' => $node->nodeName,
            'allowed_ips' => dom_peer_allowed_ips($node),
            'serveraddress' => dom_endpoint_host($node),
            'serverport' => dom_endpoint_port($node),
            'public_key_short' => $publicKey !== '' ? substr($publicKey, 0, 8) . '...' . substr($publicKey, -6) : '',
        ];
    }

    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement) {
            collect_wireguard_peers_recursive($child, $peers);
        }
    }
}

function load_wireguard_peers_dom(DOMDocument $dom): array
{
    $peers = [];
    $wireguardNodes = $dom->getElementsByTagName('wireguard');

    if ($wireguardNodes->length > 0) {
        foreach ($wireguardNodes as $wireguard) {
            if ($wireguard instanceof DOMElement) {
                collect_wireguard_peers_recursive($wireguard, $peers);
            }
        }
    } elseif ($dom->documentElement instanceof DOMElement) {
        collect_wireguard_peers_recursive($dom->documentElement, $peers);
    }

    $seen = [];
    $result = [];

    foreach ($peers as $peer) {
        $key = (string)$peer['id'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $result[] = $peer;
    }

    return $result;
}

function cidr_is_valid(string $value): bool
{
    if (!preg_match('#^([^/]+)/(\d{1,3})$#', $value, $m)) {
        return false;
    }

    $ip = $m[1];
    $prefix = (int)$m[2];

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $prefix >= 0 && $prefix <= 32;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return $prefix >= 0 && $prefix <= 128;
    }

    return false;
}

function target_items_from_allowed_ips(string $allowedIps): array
{
    $items = [];
    $parts = preg_split('/[,\s]+/', $allowedIps);

    if ($parts === false) {
        return [];
    }

    foreach ($parts as $raw) {
        $raw = trim((string)$raw);
        $raw = trim($raw, "\"'");

        if ($raw === '' || $raw === '0.0.0.0/0' || $raw === '::/0') {
            continue;
        }

        if (filter_var($raw, FILTER_VALIDATE_IP)) {
            $items[] = [
                'raw' => $raw,
                'kind' => 'host',
                'host' => $raw,
                'route' => $raw,
            ];
            continue;
        }

        if (!cidr_is_valid($raw)) {
            continue;
        }

        [$ip, $prefixText] = explode('/', $raw, 2);
        $prefix = (int)$prefixText;
        $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $hostPrefix = $isV6 ? 128 : 32;

        $items[] = [
            'raw' => $raw,
            'kind' => $prefix === $hostPrefix ? 'host' : 'network',
            'host' => $prefix === $hostPrefix ? $ip : '',
            'route' => $raw,
        ];
    }

    $unique = [];
    $result = [];

    foreach ($items as $item) {
        $key = $item['raw'] . '|' . $item['kind'];
        if (isset($unique[$key])) {
            continue;
        }
        $unique[$key] = true;
        $result[] = $item;
    }

    return $result;
}

function collect_current_routes(): array
{
    $routes = [];
    $commands = [
        '/usr/bin/netstat -rn -f inet',
        '/usr/bin/netstat -rn -f inet6',
    ];

    foreach ($commands as $command) {
        $output = [];
        $exitCode = 0;

        exec($command . ' 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0) {
            continue;
        }

        foreach ($output as $line) {
            $line = trim((string)$line);

            if ($line === '' || preg_match('/^(Routing|Internet|Destination|Expire|Netif|Name|Use|Flags)/i', $line)) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);

            if ($parts === false || empty($parts[0])) {
                continue;
            }

            $destination = trim((string)$parts[0]);

            if ($destination === '' || $destination === 'default') {
                continue;
            }

            $routes[] = $destination;
        }
    }

    return array_values(array_unique($routes));
}

function route_destination_matches(string $expected, array $routes): bool
{
    if ($expected === '' || in_array($expected, $routes, true)) {
        return $expected !== '';
    }

    $candidates = [$expected];

    if (filter_var($expected, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $candidates[] = $expected . '/32';
    } elseif (filter_var($expected, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $candidates[] = $expected . '/128';
    }

    if (preg_match('#^([^/]+)/(\d{1,3})$#', $expected, $m)) {
        $candidates[] = $m[1];
    }

    foreach ($routes as $route) {
        foreach ($candidates as $candidate) {
            if ($route === $candidate) {
                return true;
            }
        }
    }

    return false;
}

function ping_host_once(string $ip): bool
{
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

function ping_host(string $ip, int $attempts = 2): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    for ($i = 0; $i < $attempts; $i++) {
        if (ping_host_once($ip)) {
            return true;
        }
        usleep(250000);
    }

    return false;
}

function evaluate_wireguard_peers(array $peers, array $extraHosts = []): array
{
    $routes = collect_current_routes();
    $watchNetworks = [];
    $watchHosts = [];
    $missingNetworks = [];
    $unreachableHosts = [];
    $peerResults = [];
    $affectedIds = [];

    foreach ($peers as $peer) {
        $items = target_items_from_allowed_ips((string)$peer['allowed_ips']);
        $peerMissing = [];
        $peerUnreachable = [];
        $peerWatchNetworks = [];
        $peerWatchHosts = [];

        if (empty($peer['enabled'])) {
            $peerResults[] = [
                'id' => $peer['id'],
                'name' => $peer['name'],
                'enabled' => false,
                'state' => 'disabled',
                'allowed_ips' => $peer['allowed_ips'],
                'watch_networks' => [],
                'watch_hosts' => [],
                'missing_networks' => [],
                'unreachable_hosts' => [],
            ];
            continue;
        }

        foreach ($items as $item) {
            if ($item['kind'] === 'host') {
                $peerWatchHosts[] = $item['host'];
                $watchHosts[] = $item['host'];
            } else {
                $peerWatchNetworks[] = $item['route'];
                $watchNetworks[] = $item['route'];
            }

            if (!route_destination_matches((string)$item['route'], $routes)) {
                $peerMissing[] = (string)$item['route'];
                $missingNetworks[] = (string)$item['route'];
            }

            if ($item['kind'] === 'host' && !ping_host((string)$item['host'])) {
                $peerUnreachable[] = (string)$item['host'];
                $unreachableHosts[] = (string)$item['host'];
            }
        }

        $state = 'ok';
        if (!empty($peerMissing) || !empty($peerUnreachable)) {
            $state = 'degraded';
            $affectedIds[(string)$peer['id']] = true;
        }

        $peerResults[] = [
            'id' => $peer['id'],
            'uuid' => $peer['uuid'],
            'name' => $peer['name'],
            'path' => $peer['path'],
            'enabled' => true,
            'state' => $state,
            'allowed_ips' => $peer['allowed_ips'],
            'endpoint' => $peer['serveraddress'],
            'watch_networks' => array_values(array_unique($peerWatchNetworks)),
            'watch_hosts' => array_values(array_unique($peerWatchHosts)),
            'missing_networks' => array_values(array_unique($peerMissing)),
            'unreachable_hosts' => array_values(array_unique($peerUnreachable)),
        ];
    }

    foreach ($extraHosts as $host) {
        $host = trim((string)$host);
        if ($host === '' || !filter_var($host, FILTER_VALIDATE_IP)) {
            continue;
        }
        $watchHosts[] = $host;
        if (!ping_host($host)) {
            $unreachableHosts[] = $host;
        }
    }

    return [
        'routes' => $routes,
        'watch_networks' => array_values(array_unique($watchNetworks)),
        'watch_hosts' => array_values(array_unique($watchHosts)),
        'missing_networks' => array_values(array_unique($missingNetworks)),
        'unreachable_hosts' => array_values(array_unique($unreachableHosts)),
        'peer_results' => $peerResults,
        'affected_ids' => array_keys($affectedIds),
    ];
}

function backup_opn_config(string $suffix): string
{
    $backup = OPN_CONFIG_FILE . '.additional-check-wg-status.' . $suffix . '.' . date('YmdHis') . '.bak';

    if (!@copy(OPN_CONFIG_FILE, $backup)) {
        throw new RuntimeException('Не удалось создать backup config.xml: ' . $backup);
    }

    @chmod($backup, 0600);

    return $backup;
}

function save_config_dom(DOMDocument $dom): void
{
    if ($dom->save(OPN_CONFIG_FILE) === false) {
        throw new RuntimeException('Не удалось записать ' . OPN_CONFIG_FILE);
    }

    @chmod(OPN_CONFIG_FILE, 0600);
}

function apply_wireguard_config(string $phase, array $affected = []): array
{
    $commands = [];

    foreach ($affected as $peer) {
        $uuid = trim((string)($peer['uuid'] ?? ''));

        if ($uuid === '') {
            continue;
        }

        if ($phase === 'disable') {
            $commands[] = [
                'command' => '/usr/local/sbin/configctl wireguard stop ' . escapeshellarg($uuid),
                'scope' => 'uuid',
                'uuid' => $uuid,
            ];
        } elseif ($phase === 'enable') {
            $commands[] = [
                'command' => '/usr/local/sbin/configctl wireguard start ' . escapeshellarg($uuid),
                'scope' => 'uuid',
                'uuid' => $uuid,
            ];
            $commands[] = [
                'command' => '/usr/local/sbin/configctl wireguard restart ' . escapeshellarg($uuid),
                'scope' => 'uuid',
                'uuid' => $uuid,
            ];
        }
    }

    $globalCommands = [
        '/usr/local/sbin/configctl wireguard configure',
        '/usr/local/sbin/configctl wireguard restart',
        '/usr/local/sbin/configctl filter reload',
        '/usr/local/sbin/configctl wireguard reconfigure',
        '/usr/local/sbin/configctl wireguard reload',
        '/usr/local/etc/rc.d/wireguard restart',
        '/usr/sbin/service wireguard restart',
    ];

    foreach ($globalCommands as $command) {
        $commands[] = [
            'command' => $command,
            'scope' => 'global',
            'uuid' => '',
        ];
    }

    $attempts = [];
    $ok = false;

    foreach ($commands as $item) {
        $command = $item['command'];
        $binary = preg_split('/\s+/', $command)[0] ?? '';

        if ($binary === '' || !is_executable($binary)) {
            $attempts[] = [
                'phase' => $phase,
                'scope' => $item['scope'],
                'uuid' => $item['uuid'],
                'command' => $command,
                'exit_code' => 127,
                'output' => 'binary not executable',
            ];
            continue;
        }

        $result = run_command($command);
        $result['phase'] = $phase;
        $result['scope'] = $item['scope'];
        $result['uuid'] = $item['uuid'];
        $result['command'] = $command;
        $attempts[] = $result;

        if ((int)$result['exit_code'] === 0) {
            $ok = true;
        }
    }

    return [
        'ok' => $ok,
        'phase' => $phase,
        'attempts' => $attempts,
    ];
}

function affected_peer_public_info(array $peer): array
{
    return [
        'id' => $peer['id'],
        'uuid' => $peer['uuid'],
        'name' => $peer['name'] !== '' ? $peer['name'] : $peer['path'],
        'path' => $peer['path'],
        'node_name' => $peer['node_name'],
        'allowed_ips' => $peer['allowed_ips'],
        'endpoint' => $peer['serveraddress'],
        'serverport' => $peer['serverport'],
    ];
}

function reset_wireguard_peers_by_toggle(DOMDocument $dom, array $peers, array $affectedIds, bool $forceAll = false): array
{
    $affected = [];
    $affectedLookup = array_fill_keys($affectedIds, true);

    foreach ($peers as $peer) {
        if (empty($peer['enabled'])) {
            continue;
        }

        if ($forceAll || isset($affectedLookup[(string)$peer['id']])) {
            $affected[] = $peer;
        }
    }

    if (empty($affected)) {
        return [
            'ok' => false,
            'reason' => 'no_affected_peers',
            'message' => 'Не удалось определить WireGuard peer для disable/enable reset',
            'affected' => [],
        ];
    }

    $backup = backup_opn_config('before-toggle');
    $affectedInfo = [];

    foreach ($affected as $peer) {
        /** @var DOMElement $node */
        $node = $peer['node'];
        dom_set_direct_child_text($node, 'enabled', '0');
        $affectedInfo[] = affected_peer_public_info($peer);
    }

    save_config_dom($dom);
    $disableApply = apply_wireguard_config('disable', $affected);
    sleep(5);

    foreach ($affected as $peer) {
        /** @var DOMElement $node */
        $node = $peer['node'];
        dom_set_direct_child_text($node, 'enabled', '1');
    }

    save_config_dom($dom);
    $enableApply = apply_wireguard_config('enable', $affected);
    sleep(5);

    return [
        'ok' => !empty($enableApply['ok']),
        'reason' => !empty($enableApply['ok']) ? 'toggle_reset_done' : 'enable_apply_failed',
        'message' => !empty($enableApply['ok'])
            ? 'WireGuard peer disable/apply/enable/apply выполнен'
            : 'WireGuard peer disable/enable выполнен, но apply после включения завершился ошибкой',
        'backup' => $backup,
        'affected' => $affectedInfo,
        'force_all' => $forceAll,
        'disable_apply' => $disableApply,
        'enable_apply' => $enableApply,
    ];
}

function restart_wireguard(): array
{
    $commands = [
        '/usr/local/sbin/configctl wireguard configure',
        '/usr/local/sbin/configctl wireguard restart',
        '/usr/local/sbin/configctl filter reload',
        '/usr/local/sbin/configctl wireguard reconfigure',
        '/usr/local/sbin/configctl wireguard reload',
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

$silent = in_array('--silent', $argv, true) || in_array('-s', $argv, true);

$lockHandle = fopen(LOCK_FILE, 'c');

if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    out_msg('Скрипт уже выполняется. Выход.', $silent);
    exit(0);
}

try {
    $vars = load_bash_vars(VARIABLES_FILE);
    $config = load_json_config();
    $extraHosts = [];

    if (!empty($vars['WIREGUARD_CHECK_PING'])) {
        $extraHosts = array_merge($extraHosts, split_ip_list((string)$vars['WIREGUARD_CHECK_PING']));
    }

    if (!empty($config['wireguard_check_ping'])) {
        $extraHosts = array_merge($extraHosts, split_ip_list((string)$config['wireguard_check_ping']));
    }

    $extraHosts = array_values(array_unique($extraHosts));

    out_msg('Получаю список WireGuard peers из /conf/config.xml', $silent);
    $dom = load_config_dom();
    $peers = load_wireguard_peers_dom($dom);
    $activePeers = array_values(array_filter($peers, static fn(array $peer): bool => !empty($peer['enabled'])));

    out_msg('Проверяю маршруты из Allowed IPs/Tunnel Address и ping host IP', $silent);
    $evaluation = evaluate_wireguard_peers($peers, $extraHosts);

    $watchNetworks = $evaluation['watch_networks'];
    $watchHosts = $evaluation['watch_hosts'];
    $missingNetworks = $evaluation['missing_networks'];
    $unreachableHosts = $evaluation['unreachable_hosts'];
    $affectedIds = $evaluation['affected_ids'];

    if (empty($watchNetworks) && empty($watchHosts)) {
        $message = empty($activePeers)
            ? 'Нет активных WireGuard peers для проверки'
            : 'В активных WireGuard peers не найдены Allowed IPs/Tunnel Address для проверки';
        out_msg($message, $silent);
        write_status([
            'ok' => true,
            'state' => 'no_targets',
            'message' => $message,
            'watch_networks' => [],
            'watch_hosts' => [],
            'missing_networks' => [],
            'unreachable_hosts' => [],
            'peers' => $evaluation['peer_results'],
            'source' => 'config.xml'
        ]);
        exit(0);
    }

    if (!empty($missingNetworks) || !empty($unreachableHosts)) {
        out_msg('WireGuard деградация', $silent);

        if (!empty($missingNetworks)) {
            out_msg('Маршруты отсутствуют: ' . implode(', ', $missingNetworks), $silent);
        }

        if (!empty($unreachableHosts)) {
            out_msg('Хосты не пингуются: ' . implode(', ', $unreachableHosts), $silent);
        }

        $forceAll = empty($affectedIds) && (!empty($missingNetworks) || !empty($unreachableHosts));
        $peerReset = reset_wireguard_peers_by_toggle($dom, $peers, $affectedIds, $forceAll);
        $restart = null;
        $postEvaluation = null;

        if (!empty($peerReset['ok'])) {
            out_msg('WireGuard peer disable/apply/enable/apply выполнен', $silent);

            $domAfter = load_config_dom();
            $peersAfter = load_wireguard_peers_dom($domAfter);
            $postEvaluation = evaluate_wireguard_peers($peersAfter, $extraHosts);

            if (empty($postEvaluation['missing_networks']) && empty($postEvaluation['unreachable_hosts'])) {
                $message = 'WireGuard деградация устранена: peer disable/apply/enable/apply выполнен';
                $state = 'recovered_peer_toggle_reset';
                $ok = true;
                $exitCode = 0;
            } else {
                $message = 'WireGuard peer reset выполнен, но проверка всё ещё видит деградацию';
                $state = 'degraded_peer_toggle_reset_unverified';
                $ok = false;
                $exitCode = 1;
            }
        } else {
            out_msg('Peer toggle-reset не выполнен: ' . ($peerReset['message'] ?? $peerReset['reason'] ?? 'unknown'), $silent);
            out_msg('Пробую обычный перезапуск WireGuard', $silent);

            $restart = restart_wireguard();

            if ($restart['ok']) {
                out_msg('WireGuard перезапущен: ' . $restart['command'], $silent);
                $message = 'WireGuard деградация, toggle-reset не выполнен, выполнен обычный перезапуск';
                $state = 'degraded_restart_fallback';
                $ok = false;
                $exitCode = 1;
            } else {
                out_msg('Не удалось перезапустить WireGuard', $silent);
                $message = 'WireGuard деградация, перезапуск не выполнен';
                $state = 'degraded_restart_failed';
                $ok = false;
                $exitCode = 2;
            }
        }

        write_status([
            'ok' => $ok,
            'state' => $state,
            'message' => $message,
            'watch_networks' => $postEvaluation['watch_networks'] ?? $watchNetworks,
            'watch_hosts' => $postEvaluation['watch_hosts'] ?? $watchHosts,
            'missing_networks' => $postEvaluation['missing_networks'] ?? $missingNetworks,
            'unreachable_hosts' => $postEvaluation['unreachable_hosts'] ?? $unreachableHosts,
            'peers' => $postEvaluation['peer_results'] ?? $evaluation['peer_results'],
            'peer_reset' => $peerReset,
            'restart' => $restart,
            'source' => 'config.xml'
        ]);

        exit($exitCode);
    }

    out_msg('WireGuard OK', $silent);
    write_status([
        'ok' => true,
        'state' => 'ok',
        'message' => 'WireGuard OK',
        'watch_networks' => $watchNetworks,
        'watch_hosts' => $watchHosts,
        'missing_networks' => [],
        'unreachable_hosts' => [],
        'peers' => $evaluation['peer_results'],
        'source' => 'config.xml'
    ]);

    exit(0);
} catch (Throwable $e) {
    $message = $e->getMessage();
    out_msg('ERROR: ' . $message, $silent);
    write_status([
        'ok' => false,
        'state' => 'error',
        'message' => $message,
        'watch_networks' => [],
        'watch_hosts' => [],
        'missing_networks' => [],
        'unreachable_hosts' => [],
        'peers' => [],
        'source' => 'config.xml'
    ]);
    exit(2);
}
