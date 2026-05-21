#!/usr/local/bin/php
<?php

require_once("config.inc");
require_once("util.inc");
require_once("interfaces.inc");
require_once("system.inc");
require_once("legacy_bindings.inc");

const SCRIPT_NAME = "additional-check-wan";
const CONFIG_FILE = "/usr/local/opnsense/scripts/additional/check_wan.json";
const STATUS_FILE = "/var/run/additional_check_wan_status.json";
const LOCK_FILE = "/tmp/additional-check-wan.lock";

openlog(SCRIPT_NAME, LOG_PID, LOG_LOCAL0);

function log_msg_local(string $message): void
{
    syslog(LOG_NOTICE, $message);
    echo "[" . SCRIPT_NAME . "] " . $message . PHP_EOL;
}

function write_status(array $status): void
{
    $status["timestamp"] = date("Y-m-d H:i:s");
    $dir = dirname(STATUS_FILE);

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    @file_put_contents(
        STATUS_FILE,
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );
    @chmod(STATUS_FILE, 0644);
}

function fail_status(string $message, array $extra = []): void
{
    log_msg_local("ERROR: " . $message);
    write_status(array_merge([
        "state" => "error",
        "ok" => false,
        "message" => $message,
    ], $extra));
    exit(1);
}

function load_config(): array
{
    $defaults = [
        "enabled" => "0",
        "gw_a_name" => "",
        "gw_b_name" => "",
        "primary_priority" => "11",
        "backup_priority" => "12",
        "loss_limit" => "30",
        "force_defaultgw_zero" => "1",
    ];

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

function bool_value($value): bool
{
    return in_array(strtolower((string)$value), ["1", "true", "yes", "y", "on"], true);
}

function bool_to_01($value): string
{
    if (is_bool($value)) {
        return $value ? "1" : "0";
    }

    $value = strtolower((string)$value);

    if ($value === "true") {
        return "1";
    }

    if ($value === "false" || $value === "") {
        return "0";
    }

    return $value === "1" ? "1" : "0";
}

function parse_loss($rawLoss): float
{
    $raw = trim((string)$rawLoss);

    if (preg_match('/[-+]?\d+(?:[,.]\d+)?/', $raw, $matches)) {
        return (float)str_replace(",", ".", $matches[0]);
    }

    return 100.0;
}

function &get_gateway_items_ref(): array
{
    global $config;

    if (!isset($config["OPNsense"]["Gateways"]["gateway_item"])) {
        fail_status("В конфигурации не найден раздел OPNsense/Gateways/gateway_item");
    }

    if (isset($config["OPNsense"]["Gateways"]["gateway_item"]["name"])) {
        $single = $config["OPNsense"]["Gateways"]["gateway_item"];
        $config["OPNsense"]["Gateways"]["gateway_item"] = [$single];
    }

    return $config["OPNsense"]["Gateways"]["gateway_item"];
}

function find_gateway_index(array &$items, string $name)
{
    foreach ($items as $index => $item) {
        if (isset($item["name"]) && (string)$item["name"] === $name) {
            return $index;
        }
    }

    return null;
}

function get_gateway_status_map(): array
{
    $map = [];

    $output = [];
    $exitCode = 0;

    exec('/usr/local/sbin/configctl interface gateways status 2>&1', $output, $exitCode);

    if ($exitCode === 0) {
        $decoded = json_decode(trim(implode("\n", $output)), true);

        if (is_array($decoded)) {
            foreach ($decoded as $row) {
                if (is_array($row) && isset($row["name"])) {
                    $map[(string)$row["name"]] = $row;
                }
            }
        }
    }

    return $map;
}

function gateway_runtime_info(array &$items, $index, array $statusMap): array
{
    $item = $items[$index];
    $name = (string)($item["name"] ?? "");

    $status = $statusMap[$name] ?? [];

    $lossRaw = $status["loss"] ?? "~";
    $statusText = $status["status_translated"] ?? ($status["status"] ?? "Pending");

    return [
        "index" => $index,
        "name" => $name,
        "priority" => (string)($item["priority"] ?? "255"),
        "defaultgw_config" => bool_to_01($item["defaultgw"] ?? "0"),
        "status" => (string)$statusText,
        "loss_raw" => (string)$lossRaw,
        "loss_value" => parse_loss($lossRaw),
        "gateway" => (string)($item["gateway"] ?? ""),
        "interface" => (string)($item["interface"] ?? ""),
    ];
}

function other_gateway(array $gw, array $gwA, array $gwB): array
{
    return $gw["name"] === $gwA["name"] ? $gwB : $gwA;
}

function apply_gateway_priority(array &$items, array $gw, string $priority, bool $forceDefaultgwZero): void
{
    $index = $gw["index"];

    $items[$index]["priority"] = $priority;

    if ($forceDefaultgwZero) {
        $items[$index]["defaultgw"] = "0";
    }
}

function normalize_digit_setting($value, string $default): string
{
    $value = trim((string)$value);
    return ctype_digit($value) ? $value : $default;
}

$lockHandle = fopen(LOCK_FILE, "c");

if ($lockHandle === false) {
    fail_status("Не удалось открыть lock-файл: " . LOCK_FILE);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    log_msg_local("Скрипт уже выполняется. Выход.");
    write_status([
        "state" => "locked",
        "ok" => true,
        "message" => "Скрипт уже выполняется",
    ]);
    exit(0);
}

$settings = load_config();
$forceSwitch = in_array("--force-switch", $argv, true);

if (!bool_value($settings["enabled"] ?? "0")) {
    log_msg_local("Проверка WAN отключена в настройках");
    write_status([
        "state" => "disabled",
        "ok" => true,
        "message" => "Проверка WAN отключена",
        "settings" => $settings,
    ]);
    exit(0);
}

$gwAName = trim((string)($settings["gw_a_name"] ?? ""));
$gwBName = trim((string)($settings["gw_b_name"] ?? ""));

$primaryPriority = normalize_digit_setting($settings["primary_priority"] ?? "11", "11");
$backupPriority = normalize_digit_setting($settings["backup_priority"] ?? "12", "12");
$lossLimit = (float)str_replace(",", ".", (string)($settings["loss_limit"] ?? "30"));
$forceDefaultgwZero = bool_value($settings["force_defaultgw_zero"] ?? "1");
$preferredPrimaryName = $gwAName;

if ($gwAName === "" || $gwBName === "") {
    fail_status("Не выбраны оба WAN gateway", [
        "settings" => $settings,
    ]);
}

if ($gwAName === $gwBName) {
    fail_status("Gateway A и Gateway B не должны совпадать", [
        "settings" => $settings,
    ]);
}

log_msg_local("Проверяю WAN gateway: {$gwAName} и {$gwBName}");
log_msg_local("Порог loss: {$lossLimit}");
log_msg_local("Правильные приоритеты: primary={$primaryPriority}, backup={$backupPriority}");

$items = &get_gateway_items_ref();

$idxA = find_gateway_index($items, $gwAName);
$idxB = find_gateway_index($items, $gwBName);

if ($idxA === null) {
    fail_status("Шлюз не найден в конфигурации: {$gwAName}", [
        "settings" => $settings,
    ]);
}

if ($idxB === null) {
    fail_status("Шлюз не найден в конфигурации: {$gwBName}", [
        "settings" => $settings,
    ]);
}

$statusMap = get_gateway_status_map();

$gwA = gateway_runtime_info($items, $idxA, $statusMap);
$gwB = gateway_runtime_info($items, $idxB, $statusMap);

log_msg_local("{$gwA['name']}: priority={$gwA['priority']}, status={$gwA['status']}, loss={$gwA['loss_raw']}");
log_msg_local("{$gwB['name']}: priority={$gwB['priority']}, status={$gwB['status']}, loss={$gwB['loss_raw']}");

$reason = "";

if ($forceSwitch) {
    if ($gwA["priority"] === $primaryPriority) {
        $desiredPrimary = $gwB;
    } elseif ($gwB["priority"] === $primaryPriority) {
        $desiredPrimary = $gwA;
    } elseif ($gwA["priority"] === $backupPriority) {
        $desiredPrimary = $gwA;
    } elseif ($gwB["priority"] === $backupPriority) {
        $desiredPrimary = $gwB;
    } else {
        $desiredPrimary = $gwB;
    }

    $reason = "Принудительная смена приоритета: {$desiredPrimary['name']} назначается primary";
} elseif ($gwA["loss_value"] > $lossLimit && $gwB["loss_value"] < $lossLimit) {
    $desiredPrimary = $gwB;
    $reason = "{$gwAName} имеет loss > {$lossLimit}, {$gwBName} имеет loss < {$lossLimit}";
} elseif ($gwB["loss_value"] > $lossLimit && $gwA["loss_value"] < $lossLimit) {
    $desiredPrimary = $gwA;
    $reason = "{$gwBName} имеет loss > {$lossLimit}, {$gwAName} имеет loss < {$lossLimit}";
} else {
    $primaryCandidates = [];

    if ($gwA["priority"] === $primaryPriority) {
        $primaryCandidates[] = $gwA;
    }

    if ($gwB["priority"] === $primaryPriority) {
        $primaryCandidates[] = $gwB;
    }

    $backupCandidates = [];

    if ($gwA["priority"] === $backupPriority) {
        $backupCandidates[] = $gwA;
    }

    if ($gwB["priority"] === $backupPriority) {
        $backupCandidates[] = $gwB;
    }

    if (count($primaryCandidates) === 1) {
        $desiredPrimary = $primaryCandidates[0];
        $reason = "Сохраняю текущий primary по priority={$primaryPriority}";
    } elseif (count($backupCandidates) === 1) {
        $desiredPrimary = other_gateway($backupCandidates[0], $gwA, $gwB);
        $reason = "Найден backup с priority={$backupPriority}, второй шлюз назначаю primary";
    } else {
        if ($preferredPrimaryName === $gwB["name"]) {
            $desiredPrimary = $gwB;
        } else {
            $desiredPrimary = $gwA;
        }

        $reason = "Приоритеты сбиты, использую preferred primary: {$desiredPrimary['name']}";
    }
}

$desiredBackup = other_gateway($desiredPrimary, $gwA, $gwB);

$needChange = (
    $desiredPrimary["priority"] !== $primaryPriority ||
    $desiredBackup["priority"] !== $backupPriority
);

$state = "ok";
$message = "WAN gateway priorities are correct";
$changed = false;

if ($needChange) {
    log_msg_local("Нужно изменить конфигурацию:");
    log_msg_local("{$desiredPrimary['name']}: priority {$desiredPrimary['priority']} -> {$primaryPriority}");
    log_msg_local("{$desiredBackup['name']}: priority {$desiredBackup['priority']} -> {$backupPriority}");

    apply_gateway_priority($items, $desiredBackup, $backupPriority, $forceDefaultgwZero);
    apply_gateway_priority($items, $desiredPrimary, $primaryPriority, $forceDefaultgwZero);

    write_config(sprintf(
        "%s: set %s priority=%s, %s priority=%s",
        SCRIPT_NAME,
        $desiredPrimary["name"],
        $primaryPriority,
        $desiredBackup["name"],
        $backupPriority
    ));

    try {
        $output = [];
        $exitCode = 0;
        exec('/usr/local/sbin/configctl interface routes configure 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException(trim(implode("\n", $output)));
        }
        $result = trim(implode("\n", $output));
        $result = trim((string)$result);
        if ($result !== "") {
            log_msg_local("interface routes configure: {$result}");
        } else {
            log_msg_local("interface routes configure выполнен");
        }
    } catch (Throwable $e) {
        fail_status("Не удалось выполнить interface routes configure: " . $e->getMessage(), [
            "settings" => $settings,
            "gateway_a" => $gwA,
            "gateway_b" => $gwB,
            "desired_primary" => $desiredPrimary["name"],
            "desired_backup" => $desiredBackup["name"],
            "reason" => $reason,
        ]);
    }

    if ($forceSwitch) {
        $state = "switched";
        $message = "WAN gateway priorities switched manually";
    } else {
        $state = "changed";
        $message = "WAN gateway priorities changed";
    }
    $changed = true;
}

clearstatcache();

$items = &get_gateway_items_ref();
$verifyPrimary = gateway_runtime_info($items, $desiredPrimary["index"], $statusMap);
$verifyBackup = gateway_runtime_info($items, $desiredBackup["index"], $statusMap);

$errors = [];

if ($verifyPrimary["priority"] !== $primaryPriority) {
    $errors[] = "{$verifyPrimary['name']}: ожидался priority={$primaryPriority}, сейчас priority={$verifyPrimary['priority']}";
}

if ($verifyBackup["priority"] !== $backupPriority) {
    $errors[] = "{$verifyBackup['name']}: ожидался priority={$backupPriority}, сейчас priority={$verifyBackup['priority']}";
}

if (!empty($errors)) {
    fail_status("Контрольная проверка не совпала: " . implode("; ", $errors), [
        "settings" => $settings,
        "gateway_a" => $gwA,
        "gateway_b" => $gwB,
        "desired_primary" => $desiredPrimary["name"],
        "desired_backup" => $desiredBackup["name"],
        "reason" => $reason,
    ]);
}

write_status([
    "state" => $state,
    "ok" => true,
    "changed" => $changed,
    "force_switch" => $forceSwitch,
    "message" => $message,
    "reason" => $reason,
    "settings" => $settings,
    "gateway_a" => $gwA,
    "gateway_b" => $gwB,
    "desired_primary" => $desiredPrimary["name"],
    "desired_backup" => $desiredBackup["name"],
    "primary_priority" => $primaryPriority,
    "backup_priority" => $backupPriority,
    "loss_limit" => $lossLimit,
]);

log_msg_local("Готово. {$message}");
exit(0);
