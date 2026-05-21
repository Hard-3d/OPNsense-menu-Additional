<?php

namespace OPNsense\Additional\Api;

use OPNsense\Base\ApiControllerBase;

class CheckwanController extends ApiControllerBase
{
    private const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/check_wan.json';
    private const STATUS_FILE = '/var/run/additional_check_wan_status.json';
    private const SCRIPT_FILE = '/usr/local/opnsense/scripts/additional/check-wan-gateway-loss.php';
    private const OPN_CONFIG_FILE = '/conf/config.xml';

    private function defaultConfig(): array
    {
        return [
            'enabled' => '0',
            'gw_a_name' => '',
            'gw_b_name' => '',
            'primary_priority' => '11',
            'backup_priority' => '12',
            'loss_limit' => '30',
            'force_defaultgw_zero' => '1',
        ];
    }

    private function loadConfig(): array
    {
        $defaults = $this->defaultConfig();

        if (!is_readable(self::CONFIG_FILE)) {
            return $defaults;
        }

        $raw = file_get_contents(self::CONFIG_FILE);
        $data = json_decode((string)$raw, true);

        if (!is_array($data)) {
            return $defaults;
        }

        return array_merge($defaults, $data);
    }

    private function saveConfig(array $data): void
    {
        $dir = dirname(self::CONFIG_FILE);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $config = array_merge($this->defaultConfig(), $data);

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Не удалось сформировать JSON настроек');
        }

        if (file_put_contents(self::CONFIG_FILE, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать настройки Check WAN');
        }

        chmod(self::CONFIG_FILE, 0644);
    }

