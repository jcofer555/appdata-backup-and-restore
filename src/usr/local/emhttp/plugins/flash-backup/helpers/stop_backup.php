<?php
header('Content-Type: application/json');

$lock = '/tmp/flash-backup/lock.txt';

// Return an error if no backup is currently running
if (!file_exists($lock)) {
    http_response_code(400);
    echo json_encode(['error' => 'No backup running']);
    exit;
}

$lockContent = @file_get_contents($lock);

// Prevent accidentally stopping a remote backup
if ($lockContent && preg_match('/MODE=(\S+)/', $lockContent, $m) && $m[1] === 'remote') {
    http_response_code(400);
    echo json_encode(['error' => 'Remote backup is running, not local']);
    exit;
}

// Send SIGTERM to the tar process if its PID is recorded
$tarPid = trim(@file_get_contents('/tmp/flash-backup/tar.pid'));
if ($tarPid && is_numeric($tarPid)) {
    shell_exec("kill -15 " . $tarPid . " 2>&1");
}

// Write the stop flag so the watcher loop in backup.sh exits cleanly
touch('/tmp/flash-backup/stop_requested.txt');

echo json_encode(['ok' => true]);
