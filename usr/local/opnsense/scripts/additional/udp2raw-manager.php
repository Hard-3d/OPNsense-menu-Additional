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

function endpoint_host(string $endpoint): string
{
    $endpoint = trim($endpoint);

    if ($endpoint === '') {
        return '';
    }

    if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $endpoint, $m)) {
        return $m[1];
    }

    if (strpos($endpoint, ':') !== false) {
        $parts = explode(':', $endpoint);
        array_pop($parts);
        return implode(':', $parts);
    }

    return $endpoint;
}

function resolve_host_for_route(string $host): string
{
    $host = trim($host);

    if ($host === '') {
        return '';
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return $host;
    }

    $resolved = gethostbyname($host);

    if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
        return $resolved;
    }

    return $host;
}

function is_bad_pcap_dev(string $dev): bool
{
    $dev = strtolower(trim($dev));

    if ($dev === '') {
        return true;
    }

    /*
     * Эти интерфейсы часто имеют DLT_NULL/loopback/tunnel link type.
     * Для udp2raw на FreeBSD/OPNsense нужен физический Ethernet-like dev.
     */
    $badPrefixes = [
        'lo', 'pflog', 'pfsync', 'enc', 'ipsec',
        'tun', 'tap', 'wg', 'wireguard', 'ovpn', 'tailscale',
        'gif', 'gre', 'stf', 'vxlan'
    ];

    foreach ($badPrefixes as $prefix) {
        if (strpos($dev, $prefix) === 0) {
            return true;
        }
    }

    return false;
}

function parse_route_get_dev(string $target): string
{
    $target = trim($target);

    if ($target === '') {
        return '';
    }

    $cmd = '/sbin/route -n get ' . escapeshellarg($target) . ' 2>/dev/null';
    $output = [];
    $exitCode = 0;

    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
        return '';
    }

    foreach ($output as $line) {
        $line = trim((string)$line);

        if (preg_match('/^(?:interface|ifp):\s*([A-Za-z0-9_.:-]+)/', $line, $m)) {
            return trim($m[1]);
        }
    }

    return '';
}

function default_route_dev(): string
{
    $dev = parse_route_get_dev('8.8.8.8');

    if ($dev !== '' && !is_bad_pcap_dev($dev)) {
        return $dev;
    }

    $dev = parse_route_get_dev('1.1.1.1');

    if ($dev !== '' && !is_bad_pcap_dev($dev)) {
        return $dev;
    }

    return '';
}

function first_physical_dev(): string
{
    $output = [];
    $exitCode = 0;

    exec('/sbin/ifconfig -l 2>/dev/null', $output, $exitCode);

    if ($exitCode !== 0 || empty($output)) {
        return '';
    }

    $names = preg_split('/\s+/', trim(implode(' ', $output)));

    if (!is_array($names)) {
        return '';
    }

    foreach ($names as $dev) {
        $dev = trim((string)$dev);

        if ($dev !== '' && !is_bad_pcap_dev($dev)) {
            return $dev;
        }
    }

    return '';
}

function detect_dev_for_remote(string $remote): string
{
    $host = resolve_host_for_route(endpoint_host($remote));

    if ($host === '') {
        return '';
    }

    $routeDev = parse_route_get_dev($host);

    if ($routeDev !== '' && !is_bad_pcap_dev($routeDev)) {
        return $routeDev;
    }

    /*
     * Если маршрут указывает на lo0/tun/wg/tailscale и т.п.,
     * udp2raw получает ошибку вроде "unknown pcap link type : 109".
     * В этом случае используем физический default route dev как fallback.
     */
    $defaultDev = default_route_dev();

    if ($defaultDev !== '') {
        return $defaultDev;
    }

    return first_physical_dev();
}

function effective_dev(array $instance): string
{
    $dev = trim((string)($instance['dev'] ?? ''));

    if ($dev !== '') {
        return $dev;
    }

    if (($instance['mode'] ?? '') === 'client') {
        return detect_dev_for_remote((string)($instance['remote'] ?? ''));
    }

    return '';
}

function strip_ansi(string $value): string
{
    $value = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $value);
    $value = preg_replace('/\x1b\[[0-9;]*m/', '', (string)$value);
    return trim((string)$value);
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

