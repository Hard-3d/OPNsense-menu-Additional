<style>
    .checkstatus-section {
        margin-top: 20px;
    }

    .checkstatus-value {
        font-weight: bold;
    }

    .checkstatus-url-wide {
        width: 820px !important;
        max-width: 100%;
    }

    .checkstatus-card {
        margin-top: 20px;
        padding: 20px;
        border: 1px solid rgba(255, 255, 255, 0.18);
        background: rgba(255, 255, 255, 0.02);
    }

    .checkstatus-card h2 {
        margin-top: 0;
        margin-bottom: 20px;
    }

    .checkstatus-card .table {
        margin-bottom: 0;
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
        var box = $("#checkstatus_message");
        box.removeClass("alert-success alert-danger alert-warning alert-info");
        box.addClass("alert-" + type);
        box.text(message);
        box.show();
    }

    function hideMessage() {
        $("#checkstatus_message").hide();
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
        if (!value || value.length === 0) {
            return "-";
        }
        if ($.isArray(value)) {
            return value.join(", ");
        }
        return String(value);
    }

    function renderWireGuardStatus(wg) {
        wg = wg || {};

        var stateText = "Неизвестно";
        var stateClass = "label-default";

        if (wg.state === "ok" || wg.ok === true) {
            stateText = "Работает";
            stateClass = "label-success";
        } else if (wg.state === "degraded_restarted") {
            stateText = "Была деградация, выполнен перезапуск";
            stateClass = "label-warning";
        } else if (wg.state === "no_targets") {
            stateText = "Нет объектов для проверки";
            stateClass = "label-info";
        } else if (wg.state === "error" || wg.ok === false) {
            stateText = "Ошибка";
            stateClass = "label-danger";
        }

        $("#wg_state").html('<span class="label ' + stateClass + '">' + stateText + '</span>');
        $("#wg_message").text(wg.message || "-");
        $("#wg_last_check").text(wg.timestamp || "-");
        $("#wg_watch_hosts").text(listText(wg.watch_hosts));
        $("#wg_watch_networks").text(listText(wg.watch_networks));
        $("#wg_missing_networks").text(listText(wg.missing_networks));
        $("#wg_unreachable_hosts").text(listText(wg.unreachable_hosts));
    }

    function renderTailscaleStatus(ts) {
        ts = ts || {};

        var stateText = "Неизвестно";
        var stateClass = "label-default";

        if (ts.state === "ok" || ts.ok === true) {
            stateText = "Работает";
            stateClass = "label-success";
        } else if (ts.state === "degraded_restarted_ok") {
            stateText = "Был недоступен, restart помог";
            stateClass = "label-warning";
        } else if (ts.state === "degraded_started_ok") {
            stateText = "Был недоступен, start помог";
            stateClass = "label-warning";
        } else if (ts.state === "degraded_restarted" || ts.state === "degraded_started") {
            stateText = "Недоступен после перезапуска";
            stateClass = "label-danger";
        } else if (ts.state === "error" || ts.ok === false) {
            stateText = "Ошибка";
            stateClass = "label-danger";
        }

        $("#ts_state").html('<span class="label ' + stateClass + '">' + stateText + '</span>');
        $("#ts_message").text(ts.message || "-");
        $("#ts_last_check").text(ts.timestamp || "-");
        $("#ts_check_ip_current").text(ts.check_ip || "-");
        $("#ts_service_action").text(ts.service_action || "-");
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

    function rowContainsWireGuardJob(row) {
        var text = JSON.stringify(row).toLowerCase();
        return text.indexOf("additional check status wireguard") !== -1 ||
            text.indexOf("additional_check_status wireguard") !== -1 ||
            text.indexOf("check-wg-status") !== -1 ||
            text.indexOf("check wg status") !== -1;
    }

    function rowContainsTailscaleJob(row) {
        var text = JSON.stringify(row).toLowerCase();
        return text.indexOf("additional check status tailscale") !== -1 ||
            text.indexOf("additional_check_status tailscale") !== -1 ||
            text.indexOf("check-tailscale-status") !== -1 ||
            text.indexOf("tailscale status") !== -1;
    }

    function renderCronStatus(selector, found) {
        if (!found) {
            $(selector).html('<span class="label label-danger">Нет</span>');
            return;
        }

        var enabled = isCronEnabled(found);
        var label = enabled ? "label-success" : "label-warning";
        var text = enabled ? "Есть, включено" : "Есть, но отключено";
        text += " — " + cronFrequency(found);
        $(selector).html('<span class="label ' + label + '">' + text + '</span>');
    }

    function loadCronStatus() {
        ajaxCall("/api/cron/settings/search_jobs", {
            current: 1,
            rowCount: -1,
            sort: {}
        }, function(data, status) {
            var rows = [];
            if (data && data.rows) {
                rows = data.rows;
            }

            var wgFound = null;
            var tsFound = null;

            for (var i = 0; i < rows.length; i++) {
                if (wgFound === null && rowContainsWireGuardJob(rows[i])) {
                    wgFound = rows[i];
                }
                if (tsFound === null && rowContainsTailscaleJob(rows[i])) {
                    tsFound = rows[i];
                }
            }

            renderCronStatus("#wg_cron_status", wgFound);
            renderCronStatus("#ts_cron_status", tsFound);
        });
    }

    function loadCheckStatus() {
        hideMessage();

        ajaxCall("/api/additional/checkstatus/get", {}, function(data, status) {
            if (data.status === "ok") {
                var config = data.config || {};
                $("#wg_check_ping").val(config.wireguard_check_ping || "");
                $("#ts_check_ping").val(config.tailscale_check_ping || "100.100.100.100");
                renderWireGuardStatus(data.wireguard || {});
                renderTailscaleStatus(data.tailscale || {});
                loadCronStatus();
            } else {
                showMessage("danger", data.message || "Ошибка загрузки");
            }
        });
    }

    $("#btn_wg_save").click(function() {
        showMessage("info", "Сохраняю настройки WireGuard...");
        ajaxCall("/api/additional/checkstatus/setwireguard", {
            wireguard_check_ping: $("#wg_check_ping").val()
        }, function(data, status) {
            if (data.status === "ok") {
                $("#wg_check_ping").val((data.config || {}).wireguard_check_ping || "");
                showMessage("success", data.message);
            } else {
                showMessage("danger", data.message || "Ошибка сохранения");
            }
        });
    });

    $("#btn_ts_save").click(function() {
        showMessage("info", "Сохраняю настройки Tailscale...");
        ajaxCall("/api/additional/checkstatus/settailscale", {
            tailscale_check_ping: $("#ts_check_ping").val()
        }, function(data, status) {
            if (data.status === "ok") {
                $("#ts_check_ping").val((data.config || {}).tailscale_check_ping || "100.100.100.100");
                showMessage("success", data.message);
            } else {
                showMessage("danger", data.message || "Ошибка сохранения");
            }
        });
    });

    $("#btn_wg_run").click(function() {
        showConfirmModal(
            "Проверка WireGuard",
            "Запустить проверку WireGuard сейчас?\nПри деградации WireGuard будет перезапущен.",
            "Проверить",
            function() {
                $("#btn_wg_run").prop("disabled", true);
                showMessage("info", "Выполняется проверка WireGuard...");

                ajaxCall("/api/additional/checkstatus/runwireguard", {}, function(data, status) {
                    $("#btn_wg_run").prop("disabled", false);

                    if (data.status === "ok") {
                        showMessage("success", data.message);
                    } else if (data.status === "warning") {
                        showMessage("warning", data.message);
                    } else {
                        showMessage("danger", data.message || "Ошибка проверки WireGuard");
                    }

                    renderWireGuardStatus(data.wireguard || {});
                    loadCronStatus();
                });
            }
        );
    });

    $("#btn_ts_run").click(function() {
        showConfirmModal(
            "Проверка Tailscale",
            "Запустить проверку Tailscale сейчас?\nЕсли ping не проходит, tailscaled будет запущен или перезапущен.",
            "Проверить",
            function() {
                $("#btn_ts_run").prop("disabled", true);
                showMessage("info", "Выполняется проверка Tailscale...");

                ajaxCall("/api/additional/checkstatus/runtailscale", {}, function(data, status) {
                    $("#btn_ts_run").prop("disabled", false);

                    if (data.status === "ok") {
                        showMessage("success", data.message);
                    } else if (data.status === "warning") {
                        showMessage("warning", data.message);
                    } else {
                        showMessage("danger", data.message || "Ошибка проверки Tailscale");
                    }

                    renderTailscaleStatus(data.tailscale || {});
                    loadCronStatus();
                });
            }
        );
    });

    $("#btn_wg_refresh, #btn_ts_refresh").click(function() {
        loadCheckStatus();
    });

    loadCheckStatus();
});
</script>

