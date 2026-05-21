<style>
    .geoip-section {
        margin-top: 20px;
    }

    .geoip-status-value {
        font-weight: normal;
    }

    .geoip-status-table td {
        font-weight: normal;
    }

    .geoip-source-box {
        max-width: 920px;
    }

    #geoip_base_url {
        width: 820px !important;
        max-width: 100% !important;
        display: block;
        font-family: Menlo, Monaco, Consolas, "Courier New", monospace;
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
        var box = $("#geoip_message");
        box.removeClass("alert-success alert-danger alert-warning alert-info");
        box.addClass("alert-" + type);
        box.text(message);
        box.show();
    }

    function hideMessage() {
        $("#geoip_message").hide();
    }

    function deepFindStats(obj) {
        var result = {
            address_count: null,
            timestamp: null,
            file_count: null
        };

        function walk(value) {
            if (value === null || value === undefined || typeof value !== "object") {
                return;
            }

            if (Object.prototype.hasOwnProperty.call(value, "address_count")) {
                result.address_count = value.address_count;
            }

            if (Object.prototype.hasOwnProperty.call(value, "timestamp")) {
                result.timestamp = value.timestamp;
            }

            if (Object.prototype.hasOwnProperty.call(value, "file_count")) {
                result.file_count = value.file_count;
            }

            Object.keys(value).forEach(function(key) {
                if (typeof value[key] === "object") {
                    walk(value[key]);
                }
            });
        }

        walk(obj);

        return {
            address_count: result.address_count !== null && result.address_count !== undefined ? result.address_count : 0,
            timestamp: result.timestamp || "",
            file_count: result.file_count !== null && result.file_count !== undefined ? result.file_count : ""
        };
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

    function normalizeGeoIpStats(data) {
        if (!data) {
            return {
                address_count: 0,
                timestamp: "",
                file_count: ""
            };
        }

        if (data.stats) {
            return deepFindStats(data.stats);
        }

        return deepFindStats(data);
    }

    function statsAreEmpty(stats) {
        var count = parseInt(stats.address_count || 0, 10);
        return !count || count <= 0;
    }

    function renderStats(stats) {
        $("#geoip_address_count").text(stats.address_count || "0");
        $("#geoip_timestamp").text(stats.timestamp || "-");

        if (stats.file_count !== "" && stats.file_count !== null && stats.file_count !== undefined) {
            $("#geoip_file_count").text(stats.file_count);
        } else {
            $("#geoip_file_count").text("-");
        }
    }

    function loadAdditionalStatus() {
        ajaxCall("/api/additional/geoip/status", {}, function(data, status) {
            if (data && data.status === "ok") {
                renderStats(normalizeGeoIpStats(data));
            }
        });
    }

    function loadCoreGeoIpStatus() {
        $.ajax({
            url: "/api/firewall/alias/get_geo_ip",
            type: "GET",
            dataType: "json",
            success: function(data) {
                var stats = normalizeGeoIpStats(data);
                renderStats(stats);

                if (statsAreEmpty(stats)) {
                    loadAdditionalStatus();
                }
            },
            error: function() {
                loadAdditionalStatus();
            }
        });
    }

    function stringifyDeep(value) {
        var parts = [];

        function walk(v) {
            if (v === null || v === undefined) {
                return;
            }
            if (typeof v === "object") {
                Object.keys(v).forEach(function(key) {
                    walk(v[key]);
                });
                return;
            }
            parts.push(String(v));
        }

        walk(value);
        return parts.join(" ").toLowerCase();
    }

    function rowMatchesGeoIpCron(row) {
        var text = stringifyDeep(row);

        return text.indexOf("additional geoip update") !== -1 ||
               text.indexOf("additional_geoip") !== -1 ||
               text.indexOf("updategeoip") !== -1;
    }

    function cronRowIsEnabled(row) {
        if (!row) {
            return false;
        }

        var disabled = row.disabled;
        var enabled = row.enabled;

        if (disabled === true || disabled === "1" || disabled === 1 || String(disabled).toLowerCase() === "true") {
            return false;
        }

        if (enabled === false || enabled === "0" || enabled === 0 || String(enabled).toLowerCase() === "false") {
            return false;
        }

        return true;
    }

    function firstValue(row, names, fallback) {
        var value;

        names.forEach(function(name) {
            if (value === undefined && row && Object.prototype.hasOwnProperty.call(row, name)) {
                value = row[name];
            }
        });

        if (value === undefined || value === null || value === "") {
            return fallback;
        }

        return String(value).trim();
    }

    function normalizeCronPart(value) {
        value = String(value || "*").trim();

        if (value === "") {
            return "*";
        }

        return value;
    }

    function pad2(value) {
        value = parseInt(value, 10);

        if (isNaN(value)) {
            return "00";
        }

        return value < 10 ? "0" + value : String(value);
    }

    function isEvery(value) {
        value = normalizeCronPart(value);
        return value === "*" || value === "*/1" || value === "0-59/1";
    }

    function isSingleNumber(value) {
        return /^\d+$/.test(normalizeCronPart(value));
    }

    function stepValue(value) {
        var match = normalizeCronPart(value).match(/^\*\/(\d+)$/);
        return match ? parseInt(match[1], 10) : null;
    }

    function monthName(value) {
        var map = {
            "1": "январь",
            "2": "февраль",
            "3": "март",
            "4": "апрель",
            "5": "май",
            "6": "июнь",
            "7": "июль",
            "8": "август",
            "9": "сентябрь",
            "10": "октябрь",
            "11": "ноябрь",
            "12": "декабрь"
        };

        return map[String(parseInt(value, 10))] || value;
    }

    function weekdayName(value) {
        var map = {
            "0": "воскресенье",
            "1": "понедельник",
            "2": "вторник",
            "3": "среда",
            "4": "четверг",
            "5": "пятница",
            "6": "суббота",
            "7": "воскресенье",
            "sun": "воскресенье",
            "mon": "понедельник",
            "tue": "вторник",
            "wed": "среда",
            "thu": "четверг",
            "fri": "пятница",
            "sat": "суббота"
        };

        var key = String(value).trim().toLowerCase();
        return map[key] || value;
    }

    function listDescription(value, mapper) {
        value = normalizeCronPart(value);

        if (isEvery(value)) {
            return "";
        }

        if (value.indexOf(",") !== -1) {
            return value.split(",").map(function(part) {
                return mapper(part.trim());
            }).join(", ");
        }

        return mapper(value);
    }

    function formatTime(hour, minute) {
        return pad2(hour) + ":" + pad2(minute);
    }

    function describeCronSchedule(row) {
        var minute = normalizeCronPart(firstValue(row, ["minutes", "minute"], "*"));
        var hour = normalizeCronPart(firstValue(row, ["hours", "hour"], "*"));
        var day = normalizeCronPart(firstValue(row, ["days", "day", "day_of_month"], "*"));
        var month = normalizeCronPart(firstValue(row, ["months", "month"], "*"));
        var weekday = normalizeCronPart(firstValue(row, ["weekdays", "weekday", "day_of_week"], "*"));

        var minuteStep = stepValue(minute);
        var hourStep = stepValue(hour);

        if (isEvery(minute) && isEvery(hour) && isEvery(day) && isEvery(month) && isEvery(weekday)) {
            return "каждую минуту";
        }

        if (minuteStep !== null && isEvery(hour) && isEvery(day) && isEvery(month) && isEvery(weekday)) {
            if (minuteStep === 1) {
                return "каждую минуту";
            }
            return "каждые " + minuteStep + " мин.";
        }

        if (isSingleNumber(minute) && isEvery(hour) && isEvery(day) && isEvery(month) && isEvery(weekday)) {
            return "каждый час в " + pad2(minute) + " мин.";
        }

        if (isSingleNumber(minute) && hourStep !== null && isEvery(day) && isEvery(month) && isEvery(weekday)) {
            if (hourStep === 1) {
                return "каждый час в " + pad2(minute) + " мин.";
            }
            return "каждые " + hourStep + " ч. в " + pad2(minute) + " мин.";
        }

        if (isSingleNumber(minute) && isSingleNumber(hour) && isEvery(day) && isEvery(month) && isEvery(weekday)) {
            return "каждый день в " + formatTime(hour, minute);
        }

        if (isSingleNumber(minute) && isSingleNumber(hour) && isEvery(day) && isEvery(month) && !isEvery(weekday)) {
            return "каждую неделю: " + listDescription(weekday, weekdayName) + " в " + formatTime(hour, minute);
        }

        if (isSingleNumber(minute) && isSingleNumber(hour) && !isEvery(day) && isEvery(month) && isEvery(weekday)) {
            return "каждый месяц, день " + day + " в " + formatTime(hour, minute);
        }

        if (isSingleNumber(minute) && isSingleNumber(hour) && !isEvery(day) && !isEvery(month) && isEvery(weekday)) {
            return "ежегодно: " + day + " " + listDescription(month, monthName) + " в " + formatTime(hour, minute);
        }

        if (isSingleNumber(minute) && hour.indexOf(",") !== -1 && isEvery(day) && isEvery(month) && isEvery(weekday)) {
            return "каждый день в часы " + hour + ":" + pad2(minute);
        }

        return "cron: " + minute + " " + hour + " " + day + " " + month + " " + weekday;
    }

    function renderCronStatus(foundRows, enabledRows) {
        if (foundRows.length === 0) {
            $("#geoip_cron_status").html('<span class="label label-danger">Нет</span>');
            return;
        }

        var row = enabledRows.length > 0 ? enabledRows[0] : foundRows[0];
        var labelClass = enabledRows.length > 0 ? "label-success" : "label-warning";
        var prefix = enabledRows.length > 0 ? "Есть, включено" : "Есть, но отключено";
        var schedule = describeCronSchedule(row);

        $("#geoip_cron_status").html(
            '<span class="label ' + labelClass + '">' + prefix + " — " + schedule + '</span>'
        );
    }

    function loadCronStatus() {
        $("#geoip_cron_status").html('<span class="label label-info">Проверка...</span>');

        ajaxCall("/api/cron/settings/search_jobs", {
            current: 1,
            rowCount: -1,
            sort: {}
        }, function(data, status) {
            var rows = [];
            var foundRows = [];
            var enabledRows = [];

            if (data && $.isArray(data.rows)) {
                rows = data.rows;
            }

            rows.forEach(function(row) {
                if (rowMatchesGeoIpCron(row)) {
                    foundRows.push(row);
                    if (cronRowIsEnabled(row)) {
                        enabledRows.push(row);
                    }
                }
            });

            renderCronStatus(foundRows, enabledRows);
        });
    }

    function loadConfig() {
        hideMessage();

        ajaxCall("/api/additional/geoip/get", {}, function(data, status) {
            if (data.status === "ok") {
                $("#geoip_base_url").val(data.base_url);
                renderStats(normalizeGeoIpStats(data));
                loadCoreGeoIpStatus();
                loadCronStatus();
            } else {
                showMessage("danger", data.message || "Ошибка загрузки настроек");
            }
        });
    }

    $("#btn_geoip_save_url").click(function() {
        var baseUrl = $("#geoip_base_url").val();

        showMessage("info", "Сохраняю URL...");

        ajaxCall("/api/additional/geoip/set", {
            base_url: baseUrl
        }, function(data, status) {
            if (data.status === "ok") {
                $("#geoip_base_url").val(data.base_url);
                showMessage("success", data.message);
            } else {
                showMessage("danger", data.message || "Ошибка сохранения URL");
            }
        });
    });

    $("#btn_geoip_update").click(function() {
        var baseUrl = $("#geoip_base_url").val();

        showConfirmModal(
            "Обновление GeoIP",
            "Запустить обновление GeoIP баз?\nПроцесс может занять несколько минут.",
            "Обновить",
            function() {
                $("#btn_geoip_update").prop("disabled", true);
                showMessage("info", "Идёт обновление GeoIP. Дождитесь завершения...");

                ajaxCall("/api/additional/geoip/update", {
                    base_url: baseUrl
                }, function(data, status) {
                    $("#btn_geoip_update").prop("disabled", false);

                    if (data.status === "ok") {
                        showMessage("success", data.message);
                        renderStats(normalizeGeoIpStats(data));
                        loadCoreGeoIpStatus();
                        loadCronStatus();
                    } else {
                        showMessage("danger", data.message || "Ошибка обновления GeoIP");
                    }
                });
            }
        );
    });

    loadConfig();
});
</script>

