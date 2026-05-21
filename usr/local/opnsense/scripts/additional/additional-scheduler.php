#!/usr/local/bin/php
<?php

declare(strict_types=1);

const SCRIPT_NAME = 'additional-scheduler';
const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/scheduler.json';
const STATE_FILE = '/var/run/additional_scheduler_state.json';
const LOCK_FILE = '/tmp/additional_scheduler.lock';

function tasks_definition(): array
{
    return [
        'geoip_update' => [
            'title' => 'GeoIP update',
            'command' => '/usr/local/opnsense/scripts/additional/updategeoip.php --silent',
        ],
        'wireguard_check' => [
            'title' => 'WireGuard check',
            'command' => '/usr/local/opnsense/scripts/additional/check-wg-status.php --silent',
        ],
        'tailscale_check' => [
            'title' => 'Tailscale check',
            'command' => '/usr/local/opnsense/scripts/additional/check-tailscale-status.php --silent',
        ],
        'check_wan' => [
            'title' => 'Check WAN',
            'command' => '/usr/local/opnsense/scripts/additional/check-wan-gateway-loss.php',
        ],
        'update_check' => [
            'title' => 'Update check',
            'command' => '/usr/local/opnsense/scripts/additional/additional-updater.php --check --json',
        ],
    ];
}

function default_task_config(string $taskId): array
{
    $defaults = [
        'geoip_update' => [
            'enabled' => '0',
            'mode' => 'daily',
            'interval_minutes' => '1440',
            'time' => '05:00',
        ],
        'wireguard_check' => [
            'enabled' => '0',
            'mode' => 'interval',
            'interval_minutes' => '2',
            'time' => '00:00',
        ],
        'tailscale_check' => [
            'enabled' => '0',
            'mode' => 'interval',
            'interval_minutes' => '2',
            'time' => '00:00',
        ],
        'check_wan' => [
            'enabled' => '0',
            'mode' => 'interval',
            'interval_minutes' => '2',
            'time' => '00:00',
        ],
        'update_check' => [
            'enabled' => '0',
            'mode' => 'daily',
            'interval_minutes' => '1440',
            'time' => '06:00',
        ],
    ];

    return $defaults[$taskId] ?? [
        'enabled' => '0',
        'mode' => 'interval',
        'interval_minutes' => '60',
        'time' => '00:00',
    ];
}

function default_config(): array
{
    $config = [
        'version' => 1,
        'tasks' => [],
    ];

    foreach (array_keys(tasks_definition()) as $taskId) {
        $config['tasks'][$taskId] = default_task_config($taskId);
    }

    return $config;
}

function normalize_bool01($value): string
{
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
}

function normalize_task_config(string $taskId, array $task): array
{
    $default = default_task_config($taskId);

    $enabled = normalize_bool01($task['enabled'] ?? $default['enabled']);
    $mode = strtolower(trim((string)($task['mode'] ?? $default['mode'])));

    if (!in_array($mode, ['interval', 'daily'], true)) {
        $mode = $default['mode'];
    }

    $minutes = trim((string)($task['interval_minutes'] ?? $default['interval_minutes']));
    if (!ctype_digit($minutes) || (int)$minutes < 1) {
        $minutes = $default['interval_minutes'];
    }

    $time = trim((string)($task['time'] ?? $default['time']));
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $time = $default['time'];
    }

    return [
        'enabled' => $enabled,
        'mode' => $mode,
        'interval_minutes' => $minutes,
        'time' => $time,
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

    foreach (array_keys(tasks_definition()) as $taskId) {
        $config['tasks'][$taskId] = normalize_task_config(
            $taskId,
            is_array($config['tasks'][$taskId] ?? null) ? $config['tasks'][$taskId] : []
        );
    }

    return $config;
}

function save_config(array $config): void
{
    $dir = dirname(CONFIG_FILE);

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $normalized = default_config();

    if (isset($config['tasks']) && is_array($config['tasks'])) {
        foreach (array_keys(tasks_definition()) as $taskId) {
            $normalized['tasks'][$taskId] = normalize_task_config(
                $taskId,
                is_array($config['tasks'][$taskId] ?? null) ? $config['tasks'][$taskId] : []
            );
        }
    }

    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        throw new RuntimeException('Не удалось сформировать scheduler.json');
    }

    if (file_put_contents(CONFIG_FILE, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать ' . CONFIG_FILE);
    }

    chmod(CONFIG_FILE, 0644);
}

