<?php

// Lock file path — presence indicates a backup is in progress
define('LOCK_FILE', '/tmp/flash-backup/lock.txt');

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
    // Return whether the lock file exists as a simple running indicator
    respond(200, ['running' => file_exists(LOCK_FILE)]);
}

main();
