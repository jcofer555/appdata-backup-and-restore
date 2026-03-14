<?php
$debug   = !empty($_GET['debug']) && $_GET['debug'] === '1';
$logFile = $debug
    ? '/tmp/flash-backup/flash-backup-debug.log'
    : '/tmp/flash-backup/flash-backup.log';

if (file_exists($logFile)) {
    header('Content-Type: text/plain; charset=utf-8');
    readfile($logFile);
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo '';
}