function command_matches_instance(string $command, array $instance): bool
{
    $binaryBase = basename(BINARY_FILE);

    if (strpos($command, BINARY_FILE) === false && strpos($command, $binaryBase) === false) {
        return false;
    }

    $modeFlag = $instance['mode'] === 'server' ? '-s' : '-c';
    if (strpos(' ' . $command . ' ', ' ' . $modeFlag . ' ') === false) {
        return false;
    }

    if ($instance['listen'] !== '' && strpos($command, $instance['listen']) === false) {
        return false;
    }

    if ($instance['remote'] !== '' && strpos($command, $instance['remote']) === false) {
        return false;
    }

    if ($instance['raw_mode'] !== '' && strpos($command, $instance['raw_mode']) === false) {
        return false;
    }

    return true;
}

function discover_pid(array $instance): int
{
    $output = [];
    $exitCode = 0;

    exec('/bin/ps axww -o pid= -o command= 2>/dev/null', $output, $exitCode);

    if ($exitCode !== 0) {
        return 0;
    }

    foreach ($output as $line) {
        $line = trim((string)$line);

        if ($line === '' || !preg_match('/^(\d+)\s+(.+)$/', $line, $m)) {
            continue;
        }

        $pid = (int)$m[1];
        $command = (string)$m[2];

        if ($pid <= 0 || $pid === getmypid()) {
            continue;
        }

        if (strpos($command, 'udp2raw-manager.php') !== false) {
            continue;
        }

        if (command_matches_instance($command, $instance) && is_pid_running($pid)) {
            return $pid;
        }
    }

    return 0;
}

function save_pid(array $instance, int $pid): void
{
    if ($pid <= 0) {
        return;
    }

    file_put_contents(pid_file($instance), (string)$pid . "\n", LOCK_EX);
    chmod(pid_file($instance), 0644);
}

function current_pid(array $instance): int
{
    $file = pid_file($instance);

    if (is_readable($file)) {
        $pid = trim((string)file_get_contents($file));

        if (ctype_digit($pid)) {
            $pidInt = (int)$pid;

            if (is_pid_running($pidInt)) {
                return $pidInt;
            }
        }
    }

    $discovered = discover_pid($instance);

    if ($discovered > 0) {
        save_pid($instance, $discovered);
        return $discovered;
    }

    return 0;
}

function log_tail(array $instance, int $lines = 20): string
{
    $file = log_file($instance);

    if (!is_readable($file)) {
        return '';
    }

    $output = [];
    $exitCode = 0;

    exec('/usr/bin/tail -n ' . escapeshellarg((string)$lines) . ' ' . escapeshellarg($file) . ' 2>/dev/null', $output, $exitCode);

    if ($exitCode !== 0) {
        return '';
    }

    return strip_ansi(trim(implode("\n", $output)));
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
        'effective_dev' => effective_dev($instance),
        'pid' => $pid,
        'running' => $running,
        'status' => $running ? 'running' : 'stopped',
        'pid_file' => pid_file($instance),
        'log_file' => log_file($instance),
        'last_log' => $running ? '' : log_tail($instance, 10),
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

    if ($instance['mode'] === 'server' && $instance['dev'] === '') {
        throw new RuntimeException(
            'Для ' . $instance['name'] . ' в server mode на FreeBSD/OPNsense обязательно заполните Dev (--dev), например vmx1'
        );
    }

    if ($instance['mode'] === 'client' && $instance['dev'] === '' && effective_dev($instance) === '') {
        throw new RuntimeException(
            'Для ' . $instance['name'] . ' в client mode не удалось автоматически определить Dev по маршруту до Remote. Проверьте Remote (-r) или укажите интерфейс через extra args: --dev vmx1'
        );
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

    $dev = effective_dev($instance);
    if ($dev !== '') {
        $parts[] = '--dev';
        $parts[] = escapeshellarg($dev);
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
    @file_put_contents($log, '');

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

    save_pid($instance, $pid);

    /*
     * udp2raw на некоторых сборках может быстро сменить PID или отпустить
     * родительский процесс. Поэтому после старта ждём немного и ищем
     * реальный процесс по командной строке.
     */
    usleep(800000);

    $realPid = current_pid($instance);

    if ($realPid <= 0) {
        $tail = log_tail($instance, 30);
        throw new RuntimeException(
            'udp2raw стартовал, но рабочий процесс не найден после запуска: ' .
            $instance['name'] .
            ($tail !== '' ? '. Последние строки лога: ' . $tail : '')
        );
    }

    save_pid($instance, $realPid);

    return [
        'id' => $instance['id'],
        'name' => $instance['name'],
        'status' => 'started',
        'message' => 'Started',
        'pid' => $realPid,
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
