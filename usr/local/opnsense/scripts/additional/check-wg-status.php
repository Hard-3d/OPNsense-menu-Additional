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

function xml_value(SimpleXMLElement $node, string $name, string $default = ''): string
{
    if (!isset($node->{$name})) {
        return $default;
    }

    return trim((string)$node->{$name});
}

function xml_bool_enabled(SimpleXMLElement $node): bool
{
    /*
     * Если enabled отсутствует, считаем запись активной.
     * В разных версиях OPNsense модель WireGuard может отличаться,
     * но tunneladdress у активного клиента нам всё равно нужен.
     */
    if (!isset($node->enabled)) {
        return true;
    }

    $value = strtolower(trim((string)$node->enabled));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function collect_wireguard_clients_recursive(SimpleXMLElement $node, array &$clients): void
{
    if (isset($node->tunneladdress)) {
        $clients[] = [
            'enabled' => xml_bool_enabled($node),
            'uuid' => xml_value($node, 'uuid', ''),
            'name' => xml_value($node, 'name', xml_value($node, 'description', '')),
            'tunneladdress' => xml_value($node, 'tunneladdress'),
            'serveraddress' => xml_value($node, 'serveraddress', ''),
            'serverport' => xml_value($node, 'serverport', ''),
        ];
    }

    foreach ($node->children() as $child) {
        collect_wireguard_clients_recursive($child, $clients);
    }
}

function load_wireguard_clients_from_config(): array
{
    if (!is_readable(OPN_CONFIG_FILE)) {
        throw new RuntimeException('Не удалось прочитать ' . OPN_CONFIG_FILE);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file(OPN_CONFIG_FILE);

    if ($xml === false) {
        $errors = [];

        foreach (libxml_get_errors() as $error) {
            $errors[] = trim($error->message);
        }

        libxml_clear_errors();

        throw new RuntimeException('Ошибка чтения config.xml: ' . implode('; ', $errors));
    }

    $clients = [];

    if (isset($xml->OPNsense)) {
        collect_wireguard_clients_recursive($xml->OPNsense, $clients);
    } else {
        collect_wireguard_clients_recursive($xml, $clients);
    }

    return $clients;
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

            if ($line === '' || preg_match('/^(Routing|Internet|Destination|Expire|Netif)/i', $line)) {
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
    if (in_array($expected, $routes, true)) {
        return true;
    }

    /*
     * netstat в FreeBSD иногда выводит сеть без CIDR для некоторых маршрутов.
     * Поэтому дополнительно сравниваем IP-часть до slash.
     */
    $expectedBase = preg_replace('#/\d+$#', '', $expected);

    foreach ($routes as $route) {
        if ($route === $expectedBase) {
            return true;
        }
    }

    return false;
}

function ping_host(string $ip): bool
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
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $serverAddress, $m)) {
            return $m[1];
        }

        if (preg_match('/^([^:]+):\d+$/', $serverAddress, $m)) {
            return $m[1];
        }

        return $serverAddress;
    }

    $endpoint = dom_direct_child_text($node, [
        'endpoint',
        'serverendpoint',
        'peerendpoint',
    ], '');

    if ($endpoint !== '') {
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $endpoint, $m)) {
            return $m[1];
        }

        if (preg_match('/^([^:]+):\d+$/', $endpoint, $m)) {
            return $m[1];
        }

        return $endpoint;
    }

    return '';
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

function collect_wireguard_clients_dom_recursive(DOMElement $node, array &$clients): void
{
    if (dom_direct_child($node, ['tunneladdress']) !== null) {
        $clients[] = [
            'node' => $node,
            'path' => dom_node_path($node),
            'uuid' => dom_node_uuid($node),
            'enabled' => dom_node_enabled($node),
            'name' => dom_direct_child_text($node, ['name', 'description', 'descr'], ''),
            'tunneladdress' => dom_direct_child_text($node, ['tunneladdress'], ''),
            'serveraddress' => dom_endpoint_host($node),
            'serverport' => dom_endpoint_port($node),
        ];
    }

    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement) {
            collect_wireguard_clients_dom_recursive($child, $clients);
        }
    }
}

function load_wireguard_clients_dom(DOMDocument $dom): array
{
    $clients = [];
    $wireguardNodes = $dom->getElementsByTagName('wireguard');

    if ($wireguardNodes->length > 0) {
        foreach ($wireguardNodes as $wireguard) {
            if ($wireguard instanceof DOMElement) {
                collect_wireguard_clients_dom_recursive($wireguard, $clients);
            }
        }
    } elseif ($dom->documentElement instanceof DOMElement) {
        collect_wireguard_clients_dom_recursive($dom->documentElement, $clients);
    }

    return $clients;
}

