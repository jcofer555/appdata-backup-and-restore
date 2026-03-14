#!/bin/bash
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

readonly PLUGIN_NAME="flash-backup"
readonly SETTINGS_FILE="/boot/config/plugins/${PLUGIN_NAME}/settings.cfg"
readonly LOG_DIR="/tmp/flash-backup"
readonly LAST_RUN_FILE="${LOG_DIR}/flash-backup.log"
readonly ROTATE_DIR="${LOG_DIR}/archived_logs"
readonly STATUS_FILE="${LOG_DIR}/local_backup_status.txt"
readonly DEBUG_LOG="${LOG_DIR}/flash-backup-debug.log"
# Rotate log files when they exceed 10 MB
readonly LOG_MAX_BYTES=$(( 10 * 1024 * 1024 ))
# Number of rotated log archives to retain before purging the oldest
readonly LOG_ROTATE_KEEP=10

# ------------------------------------------------------------------------------
# Import environment variables from backup.php (manual or scheduled)
# ------------------------------------------------------------------------------
if [[ -n "${BACKUP_DESTINATION:-}" ]]; then
    DRY_RUN="${DRY_RUN:-no}"
    MINIMAL_BACKUP="${MINIMAL_BACKUP:-no}"
    BACKUPS_TO_KEEP="${BACKUPS_TO_KEEP:-0}"
    BACKUP_OWNER="${BACKUP_OWNER:-nobody}"
    NOTIFICATIONS="${NOTIFICATIONS:-no}"
fi

# Remove accidental quotes
BACKUPS_TO_KEEP="${BACKUPS_TO_KEEP//\"/}"
BACKUP_DESTINATION="${BACKUP_DESTINATION//\"/}"
BACKUP_OWNER="${BACKUP_OWNER//\"/}"
DRY_RUN="${DRY_RUN//\"/}"
MINIMAL_BACKUP="${MINIMAL_BACKUP//\"/}"
NOTIFICATIONS="${NOTIFICATIONS//\"/}"
NOTIFICATION_SERVICE="${NOTIFICATION_SERVICE//\"/}"
WEBHOOK_DISCORD="${WEBHOOK_DISCORD//\"/}"
WEBHOOK_GOTIFY="${WEBHOOK_GOTIFY//\"/}"
WEBHOOK_NTFY="${WEBHOOK_NTFY//\"/}"
WEBHOOK_PUSHOVER="${WEBHOOK_PUSHOVER//\"/}"
WEBHOOK_SLACK="${WEBHOOK_SLACK//\"/}"
PUSHOVER_USER_KEY="${PUSHOVER_USER_KEY//\"/}"

readonly SCRIPT_START_EPOCH=$(date +%s)

# Path to the stop flag file — created by stop_backup.php to request a graceful stop
STOP_FLAG="/tmp/flash-backup/stop_requested.txt"
# PID of the running tar process, used by stop_backup.php to send SIGTERM
TAR_PID=""
# PID of the background watcher loop that monitors the stop flag
WATCHER_PID=""

# ------------------------------------------------------------------------------
# Helpers
# ------------------------------------------------------------------------------
format_duration() {
    local total=$1
    local h=$(( total / 3600 ))
    local m=$(( (total % 3600) / 60 ))
    local s=$(( total % 60 ))
    local out=""
    (( h > 0 )) && out+="${h}h "
    (( m > 0 )) && out+="${m}m "
    out+="${s}s"
    echo "$out"
}

debug_log() {
    echo "[DEBUG $(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$DEBUG_LOG"
}

set_status() {
    echo "$1" > "$STATUS_FILE"
}