<div class="additional-page">
        <div id="geoip_message" class="alert" style="display:none;"></div>

        <div class="geoip-section geoip-source-box">
            <h2>Источник баз</h2>

            <div class="form-group">
                <label for="geoip_base_url">Base URL</label>
                <input type="text"
                       id="geoip_base_url"
                       class="form-control"
                       style="width: 820px !important; max-width: 100% !important; display: block;"
                       spellcheck="false"
                       placeholder="https://github.com/mamamialezatoz/geoip-database/releases/latest/download/">
            </div>

            <button id="btn_geoip_save_url" type="button" class="btn btn-default">
                <i class="fa fa-save"></i>
                Сохранить URL
            </button>

            <button id="btn_geoip_update" type="button" class="btn btn-primary">
                <i class="fa fa-refresh"></i>
                Обновить
            </button>
        </div>

        <div class="geoip-section">
            <h2>Текущее состояние GeoIP</h2>

            <table class="table table-condensed geoip-status-table">
                <tr>
                    <th style="width: 260px;">Total number of ranges</th>
                    <td id="geoip_address_count">-</td>
                </tr>
                <tr>
                    <th>Последнее обновление</th>
                    <td id="geoip_timestamp">-</td>
                </tr>
                <tr>
                    <th>Alias files</th>
                    <td id="geoip_file_count">-</td>
                </tr>
                <tr>
                    <th>Задание Cron</th>
                    <td id="geoip_cron_status">-</td>
                </tr>
            </table>
        </div>
</div>
