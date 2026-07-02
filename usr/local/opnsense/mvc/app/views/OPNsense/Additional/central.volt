<style>
    .additional-page {
        padding: 0 20px 24px 20px;
        max-width: 100%;
        box-sizing: border-box;
        overflow: visible;
    }
    .central-section {
        margin-top: 18px;
        padding: 18px 20px;
        border: 1px solid rgba(255, 255, 255, 0.18);
        background: rgba(255, 255, 255, 0.025);
        box-sizing: border-box;
        max-width: 100%;
        overflow-x: auto;
    }
    .central-section h2 {
        margin-top: 0;
        margin-bottom: 16px;
        font-size: 16px;
        font-weight: 700;
    }
    .central-section .form-control {
        max-width: 820px;
    }
    .central-actions .btn {
        margin-right: 4px;
        margin-bottom: 6px;
    }
    .central-result {
        white-space: pre-wrap;
        word-break: break-word;
        max-height: 420px;
        overflow: auto;
        background: rgba(0, 0, 0, 0.22);
        padding: 12px;
        border: 1px solid rgba(255,255,255,0.14);
    }
    .central-table th {
        width: 260px;
        white-space: nowrap;
    }
</style>

<script>
$(document).ready(function() {
    function showMessage(type, message) {
        var box = $('#central_message');
        box.removeClass('alert-success alert-danger alert-warning alert-info');
        box.addClass('alert-' + type);
        box.text(message);
        box.show();
    }

    function hideMessage() {
        $('#central_message').hide();
    }

    function renderJson(data) {
        $('#central_result').text(JSON.stringify(data || {}, null, 2));
    }

    function label(text, css) {
        return '<span class="label ' + css + '">' + text + '</span>';
    }

    function render(data) {
        data = data || {};
        var cfg = data.config || {};
        var st = data.agent_status || {};

        $('#central_server_url').val(cfg.server_url || '');
        $('#central_device_uuid').val(cfg.device_uuid || '');
        $('#central_enabled').prop('checked', cfg.enabled === '1');
        $('#central_verify_tls').prop('checked', cfg.verify_tls !== '0');
        $('#central_poll_jobs').prop('checked', cfg.poll_jobs !== '0');

        $('#central_registered').html(cfg.registered === '1' ? label('registered', 'label-success') : label('not registered', 'label-default'));
        $('#central_secret').text(cfg.device_secret_masked || '-');
        $('#central_last_action').text(st.last_action || '-');
        $('#central_last_time').text(st.timestamp || '-');
        $('#central_last_message').text(st.message || '-');

        if (st.ok === true) {
            $('#central_last_state').html(label('ok', 'label-success'));
        } else if (st.ok === false) {
            $('#central_last_state').html(label('error', 'label-danger'));
        } else {
            $('#central_last_state').html(label('unknown', 'label-default'));
        }
    }

    function collectSettings() {
        return {
            server_url: $('#central_server_url').val(),
            device_uuid: $('#central_device_uuid').val(),
            enabled: $('#central_enabled').is(':checked') ? '1' : '0',
            verify_tls: $('#central_verify_tls').is(':checked') ? '1' : '0',
            poll_jobs: $('#central_poll_jobs').is(':checked') ? '1' : '0'
        };
    }

    function callApi(path, payload, successText) {
        hideMessage();
        ajaxCall(path, payload || {}, function(data, status) {
            render(data || {});
            renderJson(data || {});
            if (data && data.status === 'ok') {
                showMessage('success', data.message || successText || 'Done');
            } else {
                showMessage('danger', (data && data.message) ? data.message : 'Error');
            }
        });
    }

    function loadCentral() {
        ajaxCall('/api/additional/central/get', {}, function(data, status) {
            render(data || {});
            renderJson(data || {});
        });
    }

    $('#btn_central_save').click(function() {
        callApi('/api/additional/central/set', collectSettings(), 'Settings saved');
    });

    $('#btn_central_ping').click(function() {
        callApi('/api/additional/central/ping', {}, 'Ping ok');
    });

    $('#btn_central_register').click(function() {
        var payload = collectSettings();
        payload.registration_token = $('#central_registration_token').val();
        callApi('/api/additional/central/register', payload, 'Registered');
    });

    $('#btn_central_heartbeat').click(function() {
        callApi('/api/additional/central/heartbeat', {}, 'Heartbeat sent');
    });

    $('#btn_central_poll').click(function() {
        callApi('/api/additional/central/poll', {}, 'Jobs polled');
    });

    $('#btn_central_backup').click(function() {
        callApi('/api/additional/central/backup', {}, 'Backup uploaded');
    });

    $('#btn_central_runonce').click(function() {
        callApi('/api/additional/central/runonce', {}, 'Run once completed');
    });

    $('#btn_central_clear').click(function() {
        if (!confirm('Clear local registration secret?')) {
            return;
        }
        callApi('/api/additional/central/clear', {}, 'Registration cleared');
    });

    loadCentral();
});
</script>

