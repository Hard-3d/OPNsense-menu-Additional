<style>
    .ethname-toolbar { margin-bottom: 15px; }
    .ethname-table input { font-family: Menlo, Monaco, Consolas, "Courier New", monospace; }
    .ethname-preview { font-family: Menlo, Monaco, Consolas, "Courier New", monospace; font-size: 13px; min-height: 220px; white-space: pre; }
    .ethname-section { margin-top: 20px; }

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
        var box = $("#ethname_message");
        box.removeClass("alert-success alert-danger alert-warning alert-info");
        box.addClass("alert-" + type);
        box.text(message);
        box.show();
    }

    function hideMessage() {
        $("#ethname_message").hide();
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

    function normalizeEnable(value) {
        value = String(value || "").replace(/"/g, "").trim().toUpperCase();
        return (value === "YES" || value === "TRUE" || value === "1" || value === "ON") ? "YES" : "NO";
    }

    function naturalInterfaceCompare(a, b) {
        var ma = String(a).match(/^([a-zA-Z_]+)(\d+)$/);
        var mb = String(b).match(/^([a-zA-Z_]+)(\d+)$/);
        if (ma && mb && ma[1] === mb[1]) {
            return parseInt(ma[2], 10) - parseInt(mb[2], 10);
        }
        return String(a).localeCompare(String(b));
    }

    function parseEthname(content) {
        var result = { enable: "NO", timeout: "30", interfaces: [] };
        var lines = String(content || "").split(/\r?\n/);

        lines.forEach(function(line) {
            var trimmed = line.trim();
            if (trimmed === "") { return; }

            var enableMatch = trimmed.match(/^ethname_enable\s*=\s*"?([^"]+)"?\s*$/);
            if (enableMatch) {
                result.enable = normalizeEnable(enableMatch[1]);
                return;
            }

            var timeoutMatch = trimmed.match(/^ethname_timeout\s*=\s*"?([^"]+)"?\s*$/);
            if (timeoutMatch) {
                result.timeout = timeoutMatch[1].trim();
                return;
            }

            var macMatch = trimmed.match(/^ethname_([A-Za-z0-9_.:-]+)_mac\s*=\s*"([^"]*)"\s*$/);
            if (macMatch) {
                result.interfaces.push({ name: macMatch[1], mac: macMatch[2] });
            }
        });

        result.interfaces.sort(function(a, b) { return naturalInterfaceCompare(a.name, b.name); });
        return result;
    }

    function renderRows(interfaces) {
        $("#ethname_interfaces tbody").empty();
        interfaces.forEach(function(item) { addInterfaceRow(item.name, item.mac); });
        if (interfaces.length === 0) { addInterfaceRow("vmx1", ""); }
    }

    function addInterfaceRow(name, mac) {
        var row = $("<tr>");
        var nameInput = $("<input>").attr("type", "text").addClass("form-control ethname-ifname").attr("placeholder", "vmx1").val(name || "");
        var macInput = $("<input>").attr("type", "text").addClass("form-control ethname-mac").attr("placeholder", "00:50:56:aa:bb:cc").val(mac || "");
        var removeBtn = $("<button>").attr("type", "button").addClass("btn btn-xs btn-danger ethname-remove-row").html('<i class="fa fa-trash"></i> Удалить');
        row.append($("<td>").append(nameInput));
        row.append($("<td>").append(macInput));
        row.append($("<td>").css("width", "110px").append(removeBtn));
        $("#ethname_interfaces tbody").append(row);
        updatePreview();
    }

    function getNextInterfaceName() {
        var maxNumber = 0;
        var prefix = "vmx";
        $(".ethname-ifname").each(function() {
            var name = $(this).val().trim();
            var match = name.match(/^([a-zA-Z_]+)(\d+)$/);
            if (match) {
                prefix = match[1];
                var number = parseInt(match[2], 10);
                if (number > maxNumber) { maxNumber = number; }
            }
        });
        return prefix + String(maxNumber + 1);
    }

    function collectInterfaces() {
        var interfaces = [];
        $("#ethname_interfaces tbody tr").each(function() {
            var name = $(this).find(".ethname-ifname").val().trim();
            var mac = $(this).find(".ethname-mac").val().trim();
            if (name === "" && mac === "") { return; }
            interfaces.push({ name: name, mac: mac });
        });
        interfaces.sort(function(a, b) { return naturalInterfaceCompare(a.name, b.name); });
        return interfaces;
    }

    function validateForm() {
        var errors = [];
        var names = {};
        var macRegex = /^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/;
        var timeout = $("#ethname_timeout").val().trim();
        if (!/^\d+$/.test(timeout)) { errors.push("Timeout должен быть числом."); }

        collectInterfaces().forEach(function(item) {
            if (item.name === "") { errors.push("Есть строка без имени интерфейса."); }
            if (!/^[A-Za-z0-9_.:-]+$/.test(item.name)) { errors.push("Некорректное имя интерфейса: " + item.name); }
            if (names[item.name]) { errors.push("Дублируется интерфейс: " + item.name); }
            names[item.name] = true;
            if (item.mac === "") {
                errors.push("У интерфейса " + item.name + " не указан MAC.");
            } else if (!macRegex.test(item.mac)) {
                errors.push("Некорректный MAC у " + item.name + ": " + item.mac);
            }
        });

        return errors;
    }

    function buildEthnameContent() {
        var lines = [];
        var enableValue = $("#ethname_enable").is(":checked") ? "YES" : "NO";
        var timeout = $("#ethname_timeout").val().trim();
        lines.push('ethname_enable="' + enableValue + '"');
        lines.push("ethname_timeout=" + timeout);

        collectInterfaces().forEach(function(item) {
            var name = item.name.trim();
            var mac = item.mac.trim().toLowerCase();
            if (name !== "" || mac !== "") {
                lines.push('ethname_' + name + '_mac="' + mac + '"');
            }
        });

        return lines.join("\n") + "\n";
    }

    function updatePreview() {
        $("#ethname_preview").val(buildEthnameContent());
    }

    function loadEthname() {
        hideMessage();
        ajaxCall("/api/additional/ethname/get", {}, function(data, status) {
            if (data.status === "ok") {
                var parsed = parseEthname(data.content);
                $("#ethname_enable").prop("checked", parsed.enable === "YES");
                $("#ethname_timeout").val(parsed.timeout);
                renderRows(parsed.interfaces);
                $("#ethname_path").text(data.path);
                $("#ethname_size").text(data.size + " bytes");
                updatePreview();
                hideMessage();
            } else {
                $("#ethname_path").text(data.path || "/etc/rc.conf.d/ethname");
                $("#ethname_size").text("-");
                showMessage("danger", data.message || "Ошибка загрузки");
            }
        });
    }

    $("#btn_ethname_reload").click(function() { loadEthname(); });
    $("#btn_ethname_add").click(function() { addInterfaceRow(getNextInterfaceName(), ""); });
    $("#btn_ethname_sort").click(function() { renderRows(collectInterfaces()); });
    $("#ethname_interfaces").on("click", ".ethname-remove-row", function() { $(this).closest("tr").remove(); updatePreview(); });
    $("#ethname_enable, #ethname_timeout").on("change keyup", function() { updatePreview(); });
    $("#ethname_interfaces").on("change keyup", "input", function() { updatePreview(); });

    $("#btn_ethname_save").click(function() {
        var errors = validateForm();
        if (errors.length > 0) {
            showMessage("danger", errors.join(" "));
            return;
        }

        showConfirmModal(
            "Сохранение Ethname",
            "Сохранить изменения в /etc/rc.conf.d/ethname?",
            "Сохранить",
            function() {
                showMessage("info", "Сохранение файла...");
                ajaxCall("/api/additional/ethname/set", { content: buildEthnameContent() }, function(data, status) {
                    if (data.status === "ok") {
                        showMessage("success", data.message + ". Backup: " + data.backup);
                        $("#ethname_size").text(data.size + " bytes");
                    } else {
                        showMessage("danger", data.message || "Ошибка сохранения");
                    }
                });
            }
        );
    });

    loadEthname();
});
</script>

