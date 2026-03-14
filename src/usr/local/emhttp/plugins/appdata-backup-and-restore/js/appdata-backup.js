/* ============================================================
   AppData Backup Plugin – Frontend JS
   ============================================================ */

'use strict';

var abPollTimer   = null;
var abLogTimer    = null;
var abDeleteTarget = null;

/* ── Init ── */
function abInit() {
    abRefreshLog();
    abRefreshBackupList();
    abUpdateCronReadable(document.getElementById('CRON_SCHEDULE').value);

    if (AB_CONFIG.isRunning) {
        abShowProgressModal();
        abStartPolling();
    }

    document.getElementById('CRON_SCHEDULE').addEventListener('input', function() {
        abUpdateCronReadable(this.value);
    });
}

/* ── Tab Switching ── */
function abSwitchTab(name, btn) {
    document.querySelectorAll('.ab-tab-content').forEach(function(el) {
        el.classList.remove('ab-tab-content-active');
    });
    document.querySelectorAll('.ab-tab').forEach(function(el) {
        el.classList.remove('ab-tab-active');
    });
    document.getElementById('ab-tab-' + name).classList.add('ab-tab-content-active');
    btn.classList.add('ab-tab-active');

    if (name === 'log')     abRefreshLog();
    if (name === 'backups') abRefreshBackupList();
}

/* ── Toggle Helpers ── */
function abToggleCompression(val) {
    document.getElementById('ab-compression-type-row').style.display = val === 'yes' ? '' : 'none';
}
function abToggleNotify(val) {
    document.getElementById('ab-notify-level-row').style.display = val === 'yes' ? '' : 'none';
}
function abToggleSchedule(val) {
    document.getElementById('ab-schedule-fields').style.display = val === 'yes' ? '' : 'none';
}
function abToggleRclone(val) {
    document.getElementById('ab-rclone-fields').style.display = val === 'yes' ? '' : 'none';
}

/* ── Cron Presets ── */
function abSetCron(expr) {
    document.getElementById('CRON_SCHEDULE').value = expr;
    abUpdateCronReadable(expr);
}

function abUpdateCronReadable(expr) {
    var el = document.getElementById('ab-cron-readable');
    if (!el) return;
    var readable = abParseCron(expr);
    el.textContent = readable ? '→ ' + readable : '';
}

function abParseCron(expr) {
    var parts = expr.trim().split(/\s+/);
    if (parts.length !== 5) return '';
    var min = parts[0], hr = parts[1], day = parts[2], mon = parts[3], dow = parts[4];
    var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    var time = '';
    if (min !== '*' && hr !== '*') {
        var h = parseInt(hr, 10), m = parseInt(min, 10);
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        time = h + ':' + (m < 10 ? '0' + m : m) + ' ' + ampm;
    }

    if (day === '*' && mon === '*' && dow === '*') return time ? 'Every day at ' + time : 'Every minute';
    if (day === '*' && mon === '*' && dow !== '*') {
        var d = parseInt(dow, 10);
        return 'Every ' + (days[d] || 'weekday') + (time ? ' at ' + time : '');
    }
    if (day !== '*' && mon === '*') return 'Day ' + day + ' of every month' + (time ? ' at ' + time : '');
    return expr;
}

/* ── Save Config ── */
function abSaveConfig() {
    var fields = [
        'APPDATA_SRC','BACKUP_DEST','EXTRA_FOLDERS',
        'STOP_CONTAINERS','COMPRESS','COMPRESSION_TYPE','VERIFY_BACKUP',
        'RETENTION_DAYS','RETENTION_COUNT',
        'NOTIFY_ENABLE','NOTIFY_LEVEL',
        'EXCLUDE_CONTAINERS','INCLUDE_CONTAINERS','BACKUP_VMDISKS',
        'SCHEDULED_ENABLE','CRON_SCHEDULE',
        'PRE_SCRIPT','POST_SCRIPT',
        'RCLONE_ENABLE','RCLONE_REMOTE','RCLONE_PATH'
    ];
    var config = {};
    fields.forEach(function(f) {
        var el = document.getElementById(f);
        if (el) config[f] = el.value;
    });

    fetch('/plugins/appdata-backup/helpers/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_config', config: config })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'ok') {
            abShowToast('Settings saved successfully', 'ok');
        } else {
            abShowToast('Failed to save: ' + (data.message || 'Unknown error'), 'err');
        }
    })
    .catch(function(e) {
        abShowToast('Error saving settings', 'err');
    });
}

