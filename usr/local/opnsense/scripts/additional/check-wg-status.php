#!/usr/local/bin/php
<?php

declare(strict_types=1);

const SCRIPT_NAME = 'additional-check-wg-status';
const VARIABLES_FILE = '/usr/local/opnsense/scripts/variables';
const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/check_status.json';
const OPN_CONFIG_FILE = '/conf/config.xml';
const UDP2RAW_MANAGER_FILE = '/usr/local/opnsense/scripts/additional/udp2raw-manager.php';
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

function parse_json_from_output(string $output): array
{
    $decoded = json_decode(trim($output), true);

    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($output, '{');
    $end = strrpos($output, '}');

    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($output, $start, $end - $start + 1), true);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

function udp2raw_status(): array
{
    if (!is_executable(UDP2RAW_MANAGER_FILE)) {
        return [
            'available' => false,
            'running' => false,
            'message' => 'udp2raw manager не найден или не исполняемый: ' . UDP2RAW_MANAGER_FILE,
            'instances' => [],
        ];
    }

    $result = run_command(escapeshellcmd(UDP2RAW_MANAGER_FILE) . ' --status --json');
    $data = parse_json_from_output($result['output']);

    $instances = [];
    $running = false;

    if (isset($data['instances']) && is_array($data['instances'])) {
        $instances = $data['instances'];
    } elseif (isset($data['runtime']['instances']) && is_array($data['runtime']['instances'])) {
        $instances = $data['runtime']['instances'];
    }

    foreach ($instances as $instance) {
        if (!empty($instance['running'])) {
            $running = true;
            break;
        }
    }

    return [
        'available' => true,
        'running' => $running,
        'exit_code' => $result['exit_code'],
        'message' => $running ? 'udp2raw запущен' : 'udp2raw не запущен',
        'instances' => $instances,
        'raw' => $data,
    ];
}

function restart_udp2raw_if_running(bool $silent = false): array
{
    $status = udp2raw_status();

    if (empty($status['available'])) {
        out_msg('udp2raw manager не найден, пропускаю перезапуск udp2raw', $silent);
        return [
            'attempted' => false,
            'restarted' => false,
            'ok' => true,
            'reason' => 'manager_not_available',
            'status_before' => $status,
        ];
    }

    if (empty($status['running'])) {
        out_msg('udp2raw не запущен, перезапуск udp2raw не требуется', $silent);
        return [
            'attempted' => false,
            'restarted' => false,
            'ok' => true,
            'reason' => 'not_running',
            'status_before' => $status,
        ];
    }

    out_msg('udp2raw запущен, сначала перезапускаю udp2raw', $silent);

    $restart = run_command(escapeshellcmd(UDP2RAW_MANAGER_FILE) . ' --restart-all --json');
    $decoded = parse_json_from_output($restart['output']);
    $ok = $restart['exit_code'] === 0 && (($decoded['status'] ?? 'ok') !== 'error');

    if ($ok) {
        out_msg('udp2raw перезапущен', $silent);
        /*
         * Даем udp2raw подняться перед рестартом WireGuard, чтобы WG endpoint
         * уже слушал локальный порт.
         */
        sleep(2);
    } else {
        out_msg('Не удалось перезапустить udp2raw, продолжаю перезапуск WireGuard', $silent);
    }

    return [
        'attempted' => true,
        'restarted' => $ok,
        'ok' => $ok,
        'reason' => $ok ? 'restarted' : 'restart_failed',
        'status_before' => $status,
        'restart_result' => [
            'exit_code' => $restart['exit_code'],
            'output' => $restart['output'],
            'decoded' => $decoded,
        ],
        'status_after' => udp2raw_status(),
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

        $udp2rawRestart = restart_udp2raw_if_running($silent);
        $restart = restart_wireguard();

        if ($restart['ok']) {
            out_msg('WireGuard перезапущен: ' . $restart['command'], $silent);

            if (!empty($udp2rawRestart['attempted']) && !empty($udp2rawRestart['restarted'])) {
                $message = 'WireGuard деградация, udp2raw и WireGuard перезапущены';
                $state = 'degraded_udp2raw_wireguard_restarted';
            } elseif (!empty($udp2rawRestart['attempted']) && empty($udp2rawRestart['restarted'])) {
                $message = 'WireGuard деградация, udp2raw не перезапустился, WireGuard перезапущен';
                $state = 'degraded_udp2raw_failed_wireguard_restarted';
            } else {
                $message = 'WireGuard деградация, выполнен перезапуск WireGuard';
                $state = 'degraded_restarted';
            }
        } else {
            out_msg('Не удалось перезапустить WireGuard', $silent);

            if (!empty($udp2rawRestart['attempted']) && !empty($udp2rawRestart['restarted'])) {
                $message = 'WireGuard деградация, udp2raw перезапущен, WireGuard не перезапустился';
                $state = 'degraded_udp2raw_restarted_wireguard_failed';
            } else {
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
            'udp2raw' => $udp2rawRestart,
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
