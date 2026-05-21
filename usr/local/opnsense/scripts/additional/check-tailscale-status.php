#!/usr/local/bin/php
<?php

declare(strict_types=1);

const SCRIPT_NAME = 'additional-check-tailscale-status';
const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/check_status.json';
const STATUS_FILE = '/var/run/additional_check_status_tailscale.json';
const LOCK_FILE = '/tmp/additional_check_tailscale_status.lock';
const DEFAULT_CHECK_IP = '100.100.100.100';
const TAILSCALE_RC = '/usr/local/etc/rc.d/tailscaled';

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
        return ['tailscale_check_ping' => DEFAULT_CHECK_IP];
    }

    $raw = file_get_contents(CONFIG_FILE);
    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        return ['tailscale_check_ping' => DEFAULT_CHECK_IP];
    }

    if (empty($data['tailscale_check_ping'])) {
        $data['tailscale_check_ping'] = DEFAULT_CHECK_IP;
    }

    return $data;
}

function run_command(string $command): array
{
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);

    return [
        'exit_code' => $exitCode,
        'output' => trim(implode("\n", $output))
    ];
}

function ping_host(string $ip): bool
{
    $cmd = sprintf('/sbin/ping -c 1 -W 1 %s >/dev/null 2>&1', escapeshellarg($ip));
    exec($cmd, $out, $rc);
    return $rc === 0;
}

function tailscaled_status_text(): string
{
    if (!is_executable(TAILSCALE_RC)) {
        return 'rc.d tailscaled не найден';
    }

    $result = run_command(TAILSCALE_RC . ' status');
    return $result['output'];
}

function tailscaled_is_running(): bool
{
    $status = tailscaled_status_text();
    return stripos($status, 'is running') !== false;
}

$silent = in_array('--silent', $argv, true) || in_array('-s', $argv, true);

$lockHandle = fopen(LOCK_FILE, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    out_msg('Скрипт уже выполняется. Выход.', $silent);
    exit(0);
}

try {
    $config = load_json_config();
    $checkIp = trim((string)($config['tailscale_check_ping'] ?? DEFAULT_CHECK_IP));

    if ($checkIp === '') {
        $checkIp = DEFAULT_CHECK_IP;
    }

    if (!filter_var($checkIp, FILTER_VALIDATE_IP)) {
        throw new RuntimeException('Некорректный IP для проверки Tailscale: ' . $checkIp);
    }

    out_msg('Проверяю Tailscale IP: ' . $checkIp, $silent);

    if (ping_host($checkIp)) {
        $message = 'Tailscale OK';
        out_msg($message, $silent);
        write_status([
            'ok' => true,
            'state' => 'ok',
            'message' => $message,
            'check_ip' => $checkIp,
            'service_action' => 'none',
            'service_status' => tailscaled_status_text()
        ]);
        exit(0);
    }

    out_msg('Ping не проходит', $silent);

    if (!is_executable(TAILSCALE_RC)) {
        throw new RuntimeException('Не найден скрипт сервиса: ' . TAILSCALE_RC);
    }

    $wasRunning = tailscaled_is_running();
    $action = $wasRunning ? 'restart' : 'start';

    out_msg($wasRunning ? 'tailscaled запущен, выполняю restart' : 'tailscaled не запущен, выполняю start', $silent);

    $result = run_command(TAILSCALE_RC . ' ' . $action);

    if ($result['exit_code'] !== 0) {
        throw new RuntimeException('Не удалось выполнить tailscaled ' . $action . ': ' . $result['output']);
    }

    sleep(3);

    $okAfterAction = ping_host($checkIp);

    if ($okAfterAction) {
        $message = $action === 'restart'
            ? 'Tailscale был недоступен, выполнен restart, после этого ping успешен'
            : 'Tailscale был недоступен, выполнен start, после этого ping успешен';

        out_msg($message, $silent);
        write_status([
            'ok' => true,
            'state' => $action === 'restart' ? 'degraded_restarted_ok' : 'degraded_started_ok',
            'message' => $message,
            'check_ip' => $checkIp,
            'service_action' => $action,
            'service_status' => tailscaled_status_text()
        ]);
        exit(0);
    }

    $message = $action === 'restart'
        ? 'Tailscale был недоступен, выполнен restart, но ping всё ещё не проходит'
        : 'Tailscale был недоступен, выполнен start, но ping всё ещё не проходит';

    out_msg($message, $silent);
    write_status([
        'ok' => false,
        'state' => $action === 'restart' ? 'degraded_restarted' : 'degraded_started',
        'message' => $message,
        'check_ip' => $checkIp,
        'service_action' => $action,
        'service_status' => tailscaled_status_text()
    ]);
    exit(1);
} catch (Throwable $e) {
    $message = $e->getMessage();
    out_msg('ERROR: ' . $message, $silent);
    write_status([
        'ok' => false,
        'state' => 'error',
        'message' => $message,
        'check_ip' => $checkIp ?? DEFAULT_CHECK_IP,
        'service_action' => 'error',
        'service_status' => ''
    ]);
    exit(2);
}
