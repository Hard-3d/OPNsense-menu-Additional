#!/usr/local/bin/php
<?php

const SCRIPT_NAME = 'additional-updater';
const CONFIG_FILE = '/usr/local/opnsense/scripts/additional/update_manager.json';
const VERSION_FILE = '/usr/local/opnsense/scripts/additional/VERSION';
const STATUS_FILE = '/var/run/additional_updater_status.json';
const DEFAULT_ASSET_NAME = '';

function default_config(): array
{
    return [
        'repo_url' => '',
        'asset_name' => DEFAULT_ASSET_NAME,
    ];
}

function load_config(): array
{
    $defaults = default_config();

    if (!is_readable(CONFIG_FILE)) {
        return $defaults;
    }

    $raw = file_get_contents(CONFIG_FILE);
    $data = json_decode((string)$raw, true);

    if (!is_array($data)) {
        return $defaults;
    }

    return array_merge($defaults, $data);
}

function save_status(array $status): void
{
    $status['timestamp'] = date('Y-m-d H:i:s');

    @file_put_contents(
        STATUS_FILE,
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );

    @chmod(STATUS_FILE, 0644);
}

function current_version(): string
{
    if (!is_readable(VERSION_FILE)) {
        return 'unknown';
    }

    $version = trim((string)file_get_contents(VERSION_FILE));
    return $version !== '' ? $version : 'unknown';
}

function parse_args(array $argv): array
{
    $args = [
        'mode' => 'check',
        'json' => false,
    ];

    foreach ($argv as $arg) {
        if ($arg === '--check') {
            $args['mode'] = 'check';
        } elseif ($arg === '--update') {
            $args['mode'] = 'update';
        } elseif ($arg === '--json') {
            $args['json'] = true;
        }
    }

    return $args;
}

function output_result(array $result, bool $json): void
{
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        return;
    }

    echo '[' . SCRIPT_NAME . '] ' . ($result['message'] ?? 'Done') . "\n";

    if (isset($result['current_version'])) {
        echo '[' . SCRIPT_NAME . '] Current version: ' . $result['current_version'] . "\n";
    }

    if (isset($result['latest_version'])) {
        echo '[' . SCRIPT_NAME . '] Latest version: ' . $result['latest_version'] . "\n";
    }

    if (!empty($result['download_url'])) {
        echo '[' . SCRIPT_NAME . '] Download URL: ' . $result['download_url'] . "\n";
    }
}

function fail_result(string $message, bool $json, int $code = 1, array $extra = []): void
{
    $result = array_merge([
        'status' => 'error',
        'ok' => false,
        'message' => $message,
        'current_version' => current_version(),
    ], $extra);

    save_status($result);
    output_result($result, $json);
    exit($code);
}

function normalize_repo_url(string $url): string
{
    $url = trim($url);
    $url = preg_replace('#\.git$#', '', $url);

    return rtrim($url, '/');
}

function parse_github_repo(string $repoUrl): array
{
    $repoUrl = normalize_repo_url($repoUrl);

    if ($repoUrl === '') {
        throw new RuntimeException('Укажите GitHub repository URL');
    }

    if (!preg_match('#^https://github\.com/([^/]+)/([^/]+)$#i', $repoUrl, $m)) {
        throw new RuntimeException('URL должен быть в формате https://github.com/OWNER/REPO');
    }

    return [
        'owner' => $m[1],
        'repo' => $m[2],
        'url' => $repoUrl,
    ];
}

