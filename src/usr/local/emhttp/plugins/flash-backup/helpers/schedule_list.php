<?php

define('SCHEDULES_CFG', '/boot/config/plugins/flash-backup/schedules.cfg');

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
if (file_exists(SCHEDULES_CFG)) {
    $schedules = parse_ini_file(SCHEDULES_CFG, true, INI_SCANNER_RAW);
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
  <th>Destination</th>
  <th>Keep</th>
  <th>Owner</th>
  <th>Dry Run</th>
  <th>Notify</th>
  <th>Actions</th>
</tr>
</thead>
<tbody>
<?php foreach ($schedules as $id => $s): ?>
<?php
  $enabledBool  = ($s['ENABLED'] ?? 'yes') === 'yes';
  $btnText      = $enabledBool ? 'Disable' : 'Enable';
  $dotClass     = $enabledBool ? 'enabled' : 'disabled';
  $cron         = $s['CRON'] ?? '';
  $settings     = [];
  if (!empty($s['SETTINGS'])) { $r = stripslashes($s['SETTINGS']); $settings = json_decode($r, true) ?: []; }
  $dest         = $settings['BACKUP_DESTINATION'] ?? '—';
  $btk          = (int)($settings['BACKUPS_TO_KEEP'] ?? -1);
  $backupsToKeep = $btk === -1 ? '—' : ($btk === 1 ? 'Only Latest' : ($btk === 0 ? 'Unlimited' : $btk));
  $backupOwner  = $settings['BACKUP_OWNER'] ?? '—';
  $minimalBackup = isset($settings['MINIMAL_BACKUP']) ? yes_no($settings['MINIMAL_BACKUP']) : '—';
  $dryRun       = isset($settings['DRY_RUN']) ? yes_no($settings['DRY_RUN']) : '—';
  $notify       = isset($settings['NOTIFICATIONS']) ? yes_no($settings['NOTIFICATIONS']) : '—';
  $id_esc       = htmlspecialchars($id);
?>
<tr>
  <td style="text-align:left !important;">
    <div style="display:flex;align-items:center;gap:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
      <span class="fbb-sched-dot <?= $dotClass ?>" style="flex-shrink:0;margin-right:6px;"></span>
      <span class="flash-backuptip" title="<?= htmlspecialchars(human_cron($cron) . ' — ' . $cron) ?>"><?= htmlspecialchars(human_cron($cron)) ?></span>
    </div>
  </td>
  <td><?= htmlspecialchars($minimalBackup) ?></td>
  <td class="sched-ellipsis"><span class="flash-backuptip" title="<?= htmlspecialchars($dest) ?>"><?= htmlspecialchars($dest) ?></span></td>
  <td><?= htmlspecialchars($backupsToKeep) ?></td>
  <td><?= htmlspecialchars($backupOwner) ?></td>
  <td><?= htmlspecialchars($dryRun) ?></td>
  <td><?= htmlspecialchars($notify) ?></td>
  <td>
    <div class="sched-actions">
      <button type="button" onclick="editSchedule('<?= $id_esc ?>')">Edit</button>
      <button type="button" onclick="toggleSchedule('<?= $id_esc ?>', <?= $enabledBool ? 'true' : 'false' ?>)"><?= $btnText ?></button>
      <button type="button" onclick="deleteSchedule('<?= $id_esc ?>')">Delete</button>
      <button type="button" class="schedule-run-btn" onclick="runScheduleBackup('<?= $id_esc ?>', this)">Run</button>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>