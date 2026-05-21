#!/usr/local/bin/php
<?php

const SCRIPT_NAME = 'updategeoip-php';

const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/geoip_update.json';

const DEFAULT_BASE_URL = 'https://github.com/mamamialezatoz/geoip-database/releases/latest/download/';

const ALIAS_DIR = '/usr/local/share/GeoIP/alias';

const STATS_FILE = '/usr/local/share/GeoIP/alias.stats';

const COUNTRY_LOCATIONS_FILE = 'GeoLite2-Country-Locations-en.csv';
const COUNTRY_BLOCKS_IPV4_FILE = 'GeoLite2-Country-Blocks-IPv4.csv';
const COUNTRY_BLOCKS_IPV6_FILE = 'GeoLite2-Country-Blocks-IPv6.csv';

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
        'base_url' => null,
        'silent' => false,
        'clean_old' => true,
        'refresh_aliases' => true,
    ];

    foreach ($argv as $arg) {
        if ($arg === '-s' || $arg === '--silent') {
            $args['silent'] = true;
        } elseif ($arg === '-n' || $arg === '--no-clean') {
            $args['clean_old'] = false;
        } elseif ($arg === '--no-refresh') {
            $args['refresh_aliases'] = false;
        } elseif (strpos($arg, '--base-url=') === 0) {
            $args['base_url'] = substr($arg, strlen('--base-url='));
        }
    }

    return $args;
}

function normalizeBaseUrl(string $url): string
{
    $url = trim($url);

    if ($url === '') {
        $url = DEFAULT_BASE_URL;
    }

    if (!preg_match('#^https?://#i', $url)) {
        throw new RuntimeException('Base URL должен начинаться с http:// или https://');
    }

    return rtrim($url, '/') . '/';
}

function loadConfigBaseUrl(): string
{
    if (!is_readable(CONFIG_FILE)) {
        return DEFAULT_BASE_URL;
    }

    $raw = file_get_contents(CONFIG_FILE);
    if ($raw === false || trim($raw) === '') {
        return DEFAULT_BASE_URL;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['base_url'])) {
        return DEFAULT_BASE_URL;
    }

    return (string)$data['base_url'];
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

        throw new RuntimeException('Не удалось скачать файл через fetch: ' . $url . '; ' . $result['output']);
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

        throw new RuntimeException('Не удалось скачать файл через curl: ' . $url . '; ' . $result['output']);
    }

    throw new RuntimeException('Не найден fetch или curl для загрузки файлов');
}

function openCsv(string $file)
{
    $handle = fopen($file, 'rb');

    if ($handle === false) {
        throw new RuntimeException('Не удалось открыть CSV: ' . $file);
    }

    return $handle;
}

function normalizeHeaderName(string $name): string
{
    $name = preg_replace('/^\xEF\xBB\xBF/', '', $name);
    return trim($name);
}

function readCsvHeader($handle): array
{
    $header = fgetcsv($handle);

    if ($header === false) {
        throw new RuntimeException('CSV пустой');
    }

    $index = [];

    foreach ($header as $i => $name) {
        $index[normalizeHeaderName((string)$name)] = $i;
    }

    return $index;
}

function csvValue(array $row, array $header, string $column): string
{
    if (!isset($header[$column])) {
        return '';
    }

    $idx = $header[$column];

    if (!array_key_exists($idx, $row)) {
        return '';
    }

    return trim((string)$row[$idx]);
}

function isCountryCode(string $value): bool
{
    return preg_match('/^[A-Z]{2}$/', $value) === 1;
}

function parseLocations(string $file): array
{
    $handle = openCsv($file);
    $header = readCsvHeader($handle);

    $map = [];

    while (($row = fgetcsv($handle)) !== false) {
        $geonameId = csvValue($row, $header, 'geoname_id');
        $countryCode = strtoupper(csvValue($row, $header, 'country_iso_code'));

        if ($geonameId !== '' && isCountryCode($countryCode)) {
            $map[$geonameId] = $countryCode;
        }
    }

    fclose($handle);

    return $map;
}

