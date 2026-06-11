#!/usr/local/bin/php
<?php

const SCRIPT_NAME = 'updategeoip-php';

const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/geoip_update.json';

const DEFAULT_BASE_URL = 'https://github.com/runetfreedom/russia-blocked-geoip/archive/refs/heads/release.zip';
const DEFAULT_TEXT_BASE_URL = 'https://raw.githubusercontent.com/runetfreedom/russia-blocked-geoip/release/text/';
const OLD_BASE_URL = 'https://github.com/mamamialezatoz/geoip-database/releases/latest/download/';

const ALIAS_DIR = '/usr/local/share/GeoIP/alias';

const STATS_FILE = '/usr/local/share/GeoIP/alias.stats';

const COUNTRY_LOCATIONS_FILE = 'GeoLite2-Country-Locations-en.csv';
const COUNTRY_BLOCKS_IPV4_FILE = 'GeoLite2-Country-Blocks-IPv4.csv';
const COUNTRY_BLOCKS_IPV6_FILE = 'GeoLite2-Country-Blocks-IPv6.csv';

const COUNTRY_CODES = [
    'AD','AE','AF','AG','AI','AL','AM','AO','AQ','AR','AS','AT','AU','AW','AX','AZ',
    'BA','BB','BD','BE','BF','BG','BH','BI','BJ','BL','BM','BN','BO','BQ','BR','BS',
    'BT','BV','BW','BY','BZ','CA','CC','CD','CF','CG','CH','CI','CK','CL','CM','CN',
    'CO','CR','CU','CV','CW','CX','CY','CZ','DE','DJ','DK','DM','DO','DZ','EC','EE',
    'EG','EH','ER','ES','ET','EU','FI','FJ','FK','FM','FO','FR','GA','GB','GD','GE',
    'GF','GG','GH','GI','GL','GM','GN','GP','GQ','GR','GS','GT','GU','GW','GY','HK',
    'HM','HN','HR','HT','HU','ID','IE','IL','IM','IN','IO','IQ','IR','IS','IT','JE',
    'JM','JO','JP','KE','KG','KH','KI','KM','KN','KP','KR','KW','KY','KZ','LA','LB',
    'LC','LI','LK','LR','LS','LT','LU','LV','LY','MA','MC','MD','ME','MF','MG','MH',
    'MK','ML','MM','MN','MO','MP','MQ','MR','MS','MT','MU','MV','MW','MX','MY','MZ',
    'NA','NC','NE','NF','NG','NI','NL','NO','NP','NR','NU','NZ','OM','PA','PE','PF',
    'PG','PH','PK','PL','PM','PN','PR','PS','PT','PW','PY','QA','RE','RO','RS','RU',
    'RW','SA','SB','SC','SD','SE','SG','SH','SI','SJ','SK','SL','SM','SN','SO','SR',
    'SS','ST','SV','SX','SY','SZ','TC','TD','TF','TG','TH','TJ','TK','TL','TM','TN',
    'TO','TR','TT','TV','TW','TZ','UA','UG','UM','US','UY','UZ','VA','VC','VE','VG',
    'VI','VN','VU','WF','WS','XK','YE','YT','ZA','ZM','ZW'
];

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

function isLegacyGeoIpUrl(string $url): bool
{
    $lower = strtolower(trim($url));

    return $lower === ''
        || strpos($lower, 'mamamialezatoz/geoip-database') !== false
        || strpos($lower, 'github.com/runetfreedom/russia-blocked-geoip/releases') !== false;
}

function normalizeBaseUrl(string $url): string
{
    $url = trim($url);

    if (isLegacyGeoIpUrl($url)) {
        $url = DEFAULT_BASE_URL;
    }

    if (!preg_match('#^https?://#i', $url)) {
        throw new RuntimeException('Source URL должен начинаться с http:// или https://');
    }

    if (preg_match('~\.zip(?:$|[?&#])~i', $url)) {
        return $url;
    }

    return rtrim($url, '/') . '/';
}