function tunneladdress_items(string $tunneladdress): array
{
    $items = [];

    foreach (explode(',', $tunneladdress) as $addr) {
        $addr = trim($addr);

        if ($addr === '' || $addr === '0.0.0.0/0' || $addr === '::/0') {
            continue;
        }

        if (preg_match('~/32$~', $addr)) {
            $items[] = ['type' => 'host', 'value' => substr($addr, 0, -3), 'raw' => $addr];
        } elseif (preg_match('~/128$~', $addr)) {
            $items[] = ['type' => 'host', 'value' => substr($addr, 0, -4), 'raw' => $addr];
        } else {
            $items[] = ['type' => 'network', 'value' => $addr, 'raw' => $addr];
        }
    }

    return $items;
}

function client_matches_degradation(array $client, array $missingNetworks, array $unreachableHosts): bool
{
    foreach (tunneladdress_items((string)$client['tunneladdress']) as $item) {
        if ($item['type'] === 'network' && in_array($item['value'], $missingNetworks, true)) {
            return true;
        }

        if ($item['type'] === 'host' && in_array($item['value'], $unreachableHosts, true)) {
            return true;
        }
    }

    $serverAddress = trim((string)($client['serveraddress'] ?? ''));

    if ($serverAddress !== '' && in_array($serverAddress, $unreachableHosts, true)) {
        return true;
    }

    return false;
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

    /*
     * OPNsense WireGuard configctl actions may operate per instance UUID.
     * The GUI-like toggle alone is not enough on some installations; therefore
     * we first try per-UUID stop/start/restart and then run global reconfigure.
     */
    foreach ($affected as $client) {
        $uuid = trim((string)($client['uuid'] ?? ''));

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
        '/usr/local/sbin/configctl wireguard reconfigure',
        '/usr/local/sbin/configctl wireguard restart',
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
        'command' => $ok ? 'multiple' : '',
        'attempts' => $attempts,
    ];
}

function reset_wireguard_peers_by_toggle(array $missingNetworks, array $unreachableHosts): array
{
    $dom = load_config_dom();
    $clients = load_wireguard_clients_dom($dom);
    $affected = [];

    foreach ($clients as $client) {
        if (empty($client['enabled'])) {
            continue;
        }

        if (client_matches_degradation($client, $missingNetworks, $unreachableHosts)) {
            $affected[] = $client;
        }
    }

    if (empty($affected) && (!empty($unreachableHosts) || !empty($missingNetworks))) {
        /*
         * If exact matching failed, reset all active WG clients.
         * This is safer than doing only a global service restart because it
         * reproduces the working manual action: disable peer, apply, enable, apply.
         */
        foreach ($clients as $client) {
            if (!empty($client['enabled'])) {
                $affected[] = $client;
            }
        }
    }

    if (empty($affected)) {
        return [
            'ok' => false,
            'reason' => 'no_affected_peers',
            'message' => 'Не удалось определить peer для toggle-reset',
            'affected' => [],
        ];
    }

    $backup = backup_opn_config('before-toggle');
    $affectedInfo = [];

    foreach ($affected as $client) {
        /** @var DOMElement $node */
        $node = $client['node'];
        dom_set_direct_child_text($node, 'enabled', '0');

        $affectedInfo[] = [
            'name' => $client['name'] !== '' ? $client['name'] : $client['path'],
            'uuid' => $client['uuid'] ?? '',
            'path' => $client['path'],
            'tunneladdress' => $client['tunneladdress'],
            'serveraddress' => $client['serveraddress'] ?? '',
            'serverport' => $client['serverport'] ?? '',
        ];
    }

    save_config_dom($dom);
    $disableApply = apply_wireguard_config('disable', $affected);
    sleep(3);

    foreach ($affected as $client) {
        /** @var DOMElement $node */
        $node = $client['node'];
        dom_set_direct_child_text($node, 'enabled', '1');
    }

    save_config_dom($dom);
    $enableApply = apply_wireguard_config('enable', $affected);
    sleep(3);

    return [
        'ok' => !empty($enableApply['ok']),
        'reason' => !empty($enableApply['ok']) ? 'toggle_reset_done' : 'enable_apply_failed',
        'message' => !empty($enableApply['ok'])
            ? 'Peer toggle-reset выполнен'
            : 'Peer toggle-reset выполнен, но apply после включения завершился ошибкой',
        'backup' => $backup,
        'affected' => $affectedInfo,
        'clients_found' => count($clients),
        'disable_apply' => $disableApply,
        'enable_apply' => $enableApply,
    ];
}