function http_get(string $url): string
{
    $errors = [];

    /*
     * На некоторых установках OPNsense PHP может не иметь корректно
     * работающего HTTPS stream wrapper / allow_url_fopen для GitHub API.
     * Поэтому сначала используем curl, затем fetch, и только потом
     * file_get_contents как fallback.
     */
    $curl = '/usr/local/bin/curl';

    if (is_executable($curl)) {
        $tmp = tempnam(sys_get_temp_dir(), 'additional_updater_api_');

        if ($tmp !== false) {
            $cmd = sprintf(
                '%s -LfsS --retry 2 --connect-timeout 20 --max-time 60 ' .
                '-A %s -H %s -o %s %s',
                escapeshellcmd($curl),
                escapeshellarg('OPNsense-Additional-Updater'),
                escapeshellarg('Accept: application/vnd.github+json, application/json'),
                escapeshellarg($tmp),
                escapeshellarg($url)
            );

            $result = run_command($cmd);

            if ($result['exit_code'] === 0 && is_readable($tmp) && filesize($tmp) > 0) {
                $data = file_get_contents($tmp);
                @unlink($tmp);

                if ($data !== false && $data !== '') {
                    return $data;
                }
            } else {
                $errors[] = 'curl: ' . ($result['output'] !== '' ? $result['output'] : 'empty response');
            }

            @unlink($tmp);
        }
    } else {
        $errors[] = 'curl не найден';
    }

    $fetch = '/usr/bin/fetch';

    if (is_executable($fetch)) {
        $tmp = tempnam(sys_get_temp_dir(), 'additional_updater_api_');

        if ($tmp !== false) {
            $cmd = sprintf(
                '%s -q -T 60 -o %s %s',
                escapeshellcmd($fetch),
                escapeshellarg($tmp),
                escapeshellarg($url)
            );

            $result = run_command($cmd);

            if ($result['exit_code'] === 0 && is_readable($tmp) && filesize($tmp) > 0) {
                $data = file_get_contents($tmp);
                @unlink($tmp);

                if ($data !== false && $data !== '') {
                    return $data;
                }
            } else {
                $errors[] = 'fetch: ' . ($result['output'] !== '' ? $result['output'] : 'empty response');
            }

            @unlink($tmp);
        }
    } else {
        $errors[] = 'fetch не найден';
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: OPNsense-Additional-Updater\r\nAccept: application/vnd.github+json, application/json\r\n",
            'timeout' => 60,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $data = @file_get_contents($url, false, $context);

    if ($data !== false && $data !== '') {
        return $data;
    }

    $errors[] = 'php file_get_contents: empty/false response';

    throw new RuntimeException(
        'Не удалось получить данные: ' . $url . '. Details: ' . implode(' | ', $errors)
    );
}

function normalize_version_tag(string $version): string
{
    $version = trim($version);
    $version = preg_replace('/^v/i', '', $version);
    return $version;
}

function is_latest_newer(string $latest, string $current): bool
{
    if ($current === '' || $current === 'unknown') {
        return true;
    }

    $latestNorm = normalize_version_tag($latest);
    $currentNorm = normalize_version_tag($current);

    if (
        preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][A-Za-z0-9_.-]+)?$/', $latestNorm) &&
        preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][A-Za-z0-9_.-]+)?$/', $currentNorm)
    ) {
        return version_compare($latestNorm, $currentNorm, '>');
    }

    return $latest !== $current;
}


function looks_like_version_tag(string $value): bool
{
    $value = trim($value);
    return preg_match('/^v?\d+(?:\.\d+){1,3}(?:[-+][A-Za-z0-9_.-]+)?$/', $value) === 1;
}

function release_version_from_github(array $data): string
{
    $tag = trim((string)($data['tag_name'] ?? ''));
    $name = trim((string)($data['name'] ?? ''));

    /*
     * GitHub позволяет назвать tag как угодно, например "Beta",
     * а версию указать в имени release: "v0.1.2".
     * Для сравнения версий используем нормальный semver-подобный release name,
     * если tag не похож на версию.
     */
    if (looks_like_version_tag($tag)) {
        return $tag;
    }

    if (looks_like_version_tag($name)) {
        return $name;
    }

    if ($tag !== '') {
        return $tag;
    }

    if ($name !== '') {
        return $name;
    }

    return 'unknown';
}

function latest_release_info(array $config): array
{
    $repo = parse_github_repo((string)($config['repo_url'] ?? ''));
    $assetName = trim((string)($config['asset_name'] ?? ''));

    $apiUrl = sprintf(
        'https://api.github.com/repos/%s/%s/releases/latest',
        rawurlencode($repo['owner']),
        rawurlencode($repo['repo'])
    );

    $raw = http_get($apiUrl);
    $data = json_decode($raw, true);

    if (!is_array($data) || empty($data['tag_name'])) {
        throw new RuntimeException('Не удалось прочитать latest release. Проверьте, что в GitHub создан Release.');
    }

    $downloadUrl = '';
    $downloadName = '';
    $downloadType = 'source_zip';

    $assets = is_array($data['assets'] ?? null) ? $data['assets'] : [];

    if ($assetName !== '') {
        foreach ($assets as $asset) {
            if (($asset['name'] ?? '') === $assetName && !empty($asset['browser_download_url'])) {
                $downloadUrl = (string)$asset['browser_download_url'];
                $downloadName = (string)$asset['name'];
                $downloadType = 'release_asset';
                break;
            }
        }
    }

    if ($downloadUrl === '') {
        foreach ($assets as $asset) {
            $name = (string)($asset['name'] ?? '');
            if (preg_match('/\.zip$/i', $name) && !empty($asset['browser_download_url'])) {
                $downloadUrl = (string)$asset['browser_download_url'];
                $downloadName = $name;
                $downloadType = 'release_asset';
                break;
            }
        }
    }

    if ($downloadUrl === '') {
        $downloadUrl = (string)($data['zipball_url'] ?? '');
        $downloadName = 'GitHub source zip';
        $downloadType = 'source_zip';
    }

    if ($downloadUrl === '') {
        throw new RuntimeException('В latest release не найден URL для загрузки zip');
    }

    $current = current_version();
    $latest = release_version_from_github($data);

    $updateAvailable = is_latest_newer($latest, $current);

    return [
        'status' => 'ok',
        'ok' => true,
        'message' => $updateAvailable ? 'Доступна новая версия' : 'Установлена актуальная версия',
        'repo_url' => $repo['url'],
        'asset_name' => $assetName,
        'current_version' => $current,
        'latest_version' => $latest,
        'release_tag' => (string)($data['tag_name'] ?? ''),
        'release_name' => (string)($data['name'] ?? ''),
        'update_available' => $updateAvailable,
        'published_at' => (string)($data['published_at'] ?? ''),
        'release_url' => (string)($data['html_url'] ?? ''),
        'download_url' => $downloadUrl,
        'download_name' => $downloadName,
        'download_type' => $downloadType,
    ];
}

