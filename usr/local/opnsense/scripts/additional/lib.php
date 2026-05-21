<?php

declare(strict_types=1);

function additional_load_bash_vars(string $file): array
{
    if (!is_readable($file)) {
        return [];
    }

    $vars = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            $name = $m[1];
            $value = trim($m[2]);

            if (
                strlen($value) >= 2 &&
                (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $vars[$name] = $value;
        }
    }

    return $vars;
}

class AdditionalApiClient
{
    private string $protocol;
    private string $server;
    private string $key;
    private string $secret;

    public function __construct(string $protocol, string $server, string $key, string $secret)
    {
        $this->protocol = $protocol;
        $this->server = $server;
        $this->key = $key;
        $this->secret = $secret;
    }

    public function request(string $method, string $url, $json = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is not available');
        }

        $ch = curl_init();
        $fullUrl = $this->protocol . '://' . $this->server . '/api/' . ltrim($url, '/');

        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_USERPWD, $this->key . ':' . $this->secret);

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json, text/javascript, */*; q=0.01',
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json ?? []));
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('API curl error: ' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('API HTTP ' . $httpCode . ': ' . $response);
        }

        $decoded = json_decode((string)$response, true);

        if ($decoded === null && trim((string)$response) !== '' && json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('API response is not JSON: ' . $response);
        }

        return is_array($decoded) ? $decoded : [];
    }
}

function additional_restart_wireguard(AdditionalApiClient $client): void
{
    $client->request('POST', 'wireguard/general/set', [
        'general' => ['enabled' => '0']
    ]);
    $client->request('POST', 'wireguard/service/reconfigure', []);

    sleep(2);

    $client->request('POST', 'wireguard/general/set', [
        'general' => ['enabled' => '1']
    ]);
    $client->request('POST', 'wireguard/service/reconfigure', []);
}

function additional_load_opnsense_api_config(string $configFile = '/conf/config.xml', string $userName = 'root'): array
{
    if (!is_readable($configFile)) {
        throw new RuntimeException('Config not readable: ' . $configFile);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($configFile);

    if ($xml === false) {
        throw new RuntimeException('Failed to parse config.xml');
    }

    $protocol = (string)($xml->system->webgui->protocol ?? 'https');
    if ($protocol === '') {
        $protocol = 'https';
    }

    $key = null;
    $secret = null;

    foreach ($xml->system->user as $user) {
        if ((string)$user->name !== $userName) {
            continue;
        }

        $comment = (string)$user->comment;

        if (preg_match('/key=([^\s]+)/', $comment, $m)) {
            $key = $m[1];
        }
        if (preg_match('/secret=([^\s]+)/', $comment, $m)) {
            $secret = $m[1];
        }
        break;
    }

    if (!$key || !$secret) {
        throw new RuntimeException('API key/secret not found for user ' . $userName . '. Add key=<API_KEY> secret=<API_SECRET> to root user comment or adapt API config.');
    }

    return [
        'protocol' => $protocol,
        'key' => $key,
        'secret' => $secret,
    ];
}
