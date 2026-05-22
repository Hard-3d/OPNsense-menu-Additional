<style>
    .additional-page {
        padding: 0 20px 24px 20px;
        max-width: 100%;
        box-sizing: border-box;
        overflow: visible;
    }

    .udp2raw-section {
        margin-top: 18px;
        padding: 18px 20px;
        border: 1px solid rgba(255, 255, 255, 0.18);
        background: rgba(255, 255, 255, 0.025);
        box-sizing: border-box;
        max-width: 100%;
        overflow: visible;
    }

    .udp2raw-section h2 {
        margin-top: 0;
        margin-bottom: 16px;
        font-size: 16px;
        font-weight: 700;
    }

    .udp2raw-help {
        margin-bottom: 16px;
    }

    .udp2raw-instance-card {
        border: 1px solid rgba(255, 255, 255, 0.16);
        background: rgba(0, 0, 0, 0.10);
        padding: 14px;
        margin-bottom: 14px;
        box-sizing: border-box;
    }

    .udp2raw-instance-head {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 12px;
        padding-bottom: 10px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.12);
    }

    .udp2raw-instance-title {
        font-weight: 700;
        min-width: 110px;
    }

    .udp2raw-instance-status {
        margin-left: auto;
    }

    .udp2raw-instance-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        gap: 12px 14px;
        align-items: end;
    }

    .udp2raw-field label {
        display: block;
        font-weight: 700;
        margin-bottom: 5px;
        white-space: nowrap;
    }

    .udp2raw-field input,
    .udp2raw-field select {
        width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
    }

    .udp2raw-field-wide {
        grid-column: span 2;
    }

    .udp2raw-actions {
        display: flex;
        align-items: end;
        gap: 6px;
    }

    .additional-page .btn {
        margin-right: 4px;
        margin-bottom: 6px;
    }

    .additional-page .table {
        margin-bottom: 0;
    }

    @media (max-width: 900px) {
        .udp2raw-field-wide {
            grid-column: span 1;
        }

        .udp2raw-instance-head {
            flex-wrap: wrap;
        }

        .udp2raw-instance-status {
            margin-left: 0;
        }
    }
</style>

