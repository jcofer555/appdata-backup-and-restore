<?php

// Path to the file written by remote_backup.sh with the current remote backup status
define('REMOTE_STATUS_FILE',    '/tmp/flash-backup/remote_backup_status.txt');
// Default text used when no status file is present or the file is empty
define('REMOTE_STATUS_DEFAULT', 'Remote Backup Not Running');

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
// main()
// ------------------------------------------------------------------------------
function main(): void {
    $status = REMOTE_STATUS_DEFAULT;

    // Read the status file if it exists and is not empty
    if (file_exists(REMOTE_STATUS_FILE)) {
        $raw = trim(file_get_contents(REMOTE_STATUS_FILE));
        if ($raw !== '') {
            $status = $raw;
        }
    }

    respond(200, ['status' => $status]);
}

main();
