<style>
    .checkwan-card {
        margin-top: 20px;
        padding: 20px;
        border: 1px solid rgba(255, 255, 255, 0.18);
        background: rgba(255, 255, 255, 0.02);
    }

    .checkwan-card h2 {
        margin-top: 0;
        margin-bottom: 20px;
    }

    .checkwan-section {
        margin-top: 20px;
    }

    .checkwan-value {
        font-weight: bold;
    }

    .checkwan-input {
        max-width: 260px;
    }

    .checkwan-select {
        max-width: 420px;
    }

    .checkwan-hidden {
        display: none;
    }

    /* Unified Additional pages layout */
    .additional-page {
        padding: 0 20px 24px 20px;
        max-width: 100%;
        box-sizing: border-box;
        overflow: visible;
    }

    .additional-page .alert {
        margin-top: 0;
    }

    .ethname-section,
    .geoip-section,
    .checkstatus-card,
    .checkwan-card {
        margin-top: 18px !important;
        padding: 18px 20px !important;
        border: 1px solid rgba(255, 255, 255, 0.18) !important;
        background: rgba(255, 255, 255, 0.025) !important;
        box-sizing: border-box !important;
        max-width: 100% !important;
        overflow-x: auto;
    }

    .ethname-section:first-of-type,
    .geoip-section:first-of-type,
    .checkstatus-card:first-of-type,
    .checkwan-card:first-of-type {
        margin-top: 16px !important;
    }

    .ethname-section h2,
    .geoip-section h2,
    .checkstatus-card h2,
    .checkwan-card h2 {
        margin-top: 0 !important;
        margin-bottom: 16px !important;
        font-size: 16px !important;
        font-weight: 700 !important;
    }

    .additional-page .table {
        width: 100%;
        margin-bottom: 0 !important;
    }

    .additional-page .table th {
        width: 260px;
        max-width: 260px;
        white-space: nowrap;
    }

    .additional-page .btn {
        margin-right: 4px;
        margin-bottom: 6px;
    }

    .additional-page .form-group {
        max-width: 920px;
    }

    .additional-page input.form-control,
    .additional-page select.form-control {
        max-width: 820px;
    }

    .additional-page textarea.form-control {
        max-width: 100%;
    }

</style>

