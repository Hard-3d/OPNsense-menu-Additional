<style>
    .additional-page {
        padding: 0 20px 24px 20px;
        max-width: 100%;
        box-sizing: border-box;
    }

    .wgpeers-section {
        margin-top: 18px;
        padding: 18px 20px;
        border: 1px solid rgba(255, 255, 255, 0.18);
        background: rgba(255, 255, 255, 0.025);
        box-sizing: border-box;
        max-width: 100%;
    }

    .wgpeers-section h2 {
        margin-top: 0;
        margin-bottom: 16px;
        font-size: 16px;
        font-weight: 700;
    }

    .wgpeer-card {
        border: 1px solid rgba(255, 255, 255, 0.16);
        background: rgba(0, 0, 0, 0.10);
        padding: 14px;
        margin-bottom: 14px;
        box-sizing: border-box;
    }

    .wgpeer-head {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 12px;
        padding-bottom: 10px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.12);
    }

    .wgpeer-title {
        font-weight: 700;
        min-width: 220px;
    }

    .wgpeer-endpoint {
        font-family: Menlo, Monaco, Consolas, "Courier New", monospace;
    }

    .wgpeer-status {
        margin-left: auto;
    }

    .wgpeer-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 12px 14px;
        align-items: end;
    }

    .wgpeer-field label {
        display: block;
        font-weight: 700;
        margin-bottom: 5px;
        white-space: nowrap;
    }

    .wgpeer-field input[type="text"] {
        width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
    }

    .wgpeer-field.is-hidden {
        display: none;
    }

    .additional-page .btn {
        margin-right: 4px;
        margin-bottom: 6px;
    }

    @media (max-width: 900px) {
        .wgpeer-head {
            flex-wrap: wrap;
        }

        .wgpeer-status {
            margin-left: 0;
        }
    }
</style>

