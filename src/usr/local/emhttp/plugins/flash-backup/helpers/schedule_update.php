<?php

require_once __DIR__ . '/rebuild_cron.php';
// Path to the local schedules config file
define('SCHEDULES_CFG', '/boot/config/plugins/flash-backup/schedules.cfg');
// Regex pattern for validating a standard 5-field cron expression
define('CRON_PATTERN',  '/^([\*\/0-9,-]+\s+){4}[\*\/0-9,-]+$/');

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
// fingerprint() — duplicate detection key
// ------------------------------------------------------------------------------
function fingerprint(array $settings): string {
    // Key on backup destination only — this is the field that must be unique per schedule
    $key = ['BACKUP_DESTINATION' => $settings['BACKUP_DESTINATION'] ?? ''];
    ksort($key);
    return hash('sha256', json_encode($key));
}

// ------------------------------------------------------------------------------
// check_duplicate() — conflict detection, excludes current ID
// ------------------------------------------------------------------------------
function check_duplicate(array $schedules, string $exclude_id, string $new_hash): void {
    foreach ($schedules as $existing_id => $s) {
        // Skip the schedule being updated to avoid a false self-conflict
        if ($existing_id === $exclude_id) continue;
        if (empty($s['SETTINGS']))        continue;

        $existing_settings = json_decode(stripslashes($s['SETTINGS']), true);
        if (!is_array($existing_settings)) continue;

        if (fingerprint($existing_settings) === $new_hash) {
            respond(409, [
                'error'       => 'Duplicate schedule detected',
                'conflict_id' => $existing_id,
            ]);
        }
    }
}

// ------------------------------------------------------------------------------
// main()
// ------------------------------------------------------------------------------
function main(): void {
    $id       = trim($_POST['id']    ?? '');
    $cron     = trim($_POST['cron']  ?? '');
    $settings = $_POST['settings']   ?? [];

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
    if ($id === '') {
        respond(400, ['error' => 'Missing schedule ID']);
    }

    if (!preg_match(CRON_PATTERN, $cron)) {
        respond(400, ['error' => 'Invalid cron expression']);
    }

    // Load and verify the schedule exists
    $schedules = load_schedules(SCHEDULES_CFG);

    if (!isset($schedules[$id])) {
        respond(404, ['error' => 'Schedule not found']);
    }

    // Duplicate check against all other schedules
    $new_hash = fingerprint($settings);
    check_duplicate($schedules, $id, $new_hash);

    // Encode settings as escaped JSON for safe INI storage
    $settings_json = addcslashes(
        json_encode($settings, JSON_UNESCAPED_SLASHES),
        '"'
    );

    // Apply update
    $schedules[$id]['CRON']     = $cron;
    $schedules[$id]['SETTINGS'] = $settings_json;

    // Write
    write_schedules(SCHEDULES_CFG, $schedules);

    // Rebuild cron jobs so the updated schedule takes effect
    rebuild_cron();

    respond(200, ['success' => true]);
}

main();