function saveConfigBaseUrl(string $baseUrl): void
{
    ensureDir(dirname(CONFIG_FILE), 0755);

    $json = json_encode(['base_url' => $baseUrl], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new RuntimeException('Не удалось сформировать настройки GeoIP');
    }

    if (file_put_contents(CONFIG_FILE, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать настройки GeoIP: ' . CONFIG_FILE);
    }

    chmod(CONFIG_FILE, 0644);
}

function loadConfigBaseUrl(): string
{
    $baseUrl = DEFAULT_BASE_URL;

    if (is_readable(CONFIG_FILE)) {
        $raw = file_get_contents(CONFIG_FILE);
        if ($raw !== false && trim($raw) !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data['base_url'])) {
                $baseUrl = (string)$data['base_url'];
            }
        }
    }

    $normalized = normalizeBaseUrl($baseUrl);

    if ($normalized !== $baseUrl || !is_readable(CONFIG_FILE)) {
        saveConfigBaseUrl($normalized);
    }

    return $normalized;
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

function downloadFile(string $url, string $destination, bool $required = true): bool
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
            return true;
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
            return true;
        }

        $errors[] = 'curl: ' . $result['output'];
        @unlink($destination);
    }

    if (!$required) {
        return false;
    }

    if (empty($errors)) {
        throw new RuntimeException('Не найден fetch или curl для загрузки файлов');
    }

    throw new RuntimeException('Не удалось скачать файл: ' . $url . '; ' . implode('; ', $errors));
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

function appendAliasNetwork(array &$handles, string $tmpAliasDir, string $countryCode, string $proto, string $network): void
{
    $aliasName = strtoupper($countryCode) . '-' . $proto;
    $aliasHandle = getAliasHandle($handles, $tmpAliasDir, $aliasName);
    fwrite($aliasHandle, $network . "\n");
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

        appendAliasNetwork($handles, $tmpAliasDir, $countryCode, $ipVersion, $network);
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

        $lines = array_values(array_unique(array_map('trim', $lines)));
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

function writeStats(int $addressCount, int $fileCount, string $baseUrl, array $sourceInfo): void
{
    ensureDir(dirname(STATS_FILE), 0755);

    $stats = [
        'address_count' => $addressCount,
        'file_count' => $fileCount,
        'timestamp' => date('c'),
        'locations_filename' => $sourceInfo['locations_filename'] ?? '',
        'address_sources' => $sourceInfo['address_sources'] ?? [],
        'source_base_url' => $baseUrl,
        'source_mode' => $sourceInfo['source_mode'] ?? '',
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

function isZipSource(string $url): bool
{
    return preg_match('~\.zip(?:$|[?&#])~i', $url) === 1;
}

function isRunetFreedomTextSource(string $url): bool
{
    $lower = strtolower($url);

    return strpos($lower, 'runetfreedom/russia-blocked-geoip') !== false
        && strpos($lower, '/text/') !== false;
}

function isRunetFreedomGenericSource(string $url): bool
{
    return strpos(strtolower($url), 'runetfreedom/russia-blocked-geoip') !== false;
}

function extractZipFile(string $zipFile, string $extractDir): void
{
    ensureDir($extractDir, 0755);

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        $opened = $zip->open($zipFile);

        if ($opened !== true) {
            throw new RuntimeException('Не удалось открыть ZIP-архив: ' . $zipFile);
        }

        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            throw new RuntimeException('Не удалось распаковать ZIP-архив: ' . $zipFile);
        }

        $zip->close();
        return;
    }

    $unzip = '/usr/local/bin/unzip';
    if (!is_executable($unzip)) {
        $unzip = '/usr/bin/unzip';
    }

    if (!is_executable($unzip)) {
        throw new RuntimeException('Не найден unzip и недоступен PHP ZipArchive');
    }

    $cmd = sprintf(
        '%s -q -o %s -d %s',
        escapeshellcmd($unzip),
        escapeshellarg($zipFile),
        escapeshellarg($extractDir)
    );

    $result = runCommand($cmd);

    if ($result['exit_code'] !== 0) {
        throw new RuntimeException('Не удалось распаковать ZIP: ' . $result['output']);
    }
}

function parseNetworkLine(string $line): ?array
{
    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
    $line = trim($line);

    if ($line === '' || strpos($line, '#') === 0 || strpos($line, '//') === 0) {
        return null;
    }

    $line = preg_split('/\s+/', $line)[0];
    $line = trim($line);

    if ($line === '') {
        return null;
    }

    $parts = explode('/', $line, 2);
    $ip = $parts[0];
    $prefix = $parts[1] ?? null;

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        if ($prefix !== null && (!ctype_digit($prefix) || (int)$prefix < 0 || (int)$prefix > 32)) {
            return null;
        }

        return [
            'network' => $prefix === null ? $ip : $ip . '/' . (int)$prefix,
            'proto' => 'IPv4',
        ];
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        if ($prefix !== null && (!ctype_digit($prefix) || (int)$prefix < 0 || (int)$prefix > 128)) {
            return null;
        }

        return [
            'network' => $prefix === null ? $ip : $ip . '/' . (int)$prefix,
            'proto' => 'IPv6',
        ];
    }

    return null;
}