# ------------------------------------------------------------------------------
# log rotation
# ------------------------------------------------------------------------------
rotate_log() {
    local log_file="$1"
    local pattern="$2"

    if [[ -f "$log_file" ]]; then
        local size_bytes
        size_bytes=$(stat -c%s "$log_file")
        if (( size_bytes >= LOG_MAX_BYTES )); then
            local ts
            ts="$(date +%Y%m%d_%H%M%S)"
            local rotated="${ROTATE_DIR}/${pattern}_${ts}.log"
            mv "$log_file" "$rotated"
            debug_log "Rotated log: $log_file -> $rotated (was >= 10MB)"
        fi
    fi

    mapfile -t _rotated < <(ls -1t "${ROTATE_DIR}/${pattern}_"*.log 2>/dev/null)
    if (( ${#_rotated[@]} > LOG_ROTATE_KEEP )); then
        for (( i=LOG_ROTATE_KEEP; i<${#_rotated[@]}; i++ )); do
            rm -f "${_rotated[$i]}"
            debug_log "Purged old rotated log: ${_rotated[$i]}"
        done
    fi
}

# ------------------------------------------------------------------------------
# Notification helper
# ------------------------------------------------------------------------------
notify_local() {
    local level="$1"
    local subject="$2"
    local message="$3"

    debug_log "notify_local called: level=$level subject=$subject message=$message"

    [[ "$NOTIFICATIONS" != "yes" ]] && { debug_log "Notifications disabled, skipping"; return 0; }

    local color
    case "$level" in
        alert)   color=15158332 ;;
        warning) color=16776960 ;;
        *)       color=3066993  ;;
    esac

    # Iterate over each configured notification service (comma-separated list)
    IFS=',' read -ra SERVICES <<< "$NOTIFICATION_SERVICE"
    for service in "${SERVICES[@]}"; do
        service="${service// /}"
        case "$service" in
            Discord)
                if [[ -n "$WEBHOOK_DISCORD" ]]; then
                    debug_log "Sending Discord notification"
                    curl -sf -X POST "$WEBHOOK_DISCORD" \
                        -H "Content-Type: application/json" \
                        -d "{\"embeds\":[{\"title\":\"$subject\",\"description\":\"$message\",\"color\":$color}]}" || true
                fi
                ;;
            Gotify)
                if [[ -n "$WEBHOOK_GOTIFY" ]]; then
                    debug_log "Sending Gotify notification"
                    curl -sf -X POST "$WEBHOOK_GOTIFY" \
                        -H "Content-Type: application/json" \
                        -d "{\"title\":\"$subject\",\"message\":\"$message\",\"priority\":5}" || true
                fi
                ;;
            Ntfy)
                if [[ -n "$WEBHOOK_NTFY" ]]; then
                    debug_log "Sending Ntfy notification"
                    curl -sf -X POST "$WEBHOOK_NTFY" \
                        -H "Title: $subject" \
                        -d "$message" > /dev/null || true
                fi
                ;;
            Pushover)
                if [[ -n "$WEBHOOK_PUSHOVER" ]]; then
                    debug_log "Sending Pushover notification"
                    local token="${WEBHOOK_PUSHOVER##*/}"
                    curl -sf -X POST "https://api.pushover.net/1/messages.json" \
                        -d "token=${token}" \
                        -d "user=${PUSHOVER_USER_KEY}" \
                        -d "title=${subject}" \
                        -d "message=${message}" > /dev/null || true
                fi
                ;;
            Slack)
                if [[ -n "$WEBHOOK_SLACK" ]]; then
                    debug_log "Sending Slack notification"
                    curl -sf -X POST "$WEBHOOK_SLACK" \
                        -H "Content-Type: application/json" \
                        -d "{\"text\":\"*$subject*\n$message\"}" || true
                fi
                ;;
            Unraid)
                if [[ -x /usr/local/emhttp/webGui/scripts/notify ]]; then
                    debug_log "Sending Unraid native notification"
                    /usr/local/emhttp/webGui/scripts/notify \
                        -e "Flash Backup" \
                        -s "$subject" \
                        -d "$message" \
                        -i "$level"
                else
                    debug_log "Unraid notify script not found"
                fi
                ;;
        esac
    done
}

