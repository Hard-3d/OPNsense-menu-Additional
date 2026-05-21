<?php

namespace OPNsense\Additional\Api;

use OPNsense\Base\ApiControllerBase;

class CheckstatusController extends ApiControllerBase
{
    private const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/check_status.json';

    private const WG_STATUS_FILE = '/var/run/additional_check_status_wireguard.json';
    private const TS_STATUS_FILE = '/var/run/additional_check_status_tailscale.json';

    private const WG_SCRIPT = '/usr/local/opnsense/scripts/additional/check-wg-status.php';
    private const TS_SCRIPT = '/usr/local/opnsense/scripts/additional/check-tailscale-status.php';

    private function ensureConfigDir(): void
    {
        $dir = dirname(self::CONFIG_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function defaultConfig(): array
    {
        return [
            'wireguard_check_ping' => '',
            'tailscale_check_ping' => '100.100.100.100'
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
        $this->ensureConfigDir();

        $data = array_merge($this->defaultConfig(), $data);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Не удалось сформировать JSON настроек');
        }

        if (file_put_contents(self::CONFIG_FILE, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать настройки Check status');
        }

        chmod(self::CONFIG_FILE, 0644);
    }

    private function normalizeIpList(string $value): string
    {
        $items = preg_split('/[,\s]+/', trim($value));
        $result = [];

        if ($items === false) {
            return '';
        }

        foreach ($items as $ip) {
            $ip = trim($ip);
            if ($ip === '') {
                continue;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new \RuntimeException('Некорректный IP-адрес: ' . $ip);
            }
            $result[] = $ip;
        }

        $result = array_values(array_unique($result));
        return implode(', ', $result);
    }

    private function normalizeSingleIp(string $value, string $default = ''): string
    {
        $value = trim($value);

        if ($value === '') {
            $value = $default;
        }

        if (!filter_var($value, FILTER_VALIDATE_IP)) {
            throw new \RuntimeException('Некорректный IP-адрес: ' . $value);
        }

        return $value;
    }

    private function emptyWireguardStatus(): array
    {
        return [
            'ok' => null,
            'state' => 'unknown',
            'message' => 'Проверка ещё не выполнялась',
            'timestamp' => '',
            'watch_networks' => [],
            'watch_hosts' => [],
            'missing_networks' => [],
            'unreachable_hosts' => []
        ];
    }

    private function emptyTailscaleStatus(): array
    {
        return [
            'ok' => null,
            'state' => 'unknown',
            'message' => 'Проверка ещё не выполнялась',
            'timestamp' => '',
            'check_ip' => '',
            'service_action' => '',
            'service_status' => ''
        ];
    }

    private function readStatus(string $file, array $empty): array
    {
        if (!is_readable($file)) {
            return $empty;
        }

        $raw = file_get_contents($file);
        $data = json_decode((string)$raw, true);

        if (!is_array($data)) {
            $empty['message'] = 'Файл статуса повреждён';
            return $empty;
        }

        return array_merge($empty, $data);
    }

    private function runCommand(string $command): array
    {
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        return [
            'exit_code' => $exitCode,
            'output' => trim(implode("\n", $output))
        ];
    }

    public function getAction()
    {
        return [
            'status' => 'ok',
            'config' => $this->loadConfig(),
            'wireguard' => $this->readStatus(self::WG_STATUS_FILE, $this->emptyWireguardStatus()),
            'tailscale' => $this->readStatus(self::TS_STATUS_FILE, $this->emptyTailscaleStatus())
        ];
    }

    public function setwireguardAction()
    {
        try {
            $payload = $this->request->getJsonRawBody(true);
            $ip = '';

            if (is_array($payload) && isset($payload['wireguard_check_ping'])) {
                $ip = (string)$payload['wireguard_check_ping'];
            } elseif ($this->request->hasPost('wireguard_check_ping')) {
                $ip = (string)$this->request->getPost('wireguard_check_ping');
            }

            $ip = $this->normalizeIpList($ip);
            $config = $this->loadConfig();
            $config['wireguard_check_ping'] = $ip;

            $this->saveConfig($config);

            return [
                'status' => 'ok',
                'message' => 'Настройки WireGuard сохранены',
                'config' => $config
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function settailscaleAction()
    {
        try {
            $payload = $this->request->getJsonRawBody(true);
            $ip = '';

            if (is_array($payload) && isset($payload['tailscale_check_ping'])) {
                $ip = (string)$payload['tailscale_check_ping'];
            } elseif ($this->request->hasPost('tailscale_check_ping')) {
                $ip = (string)$this->request->getPost('tailscale_check_ping');
            }

            $ip = $this->normalizeSingleIp($ip, '100.100.100.100');
            $config = $this->loadConfig();
            $config['tailscale_check_ping'] = $ip;

            $this->saveConfig($config);

            return [
                'status' => 'ok',
                'message' => 'Настройки Tailscale сохранены',
                'config' => $config
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function runwireguardAction()
    {
        try {
            if (!is_executable(self::WG_SCRIPT)) {
                return [
                    'status' => 'error',
                    'message' => 'Скрипт проверки WireGuard не найден или не исполняемый: ' . self::WG_SCRIPT
                ];
            }

            set_time_limit(0);

            $result = $this->runCommand(escapeshellcmd(self::WG_SCRIPT));
            $status = $this->readStatus(self::WG_STATUS_FILE, $this->emptyWireguardStatus());

            return [
                'status' => $result['exit_code'] === 0 ? 'ok' : 'warning',
                'message' => $result['exit_code'] === 0 ? 'Проверка WireGuard выполнена' : 'Проверка WireGuard выполнена с предупреждением',
                'exit_code' => $result['exit_code'],
                'output' => $result['output'],
                'wireguard' => $status
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function runtailscaleAction()
    {
        try {
            if (!is_executable(self::TS_SCRIPT)) {
                return [
                    'status' => 'error',
                    'message' => 'Скрипт проверки Tailscale не найден или не исполняемый: ' . self::TS_SCRIPT
                ];
            }

            set_time_limit(0);

            $result = $this->runCommand(escapeshellcmd(self::TS_SCRIPT));
            $status = $this->readStatus(self::TS_STATUS_FILE, $this->emptyTailscaleStatus());

            return [
                'status' => $result['exit_code'] === 0 ? 'ok' : 'warning',
                'message' => $result['exit_code'] === 0 ? 'Проверка Tailscale выполнена' : 'Проверка Tailscale выполнена с предупреждением',
                'exit_code' => $result['exit_code'],
                'output' => $result['output'],
                'tailscale' => $status
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
