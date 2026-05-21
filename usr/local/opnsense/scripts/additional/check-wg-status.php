#!/usr/local/bin/php
<?php

declare(strict_types=1);

require_once '/usr/local/opnsense/scripts/additional/lib.php';

const SCRIPT_NAME = 'additional-check-wg-status';
const VARIABLES_FILE = '/usr/local/opnsense/scripts/variables';
const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/check_status.json';
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

function split_ip_list(string $value): array
{
    $result = [];
    foreach (preg_split('/[,\s]+/', $value) as $ip) {
        $ip = trim($ip);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $result[] = $ip;
        }
    }
    return array_values(array_unique($result));
}

$silent = in_array('--silent', $argv, true) || in_array('-s', $argv, true);

$lockHandle = fopen(LOCK_FILE, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    out_msg('Скрипт уже выполняется. Выход.', $silent);
    exit(0);
}

try {
    $vars = additional_load_bash_vars(VARIABLES_FILE);
    $config = load_json_config();

    $api = additional_load_opnsense_api_config('/conf/config.xml', 'root');
    $client = new AdditionalApiClient($api['protocol'], '127.0.0.1', $api['key'], $api['secret']);

    $watchNetworks = [];
    $watchHosts = [];

    if (!empty($vars['WIREGUARD_CHECK_PING'])) {
        $watchHosts = array_merge($watchHosts, split_ip_list((string)$vars['WIREGUARD_CHECK_PING']));
    }

    if (!empty($config['wireguard_check_ping'])) {
        $watchHosts = array_merge($watchHosts, split_ip_list((string)$config['wireguard_check_ping']));
    }

    out_msg('Получаю список WireGuard клиентов', $silent);
    $response = $client->request('POST', 'wireguard/client/searchClient', []);
    $clients = $response['rows'] ?? [];

    foreach ($clients as $wg) {
        if ((int)($wg['enabled'] ?? 0) !== 1) {
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
            'unreachable_hosts' => []
        ]);
        exit(0);
    }

    out_msg('Проверяю маршруты и ping', $silent);
    $routes = $client->request('GET', 'diagnostics/interface/getRoutes/?resolve=');

    $currentRoutes = [];
    foreach ($routes as $r) {
        if (!empty($r['destination'])) {
            $currentRoutes[] = trim((string)$r['destination']);
        }
    }

    $missingNetworks = array_values(array_diff($watchNetworks, $currentRoutes));
    $unreachableHosts = [];

    foreach ($watchHosts as $ip) {
        $cmd = sprintf('/sbin/ping -c 1 -W 1 %s >/dev/null 2>&1', escapeshellarg($ip));
        exec($cmd, $out, $rc);
        if ($rc !== 0) {
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

        additional_restart_wireguard($client);
        out_msg('WireGuard перезапущен', $silent);

        write_status([
            'ok' => false,
            'state' => 'degraded_restarted',
            'message' => 'WireGuard деградация, выполнен перезапуск',
            'watch_networks' => $watchNetworks,
            'watch_hosts' => $watchHosts,
            'missing_networks' => $missingNetworks,
            'unreachable_hosts' => $unreachableHosts
        ]);

        exit(1);
    }

    out_msg('WireGuard OK', $silent);
    write_status([
        'ok' => true,
        'state' => 'ok',
        'message' => 'WireGuard OK',
        'watch_networks' => $watchNetworks,
        'watch_hosts' => $watchHosts,
        'missing_networks' => [],
        'unreachable_hosts' => []
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
        'unreachable_hosts' => []
    ]);
    exit(2);
}
