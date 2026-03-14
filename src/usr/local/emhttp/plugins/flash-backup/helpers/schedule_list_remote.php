<?php

define('SCHEDULES_CFG_REMOTE', '/boot/config/plugins/flash-backup/schedules-remote.cfg');


function yes_no(string $value): string {
    $v = strtolower($value);
    return ($v === 'yes' || $v === '1' || $v === 'true') ? 'Yes' : 'No';
}

function human_cron(string $cron): string {
    $cron  = trim($cron);
    $parts = preg_split('/\s+/', $cron);
    if (count($parts) !== 5) return $cron;
    [$min, $hour, $dom, $month, $dow] = $parts;
    if ($min === '*' && $hour === '*' && $dom === '*' && $month === '*' && $dow === '*') return 'Runs every minute';
    if (preg_match('/^\*\/(\d+)$/', $min, $m) && $hour === '*' && $dom === '*' && $month === '*' && $dow === '*') { $n = (int)$m[1]; return "Runs every $n minute" . ($n !== 1 ? 's' : ''); }
    if ($min === '0' && preg_match('/^\*\/(\d+)$/', $hour, $m) && $dom === '*' && $month === '*' && $dow === '*') { $n = (int)$m[1]; return "Runs every $n hour" . ($n !== 1 ? 's' : ''); }
    if (preg_match('/^\d+$/', $min) && preg_match('/^\d+$/', $hour) && $dom === '*' && $month === '*' && $dow === '*') { $t = date('g:i A', mktime((int)$hour, (int)$min)); return "Runs daily at $t"; }
    if (preg_match('/^\d+$/', $min) && preg_match('/^\d+$/', $hour) && $dom === '*' && $month === '*' && preg_match('/^\d+$/', $dow)) { $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday']; $t = date('g:i A', mktime((int)$hour, (int)$min)); $d = $days[(int)$dow] ?? $dow; return "Runs every $d at $t"; }
    if (preg_match('/^\d+$/', $min) && preg_match('/^\d+$/', $hour) && preg_match('/^\d+$/', $dom) && $month === '*' && $dow === '*') { $t = date('g:i A', mktime((int)$hour, (int)$min)); $dom_i = (int)$dom; $suffix = match($dom_i % 10) { 1 => ($dom_i === 11) ? 'th' : 'st', 2 => ($dom_i === 12) ? 'th' : 'nd', 3 => ($dom_i === 13) ? 'th' : 'rd', default => 'th' }; return "Runs monthly on the {$dom}{$suffix} at $t"; }
    return $cron;
}

$schedules = [];
if (file_exists(SCHEDULES_CFG_REMOTE)) {
    $schedules = parse_ini_file(SCHEDULES_CFG_REMOTE, true, INI_SCANNER_RAW);
}
?>
<?php if (!empty($schedules)): ?>
<style>
.fbb-sched-dot {
  display:inline-block; width:8px; height:8px; border-radius:50%;
  margin-right:6px; vertical-align:middle; flex-shrink:0;
}
.fbb-sched-dot.enabled  { background:#22c55e; box-shadow:0 0 5px #22c55e; }
.fbb-sched-dot.disabled { background:#ef4444; box-shadow:0 0 5px #ef4444; }
</style>

<div class="TableContainer">
<table class="vm-schedules-table">
<colgroup>
  <col><col><col><col><col><col><col><col>
</colgroup>
<thead>
<tr>
  <th>Scheduling</th>
  <th>Minimal</th>
  <th>Config</th>
  <th>Folder</th>
  <th>Keep</th>
  <th>Dry Run</th>
  <th>Notify</th>
  <th>Actions</th>
</tr>
</thead>
<tbody>
<?php foreach ($schedules as $id => $s): ?>
<?php
  $enabledBool   = ($s['ENABLED'] ?? 'yes') === 'yes';
  $btnText       = $enabledBool ? 'Disable' : 'Enable';
  $dotClass      = $enabledBool ? 'enabled' : 'disabled';
  $cron          = $s['CRON'] ?? '';
  $settings      = [];
  if (!empty($s['SETTINGS'])) { $r = stripslashes($s['SETTINGS']); $settings = json_decode($r, true) ?: []; }
  $rcloneConfig  = $settings['RCLONE_CONFIG_REMOTE'] ?? '—';
  $pathInConfig  = $settings['REMOTE_PATH_IN_CONFIG'] ?? '—';
  $btk           = (int)($settings['BACKUPS_TO_KEEP_REMOTE'] ?? -1);
  $backupsToKeep = $btk === -1 ? '—' : ($btk === 1 ? 'Only Latest' : ($btk === 0 ? 'Unlimited' : $btk));
  $minimalBackup = isset($settings['MINIMAL_BACKUP_REMOTE']) ? yes_no($settings['MINIMAL_BACKUP_REMOTE']) : '—';
  $dryRun        = isset($settings['DRY_RUN_REMOTE'])        ? yes_no($settings['DRY_RUN_REMOTE']) : '—';
  $notify        = isset($settings['NOTIFICATIONS_REMOTE'])  ? yes_no($settings['NOTIFICATIONS_REMOTE']) : '—';
  $id_esc        = htmlspecialchars($id);
?>
<tr>
  <td style="text-align:left !important;">
    <div style="display:flex;align-items:center;gap:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
      <span class="fbb-sched-dot <?= $dotClass ?>" style="flex-shrink:0;margin-right:6px;"></span>
      <span class="flash-backuptip" title="<?= htmlspecialchars(human_cron($cron) . ' — ' . $cron) ?>"><?= htmlspecialchars(human_cron($cron)) ?></span>
    </div>
  </td>
  <td><?= htmlspecialchars($minimalBackup) ?></td>
  <td class="sched-ellipsis"><span class="flash-backuptip" title="<?= htmlspecialchars($rcloneConfig) ?>"><?= htmlspecialchars($rcloneConfig) ?></span></td>
  <td class="sched-ellipsis"><span class="flash-backuptip" title="<?= htmlspecialchars($pathInConfig) ?>"><?= htmlspecialchars($pathInConfig) ?></span></td>
  <td><?= htmlspecialchars($backupsToKeep) ?></td>
  <td><?= htmlspecialchars($dryRun) ?></td>
  <td><?= htmlspecialchars($notify) ?></td>
  <td>
    <div class="sched-actions">
      <button type="button" onclick="editScheduleremote('<?= $id_esc ?>')">Edit</button>
      <button type="button" onclick="toggleScheduleremote('<?= $id_esc ?>', <?= $enabledBool ? 'true' : 'false' ?>)"><?= $btnText ?></button>
      <button type="button" onclick="deleteScheduleremote('<?= $id_esc ?>')">Delete</button>
      <button type="button" class="schedule-run-btn" onclick="runScheduleBackupremote('<?= $id_esc ?>', this)">Run</button>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>