function read_state(): array
{
    if (!is_readable(STATE_FILE)) {
        return [
            'last_scheduler_run' => '',
            'tasks' => [],
        ];
    }

    $raw = file_get_contents(STATE_FILE);
    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        return [
            'last_scheduler_run' => '',
            'tasks' => [],
        ];
    }

    if (!isset($data['tasks']) || !is_array($data['tasks'])) {
        $data['tasks'] = [];
    }

    return $data;
}

function write_state(array $state): void
{
    $state['last_scheduler_run'] = date('Y-m-d H:i:s');

    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json !== false) {
        file_put_contents(STATE_FILE, $json . "\n", LOCK_EX);
        chmod(STATE_FILE, 0644);
    }
}

function task_last_run_ts(array $state, string $taskId): int
{
    $value = $state['tasks'][$taskId]['last_run_ts'] ?? 0;
    return is_numeric($value) ? (int)$value : 0;
}

function daily_due(array $taskConfig, int $now, int $lastRunTs): bool
{
    $time = (string)($taskConfig['time'] ?? '00:00');

    if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $m)) {
        $time = '00:00';
        $m = ['00:00', '00', '00'];
    }

    $scheduledTs = mktime((int)$m[1], (int)$m[2], 0, (int)date('m', $now), (int)date('d', $now), (int)date('Y', $now));

    if ($now < $scheduledTs) {
        return false;
    }

    if ($lastRunTs <= 0) {
        return true;
    }

    return date('Y-m-d', $lastRunTs) !== date('Y-m-d', $now);
}

function interval_due(array $taskConfig, int $now, int $lastRunTs): bool
{
    $minutes = max(1, (int)($taskConfig['interval_minutes'] ?? 60));

    if ($lastRunTs <= 0) {
        return true;
    }

    return ($now - $lastRunTs) >= ($minutes * 60);
}

function task_due(array $taskConfig, array $state, string $taskId, int $now, bool $force = false): bool
{
    if ($force) {
        return true;
    }

    if (normalize_bool01($taskConfig['enabled'] ?? '0') !== '1') {
        return false;
    }

    $lastRunTs = task_last_run_ts($state, $taskId);
    $mode = strtolower((string)($taskConfig['mode'] ?? 'interval'));

    if ($mode === 'daily') {
        return daily_due($taskConfig, $now, $lastRunTs);
    }

    return interval_due($taskConfig, $now, $lastRunTs);
}

function next_run_ts(array $taskConfig, array $state, string $taskId, int $now): int
{
    if (normalize_bool01($taskConfig['enabled'] ?? '0') !== '1') {
        return 0;
    }

    $lastRunTs = task_last_run_ts($state, $taskId);
    $mode = strtolower((string)($taskConfig['mode'] ?? 'interval'));

    if ($mode === 'daily') {
        $time = (string)($taskConfig['time'] ?? '00:00');
        if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $m)) {
            $m = ['00:00', '00', '00'];
        }

        $today = mktime((int)$m[1], (int)$m[2], 0, (int)date('m', $now), (int)date('d', $now), (int)date('Y', $now));

        if ($now < $today) {
            return $today;
        }

        if ($lastRunTs > 0 && date('Y-m-d', $lastRunTs) === date('Y-m-d', $now)) {
            return $today + 86400;
        }

        return $today;
    }

    $minutes = max(1, (int)($taskConfig['interval_minutes'] ?? 60));

    if ($lastRunTs <= 0) {
        return $now;
    }

    return $lastRunTs + ($minutes * 60);
}

