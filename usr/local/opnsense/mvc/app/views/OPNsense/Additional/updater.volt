<style>
    .additional-page {
        padding: 0 20px 24px 20px;
        max-width: 100%;
        box-sizing: border-box;
        overflow: visible;
    }

    .updater-section {
        margin-top: 18px;
        padding: 18px 20px;
        border: 1px solid rgba(255, 255, 255, 0.18);
        background: rgba(255, 255, 255, 0.025);
        box-sizing: border-box;
        max-width: 100%;
        overflow-x: auto;
    }

    .updater-section h2 {
        margin-top: 0;
        margin-bottom: 16px;
        font-size: 16px;
        font-weight: 700;
    }

    .updater-input-wide {
        width: 820px !important;
        min-width: 620px !important;
        max-width: 100% !important;
        display: block;
        font-family: Menlo, Monaco, Consolas, "Courier New", monospace;
    }

    .additional-page .table {
        width: 100%;
        margin-bottom: 0;
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
</style>

<script>
$(document).ready(function() {
    function showMessage(type, message) {
        var box = $("#updater_message");
        box.removeClass("alert-success alert-danger alert-warning alert-info");
        box.addClass("alert-" + type);
        box.text(message);
        box.show();
    }

    function hideMessage() {
        $("#updater_message").hide();
    }


    function loadUpdaterSchedulerStatus() {
        ajaxCall("/api/additional/scheduler/status", {}, function(data, status) {
            var task = (((data || {}).config || {}).tasks || {}).update_check || {};

            if (task.enabled === "1") {
                $("#updater_scheduler_status").html('<span class="label label-success">Включено — ' + (task.schedule_text || "-") + '</span>');
            } else {
                $("#updater_scheduler_status").html('<span class="label label-default">Отключено</span>');
            }
        });
    }

    function showConfirmModal(title, message, confirmText, onConfirm) {
        var modalId = "additional_confirm_modal";
        var styleId = "additional_confirm_modal_styles";

        if ($("#" + styleId).length === 0) {
            $("head").append(
                '<style id="' + styleId + '">' +
                    '#' + modalId + ' .modal-backdrop, .modal-backdrop.in { opacity: 0.78 !important; }' +
                    '#' + modalId + ' .modal-dialog { margin-top: 90px; }' +
                    '#' + modalId + ' .modal-content { background: #101722; border: 2px solid #f47c20; border-radius: 8px; box-shadow: 0 16px 48px rgba(0,0,0,0.65); }' +
                    '#' + modalId + ' .modal-header { background: #141d2b; border-bottom: 1px solid #f47c20; border-top-left-radius: 6px; border-top-right-radius: 6px; padding: 16px 18px; }' +
                    '#' + modalId + ' .modal-title { color: #ffffff; font-weight: 700; font-size: 24px; }' +
                    '#' + modalId + ' .close { color: #ffffff; opacity: 0.85; text-shadow: none; }' +
                    '#' + modalId + ' .close:hover { opacity: 1; }' +
                    '#' + modalId + ' .modal-body { background: #101722; color: #e7edf5; padding: 18px; font-size: 16px; line-height: 1.5; }' +
                    '#' + modalId + ' .modal-footer { background: #101722; border-top: 1px solid rgba(244,124,32,0.35); padding: 14px 18px 18px; }' +
                    '#' + modalId + ' .btn-default { border-color: #7d8793; color: #ffffff; background: transparent; }' +
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

    function renderUpdateState(data) {
        data = data || {};

        $("#updater_current_version").text(data.current_version || "-");
        $("#updater_latest_version").text(data.latest_version || "-");
        $("#updater_last_check").text(data.timestamp || "-");
        $("#updater_release_url").text(data.release_url || "-");
        $("#updater_release_tag").text(data.release_tag || "-");
        $("#updater_release_name").text(data.release_name || "-");
        $("#updater_download").text(data.download_name || data.download_type || "-");

        if (data.status === "error") {
            $("#updater_state").html('<span class="label label-danger">Ошибка</span>');
            $("#updater_message_text").text(data.message || "-");
            return;
        }

        if (data.update_available === true) {
            $("#updater_state").html('<span class="label label-warning">Доступно обновление</span>');
        } else if (data.update_available === false) {
            $("#updater_state").html('<span class="label label-success">Актуальная версия</span>');
        } else {
            $("#updater_state").html('<span class="label label-default">Не проверялось</span>');
        }

        if (data.update_available === false && data.message === "Обновление установлено") {
            $("#updater_message_text").text("Обновление установлено. Установлена актуальная версия");
        } else {
            $("#updater_message_text").text(data.message || "-");
        }
    }

    function loadUpdater() {
        hideMessage();

        ajaxCall("/api/additional/updater/get", {}, function(data, status) {
            if (data.status === "ok") {
                var config = data.config || {};
                $("#updater_repo_url").val(config.repo_url || "");
                $("#updater_asset_name").val(config.asset_name || "");
                $("#updater_auto_update").prop("checked", config.auto_update === "1" || config.auto_update === 1 || config.auto_update === true);
                $("#updater_auto_update_status").html(
                    (config.auto_update === "1" || config.auto_update === 1 || config.auto_update === true)
                        ? '<span class="label label-warning">Включено</span>'
                        : '<span class="label label-default">Отключено</span>'
                );
                renderUpdateState(data.updater || { current_version: data.current_version });
                loadUpdaterSchedulerStatus();
            } else {
                showMessage("danger", data.message || "Ошибка загрузки Update");
            }
        });
    }

    $("#btn_updater_save").click(function() {
        showMessage("info", "Сохраняю настройки Update...");

        ajaxCall("/api/additional/updater/set", {
            repo_url: $("#updater_repo_url").val(),
            asset_name: $("#updater_asset_name").val(),
            auto_update: $("#updater_auto_update").is(":checked") ? "1" : "0"
        }, function(data, status) {
            if (data.status === "ok") {
                showMessage("success", data.message);
                var config = data.config || {};
                $("#updater_auto_update_status").html(
                    (config.auto_update === "1" || config.auto_update === 1 || config.auto_update === true)
                        ? '<span class="label label-warning">Включено</span>'
                        : '<span class="label label-default">Отключено</span>'
                );
                renderUpdateState(data.updater || {});
            } else {
                showMessage("danger", data.message || "Ошибка сохранения настроек Update");
            }
        });
    });

    $("#btn_updater_check").click(function() {
        $("#btn_updater_check").prop("disabled", true);
        showMessage("info", "Проверяю обновления на GitHub...");

        ajaxCall("/api/additional/updater/check", {}, function(data, status) {
            $("#btn_updater_check").prop("disabled", false);

            if (data.status === "ok") {
                showMessage("success", data.message || "Проверка выполнена");
            } else {
                showMessage("danger", data.message || "Ошибка проверки обновлений");
            }

            renderUpdateState(data.updater || {});
        });
    });

    $("#btn_updater_update").click(function() {
        showConfirmModal(
            "Обновление меню Дополнительно",
            "Установить последнюю версию из GitHub?\\nФайлы меню Дополнительно будут обновлены.",
            "Обновить",
            function() {
                $("#btn_updater_update").prop("disabled", true);
                showMessage("info", "Идёт обновление. Дождитесь завершения...");

                ajaxCall("/api/additional/updater/update", {}, function(data, status) {
                    $("#btn_updater_update").prop("disabled", false);

                    if (data.status === "ok") {
                        showMessage("success", (data.message || "Обновление выполнено") + ". Страница обновится через 5 секунд.");
                        renderUpdateState(data.updater || {});
                        setTimeout(function() {
                            window.location.reload();
                        }, 5000);
                    } else {
                        showMessage("danger", data.message || "Ошибка обновления");
                        renderUpdateState(data.updater || {});
                    }
                });
            }
        );
    });

    loadUpdater();
});
</script>

<div class="additional-page">
    <div id="updater_message" class="alert" style="display:none;"></div>

    <div class="updater-section">
        <h2>GitHub repository</h2>

        <div class="form-group">
            <label for="updater_repo_url">Repository URL</label>
            <input type="text"
                   id="updater_repo_url"
                   class="form-control updater-input-wide"
                   style="width: 820px !important; max-width: 100%;"
                   spellcheck="false"
                   placeholder="https://github.com/OWNER/REPO">
        </div>

        <div class="form-group">
            <label for="updater_asset_name">Release asset name</label>
            <input type="text"
                   id="updater_asset_name"
                   class="form-control updater-input-wide"
                   style="width: 820px !important; max-width: 100%;"
                   spellcheck="false"
                   placeholder="Например: opnsense-additional-full-menu-root.zip. Можно оставить пустым для source zip.">
        </div>

        <div class="form-group">
            <label>
                <input type="checkbox" id="updater_auto_update">
                Обновлять автоматически при проверке Scheduler
            </label>
            <div class="help-block">
                Если включено, задача Scheduler «Update check» при обнаружении новой версии автоматически установит обновление.
            </div>
        </div>

        <button id="btn_updater_save" type="button" class="btn btn-default">
            <i class="fa fa-save"></i>
            Сохранить настройки
        </button>

        <button id="btn_updater_check" type="button" class="btn btn-primary">
            <i class="fa fa-search"></i>
            Проверить обновление
        </button>

        <button id="btn_updater_update" type="button" class="btn btn-primary">
            <i class="fa fa-refresh"></i>
            Обновить
        </button>
    </div>

    <div class="updater-section">
        <h2>Текущее состояние</h2>

        <table class="table table-condensed">
            <tr>
                <th>Текущая версия</th>
                <td id="updater_current_version">-</td>
            </tr>
            <tr>
                <th>Последняя версия GitHub</th>
                <td id="updater_latest_version">-</td>
            </tr>
            <tr>
                <th>Статус</th>
                <td id="updater_state">-</td>
            </tr>
            <tr>
                <th>Сообщение</th>
                <td id="updater_message_text">-</td>
            </tr>
            <tr>
                <th>Последняя проверка</th>
                <td id="updater_last_check">-</td>
            </tr>
            <tr>
                <th>Задание Scheduler</th>
                <td id="updater_scheduler_status">-</td>
            </tr>
            <tr>
                <th>Автообновление</th>
                <td id="updater_auto_update_status">-</td>
            </tr>
            <tr>
                <th>Release tag</th>
                <td id="updater_release_tag">-</td>
            </tr>
            <tr>
                <th>Release name</th>
                <td id="updater_release_name">-</td>
            </tr>
            <tr>
                <th>Release URL</th>
                <td id="updater_release_url">-</td>
            </tr>
            <tr>
                <th>Источник загрузки</th>
                <td id="updater_download">-</td>
            </tr>
        </table>
    </div>
</div>
