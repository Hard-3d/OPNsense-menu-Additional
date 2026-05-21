<?php

namespace OPNsense\Additional\Api;

use OPNsense\Base\ApiControllerBase;

class SchedulerController extends ApiControllerBase
{
    private const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/scheduler.json';
    private const STATE_FILE = '/var/run/additional_scheduler_state.json';
    private const SCRIPT_FILE = '/usr/local/opnsense/scripts/additional/additional-scheduler.php';

    private function tasksDefinition(): array
    {
        return [
            'geoip_update' => ['title' => 'GeoIP update'],
            'wireguard_check' => ['title' => 'WireGuard check'],
            'tailscale_check' => ['title' => 'Tailscale check'],
            'check_wan' => ['title' => 'Check WAN'],
            'update_check' => ['title' => 'Update check'],
        ];
    }

    private function defaultTaskConfig(string $taskId): array
    {
        $defaults = [
            'geoip_update' => ['enabled' => '0', 'mode' => 'daily', 'interval_minutes' => '1440', 'time' => '05:00'],
            'wireguard_check' => ['enabled' => '0', 'mode' => 'interval', 'interval_minutes' => '2', 'time' => '00:00'],
            'tailscale_check' => ['enabled' => '0', 'mode' => 'interval', 'interval_minutes' => '2', 'time' => '00:00'],
            'check_wan' => ['enabled' => '0', 'mode' => 'interval', 'interval_minutes' => '2', 'time' => '00:00'],
            'update_check' => ['enabled' => '0', 'mode' => 'daily', 'interval_minutes' => '1440', 'time' => '06:00'],
        ];

        return $defaults[$taskId] ?? ['enabled' => '0', 'mode' => 'interval', 'interval_minutes' => '60', 'time' => '00:00'];
    }

    private function defaultConfig(): array
    {
        $config = [
            'version' => 1,
            'tasks' => [],
        ];

        foreach (array_keys($this->tasksDefinition()) as $taskId) {
            $config['tasks'][$taskId] = $this->defaultTaskConfig($taskId);
        }

        return $config;
    }

    private function normalizeBool01($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = strtolower(trim((string)$value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    }

    private function normalizeTaskConfig(string $taskId, array $task): array
    {
        $default = $this->defaultTaskConfig($taskId);

        $enabled = $this->normalizeBool01($task['enabled'] ?? $default['enabled']);
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

    private function loadConfig(): array
    {
        $config = $this->defaultConfig();

        if (is_readable(self::CONFIG_FILE)) {
            $raw = file_get_contents(self::CONFIG_FILE);
            $data = json_decode((string)$raw, true);

            if (is_array($data)) {
                $config = array_replace_recursive($config, $data);
            }
        }

        foreach (array_keys($this->tasksDefinition()) as $taskId) {
            $config['tasks'][$taskId] = $this->normalizeTaskConfig(
                $taskId,
                is_array($config['tasks'][$taskId] ?? null) ? $config['tasks'][$taskId] : []
            );
        }

        return $config;
    }

    private function saveConfig(array $config): array
    {
        $dir = dirname(self::CONFIG_FILE);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $normalized = $this->defaultConfig();

        if (isset($config['tasks']) && is_array($config['tasks'])) {
            foreach (array_keys($this->tasksDefinition()) as $taskId) {
                $normalized['tasks'][$taskId] = $this->normalizeTaskConfig(
                    $taskId,
                    is_array($config['tasks'][$taskId] ?? null) ? $config['tasks'][$taskId] : []
                );
            }
        }

        $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Не удалось сформировать scheduler.json');
        }

        if (file_put_contents(self::CONFIG_FILE, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать ' . self::CONFIG_FILE);
        }

        chmod(self::CONFIG_FILE, 0644);

        return $normalized;
    }

    private function readState(): array
    {
        if (!is_readable(self::STATE_FILE)) {
            return [
                'last_scheduler_run' => '',
                'tasks' => [],
            ];
        }

        $raw = file_get_contents(self::STATE_FILE);
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

    private function scheduleText(array $taskConfig): string
    {
        if (($taskConfig['enabled'] ?? '0') !== '1') {
            return 'Отключено';
        }

        if (($taskConfig['mode'] ?? 'interval') === 'daily') {
            return 'каждый день в ' . ($taskConfig['time'] ?? '00:00');
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

    private function enrichConfig(array $config, array $state): array
    {
        foreach ($this->tasksDefinition() as $taskId => $definition) {
            $config['tasks'][$taskId]['title'] = $definition['title'];
            $config['tasks'][$taskId]['schedule_text'] = $this->scheduleText($config['tasks'][$taskId]);
            $config['tasks'][$taskId]['last_run'] = $state['tasks'][$taskId]['last_run'] ?? '';
            $config['tasks'][$taskId]['last_status'] = $state['tasks'][$taskId]['last_status'] ?? '';
            $config['tasks'][$taskId]['last_message'] = $state['tasks'][$taskId]['last_message'] ?? '';
            $config['tasks'][$taskId]['next_run'] = $state['tasks'][$taskId]['next_run'] ?? '';
        }

        return $config;
    }

    private function runCommand(string $command): array
    {
        $output = [];
        $exitCode = 0;

        exec($command . ' 2>&1', $output, $exitCode);

        return [
            'exit_code' => $exitCode,
            'output' => trim(implode("\n", $output)),
        ];
    }

    public function getAction()
    {
        $config = $this->loadConfig();
        $state = $this->readState();

        return [
            'status' => 'ok',
            'config' => $this->enrichConfig($config, $state),
            'state' => $state,
            'tasks' => $this->tasksDefinition(),
        ];
    }

    public function statusAction()
    {
        return $this->getAction();
    }

    public function setAction()
    {
        try {
            $payload = $this->request->getJsonRawBody(true);

            if (!is_array($payload)) {
                $payload = $_POST;
            }

            $config = $this->saveConfig($payload);
            $state = $this->readState();

            return [
                'status' => 'ok',
                'message' => 'Настройки Scheduler сохранены',
                'config' => $this->enrichConfig($config, $state),
                'state' => $state,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function runAction()
    {
        try {
            if (!is_executable(self::SCRIPT_FILE)) {
                return [
                    'status' => 'error',
                    'message' => 'Scheduler script не найден или не исполняемый: ' . self::SCRIPT_FILE,
                ];
            }

            $payload = $this->request->getJsonRawBody(true);

            if (!is_array($payload)) {
                $payload = [];
            }

            $force = !empty($payload['force']);
            $task = trim((string)($payload['task'] ?? ''));

            $command = escapeshellcmd(self::SCRIPT_FILE) . ' --json';

            if ($force) {
                $command .= ' --force';
            }

            if ($task !== '') {
                $command .= ' --task=' . escapeshellarg($task);
            }

            $result = $this->runCommand($command);
            $decoded = json_decode($result['output'], true);

            if (!is_array($decoded)) {
                $decoded = [
                    'status' => $result['exit_code'] === 0 ? 'ok' : 'error',
                    'message' => $result['exit_code'] === 0 ? 'Scheduler выполнен' : 'Ошибка Scheduler',
                    'output' => $result['output'],
                ];
            }

            $config = $this->loadConfig();
            $state = $this->readState();

            return [
                'status' => ($decoded['status'] ?? '') === 'error' ? 'error' : 'ok',
                'message' => $decoded['message'] ?? 'Scheduler выполнен',
                'scheduler' => $decoded,
                'config' => $this->enrichConfig($config, $state),
                'state' => $state,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
