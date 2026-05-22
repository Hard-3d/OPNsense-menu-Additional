#!/usr/local/bin/php
<?php

declare(strict_types=1);

const SCRIPT_NAME = 'additional-udp2raw';
const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/udp2raw.json';
const STATUS_FILE = '/var/run/additional_udp2raw_status.json';
const BINARY_FILE = '/usr/local/opnsense/scripts/additional/bin/udp2raw_freebsd';
const LOG_DIR = '/var/log';
const PID_DIR = '/var/run';

function default_instance(): array
{
    return [
        'id' => 'default',
        'enabled' => '0',
        'name' => 'udp2raw-1',
        'mode' => 'client',
        'listen' => '127.0.0.1:51821',
        'remote' => '',
        'key' => '',
        'raw_mode' => 'easyfaketcp',
        'dev' => '',
        'log_level' => '3',
        'extra_args' => '',
    ];
}

function default_config(): array
{
    return [
        'autostart' => '0',
        'watchdog' => '0',
        'instances' => [default_instance()],
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

function safe_id(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_.-]+/', '_', $value);
    $value = trim((string)$value, '_');
    return $value !== '' ? $value : 'default';
}

function normalize_endpoint(string $value): string
{
    return trim($value);
}

function normalize_instance(array $item, int $index = 0): array
{
    $default = default_instance();
    $item = array_merge($default, $item);

    $mode = strtolower(trim((string)$item['mode']));
    if (!in_array($mode, ['client', 'server'], true)) {
        $mode = 'client';
    }

    $rawMode = strtolower(trim((string)$item['raw_mode']));
    if (!in_array($rawMode, ['faketcp', 'easyfaketcp', 'udp', 'icmp'], true)) {
        $rawMode = 'easyfaketcp';
    }

    $logLevel = trim((string)$item['log_level']);
    if (!preg_match('/^[0-9]$/', $logLevel)) {
        $logLevel = '3';
    }

    $id = safe_id((string)($item['id'] ?? ''));
    if ($id === 'default' && $index > 0) {
        $id = 'instance_' . ($index + 1);
    }

    $name = trim((string)$item['name']);
    if ($name === '') {
        $name = $id;
    }

    return [
        'id' => $id,
        'enabled' => bool01($item['enabled'] ?? '0'),
        'name' => $name,
        'mode' => $mode,
        'listen' => normalize_endpoint((string)$item['listen']),
        'remote' => normalize_endpoint((string)$item['remote']),
        'key' => (string)$item['key'],
        'raw_mode' => $rawMode,
        'dev' => trim((string)$item['dev']),
        'log_level' => $logLevel,
        'extra_args' => trim((string)$item['extra_args']),
    ];
}

function load_config(): array
{
    $config = default_config();

    if (is_readable(CONFIG_FILE)) {
        $raw = file_get_contents(CONFIG_FILE);
        $data = json_decode((string)$raw, true);

        if (is_array($data)) {
            $config = array_merge($config, $data);
        }
    }

    $instances = [];
    if (isset($config['instances']) && is_array($config['instances'])) {
        foreach (array_values($config['instances']) as $index => $instance) {
            if (is_array($instance)) {
                $instances[] = normalize_instance($instance, $index);
            }
        }
    }

    if (empty($instances)) {
        $instances[] = default_instance();
    }

    return [
        'autostart' => bool01($config['autostart'] ?? '0'),
        'watchdog' => bool01($config['watchdog'] ?? '0'),
        'instances' => $instances,
    ];
}

function save_config(array $config): array
{
    $normalized = [
        'autostart' => bool01($config['autostart'] ?? '0'),
        'watchdog' => bool01($config['watchdog'] ?? '0'),
        'instances' => [],
    ];

    if (isset($config['instances']) && is_array($config['instances'])) {
        foreach (array_values($config['instances']) as $index => $instance) {
            if (is_array($instance)) {
                $normalized['instances'][] = normalize_instance($instance, $index);
            }
        }
    }

    if (empty($normalized['instances'])) {
        $normalized['instances'][] = default_instance();
    }

    $dir = dirname(CONFIG_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Не удалось сформировать udp2raw.json');
    }

    if (file_put_contents(CONFIG_FILE, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать ' . CONFIG_FILE);
    }

    chmod(CONFIG_FILE, 0600);

    return $normalized;
}

function pid_file(array $instance): string
{
    return PID_DIR . '/additional_udp2raw_' . safe_id((string)$instance['id']) . '.pid';
}

function log_file(array $instance): string
{
    return LOG_DIR . '/additional_udp2raw_' . safe_id((string)$instance['id']) . '.log';
}

function is_pid_running(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }

    $output = [];
    $exitCode = 0;
    exec('/bin/kill -0 ' . escapeshellarg((string)$pid) . ' 2>/dev/null', $output, $exitCode);
    return $exitCode === 0;
}

function current_pid(array $instance): int
{
    $file = pid_file($instance);
    if (!is_readable($file)) {
        return 0;
    }

    $pid = trim((string)file_get_contents($file));
    return ctype_digit($pid) ? (int)$pid : 0;
}

function instance_status(array $instance): array
{
    $pid = current_pid($instance);
    $running = is_pid_running($pid);

    if (!$running && $pid > 0) {
        @unlink(pid_file($instance));
        $pid = 0;
    }

    return [
        'id' => $instance['id'],
        'name' => $instance['name'],
        'enabled' => $instance['enabled'],
        'mode' => $instance['mode'],
        'listen' => $instance['listen'],
        'remote' => $instance['remote'],
        'raw_mode' => $instance['raw_mode'],
        'pid' => $pid,
        'running' => $running,
        'status' => $running ? 'running' : 'stopped',
        'pid_file' => pid_file($instance),
        'log_file' => log_file($instance),
    ];
}

function validate_instance_for_start(array $instance): void
{
    if (!is_executable(BINARY_FILE)) {
        throw new RuntimeException('Бинарник udp2raw не найден или не исполняемый: ' . BINARY_FILE);
    }

    if ($instance['listen'] === '') {
        throw new RuntimeException('Для ' . $instance['name'] . ' не заполнен listen (-l)');
    }

    if ($instance['remote'] === '') {
        throw new RuntimeException('Для ' . $instance['name'] . ' не заполнен remote (-r)');
    }

    if ($instance['key'] === '') {
        throw new RuntimeException('Для ' . $instance['name'] . ' не заполнен key (-k)');
    }

    if ($instance['extra_args'] !== '' && !preg_match('/^[A-Za-z0-9_.,:=@%+\/\-\s]+$/', $instance['extra_args'])) {
        throw new RuntimeException('В extra args для ' . $instance['name'] . ' найдены недопустимые символы');
    }
}

function build_command(array $instance): string
{
    $flag = $instance['mode'] === 'server' ? '-s' : '-c';

    $parts = [
        escapeshellcmd(BINARY_FILE),
        $flag,
        '-l', escapeshellarg($instance['listen']),
        '-r', escapeshellarg($instance['remote']),
        '-k', escapeshellarg($instance['key']),
        '--raw-mode', escapeshellarg($instance['raw_mode']),
        '--log-level', escapeshellarg($instance['log_level']),
    ];

    if ($instance['dev'] !== '') {
        $parts[] = '--dev';
        $parts[] = escapeshellarg($instance['dev']);
    }

    if ($instance['extra_args'] !== '') {
        $parts[] = $instance['extra_args'];
    }

    return implode(' ', $parts);
}

function start_instance(array $instance): array
{
    if ($instance['enabled'] !== '1') {
        return [
            'id' => $instance['id'],
            'name' => $instance['name'],
            'status' => 'skipped',
            'message' => 'Instance disabled',
        ];
    }

    $status = instance_status($instance);
    if ($status['running']) {
        return [
            'id' => $instance['id'],
            'name' => $instance['name'],
            'status' => 'already_running',
            'message' => 'Already running',
            'pid' => $status['pid'],
        ];
    }

    validate_instance_for_start($instance);

    $log = log_file($instance);
    @touch($log);
    @chmod($log, 0644);

    $command = sprintf(
        'nohup %s >> %s 2>&1 & echo $!',
        build_command($instance),
        escapeshellarg($log)
    );

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    $pid = isset($output[0]) && ctype_digit(trim($output[0])) ? (int)trim($output[0]) : 0;

    if ($exitCode !== 0 || $pid <= 0) {
        throw new RuntimeException('Не удалось запустить ' . $instance['name']);
    }

    file_put_contents(pid_file($instance), (string)$pid . "\n", LOCK_EX);
    chmod(pid_file($instance), 0644);

    return [
        'id' => $instance['id'],
        'name' => $instance['name'],
        'status' => 'started',
        'message' => 'Started',
        'pid' => $pid,
        'command' => preg_replace('/-k\s+\S+/', '-k ***', build_command($instance)),
    ];
}

function stop_instance(array $instance): array
{
    $pid = current_pid($instance);
    if ($pid <= 0 || !is_pid_running($pid)) {
        @unlink(pid_file($instance));
        return [
            'id' => $instance['id'],
            'name' => $instance['name'],
            'status' => 'already_stopped',
            'message' => 'Already stopped',
        ];
    }

    exec('/bin/kill ' . escapeshellarg((string)$pid) . ' 2>/dev/null');
    usleep(500000);

    if (is_pid_running($pid)) {
        exec('/bin/kill -9 ' . escapeshellarg((string)$pid) . ' 2>/dev/null');
    }

    @unlink(pid_file($instance));

    return [
        'id' => $instance['id'],
        'name' => $instance['name'],
        'status' => 'stopped',
        'message' => 'Stopped',
        'pid' => $pid,
    ];
}

function find_instance(array $config, string $id): ?array
{
    foreach ($config['instances'] as $instance) {
        if ((string)$instance['id'] === $id) {
            return $instance;
        }
    }

    return null;
}

function all_status(array $config): array
{
    $items = [];
    foreach ($config['instances'] as $instance) {
        $items[] = instance_status($instance);
    }

    return $items;
}

function write_status(array $payload): void
{
    $payload['timestamp'] = date('Y-m-d H:i:s');
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        @file_put_contents(STATUS_FILE, $json . "\n", LOCK_EX);
        @chmod(STATUS_FILE, 0644);
    }
}

