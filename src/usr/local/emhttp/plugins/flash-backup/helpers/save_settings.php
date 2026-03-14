<?php
header('Content-Type: application/json');

// Path to the local settings config and a temp file used
$config = '/boot/config/plugins/flash-backup/settings.cfg';
$tmp    = $config . '.tmp';

// Read all expected fields from POST with safe defaults
$minimal_backup       = $_POST['MINIMAL_BACKUP']       ?? '';
$backup_destination   = $_POST['BACKUP_DESTINATION']   ?? '';
$backups_to_keep      = $_POST['BACKUPS_TO_KEEP']      ?? '0';
$backup_owner         = $_POST['BACKUP_OWNER']         ?? 'nobody';
$dry_run              = $_POST['DRY_RUN']              ?? 'no';
$notifications        = $_POST['NOTIFICATIONS']        ?? 'no';
$notification_service = $_POST['NOTIFICATION_SERVICE'] ?? '';
$pushover_user_key    = $_POST['PUSHOVER_USER_KEY']    ?? '';

// Collect webhook URLs for all supported notification services
$services    = ['DISCORD', 'GOTIFY', 'NTFY', 'PUSHOVER', 'SLACK'];
$webhookUrls = [];
foreach ($services as $svc) {
    $webhookUrls[$svc] = $_POST['WEBHOOK_' . $svc] ?? '';
}

// Strip quotes and newlines to prevent config file injection
function sanitize(string $val): string {
    return str_replace(['"', "'", "\n", "\r"], '', $val);
}

// Build the config key-value array in alphabetical order
$lines = [
    'BACKUP_DESTINATION'   => $backup_destination,
    'BACKUP_OWNER'         => $backup_owner,
    'BACKUPS_TO_KEEP'      => $backups_to_keep,
    'DRY_RUN'              => $dry_run,
    'MINIMAL_BACKUP'       => $minimal_backup,
    'NOTIFICATION_SERVICE' => $notification_service,
    'NOTIFICATIONS'        => $notifications,
    'PUSHOVER_USER_KEY'    => $pushover_user_key,
    'WEBHOOK_DISCORD'      => $webhookUrls['DISCORD'],
    'WEBHOOK_GOTIFY'       => $webhookUrls['GOTIFY'],
    'WEBHOOK_NTFY'         => $webhookUrls['NTFY'],
    'WEBHOOK_PUSHOVER'     => $webhookUrls['PUSHOVER'],
    'WEBHOOK_SLACK'        => $webhookUrls['SLACK'],
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
