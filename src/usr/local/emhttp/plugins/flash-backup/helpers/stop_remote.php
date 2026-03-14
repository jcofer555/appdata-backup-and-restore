<?php
header('Content-Type: application/json');

$lock = '/tmp/flash-backup/lock.txt';

// Return an error if no backup is currently running
if (!file_exists($lock)) {
    http_response_code(400);
    echo json_encode(['error' => 'No remote backup running']);
    exit;
}

$lockContent = @file_get_contents($lock);

// Prevent accidentally stopping a local backup
if ($lockContent && preg_match('/MODE=(\S+)/', $lockContent, $m) && $m[1] !== 'remote' && $m[1] !== 'schedule-remote') {
    http_response_code(400);
    echo json_encode(['error' => 'Local backup is running, not remote']);
    exit;
}

// Send SIGTERM to the script process so the watcher loop in remote_backup.sh picks it up
if ($lockContent && preg_match('/PID=(\d+)/', $lockContent, $m)) {
    shell_exec("kill -15 " . $m[1] . " 2>&1");
}

// Write the stop flag as a secondary signal watched by the remote backup script
touch('/tmp/flash-backup/stop_requested_remote.txt');

echo json_encode(['ok' => true]);
