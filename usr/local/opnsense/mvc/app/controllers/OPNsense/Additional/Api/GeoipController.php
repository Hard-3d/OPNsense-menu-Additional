<?php

namespace OPNsense\Additional\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;

class GeoipController extends ApiControllerBase
{
    private const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/geoip_update.json';

    private const DEFAULT_MMDB_URLS = [
        'https://raw.githubusercontent.com/runetfreedom/russia-blocked-geoip/release/Country.mmdb',
        'https://git.io/GeoLite2-Country.mmdb',
        'https://github.com/P3TERX/GeoLite.mmdb/raw/download/GeoLite2-Country.mmdb'
    ];

    private const UPDATE_SCRIPT = '/usr/local/opnsense/scripts/additional/updategeoip.php';
    private const STATS_FILE = '/usr/local/share/GeoIP/alias.stats';

    private function isMmdbSourceUrl(string $url): bool
    {
        return preg_match('~\.mmdb(?:$|[?&#])~i', trim($url)) === 1;
    }

    private function normalizeMmdbUrl(string $url, bool $allowEmpty = true): string
    {
        $url = trim($url);

        if ($url === '') {
            if ($allowEmpty) {
                return '';
            }

            throw new \RuntimeException('MMDB URL cannot be empty');
        }

        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('MMDB URL must start with http:// or https://');
        }

        if (!$this->isMmdbSourceUrl($url)) {
            throw new \RuntimeException('MMDB URL must point to a .mmdb file');
        }

        return $url;
    }

    private function normalizeMmdbUrls($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n]+/', $value);
        }

        if (!is_array($value)) {
            $value = [];
        }

        $urls = [];
        foreach ($value as $url) {
            $urls[] = $this->normalizeMmdbUrl((string)$url, true);
        }

        $urls = array_slice(array_pad($urls, 3, ''), 0, 3);

        $hasUrl = false;
        foreach ($urls as $url) {
            if ($url !== '') {
                $hasUrl = true;
                break;
            }
        }

        if (!$hasUrl) {
            $urls = self::DEFAULT_MMDB_URLS;
        }

        return $urls;
    }

    private function legacyUrlsFromConfig(array $data): array
    {
        $legacy = [];

        if (isset($data['mmdb_urls']) && is_array($data['mmdb_urls'])) {
            foreach ($data['mmdb_urls'] as $url) {
                $legacy[] = (string)$url;
            }
        }

        if (isset($data['mmdb_url'])) {
            $legacy[] = (string)$data['mmdb_url'];
        }

        if (isset($data['base_url']) && $this->isMmdbSourceUrl((string)$data['base_url'])) {
            $legacy[] = (string)$data['base_url'];
        }

        if (empty($legacy)) {
            $legacy = self::DEFAULT_MMDB_URLS;
        }

        return $legacy;
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
        $data = [
            'mmdb_urls' => self::DEFAULT_MMDB_URLS
        ];

        if (is_readable(self::CONFIG_FILE)) {
            $raw = file_get_contents(self::CONFIG_FILE);
            $decoded = json_decode((string)$raw, true);

            if (is_array($decoded)) {
                $data = array_merge($data, $decoded);
            }
        }

        $original = $data;

        try {
            $data['mmdb_urls'] = $this->normalizeMmdbUrls($this->legacyUrlsFromConfig($data));
        } catch (\Throwable $e) {
            $data['mmdb_urls'] = self::DEFAULT_MMDB_URLS;
        }

        unset($data['base_url'], $data['mmdb_url'], $data['download_mmdb']);

        if ($data !== $original || !is_readable(self::CONFIG_FILE)) {
            try {
                $this->saveConfig($data);
            } catch (\Throwable $e) {
                // keep returning settings when automatic migration cannot write
            }
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
            'source_mode' => '',
            'mmdb' => [],
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
            'source_mode' => (string)($data['source_mode'] ?? ''),
            'mmdb' => is_array($data['mmdb'] ?? null) ? $data['mmdb'] : [],
        ];
    }

    private function findStatsRecursive($value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        if (array_key_exists('address_count', $value) || array_key_exists('timestamp', $value) || array_key_exists('file_count', $value) || array_key_exists('mmdb', $value)) {
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
                if ($found !== null && ($found['address_count'] > 0 || !empty($found['mmdb']))) {
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

    private function payloadMmdbUrls($payload, array $current): array
    {
        if (is_array($payload) && array_key_exists('mmdb_urls', $payload)) {
            return $this->normalizeMmdbUrls($payload['mmdb_urls']);
        }

        $urls = $current;
        for ($i = 1; $i <= 3; $i++) {
            $key = 'mmdb_url' . $i;
            if (is_array($payload) && array_key_exists($key, $payload)) {
                $urls[$i - 1] = (string)$payload[$key];
            } elseif ($this->request->hasPost($key)) {
                $urls[$i - 1] = (string)$this->request->getPost($key);
            }
        }

        if (is_array($payload) && array_key_exists('mmdb_url', $payload)) {
            $urls[0] = (string)$payload['mmdb_url'];
        } elseif ($this->request->hasPost('mmdb_url')) {
            $urls[0] = (string)$this->request->getPost('mmdb_url');
        }

        return $this->normalizeMmdbUrls($urls);
    }

    public function getAction()
    {
        $config = $this->loadConfig();
        $urls = $config['mmdb_urls'] ?? self::DEFAULT_MMDB_URLS;

        return [
            'status' => 'ok',
            'mmdb_urls' => $urls,
            'mmdb_url1' => $urls[0] ?? '',
            'mmdb_url2' => $urls[1] ?? '',
            'mmdb_url3' => $urls[2] ?? '',
            'default_mmdb_urls' => self::DEFAULT_MMDB_URLS,
            'stats' => $this->readCoreGeoIpStats(),
            'stats_source' => 'filter geoip stats / alias.stats'
        ];
    }

    public function setAction()
    {
        try {
            $payload = $this->request->getJsonRawBody(true);
            $config = $this->loadConfig();
            $urls = $this->payloadMmdbUrls($payload, $config['mmdb_urls'] ?? self::DEFAULT_MMDB_URLS);

            $this->saveConfig([
                'mmdb_urls' => $urls
            ]);

            return [
                'status' => 'ok',
                'message' => 'MMDB URL settings saved',
                'mmdb_urls' => $urls,
                'mmdb_url1' => $urls[0] ?? '',
                'mmdb_url2' => $urls[1] ?? '',
                'mmdb_url3' => $urls[2] ?? ''
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
            $urls = $this->payloadMmdbUrls($payload, $config['mmdb_urls'] ?? self::DEFAULT_MMDB_URLS);

            $this->saveConfig([
                'mmdb_urls' => $urls
            ]);

            if (!is_executable(self::UPDATE_SCRIPT)) {
                return [
                    'status' => 'error',
                    'message' => 'Скрипт обновления не найден или не исполняемый: ' . self::UPDATE_SCRIPT
                ];
            }

            $args = [];
            foreach ($urls as $url) {
                if ($url !== '') {
                    $args[] = '--mmdb-url=' . escapeshellarg($url);
                }
            }

            $command = escapeshellcmd(self::UPDATE_SCRIPT) . ' ' . implode(' ', $args);
            $result = $this->runCommand($command);

            if ($result['exit_code'] !== 0) {
                return [
                    'status' => 'error',
                    'message' => 'Ошибка обновления GeoIP MMDB',
                    'output' => $result['output'],
                    'exit_code' => $result['exit_code']
                ];
            }

            return [
                'status' => 'ok',
                'message' => 'GeoIP MMDB обновлён',
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
