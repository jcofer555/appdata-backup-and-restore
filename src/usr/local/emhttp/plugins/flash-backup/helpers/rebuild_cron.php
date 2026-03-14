<?php

// Path to the local schedules config file
define('SCHEDULES_CFG',   '/boot/config/plugins/flash-backup/schedules.cfg');
// Path to the generated cron file for local backup schedules
define('CRON_FILE',       '/boot/config/plugins/flash-backup/flash-backup.cron');
// Path to the PHP script invoked by cron for each local schedule
define('RUN_SCHEDULE_PHP', '/usr/local/emhttp/plugins/flash-backup/helpers/run_schedule.php');

// ------------------------------------------------------------------------------
// rebuild_cron() — cron file rebuild, atomic write
// ------------------------------------------------------------------------------
function rebuild_cron(): void {
    // Write an empty cron file if there are no schedules
    if (!file_exists(SCHEDULES_CFG)) {
        file_put_contents(CRON_FILE, '');
        return;
    }

    $schedules = parse_ini_file(SCHEDULES_CFG, true, INI_SCANNER_RAW);
    if (!is_array($schedules)) {
        file_put_contents(CRON_FILE, '');
        return;
    }

    $out = "# Flash backup schedules\n";

    foreach ($schedules as $id => $s) {
        // Skip disabled schedules
        $enabled = strtolower((string)($s['ENABLED'] ?? 'yes')) === 'yes';
        if (!$enabled) continue;

        $cron = trim((string)($s['CRON'] ?? ''));
        if ($cron === '') continue;

        // Pass the schedule ID via environment variable so cron does not need shell quoting tricks
        $out .= "{$cron} sh -c 'SCHEDULE_ID={$id} /usr/bin/php -f " . RUN_SCHEDULE_PHP . " '\n";
    }

    file_put_contents(CRON_FILE, $out);

    // Reload cron so the new file takes effect immediately
    exec('update_cron');
}