<script>
$(document).ready(function() {
    var runtimeMap = {};

    function showMessage(type, message) {
        var box = $("#udp2raw_message");
        box.removeClass("alert-success alert-danger alert-warning alert-info");
        box.addClass("alert-" + type);
        box.text(message);
        box.show();
    }

    function hideMessage() {
        $("#udp2raw_message").hide();
    }

    function bool01(value) {
        return value === "1" || value === 1 || value === true;
    }

    function statusLabel(item) {
        if (item && item.running) {
            return '<span class="label label-success">Работает, PID ' + item.pid + '</span>';
        }
        return '<span class="label label-default">Остановлен</span>';
    }

    function updateRuntimeMap(runtime) {
        runtimeMap = {};
        var instances = (runtime || {}).instances || [];
        instances.forEach(function(item) {
            runtimeMap[item.id] = item;
        });
    }

    function fieldBlock(title, control, extraClass) {
        var block = $('<div class="udp2raw-field">');
        if (extraClass) {
            block.addClass(extraClass);
        }
        block.append($('<label>').text(title));
        block.append(control);
        return block;
    }

    function refreshCardTitle(card) {
        var name = card.find(".udp2raw-name").val() || card.attr("data-id") || "udp2raw";
        var mode = card.find(".udp2raw-mode").val() || "client";
        card.find(".udp2raw-instance-title").text(name + " / " + mode);
    }

    function addInstanceRow(instance) {
        instance = instance || {};
        var id = instance.id || ("instance_" + (new Date().getTime()));
        var card = $("<div>").addClass("udp2raw-instance-card").attr("data-id", id);

        var enabled = $('<input type="checkbox" class="udp2raw-enabled">').prop("checked", bool01(instance.enabled));

        var head = $('<div class="udp2raw-instance-head">');
        head.append($('<label style="margin:0;">').append(enabled).append(" Включено"));
        head.append($('<div class="udp2raw-instance-title">').text(instance.name || id));
        head.append($('<div class="udp2raw-instance-status">').html(statusLabel(runtimeMap[id] || {})));

        var name = $('<input type="text" class="form-control udp2raw-name">').val(instance.name || id);
        var mode = $('<select class="form-control udp2raw-mode">')
            .append('<option value="client">client</option>')
            .append('<option value="server">server</option>')
            .val(instance.mode || "client");
        var listen = $('<input type="text" class="form-control udp2raw-listen" placeholder="127.0.0.1:51821">').val(instance.listen || "");
        var remote = $('<input type="text" class="form-control udp2raw-remote" placeholder="1.2.3.4:4096 или 127.0.0.1:51820">').val(instance.remote || "");
        var key = $('<input type="text" class="form-control udp2raw-key">').val(instance.key || "");
        var rawMode = $('<select class="form-control udp2raw-raw-mode">')
            .append('<option value="easyfaketcp">easyfaketcp</option>')
            .append('<option value="faketcp">faketcp</option>')
            .append('<option value="udp">udp</option>')
            .append('<option value="icmp">icmp</option>')
            .val(instance.raw_mode || "easyfaketcp");
        var dev = $('<input type="text" class="form-control udp2raw-dev" placeholder="vmx1">').val(instance.dev || "");
        var logLevel = $('<input type="text" class="form-control udp2raw-log-level">').val(instance.log_level || "3");
        var extra = $('<input type="text" class="form-control udp2raw-extra" placeholder="доп. параметры">').val(instance.extra_args || "");
        var del = $('<button type="button" class="btn btn-xs btn-danger udp2raw-delete"><i class="fa fa-trash"></i> Удалить</button>');

        var grid = $('<div class="udp2raw-instance-grid">');
        grid.append(fieldBlock("Name", name));
        grid.append(fieldBlock("Mode", mode));
        grid.append(fieldBlock("Listen (-l)", listen));
        grid.append(fieldBlock("Remote (-r)", remote));
        grid.append(fieldBlock("Key (-k)", key, "udp2raw-field-wide"));
        grid.append(fieldBlock("Raw mode", rawMode));
        grid.append(fieldBlock("Dev", dev));
        grid.append(fieldBlock("Log", logLevel));
        grid.append(fieldBlock("Extra args", extra, "udp2raw-field-wide"));
        grid.append($('<div class="udp2raw-actions">').append(del));

        card.append(head);
        card.append(grid);

        $("#udp2raw_instances").append(card);
        refreshCardTitle(card);
    }

    function renderConfig(config, runtime) {
        updateRuntimeMap(runtime);
        config = config || {};
        $("#udp2raw_autostart").prop("checked", bool01(config.autostart));
        $("#udp2raw_watchdog").prop("checked", bool01(config.watchdog));
        $("#udp2raw_instances").empty();

        var instances = config.instances || [];
        if (instances.length === 0) {
            addInstanceRow({ id: "default" });
        } else {
            instances.forEach(addInstanceRow);
        }
    }

    function renderBinary(binary) {
        binary = binary || {};
        if (binary.executable) {
            $("#udp2raw_binary_status").html('<span class="label label-success">OK</span> ' + (binary.path || ""));
        } else if (binary.exists) {
            $("#udp2raw_binary_status").html('<span class="label label-warning">Есть, но не исполняемый</span> ' + (binary.path || ""));
        } else {
            $("#udp2raw_binary_status").html('<span class="label label-danger">Не найден</span> ' + (binary.path || ""));
        }
    }

    function collectConfig() {
        var instances = [];
        $("#udp2raw_instances .udp2raw-instance-card").each(function(index) {
            var card = $(this);
            var id = card.attr("data-id") || ("instance_" + (index + 1));

            instances.push({
                id: id,
                enabled: card.find(".udp2raw-enabled").is(":checked") ? "1" : "0",
                name: card.find(".udp2raw-name").val(),
                mode: card.find(".udp2raw-mode").val(),
                listen: card.find(".udp2raw-listen").val(),
                remote: card.find(".udp2raw-remote").val(),
                key: card.find(".udp2raw-key").val(),
                raw_mode: card.find(".udp2raw-raw-mode").val(),
                dev: card.find(".udp2raw-dev").val(),
                log_level: card.find(".udp2raw-log-level").val(),
                extra_args: card.find(".udp2raw-extra").val()
            });
        });

        return {
            autostart: $("#udp2raw_autostart").is(":checked") ? "1" : "0",
            watchdog: $("#udp2raw_watchdog").is(":checked") ? "1" : "0",
            instances: instances
        };
    }

    function loadUdp2raw() {
        hideMessage();
        ajaxCall("/api/additional/udp2raw/get", {}, function(data, status) {
            if (data.status === "ok") {
                renderConfig(data.config || {}, data.runtime || {});
                renderBinary(data.binary || {});
            } else {
                showMessage("danger", data.message || "Ошибка загрузки udp2raw");
            }
        });
    }

    function runAction(action, button, infoText) {
        button.prop("disabled", true);
        showMessage("info", infoText);

        ajaxCall("/api/additional/udp2raw/" + action, {}, function(data, status) {
            button.prop("disabled", false);

            if (data.status === "ok") {
                showMessage("success", data.message || "Команда выполнена");
                renderConfig(data.config || collectConfig(), data.runtime || {});
            } else {
                showMessage("danger", data.message || "Ошибка udp2raw");
            }
        });
    }

    $("#btn_udp2raw_add").click(function() {
        addInstanceRow({
            id: "instance_" + (new Date().getTime()),
            enabled: "0",
            name: "udp2raw",
            mode: "client",
            listen: "127.0.0.1:51821",
            remote: "",
            key: "",
            raw_mode: "easyfaketcp",
            dev: "",
            log_level: "3",
            extra_args: ""
        });
    });

    $("#udp2raw_instances").on("click", ".udp2raw-delete", function() {
        $(this).closest(".udp2raw-instance-card").remove();
    });

    $("#udp2raw_instances").on("input change", ".udp2raw-name, .udp2raw-mode", function() {
        refreshCardTitle($(this).closest(".udp2raw-instance-card"));
    });

    $("#btn_udp2raw_save").click(function() {
        showMessage("info", "Сохраняю настройки udp2raw...");
        ajaxCall("/api/additional/udp2raw/set", collectConfig(), function(data, status) {
            if (data.status === "ok") {
                showMessage("success", data.message);
                renderConfig(data.config || {}, data.runtime || {});
            } else {
                showMessage("danger", data.message || "Ошибка сохранения udp2raw");
            }
        });
    });

    $("#btn_udp2raw_start").click(function() { runAction("start", $(this), "Запускаю udp2raw..."); });
    $("#btn_udp2raw_stop").click(function() { runAction("stop", $(this), "Останавливаю udp2raw..."); });
    $("#btn_udp2raw_restart").click(function() { runAction("restart", $(this), "Перезапускаю udp2raw..."); });
    $("#btn_udp2raw_refresh").click(loadUdp2raw);

    loadUdp2raw();
});
</script>

