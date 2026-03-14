<?php
/*
 * AppData Backup Plugin – AJAX Handler
 */

header('Content-Type: application/json');
set_time_limit(0);

$plugin    = 'appdata-backup-and-restore';
$configDir = "/boot/config/plugins/$plugin";
$configFile = "$configDir/config.cfg";
$logDir    = "/tmp/$plugin";
$lockFile  = "$logDir/backup.lock";
$logFile   = "$logDir/backup.log";
$pidFile   = "$logDir/backup.pid";
$statusFile = "$logDir/backup.status";
$lastFile  = "$logDir/last_backup.txt";
$sizeFile  = "$logDir/backup_size.txt";
$backupScript = "/usr/local/emhttp/plugins/$plugin/scripts/backup.sh";

// Ensure dirs exist
@mkdir($logDir, 0755, true);
@mkdir($configDir, 0755, true);

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
$action = isset($input['action']) ? $input['action'] : '';

// ── Load config ──────────────────────────────────────────────
function loadConfig($configFile) {
    $defaults = [
        'BACKUP_DEST'        => '/mnt/user/backups/appdata',
        'APPDATA_SRC'        => '/mnt/user/appdata',
        'STOP_CONTAINERS'    => 'yes',
        'COMPRESS'           => 'yes',
        'COMPRESSION_TYPE'   => 'gz',
        'RETENTION_DAYS'     => '7',
        'RETENTION_COUNT'    => '5',
        'SCHEDULED_ENABLE'   => 'no',
        'CRON_SCHEDULE'      => '0 3 * * *',
        'NOTIFY_ENABLE'      => 'yes',
        'NOTIFY_LEVEL'       => 'both',
        'EXCLUDE_CONTAINERS' => '',
        'INCLUDE_CONTAINERS' => '',
        'VERIFY_BACKUP'      => 'yes',
        'BACKUP_VMDISKS'     => 'no',
        'EXTRA_FOLDERS'      => '',
        'PRE_SCRIPT'         => '',
        'POST_SCRIPT'        => '',
        'RCLONE_ENABLE'      => 'no',
        'RCLONE_REMOTE'      => '',
        'RCLONE_PATH'        => '',
    ];
    if (file_exists($configFile)) {
        $lines = file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && $line[0] !== '#') {
                [$k, $v] = explode('=', $line, 2);
                $defaults[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
            }
        }
    }
    return $defaults;
}