function countryByGeoname(array $map, string $geonameId): string
{
    if ($geonameId === '') {
        return '';
    }

    return $map[$geonameId] ?? '';
}

function getAliasHandle(array &$handles, string $tmpAliasDir, string $aliasName)
{
    if (!isset($handles[$aliasName])) {
        $path = $tmpAliasDir . '/' . $aliasName;
        $handle = fopen($path, 'ab');

        if ($handle === false) {
            throw new RuntimeException('Не удалось открыть файл alias для записи: ' . $path);
        }

        $handles[$aliasName] = $handle;
    }

    return $handles[$aliasName];
}

function parseBlocksToAliasFiles(
    string $file,
    array $geonameToCountry,
    string $ipVersion,
    string $tmpAliasDir,
    array &$handles
): int {
    $handle = openCsv($file);
    $header = readCsvHeader($handle);

    $count = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $network = csvValue($row, $header, 'network');

        if ($network === '') {
            continue;
        }

        $countryCode = countryByGeoname($geonameToCountry, csvValue($row, $header, 'geoname_id'));

        if ($countryCode === '') {
            $countryCode = countryByGeoname($geonameToCountry, csvValue($row, $header, 'registered_country_geoname_id'));
        }

        if ($countryCode === '') {
            $countryCode = countryByGeoname($geonameToCountry, csvValue($row, $header, 'represented_country_geoname_id'));
        }

        $countryCode = strtoupper($countryCode);

        if (!isCountryCode($countryCode)) {
            continue;
        }

        $aliasName = $countryCode . '-' . $ipVersion;
        $aliasHandle = getAliasHandle($handles, $tmpAliasDir, $aliasName);

        fwrite($aliasHandle, $network . "\n");
        $count++;
    }

    fclose($handle);

    return $count;
}

function closeAliasHandles(array &$handles): void
{
    foreach ($handles as $handle) {
        fclose($handle);
    }

    $handles = [];
}

function removeOldAliasFiles(string $aliasDir): void
{
    if (!is_dir($aliasDir)) {
        return;
    }

    foreach (scandir($aliasDir) as $name) {
        if (preg_match('/^[A-Z]{2}-IPv[46]$/', $name)) {
            @unlink($aliasDir . '/' . $name);
        }
    }
}