function print_json(array $payload): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

function parse_args(array $argv): array
{
    $args = [
        'action' => 'status',
        'id' => '',
        'json' => false,
    ];

    foreach ($argv as $arg) {
        if ($arg === '--json') {
            $args['json'] = true;
        } elseif ($arg === '--status') {
            $args['action'] = 'status';
        } elseif ($arg === '--start-all') {
            $args['action'] = 'start_all';
        } elseif ($arg === '--stop-all') {
            $args['action'] = 'stop_all';
        } elseif ($arg === '--restart-all') {
            $args['action'] = 'restart_all';
        } elseif ($arg === '--watchdog') {
            $args['action'] = 'watchdog';
        } elseif ($arg === '--autostart') {
            $args['action'] = 'autostart';
        } elseif (strpos($arg, '--start=') === 0) {
            $args['action'] = 'start';
            $args['id'] = substr($arg, 8);
        } elseif (strpos($arg, '--stop=') === 0) {
            $args['action'] = 'stop';
            $args['id'] = substr($arg, 7);
        } elseif (strpos($arg, '--restart=') === 0) {
            $args['action'] = 'restart';
            $args['id'] = substr($arg, 10);
        }
    }

    return $args;
}

$args = parse_args($argv);

try {
    $config = load_config();
    $result = [
        'status' => 'ok',
        'message' => 'OK',
        'action' => $args['action'],
        'results' => [],
    ];

    if ($args['action'] === 'status') {
        $result['instances'] = all_status($config);
        write_status($result);
        print_json($result);
        exit(0);
    }

    if ($args['action'] === 'autostart' && $config['autostart'] !== '1') {
        $result['message'] = 'Autostart disabled';
        $result['instances'] = all_status($config);
        write_status($result);
        print_json($result);
        exit(0);
    }

    if ($args['action'] === 'watchdog' && $config['watchdog'] !== '1') {
        $result['message'] = 'Watchdog disabled';
        $result['instances'] = all_status($config);
        write_status($result);
        print_json($result);
        exit(0);
    }

    if (in_array($args['action'], ['start_all', 'autostart', 'watchdog'], true)) {
        foreach ($config['instances'] as $instance) {
            $result['results'][] = start_instance($instance);
        }
    } elseif ($args['action'] === 'stop_all') {
        foreach ($config['instances'] as $instance) {
            $result['results'][] = stop_instance($instance);
        }
    } elseif ($args['action'] === 'restart_all') {
        foreach ($config['instances'] as $instance) {
            $result['results'][] = stop_instance($instance);
            $result['results'][] = start_instance($instance);
        }
    } elseif (in_array($args['action'], ['start', 'stop', 'restart'], true)) {
        $instance = find_instance($config, $args['id']);
        if ($instance === null) {
            throw new RuntimeException('Instance not found: ' . $args['id']);
        }

        if ($args['action'] === 'start') {
            $result['results'][] = start_instance($instance);
        } elseif ($args['action'] === 'stop') {
            $result['results'][] = stop_instance($instance);
        } else {
            $result['results'][] = stop_instance($instance);
            $result['results'][] = start_instance($instance);
        }
    }

    $result['instances'] = all_status($config);
    write_status($result);
    print_json($result);
    exit(0);
} catch (Throwable $e) {
    $payload = [
        'status' => 'error',
        'message' => $e->getMessage(),
        'action' => $args['action'] ?? 'unknown',
    ];
    write_status($payload);
    print_json($payload);
    exit(1);
}