<script>
$(document).ready(function() {
    function showMessage(type, message) {
        var box = $("#wgpeers_message");
        box.removeClass("alert-success alert-danger alert-warning alert-info");
        box.addClass("alert-" + type);
        box.text(message);
        box.show();
    }

    function hideMessage() {
        $("#wgpeers_message").hide();
    }

    function bool01(value) {
        return value === "1" || value === 1 || value === true;
    }

    function statusLabel(peer) {
        var last = peer.last_status || {};

        if (!peer.config_enabled) {
            return '<span class="label label-default">Выключен в WG</span>';
        }

        if (!peer.settings || !bool01(peer.settings.enabled)) {
            return '<span class="label label-default">2 IP выкл.</span>';
        }

        if (last.state === "switched") {
            return '<span class="label label-warning">Переключён</span>';
        }

        if (last.state === "ok") {
            return '<span class="label label-success">OK</span>';
        }

        if (last.state === "all_unreachable") {
            return '<span class="label label-danger">Оба IP недоступны</span>';
        }

        if (last.state === "invalid_config") {
            return '<span class="label label-danger">Ошибка настроек</span>';
        }

        if (bool01(peer.settings.check_enabled)) {
            return '<span class="label label-info">Проверка включена</span>';
        }

        return '<span class="label label-default">Проверка выкл.</span>';
    }

    function fieldBlock(title, control, extraClass) {
        var block = $('<div class="wgpeer-field">');
        if (extraClass) {
            block.addClass(extraClass);
        }
        block.append($('<label>').text(title));
        block.append(control);
        return block;
    }

    function updatePeerVisibility(card) {
        var enabled = card.find(".wgpeer-enabled").is(":checked");
        var check = card.find(".wgpeer-check");

        if (enabled) {
            card.find(".wgpeer-extra").removeClass("is-hidden");
            if (!check.data("manual-touched")) {
                check.prop("checked", true);
            }
        } else {
            card.find(".wgpeer-extra").addClass("is-hidden");
            check.prop("checked", false);
            check.data("manual-touched", false);
        }
    }

    function renderPeers(peers) {
        $("#wgpeers_list").empty();

        if (!peers || peers.length === 0) {
            $("#wgpeers_list").html('<div class="alert alert-warning">WireGuard peers с endpoint не найдены в config.xml.</div>');
            return;
        }

        peers.forEach(function(peer) {
            var settings = peer.settings || {};
            var last = peer.last_status || {};

            var card = $("<div>").addClass("wgpeer-card").attr("data-peer-id", peer.id);

            var enabled = $('<input type="checkbox" class="wgpeer-enabled">').prop("checked", bool01(settings.enabled));
            var check = $('<input type="checkbox" class="wgpeer-check">').prop("checked", bool01(settings.check_enabled));

            var head = $('<div class="wgpeer-head">');
            head.append($('<label style="margin:0;">').append(enabled).append(" 2 IP"));
            head.append($('<div class="wgpeer-title">').text(peer.name || peer.id));
            head.append($('<div class="wgpeer-endpoint">').text(peer.current_endpoint || "-"));
            head.append($('<div class="wgpeer-status">').html(statusLabel(peer)));

            var checkLabel = $('<label style="font-weight: normal; margin-top: 8px;">')
                .append(check)
                .append(" Проверять доступность");

            var ip1 = $('<input type="text" class="form-control wgpeer-ip1" placeholder="Primary IP">').val(settings.ip1 || "");
            var ip2 = $('<input type="text" class="form-control wgpeer-ip2" placeholder="Backup IP">').val(settings.ip2 || "");

            if (!settings.ip1 && peer.current_host) {
                ip1.attr("placeholder", "Primary IP, например " + peer.current_host);
            }

            var current = $('<div>')
                .append($('<div>').html('<b>Текущий host:</b> ' + (peer.current_host || "-")))
                .append($('<div>').html('<b>Port:</b> ' + (peer.current_port || "-")))
                .append($('<div>').html('<b>Key:</b> ' + (peer.public_key_short || "-")));

            var lastInfo = $('<div>')
                .append($('<div>').html('<b>Последняя проверка:</b> ' + (last.timestamp || "-")))
                .append($('<div>').html('<b>Сообщение:</b> ' + (last.message || "-")));

            if (last.ip1_ok !== undefined && last.ip1_ok !== null) {
                lastInfo.append($('<div>').html('<b>IP1:</b> ' + (last.ip1_ok ? "OK" : "FAIL")));
            }

            if (last.ip2_ok !== undefined && last.ip2_ok !== null) {
                lastInfo.append($('<div>').html('<b>IP2:</b> ' + (last.ip2_ok ? "OK" : "FAIL")));
            }

            var grid = $('<div class="wgpeer-grid">');
            grid.append(fieldBlock("Current", current));
            grid.append(fieldBlock("Check", checkLabel, "wgpeer-extra"));
            grid.append(fieldBlock("IP 1 / Primary", ip1, "wgpeer-extra"));
            grid.append(fieldBlock("IP 2 / Backup", ip2, "wgpeer-extra"));
            grid.append(fieldBlock("Status", lastInfo));

            card.append(head);
            card.append(grid);
            $("#wgpeers_list").append(card);

            updatePeerVisibility(card);
        });
    }

    function collectConfig() {
        var peers = [];

        $("#wgpeers_list .wgpeer-card").each(function() {
            var card = $(this);

            peers.push({
                peer_id: card.attr("data-peer-id"),
                enabled: card.find(".wgpeer-enabled").is(":checked") ? "1" : "0",
                check_enabled: card.find(".wgpeer-check").is(":checked") ? "1" : "0",
                ip1: card.find(".wgpeer-ip1").val(),
                ip2: card.find(".wgpeer-ip2").val()
            });
        });

        return { peers: peers };
    }

    function renderSummary(data) {
        var runtime = data.runtime || {};
        $("#wgpeers_last_check").text(runtime.timestamp || "-");
        $("#wgpeers_last_message").text(runtime.message || "-");
        $("#wgpeers_scheduler_task").html('<span class="label label-info">wireguard_peers_check</span>');
    }

    function loadPeers() {
        hideMessage();

        ajaxCall("/api/additional/wireguardpeers/get", {}, function(data, status) {
            if (data.status === "ok") {
                renderSummary(data);
                renderPeers(data.peers || []);
            } else {
                showMessage("danger", data.message || "Ошибка загрузки WireGuard peers");
            }
        });
    }

    $("#wgpeers_list").on("change", ".wgpeer-enabled", function() {
        updatePeerVisibility($(this).closest(".wgpeer-card"));
    });

    $("#wgpeers_list").on("change", ".wgpeer-check", function() {
        $(this).data("manual-touched", true);
    });

    $("#btn_wgpeers_save").click(function() {
        showMessage("info", "Сохраняю настройки WireGuard peers...");

        ajaxCall("/api/additional/wireguardpeers/set", collectConfig(), function(data, status) {
            if (data.status === "ok") {
                showMessage("success", data.message || "Настройки сохранены");
                renderSummary(data);
                renderPeers(data.peers || []);
            } else {
                showMessage("danger", data.message || "Ошибка сохранения");
            }
        });
    });

    $("#btn_wgpeers_check").click(function() {
        showMessage("info", "Проверяю WireGuard peers...");

        ajaxCall("/api/additional/wireguardpeers/check", {}, function(data, status) {
            if (data.status === "ok" || data.status === "changed") {
                showMessage(data.changed ? "warning" : "success", data.message || "Проверка выполнена");
                loadPeers();
            } else {
                showMessage("danger", data.message || "Ошибка проверки");
            }
        });
    });

    $("#btn_wgpeers_refresh").click(loadPeers);

    loadPeers();
});
</script>

<div class="additional-page">
    <div id="wgpeers_message" class="alert" style="display:none;"></div>

    <div class="wgpeers-section">
        <h2>WireGuard peers</h2>

        <div class="alert alert-info">
            Для выбранного peer можно включить режим <b>2 IP</b>, указать Primary и Backup IP.
            При проверке сначала проверяется Primary IP. Если он недоступен, но Backup доступен,
            endpoint peer переключается на Backup IP и WireGuard перезапускается.
            Если Primary снова станет доступен, endpoint вернётся на Primary.
        </div>

        <table class="table table-condensed">
            <tr>
                <th style="width:260px;">Последняя проверка</th>
                <td id="wgpeers_last_check">-</td>
            </tr>
            <tr>
                <th>Сообщение</th>
                <td id="wgpeers_last_message">-</td>
            </tr>
            <tr>
                <th>Задание Scheduler</th>
                <td id="wgpeers_scheduler_task">-</td>
            </tr>
        </table>

        <br>

        <button id="btn_wgpeers_save" type="button" class="btn btn-default"><i class="fa fa-save"></i> Сохранить настройки</button>
        <button id="btn_wgpeers_check" type="button" class="btn btn-primary"><i class="fa fa-refresh"></i> Проверить сейчас</button>
        <button id="btn_wgpeers_refresh" type="button" class="btn btn-default"><i class="fa fa-info-circle"></i> Обновить информацию</button>
    </div>

    <div class="wgpeers-section">
        <h2>Peers</h2>
        <div id="wgpeers_list"></div>
    </div>
</div>