<script>
$(document).ready(function() {
    function showMessage(type, message) {
        var box = $("#checkwan_message");
        box.removeClass("alert-success alert-danger alert-warning alert-info");
        box.addClass("alert-" + type);
        box.text(message);
        box.show();
    }

    function hideMessage() {
        $("#checkwan_message").hide();
    }


    function showConfirmModal(title, message, confirmText, onConfirm) {
        var modalId = "additional_confirm_modal";
        var styleId = "additional_confirm_modal_styles";

        if ($("#" + styleId).length === 0) {
            $("head").append(
                '<style id="' + styleId + '">' +
                    '#' + modalId + ' .modal-backdrop, .modal-backdrop.in { opacity: 0.78 !important; }' +
                    '#' + modalId + ' .modal-dialog { margin-top: 90px; }' +
                    '#' + modalId + ' .modal-content {' +
                        'background: #101722;' +
                        'border: 2px solid #f47c20;' +
                        'border-radius: 8px;' +
                        'box-shadow: 0 16px 48px rgba(0, 0, 0, 0.65);' +
                    '}' +
                    '#' + modalId + ' .modal-header {' +
                        'background: #141d2b;' +
                        'border-bottom: 1px solid #f47c20;' +
                        'border-top-left-radius: 6px;' +
                        'border-top-right-radius: 6px;' +
                        'padding: 16px 18px;' +
                    '}' +
                    '#' + modalId + ' .modal-title {' +
                        'color: #ffffff;' +
                        'font-weight: 700;' +
                        'font-size: 24px;' +
                    '}' +
                    '#' + modalId + ' .close {' +
                        'color: #ffffff;' +
                        'opacity: 0.85;' +
                        'text-shadow: none;' +
                    '}' +
                    '#' + modalId + ' .close:hover { opacity: 1; }' +
                    '#' + modalId + ' .modal-body {' +
                        'background: #101722;' +
                        'color: #e7edf5;' +
                        'padding: 18px;' +
                        'font-size: 16px;' +
                        'line-height: 1.5;' +
                    '}' +
                    '#' + modalId + ' .modal-footer {' +
                        'background: #101722;' +
                        'border-top: 1px solid rgba(244,124,32,0.35);' +
                        'padding: 14px 18px 18px;' +
                    '}' +
                    '#' + modalId + ' .btn-default {' +
                        'border-color: #7d8793;' +
                        'color: #ffffff;' +
                        'background: transparent;' +
                    '}' +
                '</style>'
            );
        }

        if ($("#" + modalId).length === 0) {
            $("body").append(
                '<div class="modal fade" id="' + modalId + '" tabindex="-1" role="dialog" aria-hidden="true">' +
                    '<div class="modal-dialog" role="document">' +
                        '<div class="modal-content">' +
                            '<div class="modal-header">' +
                                '<button type="button" class="close" data-dismiss="modal" aria-label="Close">' +
                                    '<span aria-hidden="true">&times;</span>' +
                                '</button>' +
                                '<h4 class="modal-title" id="additional_confirm_title"></h4>' +
                            '</div>' +
                            '<div class="modal-body">' +
                                '<p id="additional_confirm_body" style="white-space: pre-line; margin: 0;"></p>' +
                            '</div>' +
                            '<div class="modal-footer">' +
                                '<button type="button" class="btn btn-default" data-dismiss="modal">Отмена</button>' +
                                '<button type="button" class="btn btn-primary" id="additional_confirm_ok">OK</button>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>'
            );
        }

        $("#additional_confirm_title").text(title || "Подтверждение");
        $("#additional_confirm_body").text(message || "");
        $("#additional_confirm_ok").text(confirmText || "OK");

        $("#additional_confirm_ok").off("click").on("click", function() {
            $("#" + modalId).modal("hide");

            if (typeof onConfirm === "function") {
                onConfirm();
            }
        });

        $("#" + modalId).modal({
            backdrop: true,
            keyboard: true,
            show: true
        });
    }

    function listText(value) {
        if (!value) {
            return "-";
        }
        if ($.isArray(value)) {
            return value.length ? value.join(", ") : "-";
        }
        return String(value);
    }

    function isCronEnabled(row) {
        var disabled = row.enabled === "0" || row.enabled === 0 || row.enabled === false || row.disabled === "1" || row.disabled === 1 || row.disabled === true;
        return !disabled;
    }

    function cronField(row, names) {
        for (var i = 0; i < names.length; i++) {
            if (row[names[i]] !== undefined && row[names[i]] !== null && row[names[i]] !== "") {
                return String(row[names[i]]);
            }
        }
        return "";
    }

    function cronEveryText(value, unitOne, unitFew, unitMany) {
        var match = String(value || "").match(/^\*\/(\d+)$/);
        if (!match) {
            return "";
        }

        var n = parseInt(match[1], 10);
        var lastTwo = n % 100;
        var last = n % 10;
        var unit = unitMany;

        if (lastTwo < 11 || lastTwo > 14) {
            if (last === 1) {
                unit = unitOne;
            } else if (last >= 2 && last <= 4) {
                unit = unitFew;
            }
        }

        return "каждые " + n + " " + unit;
    }

    function cronFrequency(row) {
        var minutes = cronField(row, ["minutes", "minute", "minuteValue"]);
        var hours = cronField(row, ["hours", "hour", "hourValue"]);
        var days = cronField(row, ["days", "day", "dayValue"]);
        var months = cronField(row, ["months", "month", "monthValue"]);
        var weekdays = cronField(row, ["weekdays", "weekday", "weekdaysValue"]);

        var anyHour = hours === "*" || hours === "";
        var anyDay = days === "*" || days === "";
        var anyMonth = months === "*" || months === "";
        var anyWeekday = weekdays === "*" || weekdays === "";

        if ((minutes === "*" || minutes === "") && anyHour && anyDay && anyMonth && anyWeekday) {
            return "каждую минуту";
        }

        var everyMinutes = cronEveryText(minutes, "минуту", "минуты", "минут");
        if (everyMinutes !== "" && anyHour && anyDay && anyMonth && anyWeekday) {
            return everyMinutes;
        }

        var everyHours = cronEveryText(hours, "час", "часа", "часов");
        if (everyHours !== "" && minutes !== "*" && minutes !== "" && anyDay && anyMonth && anyWeekday) {
            return everyHours + " в " + String(minutes).padStart(2, "0") + " мин.";
        }

        if (minutes !== "*" && minutes !== "" && anyHour && anyDay && anyMonth && anyWeekday) {
            return "каждый час в " + String(minutes).padStart(2, "0") + " мин.";
        }

        if (hours !== "*" && hours !== "" && minutes !== "*" && minutes !== "" && anyDay && anyMonth && anyWeekday) {
            return "каждый день в " + String(hours).padStart(2, "0") + ":" + String(minutes).padStart(2, "0");
        }

        var parts = [];
        if (minutes !== "") { parts.push("минуты: " + minutes); }
        if (hours !== "") { parts.push("часы: " + hours); }
        if (days !== "") { parts.push("дни: " + days); }
        if (months !== "") { parts.push("месяцы: " + months); }
        if (weekdays !== "") { parts.push("дни недели: " + weekdays); }

        return parts.length ? parts.join(", ") : "расписание не определено";
    }

    function rowContainsCheckWanJob(row) {
        var text = JSON.stringify(row).toLowerCase();
        return text.indexOf("additional check wan") !== -1 ||
            text.indexOf("additional_check_wan") !== -1 ||
            text.indexOf("check-wan-gateway-loss") !== -1 ||
            text.indexOf("check wan") !== -1;
    }

    function renderCronStatus(found) {
        if (!found) {
            $("#wan_cron_status").html('<span class="label label-danger">Нет</span>');
            return;
        }

        var enabled = isCronEnabled(found);
        var label = enabled ? "label-success" : "label-warning";
        var text = enabled ? "Есть, включено" : "Есть, но отключено";
        text += " — " + cronFrequency(found);
        $("#wan_cron_status").html('<span class="label ' + label + '">' + text + '</span>');
    }

    function loadCronStatus() {
        ajaxCall("/api/additional/scheduler/status", {}, function(data, status) {
            var task = (((data || {}).config || {}).tasks || {}).check_wan || {};

            if (task.enabled === "1") {
                $("#wan_cron_status").html('<span class="label label-success">Включено — ' + (task.schedule_text || "-") + '</span>');
            } else {
                $("#wan_cron_status").html('<span class="label label-default">Отключено</span>');
            }
        });
    }

    function fillGatewaySelects(gateways, config) {
        var a = $("#wan_gw_a");
        var b = $("#wan_gw_b");

        a.empty();
        b.empty();

        a.append($("<option>").attr("value", "").text("Выберите gateway"));
        b.append($("<option>").attr("value", "").text("Выберите gateway"));

        $.each(gateways || [], function(idx, gw) {
            var title = gw.name + " — " + (gw.gateway || "-") + " — priority " + (gw.priority || "-") + " — loss " + (gw.loss || "-");

            a.append($("<option>").attr("value", gw.name).text(title));
            b.append($("<option>").attr("value", gw.name).text(title));
        });

        a.val(config.gw_a_name || "");
        b.val(config.gw_b_name || "");
    }

    function renderStatus(status) {
        status = status || {};

        var stateText = "Неизвестно";
        var stateClass = "label-default";

        if (status.state === "ok") {
            stateText = "Работает";
            stateClass = "label-success";
        } else if (status.state === "changed") {
            stateText = "Выполнено переключение";
            stateClass = "label-warning";
        } else if (status.state === "disabled") {
            stateText = "Отключено";
            stateClass = "label-default";
        } else if (status.state === "error") {
            stateText = "Ошибка";
            stateClass = "label-danger";
        } else if (status.state === "locked") {
            stateText = "Уже выполняется";
            stateClass = "label-info";
        }

        $("#wan_state").html('<span class="label ' + stateClass + '">' + stateText + '</span>');
        $("#wan_message").text(status.message || "-");
        $("#wan_last_check").text(status.timestamp || "-");
        $("#wan_reason").text(status.reason || "-");
        $("#wan_desired_primary").text(status.desired_primary || "-");
        $("#wan_desired_backup").text(status.desired_backup || "-");

        var ga = status.gateway_a || {};
        var gb = status.gateway_b || {};

        $("#wan_gateway_a_status").text((ga.name || "-") + " / priority " + (ga.priority || "-") + " / loss " + (ga.loss_raw || "-") + " / status " + (ga.status || "-"));
        $("#wan_gateway_b_status").text((gb.name || "-") + " / priority " + (gb.priority || "-") + " / loss " + (gb.loss_raw || "-") + " / status " + (gb.status || "-"));
    }

    function updateFormVisibility() {
        if ($("#wan_enabled").is(":checked")) {
            $("#wan_settings_form").show();
            $("#btn_wan_run").show();
            $("#btn_wan_switch").show();
            $("#btn_wan_refresh").show();
            $("#wan_status_section").show();
        } else {
            $("#wan_settings_form").hide();
            $("#btn_wan_run").hide();
            $("#btn_wan_switch").hide();
            $("#btn_wan_refresh").hide();
            $("#wan_status_section").hide();
        }
    }

    function loadCheckWan() {
        hideMessage();

        ajaxCall("/api/additional/checkwan/get", {}, function(data, status) {
            if (data.status === "ok") {
                var config = data.config || {};
                $("#wan_enabled").prop("checked", config.enabled === "1" || config.enabled === 1 || config.enabled === true);
                $("#wan_primary_priority").val(config.primary_priority || "11");
                $("#wan_backup_priority").val(config.backup_priority || "12");
                $("#wan_loss_limit").val(config.loss_limit || "30");

                fillGatewaySelects(data.gateways || [], config);
                renderStatus(data.checkwan || {});
                updateFormVisibility();
                loadCronStatus();
            } else {
                showMessage("danger", data.message || "Ошибка загрузки Check WAN");
            }
        });
    }

    $("#wan_enabled").change(function() {
        updateFormVisibility();
    });

    $("#btn_wan_save").click(function() {
        showMessage("info", "Сохраняю настройки Check WAN...");

        ajaxCall("/api/additional/checkwan/set", {
            enabled: $("#wan_enabled").is(":checked") ? "1" : "0",
            gw_a_name: $("#wan_gw_a").val(),
            gw_b_name: $("#wan_gw_b").val(),
            primary_priority: $("#wan_primary_priority").val(),
            backup_priority: $("#wan_backup_priority").val(),
            loss_limit: $("#wan_loss_limit").val()
        }, function(data, status) {
            if (data.status === "ok") {
                showMessage("success", data.message);
                fillGatewaySelects(data.gateways || [], data.config || {});
                renderStatus(data.checkwan || {});
                updateFormVisibility();
                loadCronStatus();
            } else {
                showMessage("danger", data.message || "Ошибка сохранения Check WAN");
            }
        });
    });

    $("#btn_wan_run").click(function() {
        showConfirmModal(
            "Проверка WAN",
            "Запустить проверку WAN сейчас?\nПри необходимости приоритеты gateway будут изменены.",
            "Проверить",
            function() {
                $("#btn_wan_run").prop("disabled", true);
                showMessage("info", "Выполняется проверка Check WAN...");

                ajaxCall("/api/additional/checkwan/run", {}, function(data, status) {
                    $("#btn_wan_run").prop("disabled", false);

                    if (data.status === "ok") {
                        showMessage("success", data.message || "Проверка выполнена");
                    } else {
                        showMessage("danger", data.message || "Ошибка проверки Check WAN");
                    }

                    if (data.gateways) {
                        fillGatewaySelects(data.gateways, {
                            gw_a_name: $("#wan_gw_a").val(),
                            gw_b_name: $("#wan_gw_b").val()
                        });
                    }

                    renderStatus(data.checkwan || {});
                    loadCronStatus();
                });
            }
        );
    });

    $("#btn_wan_switch").click(function() {
        showConfirmModal(
            "Смена приоритета WAN",
            "Принудительно сменить приоритет выбранных WAN gateway?\nТекущий primary станет backup, второй gateway станет primary.",
            "Сменить",
            function() {
                $("#btn_wan_switch").prop("disabled", true);
                showMessage("info", "Выполняется принудительная смена приоритета WAN...");

                ajaxCall("/api/additional/checkwan/switchpriority", {}, function(data, status) {
                    $("#btn_wan_switch").prop("disabled", false);

                    if (data.status === "ok") {
                        showMessage("success", data.message || "Приоритеты изменены");
                    } else {
                        showMessage("danger", data.message || "Ошибка смены приоритета WAN");
                    }

                    if (data.gateways) {
                        fillGatewaySelects(data.gateways, {
                            gw_a_name: $("#wan_gw_a").val(),
                            gw_b_name: $("#wan_gw_b").val()
                        });
                    }

                    renderStatus(data.checkwan || {});
                    loadCronStatus();
                });
            }
        );
    });

    $("#btn_wan_refresh").click(function() {
        loadCheckWan();
    });

    loadCheckWan();
});
</script>

