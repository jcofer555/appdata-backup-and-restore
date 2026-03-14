<?php
// Returns all saved local backup settings as JSON for populating the settings form
header('Content-Type: application/json');

$cfgPath = '/boot/config/plugins/flash-backup/settings.cfg';

if (!file_exists($cfgPath)) {
    echo json_encode(['error' => 'settings.cfg not found']);
    exit;
}

$settings = parse_ini_file($cfgPath);
if (!is_array($settings)) {
    echo json_encode(['error' => 'Failed to parse settings.cfg']);
    exit;
}

echo json_encode($settings, JSON_UNESCAPED_SLASHES);
