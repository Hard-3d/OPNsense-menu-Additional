#!/usr/local/bin/php
<?php

const SCRIPT_NAME = 'updategeoip-php';
const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/geoip_update.json';

const DEFAULT_MMDB_URLS = [
    'https://raw.githubusercontent.com/runetfreedom/russia-blocked-geoip/release/Country.mmdb',
    'https://git.io/GeoLite2-Country.mmdb',
    'https://github.com/P3TERX/GeoLite.mmdb/raw/download/GeoLite2-Country.mmdb'
];

const MMDB_FILE = '/usr/local/share/GeoIP/runetfreedom-Country.mmdb';
const STATS_FILE = '/usr/local/share/GeoIP/alias.stats';

function out(string $message, bool $silent): void
{
    if (!$silent) {
        echo '[' . SCRIPT_NAME . '] ' . $message . PHP_EOL;
    }
}

function fail(string $message, bool $silent, int $code = 1): void
{
    out('ERROR: ' . $message, $silent);
    exit($code);
}

function parseArgs(array $argv): array
{
    $args = [
        'mmdb_urls' => [],
        'silent' => false,
    ];

    foreach ($argv as $arg) {
        if ($arg === '-s' || $arg === '--silent') {
            $args['silent'] = true;
        } elseif (strpos($arg, '--mmdb-url=') === 0) {
            $args['mmdb_urls'][] = substr($arg, strlen('--mmdb-url='));
        } elseif (strpos($arg, '--base-url=') === 0) {
            $legacy = substr($arg, strlen('--base-url='));
            if (isMmdbSourceUrl($legacy)) {
                $args['mmdb_urls'][] = $legacy;
            }
        }
    }

    return $args;
}

function isMmdbSourceUrl(string $url): bool
{
    return preg_match('~\.mmdb(?:$|[?&#])~i', trim($url)) === 1;
}

function normalizeMmdbUrl(string $url, bool $allowEmpty = true): string
{
    $url = trim($url);

    if ($url === '') {
        if ($allowEmpty) {
            return '';
        }

        throw new RuntimeException('MMDB URL cannot be empty');
    }

    if (!preg_match('#^https?://#i', $url)) {
        throw new RuntimeException('MMDB URL must start with http:// or https://');
    }

    if (!isMmdbSourceUrl($url)) {
        throw new RuntimeException('MMDB URL must point to a .mmdb file');
    }

    return $url;
}

function normalizeMmdbUrls($value): array
{
    if (is_string($value)) {
        $value = preg_split('/[\r\n]+/', $value);
    }

    if (!is_array($value)) {
        $value = [];
    }

    $urls = [];
    foreach ($value as $url) {
        $urls[] = normalizeMmdbUrl((string)$url, true);
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
        $urls = DEFAULT_MMDB_URLS;
    }

    return $urls;
}

function legacyUrlsFromConfig(array $data): array
{
    $urls = [];

    if (isset($data['mmdb_urls']) && is_array($data['mmdb_urls'])) {
        foreach ($data['mmdb_urls'] as $url) {
            $urls[] = (string)$url;
        }
    }

    if (isset($data['mmdb_url'])) {
        $urls[] = (string)$data['mmdb_url'];
    }

    if (isset($data['base_url']) && isMmdbSourceUrl((string)$data['base_url'])) {
        $urls[] = (string)$data['base_url'];
    }

    if (empty($urls)) {
        $urls = DEFAULT_MMDB_URLS;
    }

    return $urls;
}

function ensureDir(string $path, int $mode = 0755): void
{
    if (!is_dir($path)) {
        if (!mkdir($path, $mode, true)) {
            throw new RuntimeException('Не удалось создать каталог: ' . $path);
        }
    }

    chmod($path, $mode);
}

function saveConfigSettings(array $settings): void
{
    ensureDir(dirname(CONFIG_FILE), 0755);

    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new RuntimeException('Could not encode GeoIP settings');
    }

    if (file_put_contents(CONFIG_FILE, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not write GeoIP settings: ' . CONFIG_FILE);
    }

    chmod(CONFIG_FILE, 0644);
}

function loadConfigSettings(): array
{
    $settings = [
        'mmdb_urls' => DEFAULT_MMDB_URLS,
    ];

    if (is_readable(CONFIG_FILE)) {
        $raw = file_get_contents(CONFIG_FILE);
        if ($raw !== false && trim($raw) !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $settings = array_merge($settings, $data);
            }
        }
    }

    $original = $settings;
    $settings['mmdb_urls'] = normalizeMmdbUrls(legacyUrlsFromConfig($settings));
    unset($settings['base_url'], $settings['mmdb_url'], $settings['download_mmdb']);

    if ($settings !== $original || !is_readable(CONFIG_FILE)) {
        saveConfigSettings($settings);
    }

    return $settings;
}

function runCommand(string $command): array
{
    $output = [];
    $exitCode = 0;

    exec($command . ' 2>&1', $output, $exitCode);

    return [
        'exit_code' => $exitCode,
        'output' => trim(implode("\n", $output)),
    ];
}

