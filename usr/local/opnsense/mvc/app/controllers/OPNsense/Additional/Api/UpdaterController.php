<?php

namespace OPNsense\Additional\Api;

use OPNsense\Base\ApiControllerBase;

class UpdaterController extends ApiControllerBase
{
    private const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/update_manager.json';
    private const VERSION_FILE = '/usr/local/opnsense/scripts/additional/VERSION';
    private const STATUS_FILE = '/var/run/additional_updater_status.json';
    private const SCRIPT_FILE = '/usr/local/opnsense/scripts/additional/additional-updater.php';

    private function defaultConfig(): array
    {
        return [
            'repo_url' => 'https://github.com/Hard-3d/OPNsense-menu-Additional',
            'asset_name' => '',
            'auto_update' => '0',
        ];
    }

    private function currentVersion(): string
    {
        if (!is_readable(self::VERSION_FILE)) {
            return 'unknown';
        }

        $version = trim((string)file_get_contents(self::VERSION_FILE));
        return $version !== '' ? $version : 'unknown';
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
            throw new \RuntimeException('Не удалось записать настройки Update');
        }

        chmod(self::CONFIG_FILE, 0644);
    }

    private function readStatus(): array
    {
        if (!is_readable(self::STATUS_FILE)) {
            return [
                'status' => 'unknown',
                'message' => 'Проверка обновлений ещё не выполнялась',
                'timestamp' => '',
                'current_version' => $this->currentVersion(),
            ];
        }

        $raw = file_get_contents(self::STATUS_FILE);
        $data = json_decode((string)$raw, true);

        if (!is_array($data)) {
            return [
                'status' => 'unknown',
                'message' => 'Не удалось прочитать статус обновлений',
                'timestamp' => '',
                'current_version' => $this->currentVersion(),
            ];
        }

        $data['current_version'] = $this->currentVersion();

        if (
            !empty($data['latest_version']) &&
            !empty($data['current_version']) &&
            (string)$data['latest_version'] === (string)$data['current_version']
        ) {
            $data['update_available'] = false;

            if (($data['message'] ?? '') === 'Доступна новая версия') {
                $data['message'] = 'Установлена актуальная версия';
            }
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

    private function runUpdater(string $mode): array
    {
        if (!is_executable(self::SCRIPT_FILE)) {
            return [
                'status' => 'error',
                'message' => 'Скрипт обновления не найден или не исполняемый: ' . self::SCRIPT_FILE,
                'updater' => $this->readStatus(),
            ];
        }

        $command = sprintf(
            '%s --%s --json',
            escapeshellcmd(self::SCRIPT_FILE),
            $mode
        );

        $result = $this->runCommand($command);
        $output = trim($result['output']);
        $decoded = json_decode($output, true);

        if (!is_array($decoded)) {
            $jsonStart = strpos($output, '{');
            $jsonEnd = strrpos($output, '}');

            if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
                $decoded = json_decode(substr($output, $jsonStart, $jsonEnd - $jsonStart + 1), true);
            }
        }

        if (is_array($decoded)) {
            $decoded['exit_code'] = $result['exit_code'];
            return $decoded;
        }

        return [
            'status' => $result['exit_code'] === 0 ? 'ok' : 'error',
            'message' => $result['exit_code'] === 0 ? 'Команда выполнена' : 'Ошибка выполнения команды: ' . $output,
            'output' => $output,
            'exit_code' => $result['exit_code'],
        ];
    }

    private function normalizeRepoUrl(string $url): string
    {
        $url = trim($url);
        $url = preg_replace('#\.git$#', '', $url);
        return rtrim($url, '/');
    }

    public function getAction()
    {
        return [
            'status' => 'ok',
            'config' => $this->loadConfig(),
            'updater' => $this->readStatus(),
            'current_version' => $this->currentVersion(),
        ];
    }

    public function setAction()
    {
        try {
            $payload = $this->request->getJsonRawBody(true);

            if (!is_array($payload)) {
                $payload = $_POST;
            }

            $repoUrl = $this->normalizeRepoUrl((string)($payload['repo_url'] ?? ''));
            $assetName = trim((string)($payload['asset_name'] ?? ''));
            $autoUpdate = !empty($payload['auto_update']) ? '1' : '0';

            if ($repoUrl !== '' && !preg_match('#^https://github\.com/[^/]+/[^/]+$#i', $repoUrl)) {
                return [
                    'status' => 'error',
                    'message' => 'URL должен быть в формате https://github.com/OWNER/REPO'
                ];
            }

            $this->saveConfig([
                'repo_url' => $repoUrl,
                'asset_name' => $assetName,
                'auto_update' => $autoUpdate,
            ]);

            return [
                'status' => 'ok',
                'message' => 'Настройки Update сохранены',
                'config' => $this->loadConfig(),
                'updater' => $this->readStatus(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function checkAction()
    {
        set_time_limit(0);

        $result = $this->runUpdater('check');

        return [
            'status' => ($result['status'] ?? '') === 'ok' ? 'ok' : 'error',
            'message' => $result['message'] ?? 'Проверка обновлений выполнена',
            'updater' => $result,
            'config' => $this->loadConfig(),
            'current_version' => $this->currentVersion(),
        ];
    }

    public function updateAction()
    {
        set_time_limit(0);

        $result = $this->runUpdater('update');

        return [
            'status' => ($result['status'] ?? '') === 'ok' ? 'ok' : 'error',
            'message' => $result['message'] ?? 'Обновление выполнено',
            'updater' => $result,
            'config' => $this->loadConfig(),
            'current_version' => $this->currentVersion(),
        ];
    }

    public function statusAction()
    {
        return $this->getAction();
    }
}