// ── Actions ──────────────────────────────────────────────────
switch ($action) {

    case 'save_config':
        $cfg = isset($input['config']) ? $input['config'] : [];
        $allowed = ['BACKUP_DEST','APPDATA_SRC','STOP_CONTAINERS','COMPRESS','COMPRESSION_TYPE',
                    'RETENTION_DAYS','RETENTION_COUNT','SCHEDULED_ENABLE','CRON_SCHEDULE',
                    'NOTIFY_ENABLE','NOTIFY_LEVEL','EXCLUDE_CONTAINERS','INCLUDE_CONTAINERS',
                    'VERIFY_BACKUP','BACKUP_VMDISKS','EXTRA_FOLDERS','PRE_SCRIPT','POST_SCRIPT',
                    'RCLONE_ENABLE','RCLONE_REMOTE','RCLONE_PATH'];
        $lines = ["# AppData Backup Config – generated " . date('Y-m-d H:i:s') . "\n"];
        foreach ($allowed as $key) {
            $val = isset($cfg[$key]) ? $cfg[$key] : '';
            $val = str_replace(["\n","\r","\""], '', $val);
            $lines[] = "$key=\"$val\"\n";
        }
        if (file_put_contents($configFile, implode('', $lines)) !== false) {
            // Update cron if schedule changed
            $schedEnable = isset($cfg['SCHEDULED_ENABLE']) ? $cfg['SCHEDULED_ENABLE'] : 'no';
            $cronExpr    = isset($cfg['CRON_SCHEDULE']) ? $cfg['CRON_SCHEDULE'] : '0 3 * * *';
            updateCron($schedEnable, $cronExpr, $backupScript, $plugin);
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to write config']);
        }
        break;

    case 'start_backup':
        if (file_exists($lockFile)) {
            echo json_encode(['status' => 'error', 'message' => 'Backup already running']);
            break;
        }
        if (!file_exists($backupScript)) {
            echo json_encode(['status' => 'error', 'message' => 'Backup script not found: ' . $backupScript]);
            break;
        }
        $cmd = "bash $backupScript > $logFile 2>&1 & echo $!";
        $pid = shell_exec($cmd);
        if ($pid) {
            file_put_contents($pidFile, trim($pid));
            file_put_contents($lockFile, trim($pid));
            echo json_encode(['status' => 'ok', 'pid' => trim($pid)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to launch backup script']);
        }
        break;

    case 'stop_backup':
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if (is_numeric($pid)) {
                shell_exec("kill -TERM $pid 2>/dev/null");
                shell_exec("kill -TERM -$pid 2>/dev/null");
            }
        }
        // Cleanup will be done by the script's trap
        echo json_encode(['status' => 'ok']);
        break;

    case 'get_status':
        $running = file_exists($lockFile);
        $progress = 0;
        $message  = '';
        $success  = false;
        $recentLog = '';

        if (file_exists($statusFile)) {
            $statusData = json_decode(file_get_contents($statusFile), true);
            if ($statusData) {
                $progress = isset($statusData['progress']) ? (int)$statusData['progress'] : 0;
                $message  = isset($statusData['message']) ? $statusData['message'] : '';
                $success  = isset($statusData['success']) ? (bool)$statusData['success'] : false;
            }
        }

        // Last 20 lines of log
        if (file_exists($logFile)) {
            $lines = file($logFile);
            $tail  = array_slice($lines, -20);
            $recentLog = implode('', $tail);
        }

        echo json_encode([
            'running'    => $running,
            'progress'   => $progress,
            'message'    => $message,
            'success'    => $success,
            'recent_log' => $recentLog,
        ]);
        break;

    case 'get_log':
        $log = '';
        if (file_exists($logFile)) {
            $log = file_get_contents($logFile);
            if (strlen($log) > 200000) {
                $log = substr($log, -200000);
            }
        }
        echo json_encode(['log' => $log]);
        break;

    case 'clear_log':
        if (file_exists($logFile)) unlink($logFile);
        echo json_encode(['status' => 'ok']);
        break;

    case 'list_backups':
        $cfg  = loadConfig($configFile);
        $dest = rtrim($cfg['BACKUP_DEST'], '/');
        $backups = [];
        if (is_dir($dest)) {
            $items = glob($dest . '/appdata_backup_*');
            if ($items) {
                rsort($items);
                foreach ($items as $item) {
                    $name = basename($item);
                    $date = '—';
                    // Parse date from name: appdata_backup_YYYY-MM-DD_HHMMSS
                    if (preg_match('/appdata_backup_(\d{4}-\d{2}-\d{2})_(\d{6})/', $name, $m)) {
                        $date = $m[1] . ' ' . substr($m[2],0,2) . ':' . substr($m[2],2,2) . ':' . substr($m[2],4,2);
                    }
                    // Size
                    $sizeOut = shell_exec("du -sh " . escapeshellarg($item) . " 2>/dev/null");
                    $size = $sizeOut ? explode("\t", trim($sizeOut))[0] : '—';
                    // Container count from manifest
                    $containers = '—';
                    $manifest = $item . '/manifest.txt';
                    if (file_exists($manifest)) {
                        $lines = file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        $containers = count($lines);
                    }
                    $backups[] = [
                        'name'       => $name,
                        'date'       => $date,
                        'size'       => $size,
                        'containers' => $containers,
                    ];
                }
            }
        }
        echo json_encode(['backups' => $backups]);
        break;

    case 'delete_backup':
        $cfg  = loadConfig($configFile);
        $name = isset($input['name']) ? $input['name'] : '';
        // Sanitize – only allow valid backup dir names
        if (!preg_match('/^appdata_backup_[\w\-]+$/', $name)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid backup name']);
            break;
        }
        $dest = rtrim($cfg['BACKUP_DEST'], '/');
        $path = $dest . '/' . $name;
        if (!is_dir($path)) {
            echo json_encode(['status' => 'error', 'message' => 'Backup not found']);
            break;
        }
        // Safety: ensure path is inside expected backup dir
        $realPath = realpath($path);
        $realDest = realpath($dest);
        if (!$realPath || !$realDest || strpos($realPath, $realDest) !== 0) {
            echo json_encode(['status' => 'error', 'message' => 'Path traversal detected']);
            break;
        }
        shell_exec("rm -rf " . escapeshellarg($realPath));
        echo json_encode(['status' => 'ok']);
        break;

    case 'get_stats':
        $cfg   = loadConfig($configFile);
        $dest  = rtrim($cfg['BACKUP_DEST'], '/');
        $count = 0;
        if (is_dir($dest)) {
            $items = glob($dest . '/appdata_backup_*', GLOB_ONLYDIR);
            $count = $items ? count($items) : 0;
        }
        $last = file_exists($lastFile) ? trim(file_get_contents($lastFile)) : 'Never';
        $size = file_exists($sizeFile) ? trim(file_get_contents($sizeFile)) : '—';
        echo json_encode([
            'last_backup'  => $last,
            'backup_size'  => $size,
            'backup_count' => $count,
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
        break;
}

// ── Cron Helper ──────────────────────────────────────────────
function updateCron($enable, $expr, $script, $plugin) {
    $tag     = "# appdata-backup-and-restore-cron";
    $cronLine = "$expr bash $script >> /tmp/$plugin/backup.log 2>&1 $tag";
    $current  = shell_exec("crontab -l 2>/dev/null") ?: '';
    // Remove existing entry
    $lines    = explode("\n", $current);
    $lines    = array_filter($lines, function($l) use ($tag) {
        return strpos($l, $tag) === false;
    });
    if ($enable === 'yes') {
        $lines[] = $cronLine;
    }
    $new = implode("\n", $lines);
    $new = rtrim($new) . "\n";
    $tmp = tempnam('/tmp', 'cron_');
    file_put_contents($tmp, $new);
    shell_exec("crontab $tmp 2>/dev/null");
    unlink($tmp);
}