# ------------------------------------------------------------------------------
# Cleanup
# ------------------------------------------------------------------------------
cleanup() {
    # Stop the background watcher loop
    kill "$WATCHER_PID" 2>/dev/null

    local lock_file="${LOG_DIR}/lock.txt"
    rm -f "$lock_file"
    debug_log "Lock file removed"

    local script_end_epoch script_duration script_duration_human
    script_end_epoch=$(date +%s)
    script_duration=$(( script_end_epoch - SCRIPT_START_EPOCH ))
    script_duration_human="$(format_duration "$script_duration")"

    # Handle a user-requested stop — clean up the stop flag and any partial archive
    if [[ -f "$STOP_FLAG" ]]; then
        rm -f "$STOP_FLAG"
        rm -f "${LOG_DIR}"/*.tmp 2>/dev/null
        if [[ -n "${CURRENT_BACKUP_FILE:-}" ]]; then
            rm -f "$CURRENT_BACKUP_FILE" 2>/dev/null
        fi
        echo "Backup stopped early - Duration: $script_duration_human"
        echo "Local backup session finished - $(date '+%Y-%m-%d %H:%M:%S')"
        notify_local "warning" "Flash Backup" \
            "Local backup stopped early - Duration: $script_duration_human"
        set_status "Backup stopped"
        rm -f "$STATUS_FILE"
        debug_log "===== Session ended (stopped) ====="
        return
    fi

    set_status "Local backup complete - Duration: $script_duration_human"
    echo "Backup duration: $script_duration_human"
    echo "Local backup session finished - $(date '+%Y-%m-%d %H:%M:%S')"
    debug_log "Session finished - duration=$script_duration_human error_count=$error_count"

    if (( error_count > 0 )); then
        notify_local "warning" "Flash Backup" \
            "Local backup finished with errors - Duration: $script_duration_human - Check logs for details"
    else
        notify_local "normal" "Flash Backup" \
            "Local backup finished - Duration: $script_duration_human"
    fi

    rm -f "$STATUS_FILE"
    debug_log "===== Session ended ====="
}

_STOPPING=0
handle_signal() {
    if [[ "$_STOPPING" == "0" ]]; then _STOPPING=1; exit 1; fi
}

trap cleanup EXIT
trap handle_signal SIGTERM SIGINT SIGHUP SIGQUIT

# Background watcher loop — polls for the stop flag every second and sends SIGTERM to the main process
( trap '' SIGTERM; while true; do
    sleep 1
    if [[ -f "$STOP_FLAG" ]]; then kill -TERM $$ 2>/dev/null; break; fi
done ) &>/dev/null &
WATCHER_PID=$!

# ------------------------------------------------------------------------------
# Build tar paths — minimal vs full
# ------------------------------------------------------------------------------
build_tar_paths() {
    TAR_PATHS=()

    if [[ "$MINIMAL_BACKUP" == "yes" ]]; then
        echo "Minimal backup mode only backing up /config, /extra, and /syslinux/syslinux.cfg"
        debug_log "Minimal backup mode enabled"
        local path
        for path in "/boot/config" "/boot/extra" "/boot/syslinux/syslinux.cfg"; do
            if [[ -e "$path" ]]; then
                TAR_PATHS+=("${path#/}")
                debug_log "Including path: $path"
            else
                echo "Skipping missing path -> $path"
                debug_log "Skipping missing path: $path"
            fi
        done
        if (( ${#TAR_PATHS[@]} == 0 )); then
            debug_log "ERROR: No valid paths found for minimal backup"
            echo "[ERROR] No valid paths found"
            set_status "No valid paths found"
            notify_local "alert" "Flash Backup Error" "No valid paths found for minimal backup"
            exit 1
        fi
    else
        echo "Full backup mode backing up entire /boot"
        debug_log "Full backup mode enabled"
        TAR_PATHS=("boot")
    fi

    debug_log "TAR_PATHS: ${TAR_PATHS[*]}"
}

# ------------------------------------------------------------------------------
# Create archive — tmp then rename
# ------------------------------------------------------------------------------
create_archive() {
    local backup_file="$1"
    local tmp_backup_file="${backup_file}.tmp"

    debug_log "backup_file=$backup_file"
    debug_log "tmp_backup_file=$tmp_backup_file"

    set_status "Creating backup archive"

    if [[ "$DRY_RUN" == "yes" ]]; then
        echo "[DRY RUN] Would create archive at -> $backup_file"
        debug_log "[DRY RUN] Would create archive: $backup_file"
        return 0
    fi

    CURRENT_BACKUP_FILE="$backup_file"
    debug_log "Starting tar archive creation"
    if [[ "${TAR_PATHS[0]}" == "boot" ]]; then
        tar czf "$tmp_backup_file" -C / boot &
    else
        tar czf "$tmp_backup_file" -C / "${TAR_PATHS[@]}" &
    fi
    TAR_PID=$!
    echo "$TAR_PID" > "$LOG_DIR/tar.pid"
    wait $TAR_PID
    local tar_exit=$?
    TAR_PID=""
    rm -f "$LOG_DIR/tar.pid"
    if [[ -f "$STOP_FLAG" ]] || (( tar_exit > 128 )); then
        debug_log "tar interrupted (exit=$tar_exit)"; exit 1
    fi
    if (( tar_exit != 0 )); then
        debug_log "ERROR: tar failed (exit=$tar_exit)"
        echo "[ERROR] Failed to create backup"
        notify_local "alert" "Flash Backup Error" "Failed to create backup tar archive"
        exit 1
    fi
    debug_log "tar archive created: $tmp_backup_file"

    tar -tf "$tmp_backup_file" >/dev/null 2>&1 || {
        debug_log "ERROR: Integrity check failed for: $tmp_backup_file"
        echo "[ERROR] Backup integrity check failed"
        notify_local "alert" "Flash Backup Error" "Backup integrity check failed"
        exit 1
    }
    debug_log "Integrity check passed"

    mv "$tmp_backup_file" "$backup_file"
    debug_log "Renamed tmp to final: $backup_file"
    echo "Created backup at -> $backup_file"

    if [[ -f "$backup_file" ]]; then
        local backup_size
        backup_size=$(du -sh "$backup_file" 2>/dev/null | cut -f1)
        debug_log "Final backup size: $backup_size"
    fi
}

# ------------------------------------------------------------------------------
# Set ownership
# ------------------------------------------------------------------------------
set_ownership() {
    local backup_file="$1"

    set_status "Changing ownership"

    if [[ "$DRY_RUN" == "yes" ]]; then
        echo "[DRY RUN] Would change owner to $BACKUP_OWNER:users"
        debug_log "[DRY RUN] Would chown $BACKUP_OWNER:users $backup_file"
        return 0
    fi

    debug_log "Changing ownership to $BACKUP_OWNER:users on $backup_file"
    chown "$BACKUP_OWNER:users" "$backup_file" || echo "[WARNING] Failed to change owner"
    echo "Changed owner to $BACKUP_OWNER:users"
    debug_log "Ownership change complete"
}

# ------------------------------------------------------------------------------
# Prune old backups
# ------------------------------------------------------------------------------
prune_old_backups() {
    local dest="$1"

    set_status "Cleaning up old backups"

    if (( BACKUPS_TO_KEEP == 0 )); then
        debug_log "BACKUPS_TO_KEEP=0, skipping old backup cleanup"
        return 0
    fi

    local keep_label backup_word
    if (( BACKUPS_TO_KEEP == 1 )); then
        keep_label="only latest"
        backup_word="backup"
    else
        keep_label="$BACKUPS_TO_KEEP"
        backup_word="backups"
    fi

    if [[ "$DRY_RUN" == "yes" ]]; then
        echo "[DRY RUN] Removing old backups keeping $keep_label $backup_word for destination $dest"
        debug_log "[DRY RUN] Would remove old backups keeping $keep_label $backup_word for: $dest"
    else
        echo "Removing old backups keeping $keep_label $backup_word for destination $dest"
        debug_log "Removing old backups keeping $keep_label $backup_word for: $dest"
    fi

    mapfile -t backup_files < <(ls -1t "${dest}"flash_*.tar.gz 2>/dev/null)
    local num_backups=${#backup_files[@]}
    debug_log "Found $num_backups existing backup(s) in $dest"

    if (( num_backups > BACKUPS_TO_KEEP )); then
        local remove_count=$(( num_backups - BACKUPS_TO_KEEP ))
        debug_log "Need to remove $remove_count old backup(s)"

        for (( idx=BACKUPS_TO_KEEP; idx<num_backups; idx++ )); do
            if [[ "$DRY_RUN" == "yes" ]]; then
                echo "[DRY RUN] Would remove ${backup_files[$idx]}"
                debug_log "[DRY RUN] Would remove: ${backup_files[$idx]}"
            else
                local file="${backup_files[$idx]}"
                debug_log "Removing old backup: $file"
                rm -f "$file" || echo "[WARNING] Failed to remove file $file"
                debug_log "Removed: $file"
            fi
        done
    else
        debug_log "No old backups to remove ($num_backups backups, keeping $BACKUPS_TO_KEEP)"
    fi
}

# ------------------------------------------------------------------------------
# Process a single destination
# ------------------------------------------------------------------------------
process_destination() {
    local dest="$1"
    local dest_count="$2"

    # Trim leading and trailing whitespace from the destination path
    dest="${dest#"${dest%%[![:space:]]*}"}"
    dest="${dest%"${dest##*[![:space:]]}"}"

    [[ -z "$dest" ]] && { debug_log "Skipping empty destination entry"; return 0; }

    debug_log "Processing destination: $dest"

    (( dest_count > 1 )) && { echo ""; echo "Processing destination -> $dest"; }

    if [[ ! -d "$dest" ]]; then
        if [[ "$DRY_RUN" == "yes" ]]; then
            echo "[DRY RUN] Would create directory -> $dest"
            debug_log "[DRY RUN] Would create directory: $dest"
        else
            debug_log "Directory does not exist, attempting to create: $dest"
            mkdir -p "$dest" || {
                debug_log "ERROR: Failed to create directory: $dest"
                echo "[ERROR] Failed to create backup destination -> $dest"
                notify_local "alert" "Flash Backup Error" "Failed to create backup destination: $dest"
                exit 1
            }
            debug_log "Directory created: $dest"
        fi
    else
        debug_log "Destination directory exists: $dest"
    fi

    # Ensure the destination path ends with a trailing slash
    [[ "$dest" != */ ]] && dest="${dest}/"

    local ts backup_file
    ts="$(date +"%Y-%m-%d_%H-%M-%S")"
    backup_file="${dest}flash_${ts}.tar.gz"

    create_archive "$backup_file"
    set_ownership "$backup_file"
    prune_old_backups "$dest"
}