<div class="additional-page">
        <div id="ethname_message" class="alert" style="display:none;"></div>

        <div class="ethname-section">
            <h2>Файл</h2>
            <table class="table table-condensed">
            <tr>
                <th style="width: 180px;">Файл</th>
                <td id="ethname_path">/etc/rc.conf.d/ethname</td>
            </tr>
            <tr>
                <th>Размер</th>
                <td id="ethname_size">-</td>
            </tr>
            </table>
        </div>

        <div class="ethname-section">
            <h2>Основные параметры</h2>
            <div class="form-group">
                <label><input type="checkbox" id="ethname_enable"> Включить ethname</label>
            </div>
            <div class="form-group">
                <label for="ethname_timeout">Timeout</label>
                <input type="text" id="ethname_timeout" class="form-control" style="max-width: 200px;" placeholder="30">
            </div>
        </div>

        <div class="ethname-section">
            <h2>Интерфейсы и MAC-адреса</h2>
            <div class="ethname-toolbar">
                <button id="btn_ethname_add" type="button" class="btn btn-primary"><i class="fa fa-plus"></i> Добавить строку</button>
                <button id="btn_ethname_sort" type="button" class="btn btn-default"><i class="fa fa-sort"></i> Сортировать</button>
            </div>
            <table id="ethname_interfaces" class="table table-striped table-condensed ethname-table">
                <thead>
                    <tr>
                        <th style="width: 220px;">Имя интерфейса</th>
                        <th>MAC-адрес</th>
                        <th style="width: 110px;"></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <div class="ethname-section">
            <h2>Предпросмотр файла</h2>
            <textarea id="ethname_preview" class="form-control ethname-preview" rows="12" readonly></textarea>
        </div>

        <div class="ethname-section">
            <button id="btn_ethname_save" class="btn btn-primary"><i class="fa fa-save"></i> Сохранить</button>
            <button id="btn_ethname_reload" class="btn btn-default"><i class="fa fa-refresh"></i> Перезагрузить из файла</button>
        </div>
</div>