<div class="additional-page">
    <div class="page-content-main">
        <h1>Controller connect</h1>

        <div id="central_message" class="alert" style="display:none;"></div>

        <div class="central-section">
            <h2>Connection settings</h2>

            <div class="form-group">
                <label for="central_server_url">Central server URL</label>
                <input type="text" class="form-control" id="central_server_url" placeholder="http://controller.example.net:81">
            </div>

            <div class="form-group">
                <label for="central_device_uuid">Device UUID</label>
                <input type="text" class="form-control" id="central_device_uuid" placeholder="created in OPNsense Controller">
            </div>

            <div class="form-group">
                <label for="central_registration_token">Registration token</label>
                <input type="password" class="form-control" id="central_registration_token" placeholder="shown once when device is created">
            </div>

            <div class="checkbox">
                <label><input type="checkbox" id="central_enabled"> Enable agent</label>
            </div>
            <div class="checkbox">
                <label><input type="checkbox" id="central_verify_tls" checked> Verify TLS certificate</label>
            </div>
            <div class="checkbox">
                <label><input type="checkbox" id="central_poll_jobs" checked> Poll jobs from server</label>
            </div>

            <div class="central-actions">
                <button type="button" class="btn btn-primary" id="btn_central_save"><i class="fa fa-save"></i> Save</button>
                <button type="button" class="btn btn-default" id="btn_central_ping"><i class="fa fa-plug"></i> Test server</button>
                <button type="button" class="btn btn-success" id="btn_central_register"><i class="fa fa-link"></i> Register</button>
            </div>
        </div>

        <div class="central-section">
            <h2>Status</h2>
            <table class="table table-condensed central-table">
                <tbody>
                    <tr><th>Registration</th><td id="central_registered">-</td></tr>
                    <tr><th>Device secret</th><td id="central_secret">-</td></tr>
                    <tr><th>Last state</th><td id="central_last_state">-</td></tr>
                    <tr><th>Last action</th><td id="central_last_action">-</td></tr>
                    <tr><th>Last time</th><td id="central_last_time">-</td></tr>
                    <tr><th>Message</th><td id="central_last_message">-</td></tr>
                </tbody>
            </table>

            <div class="central-actions">
                <button type="button" class="btn btn-default" id="btn_central_heartbeat"><i class="fa fa-heartbeat"></i> Heartbeat</button>
                <button type="button" class="btn btn-default" id="btn_central_poll"><i class="fa fa-tasks"></i> Poll jobs</button>
                <button type="button" class="btn btn-default" id="btn_central_backup"><i class="fa fa-download"></i> Backup config.xml</button>
                <button type="button" class="btn btn-primary" id="btn_central_runonce"><i class="fa fa-play"></i> Run once</button>
                <button type="button" class="btn btn-danger" id="btn_central_clear"><i class="fa fa-trash"></i> Clear registration</button>
            </div>
        </div>

        <div class="central-section">
            <h2>Last API response</h2>
            <pre id="central_result" class="central-result">{}</pre>
        </div>

        <div class="central-section">
            <h2>Scheduler</h2>
            <p>For automatic operation open Additional - Scheduler, create/fix Cron, then enable task <b>Controller agent</b> with interval 1 minute.</p>
        </div>
    </div>
</div>