function downloadFile(string $url, string $destination): void
{
    $fetch = '/usr/bin/fetch';
    $curl = '/usr/local/bin/curl';
    $errors = [];

    @unlink($destination);

    if (is_executable($fetch)) {
        $cmd = sprintf(
            '%s -q -T 180 -o %s %s',
            escapeshellcmd($fetch),
            escapeshellarg($destination),
            escapeshellarg($url)
        );

        $result = runCommand($cmd);

        if ($result['exit_code'] === 0 && file_exists($destination) && filesize($destination) > 0) {
            return;
        }

        $errors[] = 'fetch: ' . $result['output'];
        @unlink($destination);
    }

    if (is_executable($curl)) {
        $cmd = sprintf(
            '%s -LfsS --connect-timeout 30 --max-time 300 -o %s %s',
            escapeshellcmd($curl),
            escapeshellarg($destination),
            escapeshellarg($url)
        );

        $result = runCommand($cmd);

        if ($result['exit_code'] === 0 && file_exists($destination) && filesize($destination) > 0) {
            return;
        }

        $errors[] = 'curl: ' . $result['output'];
        @unlink($destination);
    }

    if (empty($errors)) {
        throw new RuntimeException('Не найден fetch или curl для загрузки файлов');
    }

    throw new RuntimeException(implode('; ', $errors));
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path)) {
            removeTree($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function downloadMmdbWithFallback(array $urls, string $tmpDir, bool $silent): array
{
    ensureDir(dirname(MMDB_FILE), 0755);
    ensureDir($tmpDir, 0755);

    $urls = normalizeMmdbUrls($urls);
    $attempts = [];
    $tmpFile = $tmpDir . '/Country.mmdb';

    foreach ($urls as $index => $url) {
        if ($url === '') {
            continue;
        }

        $attempt = [
            'index' => $index + 1,
            'url' => $url,
            'status' => 'error',
            'message' => '',
        ];

        try {
            out('Trying MMDB source #' . ($index + 1) . ': ' . $url, $silent);
            downloadFile($url, $tmpFile);

            $size = filesize($tmpFile);
            if ($size === false || $size < 1024) {
                throw new RuntimeException('Downloaded MMDB file is too small or unreadable');
            }

            if (!copy($tmpFile, MMDB_FILE)) {
                throw new RuntimeException('Could not install MMDB file: ' . MMDB_FILE);
            }

            chmod(MMDB_FILE, 0644);

            $attempt['status'] = 'ok';
            $attempt['message'] = 'downloaded';
            $attempt['size_bytes'] = (int)$size;
            $attempts[] = $attempt;

            return [
                'url' => $url,
                'source_index' => $index + 1,
                'file' => MMDB_FILE,
                'size_bytes' => (int)$size,
                'timestamp' => date('c'),
                'attempts' => $attempts,
            ];
        } catch (Throwable $e) {
            @unlink($tmpFile);
            $attempt['message'] = $e->getMessage();
            $attempts[] = $attempt;
            out('WARNING: source #' . ($index + 1) . ' failed: ' . $e->getMessage(), $silent);
        }
    }

    throw new RuntimeException('Все MMDB источники недоступны: ' . json_encode($attempts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function writeStats(array $mmdbInfo): void
{
    ensureDir(dirname(STATS_FILE), 0755);

    $stats = [
        'address_count' => 0,
        'file_count' => 0,
        'timestamp' => date('c'),
        'locations_filename' => basename(MMDB_FILE),
        'address_sources' => [
            'MMDB' => $mmdbInfo['url'] ?? '',
        ],
        'source_base_url' => $mmdbInfo['url'] ?? '',
        'source_mode' => 'mmdb_direct_fallback',
        'mmdb' => $mmdbInfo,
    ];

    $json = json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new RuntimeException('Не удалось сформировать alias.stats');
    }

    if (file_put_contents(STATS_FILE, $json, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать ' . STATS_FILE);
    }

    chmod(STATS_FILE, 0644);
}

$args = parseArgs($argv);
$silent = $args['silent'];
$workDir = null;

try {
    $settings = loadConfigSettings();
    $urls = $args['mmdb_urls'] ?: $settings['mmdb_urls'];
    $urls = normalizeMmdbUrls($urls);

    saveConfigSettings([
        'mmdb_urls' => $urls,
    ]);

    $workDir = sys_get_temp_dir() . '/updategeoip_mmdb_' . getmypid();
    removeTree($workDir);
    ensureDir($workDir, 0755);

    $mmdbInfo = downloadMmdbWithFallback($urls, $workDir, $silent);
    writeStats($mmdbInfo);

    removeTree($workDir);

    out('MMDB file: ' . $mmdbInfo['file'] . ' (' . $mmdbInfo['size_bytes'] . ' bytes)', $silent);
    out('Source #' . $mmdbInfo['source_index'] . ': ' . $mmdbInfo['url'], $silent);
    out('Done', $silent);

    exit(0);
} catch (Throwable $e) {
    if ($workDir !== null) {
        removeTree($workDir);
    }

    fail($e->getMessage(), $silent, 2);
}
