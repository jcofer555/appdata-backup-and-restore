<?php
// Returns all saved remote backup settings as JSON for populating the remote settings form
header('Content-Type: application/json');

$cfgPath = '/boot/config/plugins/flash-backup/settings_remote.cfg';

if (!file_exists($cfgPath)) {
    echo json_encode(['error' => 'settings_remote.cfg not found']);
    exit;
}

$settings = parse_ini_file($cfgPath);
if (!is_array($settings)) {
    echo json_encode(['error' => 'Failed to parse settings_remote.cfg']);
    exit;
}

echo json_encode($settings, JSON_UNESCAPED_SLASHES);
