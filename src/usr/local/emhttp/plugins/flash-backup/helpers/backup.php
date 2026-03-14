<?php

// Path to the local backup settings config file
define('SETTINGS_FILE', '/boot/config/plugins/flash-backup/settings.cfg');
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
// load_and_export_settings()
// ------------------------------------------------------------------------------
function load_and_export_settings(string $settings_file): void {
    // Skip silently if the settings file does not exist yet
    if (!is_file($settings_file)) {
        return;
    }

    $lines = file($settings_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    // Export each key=value pair as a process environment variable
    foreach ($lines as $line) {
        if (strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        putenv("{$key}={$val}");
    }
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
// write_lock_meta() — write metadata into open lock file handle
// ------------------------------------------------------------------------------
function write_lock_meta(mixed $fp, string $pid): void {
    $meta = implode("\n", [
        "PID={$pid}",
        "MODE=manual",
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
    // Verify backup script before acquiring lock
    if (!is_file(BACKUP_SCRIPT) || !is_executable(BACKUP_SCRIPT)) {
        respond(500, ['status' => 'error', 'message' => 'Backup script missing or not executable']);
    }

    // Load and export settings
    load_and_export_settings(SETTINGS_FILE);

    // Acquire exclusive lock
    $fp = acquire_lock();

    // Launch backup script in background and capture the spawned PID
    $cmd = 'nohup /bin/bash ' . BACKUP_SCRIPT . ' >/dev/null 2>&1 & echo $!';
    $pid = trim((string)shell_exec($cmd));

    if ($pid === '' || !is_numeric($pid)) {
        respond(500, ['status' => 'error', 'message' => 'Failed to start backup']);
    }

    // Write lock metadata and keep the file handle open to hold the lock
    write_lock_meta($fp, $pid);

    respond(200, [
        'status' => 'ok',
        'pid'    => $pid,
    ]);
}

main();