function installAliasFiles(string $tmpAliasDir, string $aliasDir, bool $cleanOld): array
{
    ensureDir($aliasDir, 0755);

    if ($cleanOld) {
        removeOldAliasFiles($aliasDir);
    }

    $fileCount = 0;
    $addressCount = 0;

    foreach (scandir($tmpAliasDir) as $name) {
        if (!preg_match('/^[A-Z]{2}-IPv[46]$/', $name)) {
            continue;
        }

        $src = $tmpAliasDir . '/' . $name;
        $dst = $aliasDir . '/' . $name;

        $lines = file($src, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Не удалось прочитать временный alias-файл: ' . $src);
        }

        sort($lines, SORT_STRING);
        $content = implode("\n", $lines) . "\n";

        if (file_put_contents($dst, $content, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать alias-файл: ' . $dst);
        }

        chmod($dst, 0644);

        $fileCount++;
        $addressCount += count($lines);
    }

    return [
        'file_count' => $fileCount,
        'address_count' => $addressCount,
    ];
}

function writeStats(int $addressCount, int $fileCount, string $baseUrl): void
{
    ensureDir(dirname(STATS_FILE), 0755);

    $stats = [
        'address_count' => $addressCount,
        'file_count' => $fileCount,
        'timestamp' => date('c'),
        'locations_filename' => COUNTRY_LOCATIONS_FILE,
        'address_sources' => [
            'IPv4' => COUNTRY_BLOCKS_IPV4_FILE,
            'IPv6' => COUNTRY_BLOCKS_IPV6_FILE,
        ],
        'source_base_url' => $baseUrl,
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

function refreshAliases(bool $silent): void
{
    $configctl = '/usr/local/sbin/configctl';

    if (!is_executable($configctl)) {
        return;
    }

    $result = runCommand($configctl . ' filter refresh_aliases');

    if ($result['exit_code'] === 0) {
        out('filter refresh_aliases выполнен', $silent);
    } else {
        out('WARNING: filter refresh_aliases завершился с ошибкой: ' . $result['output'], $silent);
    }
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

$args = parseArgs($argv);
$silent = $args['silent'];
$handles = [];
$workDir = null;

try {
    $baseUrl = $args['base_url'] !== null ? $args['base_url'] : loadConfigBaseUrl();
    $baseUrl = normalizeBaseUrl($baseUrl);

    $workDir = sys_get_temp_dir() . '/updategeoip_php_' . getmypid();
    $tmpCsvDir = $workDir . '/csv';
    $tmpAliasDir = $workDir . '/alias';

    removeTree($workDir);
    ensureDir($tmpCsvDir, 0755);
    ensureDir($tmpAliasDir, 0755);

    $locationsPath = $tmpCsvDir . '/' . COUNTRY_LOCATIONS_FILE;
    $ipv4Path = $tmpCsvDir . '/' . COUNTRY_BLOCKS_IPV4_FILE;
    $ipv6Path = $tmpCsvDir . '/' . COUNTRY_BLOCKS_IPV6_FILE;

    out('Источник: ' . $baseUrl, $silent);

    out('Скачиваю ' . COUNTRY_LOCATIONS_FILE, $silent);
    downloadFile($baseUrl . COUNTRY_LOCATIONS_FILE, $locationsPath);

    out('Скачиваю ' . COUNTRY_BLOCKS_IPV4_FILE, $silent);
    downloadFile($baseUrl . COUNTRY_BLOCKS_IPV4_FILE, $ipv4Path);

    out('Скачиваю ' . COUNTRY_BLOCKS_IPV6_FILE, $silent);
    downloadFile($baseUrl . COUNTRY_BLOCKS_IPV6_FILE, $ipv6Path);

    out('Разбираю locations', $silent);
    $geonameToCountry = parseLocations($locationsPath);

    if (empty($geonameToCountry)) {
        throw new RuntimeException('Карта стран пустая');
    }

    out('Разбираю IPv4 blocks', $silent);
    $parsedIPv4 = parseBlocksToAliasFiles($ipv4Path, $geonameToCountry, 'IPv4', $tmpAliasDir, $handles);

    out('Разбираю IPv6 blocks', $silent);
    $parsedIPv6 = parseBlocksToAliasFiles($ipv6Path, $geonameToCountry, 'IPv6', $tmpAliasDir, $handles);

    closeAliasHandles($handles);

    out('Записываю alias-файлы в ' . ALIAS_DIR, $silent);
    $written = installAliasFiles($tmpAliasDir, ALIAS_DIR, $args['clean_old']);

    writeStats($written['address_count'], $written['file_count'], $baseUrl);

    if ($args['refresh_aliases']) {
        refreshAliases($silent);
    }

    removeTree($workDir);

    out('IPv4 записей разобрано: ' . $parsedIPv4, $silent);
    out('IPv6 записей разобрано: ' . $parsedIPv6, $silent);
    out('Total number of ranges: ' . $written['address_count'], $silent);
    out('Alias files: ' . $written['file_count'], $silent);
    out('Готово', $silent);

    exit(0);
} catch (Throwable $e) {
    if (is_array($handles)) {
        closeAliasHandles($handles);
    }

    if ($workDir !== null) {
        removeTree($workDir);
    }

    fail($e->getMessage(), $silent, 2);
}
