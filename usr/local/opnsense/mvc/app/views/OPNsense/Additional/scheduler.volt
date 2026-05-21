<style>
    .additional-page {
        padding: 0 20px 24px 20px;
        max-width: 100%;
        box-sizing: border-box;
        overflow: visible;
    }

    .scheduler-section {
        margin-top: 18px;
        padding: 18px 20px;
        border: 1px solid rgba(255, 255, 255, 0.18);
        background: rgba(255, 255, 255, 0.025);
        box-sizing: border-box;
        max-width: 100%;
        overflow-x: auto;
    }

    .scheduler-section h2 {
        margin-top: 0;
        margin-bottom: 16px;
        font-size: 16px;
        font-weight: 700;
    }

    .scheduler-table input[type="text"],
    .scheduler-table select {
        max-width: 160px;
    }

    .scheduler-table .task-title {
        font-weight: 700;
        white-space: nowrap;
    }

    .additional-page .btn {
        margin-right: 4px;
        margin-bottom: 6px;
    }

    .additional-page .table {
        margin-bottom: 0;
    }
</style>

<script>
$(document).ready(function() {
    var taskOrder = ["geoip_update", "wireguard_check", "tailscale_check", "check_wan", "update_check"];

    function showMessage(type, message) {
        var box = $("#scheduler_message");
        box.removeClass("alert-success alert-danger alert-warning alert-info");
        box.addClass("alert-" + type);
        box.text(message);
        box.show();
    }

    function hideMessage() {
        $("#scheduler_message").hide();
    }

    function taskStatusLabel(task) {
        if (!task || task.enabled !== "1") {
            return '<span class="label label-default">Отключено</span>';
        }

        return '<span class="label label-success">Включено — ' + (task.schedule_text || "-") + '</span>';
    }

    function lastStatusLabel(task) {
        if (!task || !task.last_status) {
            return '<span class="label label-default">Не запускалось</span>';
        }

        if (task.last_status === "ok") {
            return '<span class="label label-success">OK</span>';
        }

        return '<span class="label label-danger">Ошибка</span>';
    }

    function renderTasks(config) {
        var tbody = $("#scheduler_tasks tbody");
        tbody.empty();

        var tasks = (config || {}).tasks || {};

        taskOrder.forEach(function(taskId) {
            var task = tasks[taskId] || {};
            var title = task.title || taskId;

            var tr = $("<tr>").attr("data-task-id", taskId);

            var enabled = $("<input>")
                .attr("type", "checkbox")
                .addClass("scheduler-enabled")
                .prop("checked", task.enabled === "1");

            var mode = $("<select>").addClass("form-control scheduler-mode");
            mode.append($("<option>").attr("value", "interval").text("Интервал"));
            mode.append($("<option>").attr("value", "daily").text("Ежедневно"));
            mode.val(task.mode || "interval");

            var minutes = $("<input>")
                .attr("type", "text")
                .addClass("form-control scheduler-minutes")
                .val(task.interval_minutes || "60");

            var time = $("<input>")
                .attr("type", "text")
                .addClass("form-control scheduler-time")
                .attr("placeholder", "05:00")
                .val(task.time || "00:00");

            var runButton = $("<button>")
                .attr("type", "button")
                .addClass("btn btn-xs btn-primary scheduler-run-task")
                .html('<i class="fa fa-play"></i> Запустить');

            tr.append($("<td>").append(enabled));
            tr.append($("<td>").addClass("task-title").text(title));
            tr.append($("<td>").append(mode));
            tr.append($("<td>").append(minutes));
            tr.append($("<td>").append(time));
            tr.append($("<td>").html(taskStatusLabel(task)));
            tr.append($("<td>").text(task.last_run || "-"));
            tr.append($("<td>").text(task.next_run || "-"));
            tr.append($("<td>").html(lastStatusLabel(task)));
            tr.append($("<td>").text(task.last_message || "-"));
            tr.append($("<td>").append(runButton));

            tbody.append(tr);
        });
    }

    function collectConfig() {
        var result = {
            tasks: {}
        };

        $("#scheduler_tasks tbody tr").each(function() {
            var row = $(this);
            var taskId = row.attr("data-task-id");

            result.tasks[taskId] = {
                enabled: row.find(".scheduler-enabled").is(":checked") ? "1" : "0",
                mode: row.find(".scheduler-mode").val(),
                interval_minutes: row.find(".scheduler-minutes").val(),
                time: row.find(".scheduler-time").val()
            };
        });

        return result;
    }

    function renderMainStatus(data) {
        var state = data.state || {};
        $("#scheduler_last_run").text(state.last_scheduler_run || "-");
        renderCronStatus(data.cron || {});
    }

    function renderCronStatus(cron) {
        cron = cron || {};

        var text = "";
        var label = "label-default";
        var showButton = false;
        var buttonText = "Создать задание Cron";

        if (cron.exists && cron.enabled && cron.correct) {
            label = "label-success";
            text = "Есть, включено — " + (cron.schedule || "* * * * *");
        } else if (cron.exists) {
            label = cron.enabled ? "label-warning" : "label-danger";
            text = cron.enabled ? "Есть, но требует исправления" : "Есть, но отключено";
            text += " — " + (cron.schedule || "-");
            showButton = true;
            buttonText = "Исправить задание Cron";
        } else {
            label = "label-danger";
            text = "Нет";
            showButton = true;
            buttonText = "Создать задание Cron";
        }

        $("#scheduler_cron_status").html('<span class="label ' + label + '">' + text + '</span>');
        $("#btn_scheduler_create_cron").text(buttonText);
        $("#btn_scheduler_create_cron").toggle(showButton);
    }

    function loadCronStatus() {
        ajaxCall("/api/additional/scheduler/cronstatus", {}, function(data, status) {
            if (data.status === "ok") {
                renderCronStatus(data.cron || {});
            }
        });
    }

    function loadScheduler() {
        hideMessage();

        ajaxCall("/api/additional/scheduler/get", {}, function(data, status) {
            if (data.status === "ok") {
                renderTasks(data.config || {});
                renderMainStatus(data);
            } else {
                showMessage("danger", data.message || "Ошибка загрузки Scheduler");
            }
        });
    }

    $("#btn_scheduler_create_cron").click(function() {
        var btn = $(this);
        btn.prop("disabled", true);
        showMessage("info", "Создаю задание Cron для Additional Scheduler...");

        ajaxCall("/api/additional/scheduler/createcron", {}, function(data, status) {
            btn.prop("disabled", false);

            if (data.status === "ok") {
                showMessage("success", data.message || "Задание Cron создано");
                renderCronStatus(data.cron || {});
            } else {
                showMessage("danger", data.message || "Ошибка создания задания Cron");
                renderCronStatus(data.cron || {});
            }
        });
    });

    $("#btn_scheduler_save").click(function() {
        showMessage("info", "Сохраняю настройки Scheduler...");

        ajaxCall("/api/additional/scheduler/set", collectConfig(), function(data, status) {
            if (data.status === "ok") {
                showMessage("success", data.message);
                renderTasks(data.config || {});
                renderMainStatus(data);
            } else {
                showMessage("danger", data.message || "Ошибка сохранения Scheduler");
            }
        });
    });

    $("#btn_scheduler_run").click(function() {
        $("#btn_scheduler_run").prop("disabled", true);
        showMessage("info", "Запускаю Scheduler...");

        ajaxCall("/api/additional/scheduler/run", {}, function(data, status) {
            $("#btn_scheduler_run").prop("disabled", false);

            if (data.status === "ok") {
                showMessage("success", data.message || "Scheduler выполнен");
                renderTasks(data.config || {});
                renderMainStatus(data);
            } else {
                showMessage("danger", data.message || "Ошибка Scheduler");
            }
        });
    });

    $("#scheduler_tasks").on("click", ".scheduler-run-task", function() {
        var row = $(this).closest("tr");
        var taskId = row.attr("data-task-id");
        var btn = $(this);

        btn.prop("disabled", true);
        showMessage("info", "Запускаю задачу: " + taskId);

        ajaxCall("/api/additional/scheduler/run", {
            task: taskId,
            force: "1"
        }, function(data, status) {
            btn.prop("disabled", false);

            if (data.status === "ok") {
                showMessage("success", data.message || "Задача выполнена");
                renderTasks(data.config || {});
                renderMainStatus(data);
            } else {
                showMessage("danger", data.message || "Ошибка запуска задачи");
            }
        });
    });

    $("#btn_scheduler_refresh").click(function() {
        loadScheduler();
    });

    loadScheduler();
});
</script>

