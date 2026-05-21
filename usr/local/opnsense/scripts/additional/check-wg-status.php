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
            'name' => xml_value($node, 'name', xml_value($node, 'description', '')),
            'tunneladdress' => xml_value($node, 'tunneladdress'),
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

        $restart = restart_wireguard();

        if ($restart['ok']) {
            out_msg('WireGuard перезапущен: ' . $restart['command'], $silent);
            $message = 'WireGuard деградация, выполнен перезапуск';
            $state = 'degraded_restarted';
        } else {
            out_msg('Не удалось перезапустить WireGuard', $silent);
            $message = 'WireGuard деградация, перезапуск не выполнен';
            $state = 'degraded_restart_failed';
        }

        write_status([
            'ok' => false,
            'state' => $state,
            'message' => $message,
            'watch_networks' => $watchNetworks,
            'watch_hosts' => $watchHosts,
            'missing_networks' => $missingNetworks,
            'unreachable_hosts' => $unreachableHosts,
            'restart' => $restart,
            'source' => 'config.xml'
        ]);

        exit($restart['ok'] ? 1 : 2);
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
