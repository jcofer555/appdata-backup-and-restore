<?php
// Clear either the main log or the debug log depending on the debug POST param.
$debug   = !empty($_POST['debug']) && $_POST['debug'] === '1';
$logFile = $debug
    ? '/tmp/flash-backup/flash-backup-debug.log'
    : '/tmp/flash-backup/flash-backup.log';

header('Content-Type: application/json');

if (file_exists($logFile)) {
    file_put_contents($logFile, '');
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => true]);
}