function command_exists(string $command): bool
{
    $parts = preg_split('/\s+/', trim($command));
    if ($parts === false || empty($parts[0])) {
        return false;
    }

    return is_executable($parts[0]);
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

function run_task(string $taskId, array $definition): array
{
    $command = (string)($definition['command'] ?? '');

    if ($command === '' || !command_exists($command)) {
        return [
            'status' => 'error',
            'message' => 'Команда задачи не найдена или не исполняемая',
            'exit_code' => 127,
            'output' => $command,
        ];
    }

    $result = run_command($command);
    $ok = $result['exit_code'] === 0;

    return [
        'status' => $ok ? 'ok' : 'error',
        'message' => $ok ? 'Задача выполнена' : 'Ошибка выполнения задачи',
        'exit_code' => $result['exit_code'],
        'output' => $result['output'],
    ];
}

function task_schedule_text(array $taskConfig): string
{
    if (normalize_bool01($taskConfig['enabled'] ?? '0') !== '1') {
        return 'Отключено';
    }

    $mode = strtolower((string)($taskConfig['mode'] ?? 'interval'));

    if ($mode === 'daily') {
        return 'каждый день в ' . (string)($taskConfig['time'] ?? '00:00');
    }

    $n = max(1, (int)($taskConfig['interval_minutes'] ?? 60));
    $lastTwo = $n % 100;
    $last = $n % 10;
    $unit = 'минут';

    if ($lastTwo < 11 || $lastTwo > 14) {
        if ($last === 1) {
            $unit = 'минуту';
        } elseif ($last >= 2 && $last <= 4) {
            $unit = 'минуты';
        }
    }

    return 'каждые ' . $n . ' ' . $unit;
}

function print_json(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

$args = [
    'json' => false,
    'force' => false,
    'task' => '',
];

foreach ($argv as $arg) {
    if ($arg === '--json') {
        $args['json'] = true;
    } elseif ($arg === '--force') {
        $args['force'] = true;
    } elseif (strpos($arg, '--task=') === 0) {
        $args['task'] = substr($arg, strlen('--task='));
    }
}

$lockHandle = fopen(LOCK_FILE, 'c');

if ($lockHandle === false) {
    $result = [
        'status' => 'error',
        'message' => 'Не удалось открыть lock-файл: ' . LOCK_FILE,
    ];
    print_json($result);
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $result = [
        'status' => 'locked',
        'message' => 'Scheduler уже выполняется',
        'state' => read_state(),
    ];
    print_json($result);
    exit(0);
}

$config = load_config();
$state = read_state();
$definitions = tasks_definition();
$now = time();

$result = [
    'status' => 'ok',
    'message' => 'Scheduler выполнен',
    'timestamp' => date('Y-m-d H:i:s', $now),
    'ran' => [],
    'skipped' => [],
];

foreach ($definitions as $taskId => $definition) {
    if ($args['task'] !== '' && $args['task'] !== $taskId) {
        continue;
    }

    $taskConfig = $config['tasks'][$taskId] ?? default_task_config($taskId);

    if (!task_due($taskConfig, $state, $taskId, $now, $args['force'])) {
        $state['tasks'][$taskId]['next_run_ts'] = next_run_ts($taskConfig, $state, $taskId, $now);
        $state['tasks'][$taskId]['next_run'] = $state['tasks'][$taskId]['next_run_ts'] > 0 ? date('Y-m-d H:i:s', $state['tasks'][$taskId]['next_run_ts']) : '';
        $state['tasks'][$taskId]['schedule'] = task_schedule_text($taskConfig);

        $result['skipped'][] = $taskId;
        continue;
    }

    $run = run_task($taskId, $definition);

    $state['tasks'][$taskId] = array_merge($state['tasks'][$taskId] ?? [], [
        'title' => $definition['title'],
        'schedule' => task_schedule_text($taskConfig),
        'last_run_ts' => $now,
        'last_run' => date('Y-m-d H:i:s', $now),
        'last_status' => $run['status'],
        'last_message' => $run['message'],
        'last_exit_code' => $run['exit_code'],
        'last_output' => $run['output'],
    ]);

    $state['tasks'][$taskId]['next_run_ts'] = next_run_ts($taskConfig, $state, $taskId, $now);
    $state['tasks'][$taskId]['next_run'] = $state['tasks'][$taskId]['next_run_ts'] > 0 ? date('Y-m-d H:i:s', $state['tasks'][$taskId]['next_run_ts']) : '';

    $result['ran'][] = [
        'task' => $taskId,
        'status' => $run['status'],
        'exit_code' => $run['exit_code'],
    ];

    if ($run['status'] !== 'ok') {
        $result['status'] = 'warning';
        $result['message'] = 'Scheduler выполнен, но есть ошибки задач';
    }
}

write_state($state);
$result['state'] = $state;

print_json($result);
exit($result['status'] === 'ok' || $result['status'] === 'warning' ? 0 : 1);
