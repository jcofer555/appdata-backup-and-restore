<?php

require_once __DIR__ . '/rebuild_cron.php';
// Path to the local schedules config file
define('SCHEDULES_CFG', '/boot/config/plugins/flash-backup/schedules.cfg');

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
        respond(404, ['error' => 'Schedules file not found']);
    }
    $schedules = parse_ini_file($real, true, INI_SCANNER_RAW);
    if (!is_array($schedules)) {
        respond(500, ['error' => 'Failed to parse schedules file']);
    }
    return $schedules;
}

// ------------------------------------------------------------------------------
// write_schedules() — tmp then rename
// ------------------------------------------------------------------------------
function write_schedules(string $cfg, array $schedules): void {
    $real = realpath($cfg);
    if ($real === false) {
        respond(500, ['error' => 'Cannot resolve schedules file path']);
    }

    $tmp = $real . '.tmp';
    $out = '';
    foreach ($schedules as $id => $fields) {
        $out .= "[{$id}]\n";
        // Sort keys so the file layout is deterministic regardless of insertion order
        ksort($fields);
        foreach ($fields as $key => $val) {
            $out .= "{$key}=\"{$val}\"\n";
        }
        $out .= "\n";
    }

    if (file_put_contents($tmp, $out) === false) {
        respond(500, ['error' => 'Failed to write temporary schedules file']);
    }
    if (!rename($tmp, $real)) {
        @unlink($tmp);
        respond(500, ['error' => 'Failed to commit schedules file update']);
    }
}

// ------------------------------------------------------------------------------
// main()
// ------------------------------------------------------------------------------
function main(): void {
    $id = trim($_POST['id'] ?? '');

    if ($id === '') {
        respond(400, ['error' => 'Missing schedule ID']);
    }

    $schedules = load_schedules(SCHEDULES_CFG);

    if (!isset($schedules[$id])) {
        respond(404, ['error' => 'Schedule not found']);
    }

    // Toggle ENABLED — map to human-friendly yes/no
    $current = strtolower((string)($schedules[$id]['ENABLED'] ?? 'yes'));
    $schedules[$id]['ENABLED'] = ($current === 'yes') ? 'no' : 'yes';

    write_schedules(SCHEDULES_CFG, $schedules);

    // Rebuild cron so the enabled state change takes effect immediately
    rebuild_cron();

    respond(200, ['success' => true]);
}

main();