function parseTextCountryFile(string $file, string $countryCode, string $tmpAliasDir, array &$handles): array
{
    $handle = fopen($file, 'rb');

    if ($handle === false) {
        throw new RuntimeException('Не удалось открыть текстовый список: ' . $file);
    }

    $counts = [
        'IPv4' => 0,
        'IPv6' => 0,
    ];

    while (($line = fgets($handle)) !== false) {
        $parsed = parseNetworkLine($line);

        if ($parsed === null) {
            continue;
        }

        appendAliasNetwork($handles, $tmpAliasDir, $countryCode, $parsed['proto'], $parsed['network']);
        $counts[$parsed['proto']]++;
    }

    fclose($handle);

    return $counts;
}

function processRunetFreedomZip(string $zipFile, string $extractDir, string $tmpAliasDir, array &$handles): array
{
    extractZipFile($zipFile, $extractDir);

    $countryFiles = 0;
    $parsedIPv4 = 0;
    $parsedIPv6 = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extractDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $path = $fileInfo->getPathname();
        $parent = basename(dirname($path));
        $filename = basename($path);

        if (strtolower($parent) !== 'text') {
            continue;
        }

        if (!preg_match('/^([a-z]{2})\.txt$/i', $filename, $matches)) {
            continue;
        }

        $countryCode = strtoupper($matches[1]);

        if (!isCountryCode($countryCode)) {
            continue;
        }

        $counts = parseTextCountryFile($path, $countryCode, $tmpAliasDir, $handles);

        if ($counts['IPv4'] > 0 || $counts['IPv6'] > 0) {
            $countryFiles++;
            $parsedIPv4 += $counts['IPv4'];
            $parsedIPv6 += $counts['IPv6'];
        }
    }

    if ($countryFiles === 0) {
        throw new RuntimeException('В ZIP не найдены country-файлы text/*.txt с IP/CIDR сетями');
    }

    return [
        'source_mode' => 'runetfreedom_zip_text',
        'locations_filename' => 'text/*.txt',
        'address_sources' => [
            'IPv4' => 'text/*.txt',
            'IPv6' => 'text/*.txt',
        ],
        'parsed_ipv4' => $parsedIPv4,
        'parsed_ipv6' => $parsedIPv6,
        'country_files' => $countryFiles,
    ];
}

function processRunetFreedomTextBase(string $baseUrl, string $tmpDownloadDir, string $tmpAliasDir, array &$handles, bool $silent): array
{
    $downloadedFiles = 0;
    $parsedIPv4 = 0;
    $parsedIPv6 = 0;

    foreach (COUNTRY_CODES as $countryCode) {
        $filename = strtolower($countryCode) . '.txt';
        $url = rtrim($baseUrl, '/') . '/' . $filename;
        $destination = $tmpDownloadDir . '/' . $filename;

        out('Скачиваю ' . $filename, $silent);

        if (!downloadFile($url, $destination, false)) {
            continue;
        }

        $counts = parseTextCountryFile($destination, $countryCode, $tmpAliasDir, $handles);

        if ($counts['IPv4'] > 0 || $counts['IPv6'] > 0) {
            $downloadedFiles++;
            $parsedIPv4 += $counts['IPv4'];
            $parsedIPv6 += $counts['IPv6'];
        }
    }

    if ($downloadedFiles === 0) {
        throw new RuntimeException('Не удалось скачать ни один country-файл из text source: ' . $baseUrl);
    }

    return [
        'source_mode' => 'runetfreedom_text_base',
        'locations_filename' => 'text/*.txt',
        'address_sources' => [
            'IPv4' => 'text/*.txt',
            'IPv6' => 'text/*.txt',
        ],
        'parsed_ipv4' => $parsedIPv4,
        'parsed_ipv6' => $parsedIPv6,
        'country_files' => $downloadedFiles,
    ];
}

