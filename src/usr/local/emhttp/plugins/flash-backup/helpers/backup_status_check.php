<?php

// Path to the file written by backup.sh with the current local backup status
define('LOCAL_STATUS_FILE',    '/tmp/flash-backup/local_backup_status.txt');
// Default text used when no status file is present or the file is empty
define('LOCAL_STATUS_DEFAULT', 'Local Backup Not Running');

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
    $status = LOCAL_STATUS_DEFAULT;

    // Read the status file if it exists and is not empty
    if (file_exists(LOCAL_STATUS_FILE)) {
        $raw = trim(file_get_contents(LOCAL_STATUS_FILE));
        if ($raw !== '') {
            $status = $raw;
        }
    }

    respond(200, [
        'status'  => $status,
        // Running is true when the status is anything other than the default idle message
        'running' => ($status !== LOCAL_STATUS_DEFAULT),
    ]);
}

main();