# ------------------------------------------------------------------------------
# Main
# ------------------------------------------------------------------------------
main() {
    mkdir -p "$LOG_DIR" "$ROTATE_DIR"

    rotate_log "$LAST_RUN_FILE" "flash-backup"
    rotate_log "$DEBUG_LOG"     "flash-backup-debug"

    exec > >(tee -a "$LAST_RUN_FILE") 2>&1

    # Read plugin version from the .plg installer file for logging
PLG_FILE="/boot/config/plugins/flash-backup.plg"
if [[ -f "$PLG_FILE" ]]; then
    version=$(grep -oP 'version="\K[^"]+' "$PLG_FILE" | head -n1)
else
    version="unknown"
fi

    echo "----------------------------------------------------------------------------------------"
    echo "Local backup session started - $(date '+%Y-%m-%d %H:%M:%S')"
    echo "Plugin version: $version"
    set_status "Starting local backup"

    debug_log "===== Session started ====="
    debug_log "BACKUP_DESTINATION=$BACKUP_DESTINATION"
    debug_log "BACKUPS_TO_KEEP=$BACKUPS_TO_KEEP"
    debug_log "BACKUP_OWNER=$BACKUP_OWNER"
    debug_log "DRY_RUN=$DRY_RUN"
    debug_log "MINIMAL_BACKUP=$MINIMAL_BACKUP"
    debug_log "NOTIFICATIONS=$NOTIFICATIONS"
    debug_log "NOTIFICATION_SERVICE=$NOTIFICATION_SERVICE"
    debug_log "WEBHOOK_DISCORD=${WEBHOOK_DISCORD:+(set)}"
    debug_log "WEBHOOK_GOTIFY=${WEBHOOK_GOTIFY:+(set)}"
    debug_log "WEBHOOK_NTFY=${WEBHOOK_NTFY:+(set)}"
    debug_log "WEBHOOK_PUSHOVER=${WEBHOOK_PUSHOVER:+(set)}"
    debug_log "WEBHOOK_SLACK=${WEBHOOK_SLACK:+(set)}"
    debug_log "PUSHOVER_USER_KEY=${PUSHOVER_USER_KEY:+(set)}"
    debug_log "SCRIPT_START_EPOCH=$SCRIPT_START_EPOCH"

    # --- Validation ---
    error_count=0

    if [[ -z "$BACKUP_DESTINATION" ]]; then
        debug_log "ERROR: BACKUP_DESTINATION is empty"
        echo "[ERROR] Backup destination is empty"
        set_status "Backup destination empty"
        notify_local "alert" "Flash Backup Error" "Backup destination is empty"
        exit 1
    fi

    if ! [[ "$BACKUPS_TO_KEEP" =~ ^[0-9]+$ ]]; then
        debug_log "ERROR: BACKUPS_TO_KEEP is not numeric: $BACKUPS_TO_KEEP"
        echo "[ERROR] Backups to keep is not numeric: $BACKUPS_TO_KEEP"
        set_status "Backups to keep invalid"
        notify_local "alert" "Flash Backup Error" "Backups to keep is not numeric: $BACKUPS_TO_KEEP"
        exit 1
    fi

    IFS=',' read -r -a DEST_ARRAY <<< "$BACKUP_DESTINATION"
    local dest_count=${#DEST_ARRAY[@]}

    if (( dest_count == 0 )); then
        debug_log "ERROR: No valid backup destinations after split"
        echo "[ERROR] No valid backup destinations"
        notify_local "alert" "Flash Backup Error" "No valid backup destinations"
        exit 1
    fi

    debug_log "Destination count: $dest_count"

    notify_local "normal" "Flash Backup" "Local backup started"

    sleep 5

    build_tar_paths

    for dest in "${DEST_ARRAY[@]}"; do
        process_destination "$dest" "$dest_count"
    done

    debug_log "All destinations processed"
    echo "Local backup completed successfully"
    exit 0
}

main "$@"
