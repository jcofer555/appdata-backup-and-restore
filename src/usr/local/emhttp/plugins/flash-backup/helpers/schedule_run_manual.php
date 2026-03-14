<?php

// Path to the local schedules config file
define('SCHEDULES_CFG', '/boot/config/plugins/flash-backup/schedules.cfg');
// Directory used to hold the lock file and runtime state
define('LOCK_DIR',      '/tmp/flash-backup');
// Lock file path — presence indicates a backup is in progress
define('LOCK_FILE',     LOCK_DIR . '/lock.txt');
// Path to the backup shell script
define('BACKUP_SCRIPT', '/usr/local/emhttp/plugins/flash-backup/helpers/backup.sh');

// ------------------------------------------------------------------------------
// respond() — JSON response with explicit HTTP code, then exit
// ------------------------------------------------------------------------------
function respond(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------------------------
// load_schedules()
// ------------------------------------------------------------------------------
function load_schedules(string $cfg): array {
    $real = realpath($cfg);
    if ($real === false || !file_exists($real)) {
        respond(404, ['status' => 'error', 'message' => 'Schedules file not found']);
    }
    $schedules = parse_ini_file($real, true, INI_SCANNER_RAW);
    if (!is_array($schedules)) {
        respond(500, ['status' => 'error', 'message' => 'Failed to parse schedules file']);
    }
    return $schedules;
}

// ------------------------------------------------------------------------------
// acquire_lock() — Non blocking
// ------------------------------------------------------------------------------
function acquire_lock(): mixed {
    // Create the lock directory if it does not exist
    if (!is_dir(LOCK_DIR)) {
        if (!mkdir(LOCK_DIR, 0777, true)) {
            respond(500, ['status' => 'error', 'message' => 'Unable to create lock directory']);
        }
    }

    $fp = fopen(LOCK_FILE, 'c');
    if (!$fp) {
        respond(500, ['status' => 'error', 'message' => 'Unable to open lock file']);
    }

    // Non-blocking lock — returns 409 immediately if another backup is running
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        respond(409, ['status' => 'error', 'message' => 'Backup already running']);
    }

    return $fp;
}

// ------------------------------------------------------------------------------
// build_env()
// ------------------------------------------------------------------------------
function build_env(array $settings, string $id): string {
    $env = '';
    foreach ($settings as $key => $val) {
        $env .= $key . '="' . addslashes((string)$val) . '" ';
    }
    // Append the schedule ID so the backup script can log which schedule triggered it
    $env .= 'SCHEDULE_ID="' . addslashes($id) . '" ';
    return $env;
}

// ------------------------------------------------------------------------------
// write_lock_meta() — write metadata into open lock file handle
// ------------------------------------------------------------------------------
function write_lock_meta(mixed $fp, string $pid, string $id): void {
    $meta = implode("\n", [
        "PID={$pid}",
        "MODE=schedule-manual",
        "SCHEDULE_ID={$id}",
        "START=" . time(),
    ]) . "\n";

    // Truncate before writing so stale data from a previous run is not left behind
    ftruncate($fp, 0);
    fwrite($fp, $meta);
    fflush($fp);
}

// ------------------------------------------------------------------------------
// main()
// ------------------------------------------------------------------------------
function main(): void {
    $id = trim($_POST['id'] ?? '');

    if ($id === '') {
        respond(400, ['status' => 'error', 'message' => 'Missing schedule ID']);
    }

    // Load and verify schedule exists
    $schedules = load_schedules(SCHEDULES_CFG);

    if (!isset($schedules[$id])) {
        respond(404, ['status' => 'error', 'message' => 'Schedule not found']);
    }

    // Decode settings — manual runs use the saved schedule settings directly
    $settings = json_decode($schedules[$id]['SETTINGS'] ?? '', true);
    if (!is_array($settings)) {
        respond(500, ['status' => 'error', 'message' => 'Invalid schedule settings']);
    }

    // Verify backup script
    if (!is_file(BACKUP_SCRIPT) || !is_executable(BACKUP_SCRIPT)) {
        respond(500, ['status' => 'error', 'message' => 'Scheduled backup script missing or not executable']);
    }

    // Acquire exclusive lock
    $fp = acquire_lock();

    // Build and launch command with settings injected as inline environment variables
    $env = build_env($settings, $id);
    $cmd = "nohup /usr/bin/env {$env} /bin/bash " . BACKUP_SCRIPT . " >/dev/null 2>&1 & echo $!";
    $pid = trim((string)shell_exec($cmd));

    if ($pid === '' || !is_numeric($pid)) {
        respond(500, ['status' => 'error', 'message' => 'Failed to start scheduled backup']);
    }

    // Write lock metadata and keep the file handle open to hold the lock
    write_lock_meta($fp, $pid, $id);

    respond(200, [
        'status'  => 'ok',
        'started' => true,
        'id'      => $id,
        'pid'     => $pid,
    ]);
}

main();
