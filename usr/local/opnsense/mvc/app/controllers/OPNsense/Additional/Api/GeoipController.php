<?php

namespace OPNsense\Additional\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;

class GeoipController extends ApiControllerBase
{
    private const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/geoip_update.json';

    private const DEFAULT_BASE_URL = 'https://github.com/mamamialezatoz/geoip-database/releases/latest/download/';

    private const UPDATE_SCRIPT = '/usr/local/opnsense/scripts/additional/updategeoip.php';

    private const STATS_FILE = '/usr/local/share/GeoIP/alias.stats';

    private function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            $url = self::DEFAULT_BASE_URL;
        }

        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('URL должен начинаться с http:// или https://');
        }

        return rtrim($url, '/') . '/';
    }

    private function ensureConfigDir(): void
    {
        $dir = dirname(self::CONFIG_FILE);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function loadConfig(): array
    {
        if (!is_readable(self::CONFIG_FILE)) {
            return [
                'base_url' => self::DEFAULT_BASE_URL
            ];
        }

        $raw = file_get_contents(self::CONFIG_FILE);
        $data = json_decode((string)$raw, true);

        if (!is_array($data)) {
            return [
                'base_url' => self::DEFAULT_BASE_URL
            ];
        }

        if (empty($data['base_url'])) {
            $data['base_url'] = self::DEFAULT_BASE_URL;
        }

        return $data;
    }

    private function saveConfig(array $data): void
    {
        $this->ensureConfigDir();

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new \RuntimeException('Не удалось сформировать JSON настроек');
        }

        if (file_put_contents(self::CONFIG_FILE, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать настройки GeoIP');
        }

        chmod(self::CONFIG_FILE, 0644);
    }

    private function emptyStats(): array
    {
        return [
            'address_count' => 0,
            'timestamp' => '',
            'file_count' => 0,
            'locations_filename' => '',
            'address_sources' => [],
            'source_base_url' => '',
        ];
    }

    private function normalizeStats(array $data): array
    {
        return [
            'address_count' => (int)($data['address_count'] ?? 0),
            'timestamp' => (string)($data['timestamp'] ?? ''),
            'file_count' => (int)($data['file_count'] ?? 0),
            'locations_filename' => (string)($data['locations_filename'] ?? ''),
            'address_sources' => $data['address_sources'] ?? [],
            'source_base_url' => (string)($data['source_base_url'] ?? ''),
        ];
    }

    private function findStatsRecursive($value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        if (array_key_exists('address_count', $value) || array_key_exists('timestamp', $value) || array_key_exists('file_count', $value)) {
            return $this->normalizeStats($value);
        }

        foreach ($value as $child) {
            $found = $this->findStatsRecursive($child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function readAliasStatsFile(): array
    {
        if (!is_readable(self::STATS_FILE)) {
            return $this->emptyStats();
        }

        $raw = file_get_contents(self::STATS_FILE);
        $data = json_decode((string)$raw, true);

        if (!is_array($data)) {
            return $this->emptyStats();
        }

        $found = $this->findStatsRecursive($data);
        return $found ?? $this->emptyStats();
    }

    private function readCoreGeoIpStats(): array
    {
        try {
            $raw = (new Backend())->configdRun('filter geoip stats');
            $data = json_decode((string)$raw, true);

            if (is_array($data)) {
                $found = $this->findStatsRecursive($data);
                if ($found !== null && $found['address_count'] > 0) {
                    return $found;
                }
            }
        } catch (\Throwable $e) {
            // fallback below
        }

        return $this->readAliasStatsFile();
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
        $config = $this->loadConfig();

        return [
            'status' => 'ok',
            'base_url' => $config['base_url'] ?? self::DEFAULT_BASE_URL,
            'default_base_url' => self::DEFAULT_BASE_URL,
            'stats' => $this->readCoreGeoIpStats(),
            'stats_source' => 'filter geoip stats / alias.stats'
        ];
    }

    public function setAction()
    {
        try {
            $payload = $this->request->getJsonRawBody(true);
            $baseUrl = '';

            if (is_array($payload) && isset($payload['base_url'])) {
                $baseUrl = (string)$payload['base_url'];
            } elseif ($this->request->hasPost('base_url')) {
                $baseUrl = (string)$this->request->getPost('base_url');
            }

            $baseUrl = $this->normalizeBaseUrl($baseUrl);

            $this->saveConfig([
                'base_url' => $baseUrl
            ]);

            return [
                'status' => 'ok',
                'message' => 'URL сохранён',
                'base_url' => $baseUrl
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    public function statusAction()
    {
        return [
            'status' => 'ok',
            'stats' => $this->readCoreGeoIpStats(),
            'stats_source' => 'filter geoip stats / alias.stats'
        ];
    }

    public function updateAction()
    {
        try {
            set_time_limit(0);

            $payload = $this->request->getJsonRawBody(true);
            $config = $this->loadConfig();

            $baseUrl = $config['base_url'] ?? self::DEFAULT_BASE_URL;

            if (is_array($payload) && !empty($payload['base_url'])) {
                $baseUrl = (string)$payload['base_url'];
            } elseif ($this->request->hasPost('base_url')) {
                $baseUrl = (string)$this->request->getPost('base_url');
            }

            $baseUrl = $this->normalizeBaseUrl($baseUrl);

            $this->saveConfig([
                'base_url' => $baseUrl
            ]);

            if (!is_executable(self::UPDATE_SCRIPT)) {
                return [
                    'status' => 'error',
                    'message' => 'Скрипт обновления не найден или не исполняемый: ' . self::UPDATE_SCRIPT
                ];
            }

            $command = sprintf(
                '%s --base-url=%s',
                escapeshellcmd(self::UPDATE_SCRIPT),
                escapeshellarg($baseUrl)
            );

            $result = $this->runCommand($command);

            if ($result['exit_code'] !== 0) {
                return [
                    'status' => 'error',
                    'message' => 'Ошибка обновления GeoIP',
                    'output' => $result['output'],
                    'exit_code' => $result['exit_code']
                ];
            }

            return [
                'status' => 'ok',
                'message' => 'GeoIP обновлён',
                'output' => $result['output'],
                'stats' => $this->readCoreGeoIpStats(),
                'stats_source' => 'filter geoip stats / alias.stats'
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
