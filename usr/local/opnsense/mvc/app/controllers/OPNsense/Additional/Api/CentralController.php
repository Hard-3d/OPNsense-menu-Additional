<?php

namespace OPNsense\Additional\Api;

use OPNsense\Base\ApiControllerBase;

class CentralController extends ApiControllerBase
{
    private const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/controller_agent.json';
    private const STATUS_FILE = '/var/run/additional_controller_agent_status.json';
    private const SCRIPT_FILE = '/usr/local/opnsense/scripts/additional/controller-agent.php';

    private function defaultConfig(): array
    {
        return [
            'version' => 1,
            'enabled' => '0',
            'server_url' => '',
            'device_uuid' => '',
            'device_secret' => '',
            'verify_tls' => '1',
            'poll_jobs' => '1',
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

    private function normalizeUrl(string $url, bool $allowEmpty = true): string
    {
        $url = trim($url);
        if ($url === '') {
            if ($allowEmpty) {
                return '';
            }
            throw new \RuntimeException('Server URL is required');
        }
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Server URL must start with http:// or https://');
        }
        return rtrim($url, '/');
    }

    private function loadConfigRaw(): array
    {
        $config = $this->defaultConfig();
        if (is_readable(self::CONFIG_FILE)) {
            $raw = file_get_contents(self::CONFIG_FILE);
            $data = json_decode((string)$raw, true);
            if (is_array($data)) {
                $config = array_replace($config, $data);
            }
        }
        $config['enabled'] = $this->bool01($config['enabled'] ?? '0');
        $config['verify_tls'] = $this->bool01($config['verify_tls'] ?? '1');
        $config['poll_jobs'] = $this->bool01($config['poll_jobs'] ?? '1');
        return $config;
    }

    private function saveConfig(array $payload): array
    {
        $current = $this->loadConfigRaw();
        $config = array_replace($current, [
            'version' => 1,
            'enabled' => $this->bool01($payload['enabled'] ?? $current['enabled'] ?? '0'),
            'server_url' => $this->normalizeUrl((string)($payload['server_url'] ?? $current['server_url'] ?? ''), true),
            'device_uuid' => trim((string)($payload['device_uuid'] ?? $current['device_uuid'] ?? '')),
            'verify_tls' => $this->bool01($payload['verify_tls'] ?? $current['verify_tls'] ?? '1'),
            'poll_jobs' => $this->bool01($payload['poll_jobs'] ?? $current['poll_jobs'] ?? '1'),
        ]);

        if (isset($payload['device_secret'])) {
            $config['device_secret'] = trim((string)$payload['device_secret']);
        }

        $dir = dirname(self::CONFIG_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Cannot encode controller config');
        }
        if (file_put_contents(self::CONFIG_FILE, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write ' . self::CONFIG_FILE);
        }
        chmod(self::CONFIG_FILE, 0600);
        return $config;
    }

    private function publicConfig(array $config): array
    {
        $secret = (string)($config['device_secret'] ?? '');
        $config['registered'] = $secret !== '' ? '1' : '0';
        $config['device_secret_masked'] = $secret !== '' ? substr($secret, 0, 6) . '...' . substr($secret, -6) : '';
        unset($config['device_secret']);
        return $config;
    }

    private function readStatus(): array
    {
        if (!is_readable(self::STATUS_FILE)) {
            return [
                'ok' => null,
                'last_action' => '',
                'timestamp' => '',
                'message' => 'No status yet',
            ];
        }
        $raw = file_get_contents(self::STATUS_FILE);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [
            'ok' => false,
            'last_action' => '',
            'timestamp' => '',
            'message' => 'Status file is invalid',
        ];
    }

    private function parseJson(string $output): array
    {
        $data = json_decode(trim($output), true);
        if (is_array($data)) {
            return $data;
        }
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $data = json_decode(substr($output, $start, $end - $start + 1), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }

    private function runScript(array $args): array
    {
        if (!is_executable(self::SCRIPT_FILE)) {
            return [
                'status' => 'error',
                'message' => 'Script not found or not executable: ' . self::SCRIPT_FILE,
            ];
        }
        $cmd = escapeshellcmd(self::SCRIPT_FILE);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg((string)$arg);
        }
        $output = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);
        $raw = trim(implode("\n", $output));
        $data = $this->parseJson($raw);
        if (!is_array($data) || empty($data)) {
            $data = [
                'status' => $exitCode === 0 ? 'ok' : 'error',
                'message' => $exitCode === 0 ? 'Command completed' : 'Command failed',
                'output' => $raw,
            ];
        }
        $data['exit_code'] = $exitCode;
        return $data;
    }

    public function getAction()
    {
        return [
            'status' => 'ok',
            'config' => $this->publicConfig($this->loadConfigRaw()),
            'agent_status' => $this->readStatus(),
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
                'message' => 'Settings saved',
                'config' => $this->publicConfig($config),
                'agent_status' => $this->readStatus(),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function registerAction()
    {
        try {
            $payload = $this->request->getJsonRawBody(true);
            if (!is_array($payload)) {
                $payload = $_POST;
            }
            $serverUrl = $this->normalizeUrl((string)($payload['server_url'] ?? ''), false);
            $uuid = trim((string)($payload['device_uuid'] ?? ''));
            $token = trim((string)($payload['registration_token'] ?? ''));
            $verifyTls = $this->bool01($payload['verify_tls'] ?? '1');
            if ($uuid === '' || $token === '') {
                throw new \RuntimeException('Device UUID and registration token are required');
            }
            $result = $this->runScript([
                'register', '--json', '--server', $serverUrl, '--uuid', $uuid, '--token', $token, '--verify-tls', $verifyTls,
            ]);
            return array_merge($this->getAction(), $result);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage(), 'agent_status' => $this->readStatus()];
        }
    }

    public function pingAction()
    {
        $result = $this->runScript(['ping', '--json']);
        return array_merge($result, ['config' => $this->publicConfig($this->loadConfigRaw()), 'agent_status' => $this->readStatus()]);
    }

    public function heartbeatAction()
    {
        $result = $this->runScript(['heartbeat', '--json']);
        return array_merge($result, ['config' => $this->publicConfig($this->loadConfigRaw()), 'agent_status' => $this->readStatus()]);
    }

    public function backupAction()
    {
        $result = $this->runScript(['backup', '--json']);
        return array_merge($result, ['config' => $this->publicConfig($this->loadConfigRaw()), 'agent_status' => $this->readStatus()]);
    }

    public function pollAction()
    {
        $result = $this->runScript(['poll', '--json']);
        return array_merge($result, ['config' => $this->publicConfig($this->loadConfigRaw()), 'agent_status' => $this->readStatus()]);
    }

    public function runonceAction()
    {
        $result = $this->runScript(['run-once', '--json']);
        return array_merge($result, ['config' => $this->publicConfig($this->loadConfigRaw()), 'agent_status' => $this->readStatus()]);
    }

    public function clearAction()
    {
        try {
            $config = $this->loadConfigRaw();
            $config['device_secret'] = '';
            $config['enabled'] = '0';
            $this->saveConfig($config);
            return [
                'status' => 'ok',
                'message' => 'Registration data cleared',
                'config' => $this->publicConfig($this->loadConfigRaw()),
                'agent_status' => $this->readStatus(),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
