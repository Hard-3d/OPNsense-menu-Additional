<?php

namespace OPNsense\Additional\Api;

use OPNsense\Base\ApiControllerBase;

class WireguardpeersController extends ApiControllerBase
{
    private const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/wireguard_peers.json';
    private const MANAGER_FILE = '/usr/local/opnsense/scripts/additional/wireguard-peers-manager.php';

    private function bool01($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = strtolower(trim((string)$value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    }

    private function defaultConfig(): array
    {
        return [
            'version' => 1,
            'peers' => [],
        ];
    }

    private function normalizePeer(array $item): array
    {
        $peerId = trim((string)($item['peer_id'] ?? $item['id'] ?? ''));

        if ($peerId === '') {
            throw new \RuntimeException('Не найден peer_id');
        }

        $ip1 = trim((string)($item['ip1'] ?? ''));
        $ip2 = trim((string)($item['ip2'] ?? ''));

        foreach (['IP 1' => $ip1, 'IP 2' => $ip2] as $label => $ip) {
            if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new \RuntimeException($label . ' некорректный: ' . $ip);
            }
        }

        return [
            'peer_id' => $peerId,
            'enabled' => $this->bool01($item['enabled'] ?? '0'),
            'check_enabled' => $this->bool01($item['check_enabled'] ?? '0'),
            'ip1' => $ip1,
            'ip2' => $ip2,
            'active_ip' => trim((string)($item['active_ip'] ?? '')),
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

        if (!isset($config['peers']) || !is_array($config['peers'])) {
            $config['peers'] = [];
        }

        return $config;
    }

    private function saveConfig(array $payload): array
    {
        $config = $this->defaultConfig();
        $peers = $payload['peers'] ?? [];

        if (!is_array($peers)) {
            throw new \RuntimeException('Некорректный список peers');
        }

        foreach ($peers as $item) {
            if (!is_array($item)) {
                continue;
            }

            $peer = $this->normalizePeer($item);
            $config['peers'][$peer['peer_id']] = $peer;
        }

        $dir = dirname(self::CONFIG_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Не удалось сформировать wireguard_peers.json');
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

    public function getAction()
    {
        $data = $this->runManager('--status');
        $data['config'] = $this->loadConfig();

        return $data;
    }

    public function setAction()
    {
        try {
            $payload = $this->request->getJsonRawBody(true);

            if (!is_array($payload)) {
                $payload = [];
            }

            $config = $this->saveConfig($payload);
            $data = $this->runManager('--status');
            $data['config'] = $config;
            $data['message'] = 'Настройки WireGuard peers сохранены';

            return $data;
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    public function checkAction()
    {
        try {
            set_time_limit(0);
            return $this->runManager('--check');
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