function processLegacyCsvSource(string $baseUrl, string $tmpCsvDir, string $tmpAliasDir, array &$handles, bool $silent): array
{
    $locationsPath = $tmpCsvDir . '/' . COUNTRY_LOCATIONS_FILE;
    $ipv4Path = $tmpCsvDir . '/' . COUNTRY_BLOCKS_IPV4_FILE;
    $ipv6Path = $tmpCsvDir . '/' . COUNTRY_BLOCKS_IPV6_FILE;

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

    return [
        'source_mode' => 'legacy_maxmind_csv',
        'locations_filename' => COUNTRY_LOCATIONS_FILE,
        'address_sources' => [
            'IPv4' => COUNTRY_BLOCKS_IPV4_FILE,
            'IPv6' => COUNTRY_BLOCKS_IPV6_FILE,
        ],
        'parsed_ipv4' => $parsedIPv4,
        'parsed_ipv6' => $parsedIPv6,
        'country_files' => 0,
    ];
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
    $tmpZipDir = $workDir . '/zip';
    $tmpExtractDir = $workDir . '/extract';
    $tmpTextDir = $workDir . '/text';

    removeTree($workDir);
    ensureDir($tmpCsvDir, 0755);
    ensureDir($tmpAliasDir, 0755);
    ensureDir($tmpZipDir, 0755);
    ensureDir($tmpTextDir, 0755);

    out('Источник: ' . $baseUrl, $silent);

    if (isZipSource($baseUrl)) {
        $zipFile = $tmpZipDir . '/source.zip';
        out('Скачиваю ZIP с runetfreedom release branch', $silent);
        downloadFile($baseUrl, $zipFile);
        out('Разбираю text/*.txt из ZIP', $silent);
        $sourceInfo = processRunetFreedomZip($zipFile, $tmpExtractDir, $tmpAliasDir, $handles);
    } elseif (isRunetFreedomTextSource($baseUrl)) {
        out('Разбираю runetfreedom text base', $silent);
        $sourceInfo = processRunetFreedomTextBase($baseUrl, $tmpTextDir, $tmpAliasDir, $handles, $silent);
    } elseif (isRunetFreedomGenericSource($baseUrl)) {
        out('Для runetfreedom используется release.zip: ' . DEFAULT_BASE_URL, $silent);
        $baseUrl = DEFAULT_BASE_URL;
        $zipFile = $tmpZipDir . '/source.zip';
        downloadFile($baseUrl, $zipFile);
        $sourceInfo = processRunetFreedomZip($zipFile, $tmpExtractDir, $tmpAliasDir, $handles);
    } else {
        $sourceInfo = processLegacyCsvSource($baseUrl, $tmpCsvDir, $tmpAliasDir, $handles, $silent);
    }

    closeAliasHandles($handles);

    out('Записываю alias-файлы в ' . ALIAS_DIR, $silent);
    $written = installAliasFiles($tmpAliasDir, ALIAS_DIR, $args['clean_old']);

    writeStats($written['address_count'], $written['file_count'], $baseUrl, $sourceInfo);

    if ($args['refresh_aliases']) {
        refreshAliases($silent);
    }

    removeTree($workDir);

    out('IPv4 записей разобрано: ' . $sourceInfo['parsed_ipv4'], $silent);
    out('IPv6 записей разобрано: ' . $sourceInfo['parsed_ipv6'], $silent);
    out('Total number of ranges: ' . $written['address_count'], $silent);
    out('Alias files: ' . $written['file_count'], $silent);
    out('Source mode: ' . $sourceInfo['source_mode'], $silent);
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