<div class="additional-page">
    <div id="udp2raw_message" class="alert" style="display:none;"></div>

    <div class="udp2raw-section">
        <h2>Запуск udp2raw</h2>

        <table class="table table-condensed">
            <tr>
                <th style="width:260px;">Бинарник</th>
                <td id="udp2raw_binary_status">-</td>
            </tr>
            <tr>
                <th>Autostart при загрузке OPNsense</th>
                <td><label><input type="checkbox" id="udp2raw_autostart"> Включить запуск через rc.syshook</label></td>
            </tr>
            <tr>
                <th>Watchdog через Scheduler</th>
                <td><label><input type="checkbox" id="udp2raw_watchdog"> Перезапускать включённые instance, если процесс не найден</label></td>
            </tr>
        </table>

        <br>

        <button id="btn_udp2raw_save" type="button" class="btn btn-default"><i class="fa fa-save"></i> Сохранить настройки</button>
        <button id="btn_udp2raw_start" type="button" class="btn btn-primary"><i class="fa fa-play"></i> Запустить</button>
        <button id="btn_udp2raw_stop" type="button" class="btn btn-default"><i class="fa fa-stop"></i> Остановить</button>
        <button id="btn_udp2raw_restart" type="button" class="btn btn-primary"><i class="fa fa-refresh"></i> Перезапустить</button>
        <button id="btn_udp2raw_refresh" type="button" class="btn btn-default"><i class="fa fa-info-circle"></i> Обновить информацию</button>
    </div>

    <div class="udp2raw-section">
        <h2>Instances</h2>

        <div class="alert alert-info udp2raw-help">
            Для клиента используется ключ <b>-c</b>, для сервера <b>-s</b>. Поле <b>Listen</b> соответствует <b>-l</b>, <b>Remote</b> соответствует <b>-r</b>.
            Для WireGuard обычно endpoint указывается на локальный порт udp2raw, например <code>127.0.0.1:51821</code>.
        </div>

        <div id="udp2raw_instances"></div>

        <button id="btn_udp2raw_add" type="button" class="btn btn-default"><i class="fa fa-plus"></i> Добавить instance</button>
    </div>
</div>
