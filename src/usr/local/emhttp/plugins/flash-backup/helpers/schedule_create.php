<?php

require_once __DIR__ . '/rebuild_cron.php';
// Path to the local schedules config file
define('SCHEDULES_CFG',  '/boot/config/plugins/flash-backup/schedules.cfg');
// Regex pattern for validating a standard 5-field cron expression
define('CRON_PATTERN',   '/^([\*\/0-9,-]+\s+){4}[\*\/0-9,-]+$/');

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
// check_duplicate() — destination conflict detection
// ------------------------------------------------------------------------------
function check_duplicate(array $schedules, string $new_dest): void {
    foreach ($schedules as $existing_id => $s) {
        if (empty($s['SETTINGS'])) continue;

        $existing_settings = json_decode(stripslashes($s['SETTINGS']), true);
        if (!is_array($existing_settings)) continue;

        $existing_dest = trim($existing_settings['BACKUP_DESTINATION'] ?? '');
        // Reject if another schedule already uses this destination
        if ($existing_dest !== '' && $existing_dest === $new_dest) {
            respond(409, [
                'error'       => 'Duplicate schedule detected',
                'conflict_id' => $existing_id,
            ]);
        }
    }
}

// ------------------------------------------------------------------------------
// append_schedule() — append new INI block
// ------------------------------------------------------------------------------
function append_schedule(string $cfg, string $id, string $type, string $cron, string $settings_json): void {
    $real = realpath($cfg);

    // If file does not exist yet, use the raw path for the first write
    $target = ($real !== false) ? $real : $cfg;

    $block  = "\n[{$id}]\n";
    $block .= "CRON=\"{$cron}\"\n";
    $block .= "ENABLED=\"yes\"\n";
    $block .= "SETTINGS=\"{$settings_json}\"\n";
    $block .= "TYPE=\"{$type}\"\n";

    if (file_put_contents($target, $block, FILE_APPEND) === false) {
        respond(500, ['error' => 'Failed to write schedule']);
    }
}

// ------------------------------------------------------------------------------
// main()
// ------------------------------------------------------------------------------
function main(): void {
    $type     = trim($_POST['type']     ?? '');
    $cron     = trim($_POST['cron']     ?? '');
    $settings = $_POST['settings']      ?? [];

    if (!is_array($settings)) {
        $settings = [];
    }

    // Allowlist: only store fields that belong in a local schedule
    $allowed = [
        'BACKUP_DESTINATION',
        'BACKUP_OWNER',
        'BACKUPS_TO_KEEP',
        'DRY_RUN',
        'MINIMAL_BACKUP',
        'NOTIFICATION_SERVICE',
        'NOTIFICATIONS',
        'PUSHOVER_USER_KEY',
        'WEBHOOK_DISCORD',
        'WEBHOOK_GOTIFY',
        'WEBHOOK_NTFY',
        'WEBHOOK_PUSHOVER',
        'WEBHOOK_SLACK',
    ];
    $settings = array_intersect_key($settings, array_flip($allowed));

    // Always exclude UI-only fields that must not be persisted
    $exclude = ['csrf_token', 'CRON_EXPRESSION'];
    $settings = array_diff_key($settings, array_flip($exclude));

    // Input validation
    if (!preg_match(CRON_PATTERN, $cron)) {
        respond(400, ['error' => 'Invalid cron expression']);
    }

    $new_dest = trim($settings['BACKUP_DESTINATION'] ?? '');
    if ($new_dest === '') {
        respond(400, ['error' => 'Backup destination is required']);
    }

    // Load existing schedules and check for destination conflicts
    $schedules = load_schedules(SCHEDULES_CFG);
    check_duplicate($schedules, $new_dest);

    // Encode settings as escaped JSON for safe INI storage
    $settings_json = addcslashes(
        json_encode($settings, JSON_UNESCAPED_SLASHES),
        '"'
    );

    // Generate a unique timestamp-based ID and append the new block
    $id = 'schedule_' . time();
    append_schedule(SCHEDULES_CFG, $id, $type, $cron, $settings_json);

    rebuild_cron();

    respond(200, [
        'success' => true,
        'id'      => $id,
    ]);
}

main();
