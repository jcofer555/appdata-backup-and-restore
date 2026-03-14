<?php

// Path to the remote schedules config file
define('REMOTE_SCHEDULES_CFG', '/boot/config/plugins/flash-backup/schedules-remote.cfg');

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
// load_schedules()
// ------------------------------------------------------------------------------
function load_schedules(string $cfg): array {
    $real = realpath($cfg);
    if ($real === false || !file_exists($real)) {
        respond(404, ['error' => 'Remote schedules file not found']);
    }
    $schedules = parse_ini_file($real, true, INI_SCANNER_RAW);
    if (!is_array($schedules)) {
        respond(500, ['error' => 'Failed to parse remote schedules file']);
    }
    return $schedules;
}

// ------------------------------------------------------------------------------
// main()
// ------------------------------------------------------------------------------
function main(): void {
    $id = trim($_GET['id'] ?? '');

    if ($id === '') {
        respond(400, ['error' => 'Missing schedule ID']);
    }

    $schedules = load_schedules(REMOTE_SCHEDULES_CFG);

    if (!isset($schedules[$id])) {
        respond(404, ['error' => 'Remote schedule not found']);
    }

    $entry = $schedules[$id];

    // Decode SETTINGS JSON — strip INI escaping, fall back to empty array
    $settings = json_decode(stripslashes($entry['SETTINGS'] ?? '{}'), true);
    if (!is_array($settings)) {
        $settings = [];
    }

    // Replace the raw escaped JSON string with the decoded array
    $entry['SETTINGS'] = $settings;

    respond(200, $entry);
}

main();
