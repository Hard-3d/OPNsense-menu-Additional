<?php

namespace OPNsense\Additional\Api;

use OPNsense\Base\ApiControllerBase;

class SchedulerController extends ApiControllerBase
{
    private const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/scheduler.json';
    private const STATE_FILE = '/var/run/additional_scheduler_state.json';
    private const SCRIPT_FILE = '/usr/local/opnsense/scripts/additional/additional-scheduler.php';
    private const CONFIG_XML = '/conf/config.xml';
    private const CRON_DESCRIPTION = 'Additional Scheduler';
    private const CRON_COMMAND = 'additional_scheduler run';

    private function tasksDefinition(): array
    {
        return [
            'geoip_update' => ['title' => 'GeoIP update'],
            'wireguard_check' => ['title' => 'WireGuard check'],
            'wireguard_peers_check' => ['title' => 'WireGuard peers check'],
            'tailscale_check' => ['title' => 'Tailscale check'],
            'check_wan' => ['title' => 'Check WAN'],
            'udp2raw_watchdog' => ['title' => 'udp2raw watchdog'],
            'update_check' => ['title' => 'Update check'],
        ];
    }

    private function defaultTaskConfig(string $taskId): array
    {
        $defaults = [
            'geoip_update' => ['enabled' => '0', 'mode' => 'daily', 'interval_minutes' => '1440', 'time' => '05:00'],
            'wireguard_check' => ['enabled' => '0', 'mode' => 'interval', 'interval_minutes' => '2', 'time' => '00:00'],
            'wireguard_peers_check' => ['enabled' => '0', 'mode' => 'interval', 'interval_minutes' => '1', 'time' => '00:00'],
            'tailscale_check' => ['enabled' => '0', 'mode' => 'interval', 'interval_minutes' => '2', 'time' => '00:00'],
            'check_wan' => ['enabled' => '0', 'mode' => 'interval', 'interval_minutes' => '2', 'time' => '00:00'],
            'udp2raw_watchdog' => ['enabled' => '0', 'mode' => 'interval', 'interval_minutes' => '1', 'time' => '00:00'],
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


    private function xmlValue($node, string $name, string $default = ''): string
    {
        if (!isset($node->{$name})) {
            return $default;
        }

        return trim((string)$node->{$name});
    }

    private function cronJobMatches($job): bool
    {
        $description = $this->xmlValue($job, 'description');
        $command = $this->xmlValue($job, 'command');

        return $description === self::CRON_DESCRIPTION || $command === self::CRON_COMMAND;
    }

    private function cronScheduleTextFromJob($job): string
    {
        return sprintf(
            '%s %s %s %s %s',
            $this->xmlValue($job, 'minutes', '*'),
            $this->xmlValue($job, 'hours', '*'),
            $this->xmlValue($job, 'days', '*'),
            $this->xmlValue($job, 'months', '*'),
            $this->xmlValue($job, 'weekdays', '*')
        );
    }

    private function isSchedulerCronCorrect($job): bool
    {
        return $this->xmlValue($job, 'minutes', '') === '*' &&
            $this->xmlValue($job, 'hours', '') === '*' &&
            $this->xmlValue($job, 'days', '') === '*' &&
            $this->xmlValue($job, 'months', '') === '*' &&
            $this->xmlValue($job, 'weekdays', '') === '*' &&
            $this->xmlValue($job, 'command', '') === self::CRON_COMMAND;
    }

    private function readSchedulerCronStatus(): array
    {
        if (!is_readable(self::CONFIG_XML)) {
            return [
                'exists' => false,
                'enabled' => false,
                'correct' => false,
                'message' => 'Не удалось прочитать ' . self::CONFIG_XML,
            ];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file(self::CONFIG_XML);

        if ($xml === false) {
            $errors = [];
            foreach (libxml_get_errors() as $error) {
                $errors[] = trim($error->message);
            }
            libxml_clear_errors();

            return [
                'exists' => false,
                'enabled' => false,
                'correct' => false,
                'message' => 'Ошибка чтения config.xml: ' . implode('; ', $errors),
            ];
        }

        if (!isset($xml->OPNsense->cron->jobs->job)) {
            return [
                'exists' => false,
                'enabled' => false,
                'correct' => false,
                'message' => 'Задание Additional Scheduler не найдено',
            ];
        }

        foreach ($xml->OPNsense->cron->jobs->job as $job) {
            if (!$this->cronJobMatches($job)) {
                continue;
            }

            $enabled = $this->xmlValue($job, 'enabled', '0') === '1';
            $correct = $this->isSchedulerCronCorrect($job);

            return [
                'exists' => true,
                'enabled' => $enabled,
                'correct' => $correct,
                'uuid' => (string)($job['uuid'] ?? ''),
                'description' => $this->xmlValue($job, 'description'),
                'command' => $this->xmlValue($job, 'command'),
                'parameters' => $this->xmlValue($job, 'parameters'),
                'minutes' => $this->xmlValue($job, 'minutes', '*'),
                'hours' => $this->xmlValue($job, 'hours', '*'),
                'days' => $this->xmlValue($job, 'days', '*'),
                'months' => $this->xmlValue($job, 'months', '*'),
                'weekdays' => $this->xmlValue($job, 'weekdays', '*'),
                'schedule' => $this->cronScheduleTextFromJob($job),
                'message' => $enabled && $correct ? 'Задание Cron создано корректно' : 'Задание Cron найдено, но требует исправления',
            ];
        }

        return [
            'exists' => false,
            'enabled' => false,
            'correct' => false,
            'message' => 'Задание Additional Scheduler не найдено',
        ];
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function setXmlChild($node, string $name, string $value): void
    {
        if (isset($node->{$name})) {
            $node->{$name} = $value;
        } else {
            $node->addChild($name, htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
        }
    }

    private function saveConfigXml(\SimpleXMLElement $xml): void
    {
        $backup = self::CONFIG_XML . '.additional-scheduler-' . date('Ymd-His') . '.bak';

        if (!copy(self::CONFIG_XML, $backup)) {
            throw new \RuntimeException('Не удалось создать backup config.xml');
        }

        $data = $xml->asXML();

        if ($data === false || $data === '') {
            throw new \RuntimeException('Не удалось сформировать XML конфигурации');
        }

        if (file_put_contents(self::CONFIG_XML, $data, LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать ' . self::CONFIG_XML);
        }

        chmod(self::CONFIG_XML, 0600);
    }

    private function reloadCronService(): array
    {
        $template = $this->runCommand('/usr/local/sbin/configctl template reload OPNsense/Cron');
        $restart = $this->runCommand('/usr/local/sbin/configctl cron restart');

        return [
            'template_reload' => $template,
            'cron_restart' => $restart,
        ];
    }

    private function createOrFixSchedulerCron(): array
    {
        if (!is_readable(self::CONFIG_XML) || !is_writable(self::CONFIG_XML)) {
            throw new \RuntimeException('Нет прав на чтение/запись ' . self::CONFIG_XML);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file(self::CONFIG_XML);

        if ($xml === false) {
            $errors = [];
            foreach (libxml_get_errors() as $error) {
                $errors[] = trim($error->message);
            }
            libxml_clear_errors();

            throw new \RuntimeException('Ошибка чтения config.xml: ' . implode('; ', $errors));
        }

        if (!isset($xml->OPNsense)) {
            $opnsense = $xml->addChild('OPNsense');
        } else {
            $opnsense = $xml->OPNsense;
        }

        if (!isset($opnsense->cron)) {
            $cron = $opnsense->addChild('cron');
            $cron->addAttribute('version', '1.0.4');
        } else {
            $cron = $opnsense->cron;
            if (!isset($cron['version'])) {
                $cron->addAttribute('version', '1.0.4');
            }
        }

        if (!isset($cron->jobs)) {
            $jobs = $cron->addChild('jobs');
        } else {
            $jobs = $cron->jobs;
        }

        $targetJob = null;
        foreach ($jobs->job as $job) {
            if ($this->cronJobMatches($job)) {
                $targetJob = $job;
                break;
            }
        }

        $created = false;

        if ($targetJob === null) {
            $targetJob = $jobs->addChild('job');
            $targetJob->addAttribute('uuid', $this->generateUuid());
            $created = true;
        }

        $this->setXmlChild($targetJob, 'origin', 'cron');
        $this->setXmlChild($targetJob, 'enabled', '1');
        $this->setXmlChild($targetJob, 'minutes', '*');
        $this->setXmlChild($targetJob, 'hours', '*');
        $this->setXmlChild($targetJob, 'days', '*');
        $this->setXmlChild($targetJob, 'months', '*');
        $this->setXmlChild($targetJob, 'weekdays', '*');
        $this->setXmlChild($targetJob, 'who', 'root');
        $this->setXmlChild($targetJob, 'command', self::CRON_COMMAND);
        $this->setXmlChild($targetJob, 'parameters', '');
        $this->setXmlChild($targetJob, 'description', self::CRON_DESCRIPTION);

        $this->saveConfigXml($xml);
        $reload = $this->reloadCronService();

        return [
            'created' => $created,
            'reload' => $reload,
            'cron' => $this->readSchedulerCronStatus(),
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
            'cron' => $this->readSchedulerCronStatus(),
        ];
    }


    public function cronstatusAction()
    {
        return [
            'status' => 'ok',
            'cron' => $this->readSchedulerCronStatus(),
        ];
    }

    public function createcronAction()
    {
        try {
            $result = $this->createOrFixSchedulerCron();

            return [
                'status' => 'ok',
                'message' => $result['created'] ? 'Задание Cron создано' : 'Задание Cron исправлено',
                'cron' => $result['cron'],
                'reload' => $result['reload'],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'cron' => $this->readSchedulerCronStatus(),
            ];
        }
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
                'cron' => $this->readSchedulerCronStatus(),
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
                'cron' => $this->readSchedulerCronStatus(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