/* ── Start Backup ── */
function abStartBackup() {
    fetch('/plugins/appdata-backup/helpers/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'start_backup' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'ok') {
            abSetRunningState(true);
            abShowProgressModal();
            abStartPolling();
        } else {
            abShowToast('Failed to start: ' + (data.message || 'Unknown error'), 'err');
        }
    })
    .catch(function(e) { abShowToast('Error starting backup', 'err'); });
}

/* ── Stop Backup ── */
function abStopBackup() {
    fetch('/plugins/appdata-backup/helpers/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'stop_backup' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        abShowToast('Stop signal sent', 'warn');
    });
}

/* ── Polling ── */
function abStartPolling() {
    if (abPollTimer) clearInterval(abPollTimer);
    abPollTimer = setInterval(abPollStatus, 2500);
}
function abStopPolling() {
    if (abPollTimer) { clearInterval(abPollTimer); abPollTimer = null; }
}

function abPollStatus() {
    fetch('/plugins/appdata-backup/helpers/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_status' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var prog = document.getElementById('ab-progress-bar');
        var msg  = document.getElementById('ab-progress-msg');
        var log  = document.getElementById('ab-progress-log');

        if (prog) prog.style.width = (data.progress || 0) + '%';
        if (msg)  msg.textContent = data.message || '';
        if (log && data.recent_log) {
            log.innerHTML = abColorizeLog(data.recent_log);
            log.scrollTop = log.scrollHeight;
        }

        if (!data.running) {
            abStopPolling();
            abSetRunningState(false);
            abHideProgressModal();
            abRefreshLog();
            abRefreshBackupList();
            abUpdateStats();
            if (data.success) {
                abShowToast('Backup completed successfully!', 'ok');
            } else {
                abShowToast('Backup finished with errors. Check the log.', 'err');
            }
        }
    })
    .catch(function() {});
}

/* ── Progress Modal ── */
function abShowProgressModal() {
    document.getElementById('ab-progress-modal').style.display = 'flex';
}
function abHideProgressModal() {
    document.getElementById('ab-progress-modal').style.display = 'none';
}

/* ── Running State ── */
function abSetRunningState(running) {
    var badge  = document.getElementById('ab-status-badge');
    var btext  = document.getElementById('ab-status-text');
    var btn    = document.getElementById('ab-backup-btn');
    var stopBtn = document.getElementById('ab-stop-btn');

    if (running) {
        badge.className = 'ab-badge ab-badge-running';
        btext.textContent = 'Backup Running';
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Backup Running…';
        if (stopBtn) stopBtn.style.display = '';
    } else {
        badge.className = 'ab-badge ab-badge-idle';
        btext.textContent = 'Idle';
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-play"></i> Start Backup Now';
        if (stopBtn) stopBtn.style.display = 'none';
    }
}

/* ── Log ── */
function abRefreshLog() {
    var el = document.getElementById('ab-log-output');
    if (!el) return;
    fetch('/plugins/appdata-backup/helpers/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_log' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        el.innerHTML = data.log ? abColorizeLog(data.log) : '<span style="color:#555">No log entries yet.</span>';
        el.scrollTop = el.scrollHeight;
    })
    .catch(function() { el.textContent = 'Error loading log.'; });
}

function abClearLog() {
    if (!confirm('Clear the backup log?')) return;
    fetch('/plugins/appdata-backup/helpers/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'clear_log' })
    })
    .then(function(r) { return r.json(); })
    .then(function() { abRefreshLog(); abShowToast('Log cleared', 'ok'); });
}

function abColorizeLog(text) {
    return text
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/(ERROR|FAILED|error|failed)/g, '<span class="ab-log-line-err">$1</span>')
        .replace(/(WARNING|WARN|warning|warn)/g, '<span class="ab-log-line-warn">$1</span>')
        .replace(/(SUCCESS|OK|DONE|success|done)/g, '<span class="ab-log-line-ok">$1</span>')
        .replace(/(\[INFO\]|\[START\]|\[END\])/g, '<span class="ab-log-line-info">$1</span>');
}