<div class="additional-page">
        <div id="checkstatus_message" class="alert" style="display:none;"></div>

        <div class="checkstatus-card">
            <h2>Status WireGuard</h2>

            <div class="form-group">
                <label for="wg_check_ping">IP для проверки</label>
                <input type="text"
                       id="wg_check_ping"
                       class="form-control checkstatus-url-wide"
                       spellcheck="false"
                       placeholder="172.16.0.1 или несколько через запятую">
            </div>

            <button id="btn_wg_save" type="button" class="btn btn-default">
                <i class="fa fa-save"></i>
                Сохранить IP
            </button>

            <button id="btn_wg_run" type="button" class="btn btn-primary">
                <i class="fa fa-refresh"></i>
                Проверить сейчас
            </button>

            <button id="btn_wg_refresh" type="button" class="btn btn-default">
                <i class="fa fa-info-circle"></i>
                Обновить информацию
            </button>

            <div class="checkstatus-section">
                <table class="table table-condensed">
                    <tr>
                        <th style="width: 260px;">Статус WireGuard</th>
                        <td class="checkstatus-value" id="wg_state">-</td>
                    </tr>
                    <tr>
                        <th>Сообщение</th>
                        <td id="wg_message">-</td>
                    </tr>
                    <tr>
                        <th>Последняя проверка</th>
                        <td id="wg_last_check">-</td>
                    </tr>
                    <tr>
                        <th>Задание Cron</th>
                        <td id="wg_cron_status">-</td>
                    </tr>
                    <tr>
                        <th>Проверяемые host IP</th>
                        <td id="wg_watch_hosts">-</td>
                    </tr>
                    <tr>
                        <th>Проверяемые маршруты</th>
                        <td id="wg_watch_networks">-</td>
                    </tr>
                    <tr>
                        <th>Отсутствующие маршруты</th>
                        <td id="wg_missing_networks">-</td>
                    </tr>
                    <tr>
                        <th>Недоступные host IP</th>
                        <td id="wg_unreachable_hosts">-</td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="checkstatus-card">
            <h2>Status Tailscale</h2>

            <div class="form-group">
                <label for="ts_check_ping">IP для проверки</label>
                <input type="text"
                       id="ts_check_ping"
                       class="form-control checkstatus-url-wide"
                       spellcheck="false"
                       placeholder="100.100.100.100">
            </div>

            <button id="btn_ts_save" type="button" class="btn btn-default">
                <i class="fa fa-save"></i>
                Сохранить IP
            </button>

            <button id="btn_ts_run" type="button" class="btn btn-primary">
                <i class="fa fa-refresh"></i>
                Проверить сейчас
            </button>

            <button id="btn_ts_refresh" type="button" class="btn btn-default">
                <i class="fa fa-info-circle"></i>
                Обновить информацию
            </button>

            <div class="checkstatus-section">
                <table class="table table-condensed">
                    <tr>
                        <th style="width: 260px;">Статус Tailscale</th>
                        <td class="checkstatus-value" id="ts_state">-</td>
                    </tr>
                    <tr>
                        <th>Сообщение</th>
                        <td id="ts_message">-</td>
                    </tr>
                    <tr>
                        <th>Последняя проверка</th>
                        <td id="ts_last_check">-</td>
                    </tr>
                    <tr>
                        <th>Задание Cron</th>
                        <td id="ts_cron_status">-</td>
                    </tr>
                    <tr>
                        <th>Проверяемый IP</th>
                        <td id="ts_check_ip_current">-</td>
                    </tr>
                    <tr>
                        <th>Действие с сервисом</th>
                        <td id="ts_service_action">-</td>
                    </tr>
                </table>
            </div>
        </div>
</div>
