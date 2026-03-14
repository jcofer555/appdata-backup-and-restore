<?php

// List of schedule config files to inspect — covers both local and remote schedules
define('SCHEDULE_CFGS', [
    '/boot/config/plugins/flash-backup/schedules.cfg',
    '/boot/config/plugins/flash-backup/schedules-remote.cfg',
]);

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
        return [];
    }
    $schedules = parse_ini_file($real, true, INI_SCANNER_RAW);
    return is_array($schedules) ? $schedules : [];
}

// ------------------------------------------------------------------------------
// main()
// ------------------------------------------------------------------------------
function main(): void {
    $crons = [];

    // Collect cron entries from both local and remote schedule files
    foreach (SCHEDULE_CFGS as $cfg) {
        $schedules = load_schedules($cfg);

        foreach ($schedules as $id => $s) {
            $cron    = trim($s['CRON'] ?? '');
            $enabled = strtolower((string)($s['ENABLED'] ?? 'yes')) === 'yes';

            // Skip entries with no cron expression
            if ($cron === '') continue;

            $crons[] = [
                'id'      => $id,
                'cron'    => $cron,
                'enabled' => $enabled,
            ];
        }
    }

    respond(200, $crons);
}

main();