<div class="additional-page">
    <div id="scheduler_message" class="alert" style="display:none;"></div>

    <div class="scheduler-section">
        <h2>Общий Scheduler</h2>

        <table class="table table-condensed">
            <tr>
                <th style="width:260px;">Задание Cron</th>
                <td>
                    <span id="scheduler_cron_status">-</span>
                    <button id="btn_scheduler_create_cron" type="button" class="btn btn-xs btn-primary" style="display:none; margin-left: 10px;">
                        Создать задание Cron
                    </button>
                </td>
            </tr>
            <tr>
                <th>Требуемое расписание Cron</th>
                <td><code>* * * * *</code></td>
            </tr>
            <tr>
                <th>Последний запуск Scheduler</th>
                <td id="scheduler_last_run">-</td>
            </tr>
        </table>

        <br>

        <button id="btn_scheduler_save" type="button" class="btn btn-default">
            <i class="fa fa-save"></i>
            Сохранить настройки
        </button>

        <button id="btn_scheduler_run" type="button" class="btn btn-primary">
            <i class="fa fa-refresh"></i>
            Запустить Scheduler сейчас
        </button>

        <button id="btn_scheduler_refresh" type="button" class="btn btn-default">
            <i class="fa fa-info-circle"></i>
            Обновить информацию
        </button>
    </div>

    <div class="scheduler-section">
        <h2>Задачи</h2>

        <table id="scheduler_tasks" class="table table-condensed scheduler-table">
            <thead>
                <tr>
                    <th style="width:70px;">Вкл.</th>
                    <th style="width:180px;">Задача</th>
                    <th style="width:150px;">Режим</th>
                    <th style="width:150px;">Минуты</th>
                    <th style="width:150px;">Время</th>
                    <th style="width:260px;">Расписание</th>
                    <th style="width:170px;">Последний запуск</th>
                    <th style="width:170px;">Следующий запуск</th>
                    <th style="width:120px;">Статус</th>
                    <th>Сообщение</th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