function run_command(string $command): array
{
    $output = [];
    $exitCode = 0;

    exec($command . ' 2>&1', $output, $exitCode);

    return [
        'exit_code' => $exitCode,
        'output' => trim(implode("\n", $output)),
    ];
}

function download_file(string $url, string $destination): void
{
    $curl = '/usr/local/bin/curl';

    if (is_executable($curl)) {
        $cmd = sprintf(
            '%s -LfsS --connect-timeout 30 --max-time 300 -A %s -o %s %s',
            escapeshellcmd($curl),
            escapeshellarg('OPNsense-Additional-Updater'),
            escapeshellarg($destination),
            escapeshellarg($url)
        );

        $result = run_command($cmd);

        if ($result['exit_code'] === 0 && file_exists($destination) && filesize($destination) > 0) {
            return;
        }

        throw new RuntimeException('Не удалось скачать архив через curl: ' . $result['output']);
    }

    $fetch = '/usr/bin/fetch';

    if (is_executable($fetch)) {
        $cmd = sprintf(
            '%s -q -T 180 -o %s %s',
            escapeshellcmd($fetch),
            escapeshellarg($destination),
            escapeshellarg($url)
        );

        $result = run_command($cmd);

        if ($result['exit_code'] === 0 && file_exists($destination) && filesize($destination) > 0) {
            return;
        }

        throw new RuntimeException('Не удалось скачать архив через fetch: ' . $result['output']);
    }

    throw new RuntimeException('Не найден curl или fetch для загрузки архива');
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path) && !is_link($path)) {
            remove_tree($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function find_install_root(string $dir): string
{
    if (is_file($dir . '/install.sh')) {
        return $dir;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir() && is_file($item->getPathname() . '/install.sh')) {
            return $item->getPathname();
        }
    }

    throw new RuntimeException('В архиве не найден install.sh');
}

function perform_update(array $info): array
{
    $workDir = sys_get_temp_dir() . '/additional_updater_' . getmypid();
    $zipFile = $workDir . '/package.zip';
    $extractDir = $workDir . '/extract';

    remove_tree($workDir);

    if (!mkdir($extractDir, 0755, true)) {
        throw new RuntimeException('Не удалось создать временный каталог: ' . $extractDir);
    }

    download_file($info['download_url'], $zipFile);

    $unzip = '/usr/local/bin/unzip';
    if (!is_executable($unzip)) {
        $unzip = '/usr/bin/unzip';
    }
    if (!is_executable($unzip)) {
        $unzip = 'unzip';
    }

    $unzipResult = run_command(sprintf('%s -oq %s -d %s', escapeshellcmd($unzip), escapeshellarg($zipFile), escapeshellarg($extractDir)));

    if ($unzipResult['exit_code'] !== 0) {
        throw new RuntimeException('Не удалось распаковать архив: ' . $unzipResult['output']);
    }

    $installRoot = find_install_root($extractDir);

    $copyResult = run_command(sprintf(
        '/bin/cp -R %s/. /',
        escapeshellarg($installRoot)
    ));

    if ($copyResult['exit_code'] !== 0) {
        throw new RuntimeException('Не удалось скопировать файлы обновления: ' . $copyResult['output']);
    }

    @chmod('/install.sh', 0755);

    $installResult = run_command('/bin/sh /install.sh');

    if ($installResult['exit_code'] !== 0) {
        throw new RuntimeException('install.sh завершился с ошибкой: ' . $installResult['output']);
    }

    @file_put_contents(VERSION_FILE, $info['latest_version'] . "\n", LOCK_EX);
    @chmod(VERSION_FILE, 0644);

    remove_tree($workDir);

    return [
        'install_output' => $installResult['output'],
        'installed_from' => $installRoot,
    ];
}

$args = parse_args($argv);

try {
    $config = load_config();
    $info = latest_release_info($config);

    if ($args['mode'] === 'check') {
        save_status($info);
        output_result($info, $args['json']);
        exit(0);
    }

    $updateInfo = perform_update($info);

    $result = array_merge($info, [
        'status' => 'ok',
        'ok' => true,
        'message' => 'Обновление установлено',
        'updated' => true,
        'previous_version' => $info['current_version'],
        'current_version' => $info['latest_version'],
        'install_output' => $updateInfo['install_output'],
    ]);

    save_status($result);
    output_result($result, $args['json']);
    exit(0);
} catch (Throwable $e) {
    fail_result($e->getMessage(), $args['json'], 1);
}