    private function readStatus(): array
    {
        if (!is_readable(self::STATUS_FILE)) {
            return [
                'state' => 'unknown',
                'ok' => null,
                'message' => 'Проверка ещё не выполнялась',
                'timestamp' => '',
            ];
        }

        $raw = file_get_contents(self::STATUS_FILE);
        $data = json_decode((string)$raw, true);

        if (!is_array($data)) {
            return [
                'state' => 'unknown',
                'ok' => null,
                'message' => 'Не удалось прочитать файл статуса',
                'timestamp' => '',
            ];
        }

        return $data;
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

    private function boolTo01($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = strtolower((string)$value);

        if ($value === 'true') {
            return '1';
        }

        if ($value === 'false' || $value === '') {
            return '0';
        }

        return $value === '1' ? '1' : '0';
    }

    private function parseLoss($rawLoss): float
    {
        $raw = trim((string)$rawLoss);

        if (preg_match('/[-+]?\d+(?:[,.]\d+)?/', $raw, $matches)) {
            return (float)str_replace(',', '.', $matches[0]);
        }

        return 100.0;
    }

    private function getGatewayStatusMap(): array
    {
        $map = [];

        $result = $this->runCommand('/usr/local/sbin/configctl interface gateways status');

        if ($result['exit_code'] !== 0 || $result['output'] === '') {
            return $map;
        }

        $decoded = json_decode($result['output'], true);

        if (!is_array($decoded)) {
            return $map;
        }

        foreach ($decoded as $row) {
            if (is_array($row) && isset($row['name'])) {
                $map[(string)$row['name']] = $row;
            }
        }

        return $map;
    }

    private function xmlValue($node, string $name, string $default = ''): string
    {
        if (!isset($node->{$name})) {
            return $default;
        }

        return trim((string)$node->{$name});
    }

    private function getGatewayItemsFromXml(): array
    {
        if (!is_readable(self::OPN_CONFIG_FILE)) {
            throw new \RuntimeException('Не удалось прочитать ' . self::OPN_CONFIG_FILE);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file(self::OPN_CONFIG_FILE);

        if ($xml === false) {
            $errors = [];
            foreach (libxml_get_errors() as $error) {
                $errors[] = trim($error->message);
            }
            libxml_clear_errors();

            throw new \RuntimeException('Ошибка чтения config.xml: ' . implode('; ', $errors));
        }

        if (!isset($xml->OPNsense->Gateways->gateway_item)) {
            return [];
        }

        $items = [];

        foreach ($xml->OPNsense->Gateways->gateway_item as $item) {
            $items[] = [
                'name' => $this->xmlValue($item, 'name'),
                'interface' => $this->xmlValue($item, 'interface'),
                'gateway' => $this->xmlValue($item, 'gateway'),
                'priority' => $this->xmlValue($item, 'priority', '255'),
                'defaultgw' => $this->xmlValue($item, 'defaultgw', '0'),
            ];
        }

        return $items;
    }

    private function getWanGateways(): array
    {
        $items = $this->getGatewayItemsFromXml();
        $statusMap = $this->getGatewayStatusMap();
        $gateways = [];

        foreach ($items as $item) {
            $name = (string)($item['name'] ?? '');

            if ($name === '' || stripos($name, 'WAN') !== 0) {
                continue;
            }

            $status = $statusMap[$name] ?? [];
            $lossRaw = (string)($status['loss'] ?? '~');

            $gateways[] = [
                'name' => $name,
                'interface' => (string)($item['interface'] ?? ''),
                'gateway' => (string)($item['gateway'] ?? ''),
                'priority' => (string)($item['priority'] ?? '255'),
                'defaultgw_config' => $this->boolTo01($item['defaultgw'] ?? '0'),
                'status' => (string)($status['status_translated'] ?? ($status['status'] ?? 'Pending')),
                'loss' => $lossRaw,
                'loss_value' => $this->parseLoss($lossRaw),
            ];
        }

        usort($gateways, function ($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });

        return $gateways;
    }

    private function normalizePayloadConfig(array $payload): array
    {
        $enabled = !empty($payload['enabled']) ? '1' : '0';

        $gwA = trim((string)($payload['gw_a_name'] ?? ''));
        $gwB = trim((string)($payload['gw_b_name'] ?? ''));

        $primary = trim((string)($payload['primary_priority'] ?? '11'));
        $backup = trim((string)($payload['backup_priority'] ?? '12'));
        $loss = trim((string)($payload['loss_limit'] ?? '30'));

        if (!ctype_digit($primary)) {
            $primary = '11';
        }

        if (!ctype_digit($backup)) {
            $backup = '12';
        }

        if (!is_numeric(str_replace(',', '.', $loss))) {
            $loss = '30';
        }

        return [
            'enabled' => $enabled,
            'gw_a_name' => $gwA,
            'gw_b_name' => $gwB,
            'primary_priority' => $primary,
            'backup_priority' => $backup,
            'loss_limit' => str_replace(',', '.', $loss),
            'force_defaultgw_zero' => '1',
        ];
    }

    public function getAction()
    {
        try {
            return [
                'status' => 'ok',
                'config' => $this->loadConfig(),
                'gateways' => $this->getWanGateways(),
                'checkwan' => $this->readStatus(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'config' => $this->loadConfig(),
                'gateways' => [],
                'checkwan' => $this->readStatus(),
            ];
        }
    }

    public function gatewaysAction()
    {
        try {
            return [
                'status' => 'ok',
                'gateways' => $this->getWanGateways(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'gateways' => [],
            ];
        }
    }

    public function setAction()
    {
        try {
            $payload = $this->request->getJsonRawBody(true);

            if (!is_array($payload)) {
                $payload = $_POST;
            }

            $config = $this->normalizePayloadConfig($payload);

            if ($config['enabled'] === '1') {
                if ($config['gw_a_name'] === '' || $config['gw_b_name'] === '') {
                    return [
                        'status' => 'error',
                        'message' => 'Выберите оба WAN gateway'
                    ];
                }

                if ($config['gw_a_name'] === $config['gw_b_name']) {
                    return [
                        'status' => 'error',
                        'message' => 'Gateway A и Gateway B не должны совпадать'
                    ];
                }
            }

            $this->saveConfig($config);

            return [
                'status' => 'ok',
                'message' => 'Настройки Check WAN сохранены',
                'config' => $config,
                'gateways' => $this->getWanGateways(),
                'checkwan' => $this->readStatus(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function runAction()
    {
        try {
            if (!is_executable(self::SCRIPT_FILE)) {
                return [
                    'status' => 'error',
                    'message' => 'Скрипт не найден или не исполняемый: ' . self::SCRIPT_FILE,
                    'checkwan' => $this->readStatus(),
                ];
            }

            $result = $this->runCommand(escapeshellcmd(self::SCRIPT_FILE));

            $status = $this->readStatus();

            if ($result['exit_code'] !== 0) {
                return [
                    'status' => 'error',
                    'message' => $status['message'] ?? 'Ошибка проверки Check WAN',
                    'output' => $result['output'],
                    'exit_code' => $result['exit_code'],
                    'checkwan' => $status,
                    'gateways' => $this->getWanGateways(),
                ];
            }

            return [
                'status' => 'ok',
                'message' => $status['message'] ?? 'Проверка Check WAN выполнена',
                'output' => $result['output'],
                'checkwan' => $status,
                'gateways' => $this->getWanGateways(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'checkwan' => $this->readStatus(),
            ];
        }
    }


    public function switchpriorityAction()
    {
        try {
            if (!is_executable(self::SCRIPT_FILE)) {
                return [
                    'status' => 'error',
                    'message' => 'Скрипт не найден или не исполняемый: ' . self::SCRIPT_FILE,
                    'checkwan' => $this->readStatus(),
                ];
            }

            $config = $this->loadConfig();

            if (($config['enabled'] ?? '0') !== '1') {
                return [
                    'status' => 'error',
                    'message' => 'Check WAN отключён. Включите переключатель и сохраните настройки.',
                    'checkwan' => $this->readStatus(),
                ];
            }

            if (empty($config['gw_a_name']) || empty($config['gw_b_name'])) {
                return [
                    'status' => 'error',
                    'message' => 'Выберите оба WAN gateway и сохраните настройки.',
                    'checkwan' => $this->readStatus(),
                ];
            }

            $result = $this->runCommand(escapeshellcmd(self::SCRIPT_FILE) . ' --force-switch');

            $status = $this->readStatus();

            if ($result['exit_code'] !== 0) {
                return [
                    'status' => 'error',
                    'message' => $status['message'] ?? 'Ошибка принудительной смены приоритета WAN',
                    'output' => $result['output'],
                    'exit_code' => $result['exit_code'],
                    'checkwan' => $status,
                    'gateways' => $this->getWanGateways(),
                ];
            }

            return [
                'status' => 'ok',
                'message' => 'Приоритеты WAN gateway изменены',
                'output' => $result['output'],
                'checkwan' => $status,
                'gateways' => $this->getWanGateways(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'checkwan' => $this->readStatus(),
            ];
        }
    }

    public function statusAction()
    {
        return $this->getAction();
    }
}
