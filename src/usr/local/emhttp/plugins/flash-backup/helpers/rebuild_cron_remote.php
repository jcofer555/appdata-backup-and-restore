<?php

// Path to the remote schedules config file
define('REMOTE_SCHEDULES_CFG',       '/boot/config/plugins/flash-backup/schedules-remote.cfg');
// Path to the generated cron file for remote backup schedules
define('REMOTE_CRON_FILE',           '/boot/config/plugins/flash-backup/flash-backup-remote.cron');
// Path to the PHP script invoked by cron for each remote schedule
define('RUN_SCHEDULE_REMOTE_PHP',    '/usr/local/emhttp/plugins/flash-backup/helpers/run_schedule_remote.php');

// ------------------------------------------------------------------------------
// rebuild_cron_remote() — remote cron file rebuild, atomic write
// ------------------------------------------------------------------------------
function rebuild_cron_remote(): void {
    // Write an empty cron file if there are no remote schedules
    if (!file_exists(REMOTE_SCHEDULES_CFG)) {
        file_put_contents(REMOTE_CRON_FILE, '');
        return;
    }

    $schedules = parse_ini_file(REMOTE_SCHEDULES_CFG, true, INI_SCANNER_RAW);
    if (!is_array($schedules)) {
        file_put_contents(REMOTE_CRON_FILE, '');
        return;
    }

    $out = "# Flash backup remote schedules\n";

    foreach ($schedules as $id => $s) {
        // Skip disabled schedules
        $enabled = strtolower((string)($s['ENABLED'] ?? 'yes')) === 'yes';
        if (!$enabled) continue;

        $cron = trim((string)($s['CRON'] ?? ''));
        if ($cron === '') continue;

        // Pass the schedule ID via environment variable — redirects remote output to a dedicated log
        $out .= "{$cron} sh -c 'SCHEDULE_ID={$id} /usr/bin/php -f " . RUN_SCHEDULE_REMOTE_PHP . " >> /tmp/flash-backup_remote.log 2>&1'\n";
    }

    file_put_contents(REMOTE_CRON_FILE, $out);

    // Reload cron so the new file takes effect immediately
    exec('update_cron');
}