/* ── Backup List ── */
function abRefreshBackupList() {
    var el = document.getElementById('ab-backup-list');
    if (!el) return;
    el.innerHTML = '<div class="ab-loading"><i class="fa fa-spinner fa-spin"></i> Loading backups…</div>';

    fetch('/plugins/appdata-backup/helpers/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'list_backups' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.backups || data.backups.length === 0) {
            el.innerHTML = '<div class="ab-empty"><i class="fa fa-inbox" style="font-size:28px;display:block;margin-bottom:8px"></i>No backups found.</div>';
            return;
        }
        var html = '<table class="ab-backup-table"><thead><tr>'
            + '<th>Name</th><th>Date</th><th>Size</th><th>Containers</th><th>Actions</th>'
            + '</tr></thead><tbody>';
        data.backups.forEach(function(b) {
            html += '<tr>'
                + '<td class="ab-backup-name"><i class="fa fa-archive" style="color:var(--ab-gold);margin-right:6px"></i>' + abEsc(b.name) + '</td>'
                + '<td>' + abEsc(b.date) + '</td>'
                + '<td>' + abEsc(b.size) + '</td>'
                + '<td>' + (b.containers || '—') + '</td>'
                + '<td><button class="ab-btn ab-btn-sm ab-btn-danger" onclick="abConfirmDelete(\'' + abEsc(b.name) + '\')">'
                + '<i class="fa fa-trash"></i></button></td>'
                + '</tr>';
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    })
    .catch(function() { el.innerHTML = '<div class="ab-empty">Error loading backup list.</div>'; });
}

/* ── Delete Backup ── */
function abConfirmDelete(name) {
    abDeleteTarget = name;
    document.getElementById('ab-delete-msg').textContent = 'Delete backup "' + name + '"? This cannot be undone.';
    document.getElementById('ab-delete-modal').style.display = 'flex';
    document.getElementById('ab-delete-confirm-btn').onclick = function() {
        abDoDelete(name);
    };
}
function abCloseDeleteModal() {
    document.getElementById('ab-delete-modal').style.display = 'none';
    abDeleteTarget = null;
}
function abDoDelete(name) {
    abCloseDeleteModal();
    fetch('/plugins/appdata-backup/helpers/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_backup', name: name })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'ok') {
            abShowToast('Backup deleted', 'ok');
            abRefreshBackupList();
            abUpdateStats();
        } else {
            abShowToast('Delete failed: ' + (data.message || ''), 'err');
        }
    });
}

/* ── Stats Update ── */
function abUpdateStats() {
    fetch('/plugins/appdata-backup/helpers/ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'get_stats' })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var lb = document.getElementById('ab-last-backup');
        var bs = document.getElementById('ab-backup-size');
        var bc = document.getElementById('ab-backup-count');
        if (lb && data.last_backup)  lb.textContent = data.last_backup;
        if (bs && data.backup_size)  bs.textContent = data.backup_size;
        if (bc && data.backup_count !== undefined) bc.textContent = data.backup_count;
    });
}

/* ── Toast ── */
function abShowToast(msg, type) {
    var existing = document.getElementById('ab-toast');
    if (existing) existing.remove();

    var colors = { ok: 'var(--ab-green)', err: 'var(--ab-red)', warn: 'var(--ab-gold)' };
    var t = document.createElement('div');
    t.id = 'ab-toast';
    t.style.cssText = [
        'position:fixed','bottom:24px','right:24px','z-index:99999',
        'background:#222','border:1px solid ' + (colors[type] || '#555'),
        'color:' + (colors[type] || '#ccc'),
        'padding:10px 18px','border-radius:6px','font-size:13px',
        'box-shadow:0 4px 20px rgba(0,0,0,0.5)',
        'transition:opacity 0.4s ease','opacity:0'
    ].join(';');
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function() {
        t.style.opacity = '1';
        setTimeout(function() {
            t.style.opacity = '0';
            setTimeout(function() { if (t.parentNode) t.remove(); }, 400);
        }, 3000);
    });
}

/* ── Utility ── */
function abEsc(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
