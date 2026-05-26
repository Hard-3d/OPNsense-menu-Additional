<?php

namespace OPNsense\Additional\Api;

use OPNsense\Base\ApiControllerBase;

class Udp2rawController extends ApiControllerBase
{
    private const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/udp2raw.json';
    private const MANAGER_FILE = '/usr/local/opnsense/scripts/additional/udp2raw-manager.php';
    private const BINARY_FILE = '/usr/local/opnsense/scripts/additional/bin/udp2raw_freebsd';
    private const CONFIG_XML = '/conf/config.xml';

    private function defaultInstance(): array
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
            'connection_logging' => '0',
            'log_level' => '3',
            'extra_args' => '',
        ];
    }

    private function defaultConfig(): array
    {
        return [
            'autostart' => '0',
            'watchdog' => '0',
            'log_rotate_size_kb' => '1024',
            'log_rotate_keep' => '5',
            'instances' => [$this->defaultInstance()],
        ];
    }

    private function bool01($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = strtolower(trim((string)$value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    }

    private function positiveIntString($value, int $default, int $min, int $max): string
    {
        $value = trim((string)$value);

        if (!ctype_digit($value)) {
            return (string)$default;
        }

        $intValue = (int)$value;

        if ($intValue < $min || $intValue > $max) {
            return (string)$default;
        }

        return (string)$intValue;
    }

    private function safeId(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.-]+/', '_', $value);
        $value = trim((string)$value, '_');
        return $value !== '' ? $value : 'default';
    }

    private function normalizeInstance(array $item, int $index = 0): array
    {
        $item = array_merge($this->defaultInstance(), $item);

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

        $id = $this->safeId((string)($item['id'] ?? ''));
        if ($id === 'default' && $index > 0) {
            $id = 'instance_' . ($index + 1);
        }

        $name = trim((string)$item['name']);
        if ($name === '') {
            $name = $id;
        }

        return [
            'id' => $id,
            'enabled' => $this->bool01($item['enabled'] ?? '0'),
            'name' => $name,
            'mode' => $mode,
            'listen' => trim((string)$item['listen']),
            'remote' => trim((string)$item['remote']),
            'key' => (string)$item['key'],
            'raw_mode' => $rawMode,
            'dev' => trim((string)$item['dev']),
            'connection_logging' => $this->bool01($item['connection_logging'] ?? '0'),
            'log_level' => $logLevel,
            'extra_args' => trim((string)$item['extra_args']),
        ];
    }

    private function loadConfig(): array
    {
        $config = $this->defaultConfig();

        if (is_readable(self::CONFIG_FILE)) {
            $raw = file_get_contents(self::CONFIG_FILE);
            $data = json_decode((string)$raw, true);

            if (is_array($data)) {
                $config = array_merge($config, $data);
            }
        }

        $instances = [];
        if (isset($config['instances']) && is_array($config['instances'])) {
            foreach (array_values($config['instances']) as $index => $instance) {
                if (is_array($instance)) {
                    if (!array_key_exists('connection_logging', $instance) && array_key_exists('connection_logging', $config)) {
                        $instance['connection_logging'] = $config['connection_logging'];
                    }

                    $instances[] = $this->normalizeInstance($instance, $index);
                }
            }
        }

        if (empty($instances)) {
            $instances[] = $this->defaultInstance();
        }

        return [
            'autostart' => $this->bool01($config['autostart'] ?? '0'),
            'watchdog' => $this->bool01($config['watchdog'] ?? '0'),
            'log_rotate_size_kb' => $this->positiveIntString($config['log_rotate_size_kb'] ?? '1024', 1024, 64, 1048576),
            'log_rotate_keep' => $this->positiveIntString($config['log_rotate_keep'] ?? '5', 5, 1, 50),
            'instances' => $instances,
        ];
    }

    private function saveConfig(array $payload): array
    {
        $config = [
            'autostart' => $this->bool01($payload['autostart'] ?? '0'),
            'watchdog' => $this->bool01($payload['watchdog'] ?? '0'),
            'log_rotate_size_kb' => $this->positiveIntString($payload['log_rotate_size_kb'] ?? '1024', 1024, 64, 1048576),
            'log_rotate_keep' => $this->positiveIntString($payload['log_rotate_keep'] ?? '5', 5, 1, 50),
            'instances' => [],
        ];

        if (isset($payload['instances']) && is_array($payload['instances'])) {
            foreach (array_values($payload['instances']) as $index => $instance) {
                if (is_array($instance)) {
                    $config['instances'][] = $this->normalizeInstance($instance, $index);
                }
            }
        }

        if (empty($config['instances'])) {
            $config['instances'][] = $this->defaultInstance();
        }

        $dir = dirname(self::CONFIG_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Не удалось сформировать udp2raw.json');
        }

        if (file_put_contents(self::CONFIG_FILE, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать ' . self::CONFIG_FILE);
        }

        chmod(self::CONFIG_FILE, 0600);

        return $config;
    }

    private function runManager(string $action): array
    {
        if (!is_executable(self::MANAGER_FILE)) {
            return [
                'status' => 'error',
                'message' => 'Manager script не найден или не исполняемый: ' . self::MANAGER_FILE,
            ];
        }

        $command = escapeshellcmd(self::MANAGER_FILE) . ' ' . $action . ' --json';
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);
        $raw = trim(implode("\n", $output));
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return [
                'status' => $exitCode === 0 ? 'ok' : 'error',
                'message' => $exitCode === 0 ? 'Команда выполнена' : 'Ошибка выполнения команды',
                'output' => $raw,
                'exit_code' => $exitCode,
            ];
        }

        $data['exit_code'] = $exitCode;
        return $data;
    }



    private function runBinaryVersionCommand(string $argument): array
    {
        if (!is_executable(self::BINARY_FILE)) {
            return [
                'exit_code' => 127,
                'output' => '',
            ];
        }

        $output = [];
        $exitCode = 0;
        $command = escapeshellarg(self::BINARY_FILE) . ' ' . $argument;
        exec($command . ' 2>&1', $output, $exitCode);

        return [
            'exit_code' => $exitCode,
            'output' => trim(implode("\n", $output)),
        ];
    }

    private function getBinaryInfo(): array
    {
        $info = [
            'path' => self::BINARY_FILE,
            'exists' => file_exists(self::BINARY_FILE),
            'executable' => is_executable(self::BINARY_FILE),
            'version' => '',
            'version_full' => '',
            'version_json' => null,
            'version_error' => '',
        ];

        if (!$info['executable']) {
            return $info;
        }

        $version = $this->runBinaryVersionCommand('--version');
        if ($version['exit_code'] === 0) {
            $info['version'] = $version['output'];
        } else {
            $info['version_error'] = $version['output'];
        }

        $versionFull = $this->runBinaryVersionCommand('--version-full');
        if ($versionFull['exit_code'] === 0) {
            $info['version_full'] = $versionFull['output'];
        }

        $versionJson = $this->runBinaryVersionCommand('--version-json');
        if ($versionJson['exit_code'] === 0 && $versionJson['output'] !== '') {
            $decoded = json_decode($versionJson['output'], true);

            if (is_array($decoded)) {
                $info['version_json'] = $decoded;
            }
        }

        return $info;
    }


    private function getInterfaces(): array
    {
        $result = [];
        $seen = [];

        if (is_readable(self::CONFIG_XML)) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file(self::CONFIG_XML);

            if ($xml !== false && isset($xml->interfaces)) {
                foreach ($xml->interfaces->children() as $name => $interface) {
                    $if = trim((string)($interface->if ?? ''));

                    if ($if === '' || isset($seen[$if])) {
                        continue;
                    }

                    $descr = trim((string)($interface->descr ?? ''));
                    $label = $descr !== '' ? $descr . ' / ' . $if : $if;

                    $result[] = [
                        'value' => $if,
                        'label' => $label,
                        'name' => (string)$name,
                        'descr' => $descr,
                    ];
                    $seen[$if] = true;
                }
            }

            libxml_clear_errors();
        }

        $output = [];
        $exitCode = 0;
        exec('/sbin/ifconfig -l 2>/dev/null', $output, $exitCode);

        if ($exitCode === 0 && !empty($output)) {
            $names = preg_split('/\s+/', trim(implode(' ', $output)));

            if (is_array($names)) {
                foreach ($names as $if) {
                    $if = trim((string)$if);

                    if ($if === '' || $if === 'lo0' || isset($seen[$if])) {
                        continue;
                    }

                    $result[] = [
                        'value' => $if,
                        'label' => $if,
                        'name' => '',
                        'descr' => '',
                    ];
                    $seen[$if] = true;
                }
            }
        }

        usort($result, function ($a, $b) {
            return strnatcasecmp((string)$a['label'], (string)$b['label']);
        });

        return $result;
    }

    public function getAction()
    {
        return [
            'status' => 'ok',
            'config' => $this->loadConfig(),
            'runtime' => $this->runManager('--status'),
            'binary' => $this->getBinaryInfo(),
            'interfaces' => $this->getInterfaces(),
        ];
    }

    public function setAction()
    {
        try {
            $payload = $this->request->getJsonRawBody(true);
            if (!is_array($payload)) {
                $payload = $_POST;
            }

            $config = $this->saveConfig($payload);

            return [
                'status' => 'ok',
                'message' => 'Настройки udp2raw сохранены',
                'config' => $config,
                'runtime' => $this->runManager('--status'),
                'binary' => $this->getBinaryInfo(),
                'interfaces' => $this->getInterfaces(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function startAction()
    {
        return $this->actionResponse('--start-all', 'udp2raw запущен');
    }

    public function stopAction()
    {
        return $this->actionResponse('--stop-all', 'udp2raw остановлен');
    }

    public function restartAction()
    {
        return $this->actionResponse('--restart-all', 'udp2raw перезапущен');
    }

    public function statusAction()
    {
        return $this->getAction();
    }

    private function actionResponse(string $managerAction, string $okMessage): array
    {
        try {
            /*
             * Start/restart should use values currently visible on the page.
             * Otherwise user can change Dev/Remote/Key in UI and press Start
             * without pressing "Save instances", while manager still uses old
             * udp2raw.json.
             */
            if ($managerAction === '--start-all' || $managerAction === '--restart-all') {
                $payload = $this->request->getJsonRawBody(true);

                if (is_array($payload) && isset($payload['instances']) && is_array($payload['instances'])) {
                    $this->saveConfig($payload);
                }
            }

            $runtime = $this->runManager($managerAction);
            $status = ($runtime['status'] ?? '') === 'error' ? 'error' : 'ok';

            return [
                'status' => $status,
                'message' => $status === 'ok' ? $okMessage : ($runtime['message'] ?? 'Ошибка udp2raw'),
                'config' => $this->loadConfig(),
                'runtime' => $runtime,
                'binary' => $this->getBinaryInfo(),
                'interfaces' => $this->getInterfaces(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
