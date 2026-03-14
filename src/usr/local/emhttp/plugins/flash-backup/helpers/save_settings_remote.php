<?php
header('Content-Type: application/json');

// Path to the remote settings config and a temp file used
$config = '/boot/config/plugins/flash-backup/settings_remote.cfg';
$tmp    = $config . '.tmp';

// Read all expected fields from POST with safe defaults
$minimal_backup_remote       = $_POST['MINIMAL_BACKUP_REMOTE']       ?? 'no';
$backups_to_keep_remote      = $_POST['BACKUPS_TO_KEEP_REMOTE']      ?? '0';
$dry_run_remote              = $_POST['DRY_RUN_REMOTE']              ?? 'no';
$notifications_remote        = $_POST['NOTIFICATIONS_REMOTE']        ?? 'no';
$notification_service_remote = $_POST['NOTIFICATION_SERVICE_REMOTE'] ?? '';
$pushover_user_key_remote    = $_POST['PUSHOVER_USER_KEY_REMOTE']    ?? '';

// Accept either an array (multi-select) or a comma string
$rclone_raw = $_POST['RCLONE_CONFIG_REMOTE'] ?? '';
if (is_array($rclone_raw)) {
    $rclone_config_remote = implode(',', array_map('trim', $rclone_raw));
} else {
    $rclone_config_remote = trim((string)$rclone_raw);
}

// No leading slash, trailing slash enforced
$b2_bucket_name = trim($_POST['B2_BUCKET_NAME'] ?? '');
if ($b2_bucket_name !== '') {
    $b2_bucket_name = ltrim($b2_bucket_name, '/');
    if (substr($b2_bucket_name, -1) !== '/') {
        $b2_bucket_name .= '/';
    }
}

// Leading and trailing slash enforced, default applied if empty
$remote_path_in_config = trim($_POST['REMOTE_PATH_IN_CONFIG'] ?? '');
if ($remote_path_in_config === '') {
    $remote_path_in_config = '/Flash_Backups/';
} else {
    if ($remote_path_in_config[0] !== '/') {
        $remote_path_in_config = '/' . $remote_path_in_config;
    }
    if (substr($remote_path_in_config, -1) !== '/') {
        $remote_path_in_config .= '/';
    }
}

// Collect webhook URLs for all supported notification services
$services    = ['DISCORD', 'GOTIFY', 'NTFY', 'PUSHOVER', 'SLACK'];
$webhookUrls = [];
foreach ($services as $svc) {
    $webhookUrls[$svc] = $_POST['WEBHOOK_' . $svc . '_REMOTE'] ?? '';
}

// Strip quotes and newlines to prevent config file injection
function sanitize(string $val): string {
    return str_replace(['"', "'", "\n", "\r"], '', $val);
}

// Build the config key-value array in alphabetical order
$lines = [
    'B2_BUCKET_NAME'              => $b2_bucket_name,
    'BACKUPS_TO_KEEP_REMOTE'      => $backups_to_keep_remote,
    'DRY_RUN_REMOTE'              => $dry_run_remote,
    'MINIMAL_BACKUP_REMOTE'       => $minimal_backup_remote,
    'NOTIFICATION_SERVICE_REMOTE' => $notification_service_remote,
    'NOTIFICATIONS_REMOTE'        => $notifications_remote,
    'PUSHOVER_USER_KEY_REMOTE'    => $pushover_user_key_remote,
    'RCLONE_CONFIG_REMOTE'        => $rclone_config_remote,
    'REMOTE_PATH_IN_CONFIG'       => $remote_path_in_config,
    'WEBHOOK_DISCORD_REMOTE'      => $webhookUrls['DISCORD'],
    'WEBHOOK_GOTIFY_REMOTE'       => $webhookUrls['GOTIFY'],
    'WEBHOOK_NTFY_REMOTE'         => $webhookUrls['NTFY'],
    'WEBHOOK_PUSHOVER_REMOTE'     => $webhookUrls['PUSHOVER'],
    'WEBHOOK_SLACK_REMOTE'        => $webhookUrls['SLACK'],
];

$content = '';
foreach ($lines as $key => $val) {
    $content .= $key . '="' . sanitize($val) . '"' . "\n";
}

// Write to a temp file then rename over the real config
@mkdir(dirname($config), 0755, true);

if (file_put_contents($tmp, $content) === false) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to write temp config']);
    exit;
}

if (!rename($tmp, $config)) {
    @unlink($tmp);
    echo json_encode(['status' => 'error', 'message' => 'Failed to move config into place']);
    exit;
}

echo json_encode(['status' => 'ok']);