<div class="additional-page">
        <div id="checkwan_message" class="alert" style="display:none;"></div>

        <div class="checkwan-card">
            <div class="form-group">
                <label>
                    <input type="checkbox" id="wan_enabled">
                    Следить за статусом WAN интерфейсов
                </label>
            </div>

            <div id="wan_settings_form" class="checkwan-hidden">
                <div class="form-group">
                    <label for="wan_gw_a">WAN gateway A</label>
                    <select id="wan_gw_a" class="form-control checkwan-select"></select>
                </div>

                <div class="form-group">
                    <label for="wan_gw_b">WAN gateway B</label>
                    <select id="wan_gw_b" class="form-control checkwan-select"></select>
                </div>

                <div class="form-group">
                    <label for="wan_primary_priority">Primary priority</label>
                    <input type="text" id="wan_primary_priority" class="form-control checkwan-input" placeholder="11">
                </div>

                <div class="form-group">
                    <label for="wan_backup_priority">Backup priority</label>
                    <input type="text" id="wan_backup_priority" class="form-control checkwan-input" placeholder="12">
                </div>

                <div class="form-group">
                    <label for="wan_loss_limit">Loss limit, %</label>
                    <input type="text" id="wan_loss_limit" class="form-control checkwan-input" placeholder="30">
                </div>
            </div>

            <button id="btn_wan_save" type="button" class="btn btn-default">
                <i class="fa fa-save"></i>
                Сохранить настройки
            </button>

            <button id="btn_wan_run" type="button" class="btn btn-primary">
                <i class="fa fa-refresh"></i>
                Проверить сейчас
            </button>

            <button id="btn_wan_switch" type="button" class="btn btn-primary">
                <i class="fa fa-exchange"></i>
                Сменить приоритет
            </button>

            <button id="btn_wan_refresh" type="button" class="btn btn-default">
                <i class="fa fa-info-circle"></i>
                Обновить информацию
            </button>

            <div id="wan_status_section" class="checkwan-section">
                <table class="table table-condensed">
                    <tr>
                        <th style="width: 260px;">Статус Check WAN</th>
                        <td class="checkwan-value" id="wan_state">-</td>
                    </tr>
                    <tr>
                        <th>Сообщение</th>
                        <td id="wan_message">-</td>
                    </tr>
                    <tr>
                        <th>Последняя проверка</th>
                        <td id="wan_last_check">-</td>
                    </tr>
                    <tr>
                        <th>Задание Scheduler</th>
                        <td id="wan_cron_status">-</td>
                    </tr>
                    <tr>
                        <th>Решение</th>
                        <td id="wan_reason">-</td>
                    </tr>
                    <tr>
                        <th>Должен быть primary</th>
                        <td id="wan_desired_primary">-</td>
                    </tr>
                    <tr>
                        <th>Должен быть backup</th>
                        <td id="wan_desired_backup">-</td>
                    </tr>
                    <tr>
                        <th>Gateway A</th>
                        <td id="wan_gateway_a_status">-</td>
                    </tr>
                    <tr>
                        <th>Gateway B</th>
                        <td id="wan_gateway_b_status">-</td>
                    </tr>
                </table>
            </div>
        </div>
</div>