function restart_wireguard(): array
{
    /*
     * Без API key/secret. Пробуем локальные способы, которые доступны root.
     * На разных версиях OPNsense action может отличаться, поэтому список
     * сделан каскадным.
     */
    $commands = [
        '/usr/local/sbin/configctl wireguard restart',
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

    $watchNetworks = [];
    $watchHosts = [];

    if (!empty($vars['WIREGUARD_CHECK_PING'])) {
        $watchHosts = array_merge($watchHosts, split_ip_list((string)$vars['WIREGUARD_CHECK_PING']));
    }

    if (!empty($config['wireguard_check_ping'])) {
        $watchHosts = array_merge($watchHosts, split_ip_list((string)$config['wireguard_check_ping']));
    }

    out_msg('Получаю список WireGuard клиентов из /conf/config.xml', $silent);
    $clients = load_wireguard_clients_from_config();

    foreach ($clients as $wg) {
        if (empty($wg['enabled'])) {
            continue;
        }

        if (empty($wg['tunneladdress'])) {
            continue;
        }

        $addresses = explode(',', (string)$wg['tunneladdress']);

        foreach ($addresses as $addr) {
            $addr = trim($addr);

            if ($addr === '' || $addr === '0.0.0.0/0' || $addr === '::/0') {
                continue;
            }

            if (preg_match('~/32$~', $addr)) {
                $watchHosts[] = substr($addr, 0, -3);
            } elseif (preg_match('~/128$~', $addr)) {
                $watchHosts[] = substr($addr, 0, -4);
            } else {
                $watchNetworks[] = $addr;
            }
        }
    }

    $watchNetworks = array_values(array_unique($watchNetworks));
    $watchHosts = array_values(array_unique($watchHosts));

    if (empty($watchNetworks) && empty($watchHosts)) {
        $message = 'Нет активных WireGuard клиентов или IP для проверки';
        out_msg($message, $silent);
        write_status([
            'ok' => true,
            'state' => 'no_targets',
            'message' => $message,
            'watch_networks' => [],
            'watch_hosts' => [],
            'missing_networks' => [],
            'unreachable_hosts' => [],
            'source' => 'config.xml'
        ]);
        exit(0);
    }

    out_msg('Проверяю маршруты и ping', $silent);
    $currentRoutes = collect_current_routes();

    $missingNetworks = [];

    foreach ($watchNetworks as $network) {
        if (!route_destination_matches($network, $currentRoutes)) {
            $missingNetworks[] = $network;
        }
    }

    $unreachableHosts = [];

    foreach ($watchHosts as $ip) {
        if (!ping_host($ip)) {
            $unreachableHosts[] = $ip;
        }
    }

    if (!empty($missingNetworks) || !empty($unreachableHosts)) {
        out_msg('WireGuard деградация', $silent);

        if (!empty($missingNetworks)) {
            out_msg('Маршруты отсутствуют: ' . implode(', ', $missingNetworks), $silent);
        }

        if (!empty($unreachableHosts)) {
            out_msg('Хосты не пингуются: ' . implode(', ', $unreachableHosts), $silent);
        }

        $peerReset = reset_wireguard_peers_by_toggle($missingNetworks, $unreachableHosts);
        $restart = null;

        if (!empty($peerReset['ok'])) {
            out_msg('WireGuard peer toggle-reset выполнен', $silent);
            $message = 'WireGuard деградация, выполнен toggle-reset peer';
            $state = 'degraded_peer_toggle_reset';
        } else {
            out_msg('Peer toggle-reset не выполнен: ' . ($peerReset['message'] ?? $peerReset['reason'] ?? 'unknown'), $silent);
            out_msg('Пробую обычный перезапуск WireGuard', $silent);

            $restart = restart_wireguard();

            if ($restart['ok']) {
                out_msg('WireGuard перезапущен: ' . $restart['command'], $silent);
                $message = 'WireGuard деградация, toggle-reset не выполнен, выполнен обычный перезапуск';
                $state = 'degraded_restart_fallback';
            } else {
                out_msg('Не удалось перезапустить WireGuard', $silent);
                $message = 'WireGuard деградация, перезапуск не выполнен';
                $state = 'degraded_restart_failed';
            }
        }

        write_status([
            'ok' => false,
            'state' => $state,
            'message' => $message,
            'watch_networks' => $watchNetworks,
            'watch_hosts' => $watchHosts,
            'missing_networks' => $missingNetworks,
            'unreachable_hosts' => $unreachableHosts,
            'peer_reset' => $peerReset,
            'restart' => $restart,
            'source' => 'config.xml'
        ]);

        exit((!empty($peerReset['ok']) || ($restart !== null && !empty($restart['ok']))) ? 1 : 2);
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
        'source' => 'config.xml'
    ]);
    exit(2);
}
