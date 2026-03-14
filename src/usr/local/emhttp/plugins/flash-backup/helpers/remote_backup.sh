#!/bin/bash
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

readonly PLUGIN_NAME="flash-backup"
readonly LOG_DIR="/tmp/flash-backup"
readonly LAST_RUN_FILE="${LOG_DIR}/flash-backup.log"
readonly ROTATE_DIR="${LOG_DIR}/archived_logs"
readonly STATUS_FILE="${LOG_DIR}/remote_backup_status.txt"
readonly DEBUG_LOG="${LOG_DIR}/flash-backup-debug.log"
# Rotate log files when they exceed 10 MB
readonly LOG_MAX_BYTES=$(( 10 * 1024 * 1024 ))
# Number of rotated log archives to retain before purging the oldest
readonly LOG_ROTATE_KEEP=10

# ------------------------------------------------------------------------------
# Import environment variables from remote_backup.php (manual or scheduled)
# ------------------------------------------------------------------------------
if [[ -n "${RCLONE_CONFIG_REMOTE:-}" ]]; then
    DRY_RUN_REMOTE="${DRY_RUN_REMOTE:-no}"
    MINIMAL_BACKUP_REMOTE="${MINIMAL_BACKUP_REMOTE:-no}"
    BACKUPS_TO_KEEP_REMOTE="${BACKUPS_TO_KEEP_REMOTE:-0}"
    NOTIFICATIONS_REMOTE="${NOTIFICATIONS_REMOTE:-no}"
    REMOTE_PATH_IN_CONFIG="${REMOTE_PATH_IN_CONFIG:-}"
    B2_BUCKET_NAME="${B2_BUCKET_NAME:-}"
fi

# Remove accidental quotes
RCLONE_CONFIG_REMOTE="${RCLONE_CONFIG_REMOTE//\"/}"
DRY_RUN_REMOTE="${DRY_RUN_REMOTE//\"/}"
MINIMAL_BACKUP_REMOTE="${MINIMAL_BACKUP_REMOTE//\"/}"
BACKUPS_TO_KEEP_REMOTE="${BACKUPS_TO_KEEP_REMOTE//\"/}"
NOTIFICATIONS_REMOTE="${NOTIFICATIONS_REMOTE//\"/}"
REMOTE_PATH_IN_CONFIG="${REMOTE_PATH_IN_CONFIG//\"/}"
B2_BUCKET_NAME="${B2_BUCKET_NAME//\"/}"
NOTIFICATION_SERVICE_REMOTE="${NOTIFICATION_SERVICE_REMOTE//\"/}"
WEBHOOK_DISCORD_REMOTE="${WEBHOOK_DISCORD_REMOTE//\"/}"
WEBHOOK_GOTIFY_REMOTE="${WEBHOOK_GOTIFY_REMOTE//\"/}"
WEBHOOK_NTFY_REMOTE="${WEBHOOK_NTFY_REMOTE//\"/}"
WEBHOOK_PUSHOVER_REMOTE="${WEBHOOK_PUSHOVER_REMOTE//\"/}"
WEBHOOK_SLACK_REMOTE="${WEBHOOK_SLACK_REMOTE//\"/}"
PUSHOVER_USER_KEY_REMOTE="${PUSHOVER_USER_KEY_REMOTE//\"/}"

readonly SCRIPT_START_EPOCH=$(date +%s)

# Path to the stop flag file — created by stop_remote.php to request a graceful stop
STOP_FLAG="/tmp/flash-backup/stop_requested_remote.txt"
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

format_bytes() {
    local bytes=$1
    local units=(B KB MB GB TB)
    local i=0
    while (( bytes >= 1024 && i < ${#units[@]}-1 )); do
        bytes=$(( bytes / 1024 ))
        (( i++ ))
    done
    echo "${bytes}${units[$i]}"
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
# rclone flags — per remote type
# ------------------------------------------------------------------------------
get_rclone_flags() {
    local remote_type="$1"
    local underlying_type="$2"

    [[ "$remote_type" == "crypt" ]] && remote_type="$underlying_type"

    case "$remote_type" in
        b2)
            echo "--fast-list --transfers=32 --checkers=32 --b2-chunk-size=100M --retries=5 --low-level-retries=10"
            ;;
        drive)
            echo "--fast-list --transfers=8 --checkers=8 --drive-chunk-size=64M --drive-skip-gdocs --retries=5 --low-level-retries=10"
            ;;
        s3)
            echo "--fast-list --transfers=32 --checkers=32 --s3-upload-cutoff=100M --s3-chunk-size=100M --retries=5 --low-level-retries=10"
            ;;
        onedrive)
            echo "--fast-list --transfers=10 --checkers=10 --onedrive-chunk-size=100M --retries=5 --low-level-retries=10"
            ;;
        dropbox)
            echo "--transfers=8 --checkers=8 --dropbox-chunk-size=48M --retries=5 --low-level-retries=10"
            ;;
        sftp|ftp|ssh)
            echo "--transfers=4 --checkers=4 --timeout=1m --contimeout=1m --retries=3 --low-level-retries=5"
            ;;
        s3idrive)
            echo "--fast-list --transfers=16 --checkers=16 --s3-upload-cutoff=64M --s3-chunk-size=64M --retries=5 --low-level-retries=10"
            ;;
        box)
            echo "--fast-list --transfers=6 --checkers=6 --retries=5 --low-level-retries=10"
            ;;
        gcs)
            echo "--fast-list --transfers=32 --checkers=32 --gcs-chunk-size=100M --retries=5 --low-level-retries=10"
            ;;
        googlephotos)
            echo "--transfers=4 --checkers=4 --retries=5 --low-level-retries=10"
            ;;
        koofr)
            echo "--fast-list --transfers=8 --checkers=8 --retries=5 --low-level-retries=10"
            ;;
        mega)
            echo "--transfers=6 --checkers=6 --mega-chunk-size=32M --retries=5 --low-level-retries=10"
            ;;
        azureblob)
            echo "--fast-list --transfers=32 --checkers=32 --azureblob-chunk-size=100M --retries=5 --low-level-retries=10"
            ;;
        azurefiles)
            echo "--transfers=16 --checkers=16 --azurefiles-chunk-size=64M --retries=5 --low-level-retries=10"
            ;;
        protondrive)
            echo "--transfers=4 --checkers=4 --retries=5 --low-level-retries=10"
            ;;
        putio)
            echo "--transfers=6 --checkers=6 --retries=5 --low-level-retries=10"
            ;;
        smb)
            echo "--transfers=8 --checkers=8 --retries=3 --low-level-retries=5"
            ;;
        seafile)
            echo "--fast-list --transfers=8 --checkers=8 --retries=5 --low-level-retries=10"
            ;;
        *)
            echo "--transfers=4 --checkers=4 --retries=5 --low-level-retries=10"
            ;;
    esac
}

# ------------------------------------------------------------------------------
# Notification helper
# ------------------------------------------------------------------------------
notify_remote() {
    local level="$1"
    local subject="$2"
    local message="$3"

    debug_log "notify_remote called: level=$level subject=$subject message=$message"

    [[ "$NOTIFICATIONS_REMOTE" != "yes" ]] && { debug_log "Notifications disabled, skipping"; return 0; }

    local color
    case "$level" in
        alert)   color=15158332 ;;
        warning) color=16776960 ;;
        *)       color=3066993  ;;
    esac

    # Iterate over each configured notification service (comma-separated list)
    IFS=',' read -ra SERVICES <<< "$NOTIFICATION_SERVICE_REMOTE"
    for service in "${SERVICES[@]}"; do
        service="${service// /}"
        case "$service" in
            Discord)
                if [[ -n "$WEBHOOK_DISCORD_REMOTE" ]]; then
                    debug_log "Sending Discord notification"
                    curl -sf -X POST "$WEBHOOK_DISCORD_REMOTE" \
                        -H "Content-Type: application/json" \
                        -d "{\"embeds\":[{\"title\":\"$subject\",\"description\":\"$message\",\"color\":$color}]}" || true
                fi
                ;;
            Gotify)
                if [[ -n "$WEBHOOK_GOTIFY_REMOTE" ]]; then
                    debug_log "Sending Gotify notification"
                    curl -sf -X POST "$WEBHOOK_GOTIFY_REMOTE" \
                        -H "Content-Type: application/json" \
                        -d "{\"title\":\"$subject\",\"message\":\"$message\",\"priority\":5}" || true
                fi
                ;;
            Ntfy)
                if [[ -n "$WEBHOOK_NTFY_REMOTE" ]]; then
                    debug_log "Sending Ntfy notification"
                    curl -sf -X POST "$WEBHOOK_NTFY_REMOTE" \
                        -H "Title: $subject" \
                        -d "$message" > /dev/null || true
                fi
                ;;
            Pushover)
                if [[ -n "$WEBHOOK_PUSHOVER_REMOTE" ]]; then
                    debug_log "Sending Pushover notification"
                    local token="${WEBHOOK_PUSHOVER_REMOTE##*/}"
                    curl -sf -X POST "https://api.pushover.net/1/messages.json" \
                        -d "token=${token}" \
                        -d "user=${PUSHOVER_USER_KEY_REMOTE}" \
                        -d "title=${subject}" \
                        -d "message=${message}" > /dev/null || true
                fi
                ;;
            Slack)
                if [[ -n "$WEBHOOK_SLACK_REMOTE" ]]; then
                    debug_log "Sending Slack notification"
                    curl -sf -X POST "$WEBHOOK_SLACK_REMOTE" \
                        -H "Content-Type: application/json" \
                        -d "{\"text\":\"*$subject*\n$message\"}" || true
                fi
                ;;
            Unraid)
                if [[ -x /usr/local/emhttp/webGui/scripts/notify ]]; then
                    debug_log "Sending Unraid native notification"
                    /usr/local/emhttp/webGui/scripts/notify \
                        -e "Flash Backup (Remote)" \
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
    # Remove lock file and any leftover temp archives in /tmp
    rm -f "$lock_file"
    rm -f /tmp/flash_*.tar.gz /tmp/flash_*.tar.gz.tmp 2>/dev/null
    debug_log "Lock file and temp files removed"

    local script_end_epoch script_duration script_duration_human
    script_end_epoch=$(date +%s)
    script_duration=$(( script_end_epoch - SCRIPT_START_EPOCH ))
    script_duration_human="$(format_duration "$script_duration")"

    # Handle a user-requested stop — clean up the stop flag
    if [[ -f "$STOP_FLAG" ]]; then
        rm -f "$STOP_FLAG"
        echo "Remote backup stopped early - Duration: $script_duration_human"
        echo "Remote backup session finished - $(date '+%Y-%m-%d %H:%M:%S')"
        notify_remote "warning" "Flash Backup" \
            "Remote backup stopped early - Duration: $script_duration_human"
        set_status "Remote backup stopped"
        rm -f "$STATUS_FILE"
        debug_log "===== Session ended (stopped) ====="
        return
    fi

    set_status "Remote backup complete - Duration: $script_duration_human"

    echo "Backup duration: $script_duration_human"
    echo "Remote backup session finished - $(date '+%Y-%m-%d %H:%M:%S')"

    debug_log "Session finished - duration=$script_duration_human failure_count=$failure_count success_count=$success_count"

    if (( failure_count > 0 )); then
        notify_remote "warning" "Flash Backup" \
            "Remote backup finished with errors - Duration: $script_duration_human - Check logs for details"
    else
        notify_remote "normal" "Flash Backup" \
            "Remote backup finished - Duration: $script_duration_human"
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

    if [[ "$MINIMAL_BACKUP_REMOTE" == "yes" ]]; then
        echo "Minimal remote backup mode only backing up /config, /extra, and /syslinux/syslinux.cfg"
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
            notify_remote "alert" "Flash Backup Error" "No valid paths found for minimal backup"
            exit 1
        fi
    else
        echo "Full remote backup mode backing up entire /boot"
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

    if [[ "$DRY_RUN_REMOTE" == "yes" ]]; then
        echo "[DRY RUN] Would create archive -> $backup_file"
        debug_log "[DRY RUN] Would create archive: $backup_file"
        return 0
    fi

    debug_log "Starting tar archive creation"
    if [[ "${TAR_PATHS[0]}" == "boot" ]]; then
        tar czf "$tmp_backup_file" -C / boot || {
            debug_log "ERROR: tar failed for full boot backup"
            echo "[ERROR] Failed to create remote backup tar archive"
            notify_remote "alert" "Flash Backup Error" "Failed to create backup tar archive"
            exit 1
        }
    else
        tar czf "$tmp_backup_file" -C / "${TAR_PATHS[@]}" || {
            debug_log "ERROR: tar failed for minimal backup paths: ${TAR_PATHS[*]}"
            echo "[ERROR] Failed to create remote backup tar archive"
            notify_remote "alert" "Flash Backup Error" "Failed to create backup tar archive"
            exit 1
        }
    fi
    debug_log "tar archive created: $tmp_backup_file"

    tar -tf "$tmp_backup_file" >/dev/null 2>&1 || {
        debug_log "ERROR: Integrity check failed for: $tmp_backup_file"
        echo "[ERROR] Remote backup integrity check failed"
        notify_remote "alert" "Flash Backup Error" "Backup integrity check failed"
        exit 1
    }
    debug_log "Integrity check passed"

    mv "$tmp_backup_file" "$backup_file"
    debug_log "Renamed tmp to final: $backup_file"
}

# ------------------------------------------------------------------------------
# Resolve remote type and underlying type for crypt remotes
# ------------------------------------------------------------------------------
resolve_remote_type() {
    local remote="$1"
    local remote_type underlying_type=""

    remote_type=$(rclone config show "$remote" 2>/dev/null | awk -F' *= *' '/^[[:space:]]*type/{print $2; exit}')
    debug_log "Remote type for $remote: $remote_type"

    if [[ "$remote_type" == "crypt" ]]; then
        local crypt_remote
        crypt_remote=$(rclone config show "$remote" 2>/dev/null | awk -F' *= *' '/^[[:space:]]*remote/{print $2; exit}')
        crypt_remote="${crypt_remote%%:*}"
        underlying_type=$(rclone config show "$crypt_remote" 2>/dev/null | awk -F' *= *' '/^[[:space:]]*type/{print $2; exit}')
        debug_log "Crypt remote detected, underlying remote: $crypt_remote type: $underlying_type"
    fi

    echo "$remote_type $underlying_type"
}

# ------------------------------------------------------------------------------
# Prune old remote backups
# ------------------------------------------------------------------------------
prune_old_remote_backups() {
    local dest="$1"
    local remote="$2"

    set_status "Cleaning up old remote backups"

    if (( BACKUPS_TO_KEEP_REMOTE == 0 )); then
        debug_log "BACKUPS_TO_KEEP_REMOTE=0, skipping old backup cleanup for $remote"
        return 0
    fi

    local keep_label backup_word
    if (( BACKUPS_TO_KEEP_REMOTE == 1 )); then
        keep_label="only latest"
        backup_word="backup"
    else
        keep_label="$BACKUPS_TO_KEEP_REMOTE"
        backup_word="backups"
    fi

    if [[ "$DRY_RUN_REMOTE" == "yes" ]]; then
        echo "[DRY RUN] Removing old remote backups keeping $keep_label $backup_word for $remote"
        debug_log "[DRY RUN] Would remove old backups keeping $keep_label $backup_word for $remote"
    else
        echo "Removing old remote backups keeping $keep_label $backup_word for $remote"
        debug_log "Removing old backups keeping $keep_label $backup_word for $remote"
    fi

    mapfile -t files < <(rclone lsf "$dest" --files-only --format "p" 2>/dev/null | sort -r)
    local num_files=${#files[@]}
    debug_log "Found $num_files existing remote backup(s) in $dest"

    if (( num_files > BACKUPS_TO_KEEP_REMOTE )); then
        local remove_count=$(( num_files - BACKUPS_TO_KEEP_REMOTE ))
        debug_log "Need to remove $remove_count old remote backup(s)"

        for (( idx=BACKUPS_TO_KEEP_REMOTE; idx<num_files; idx++ )); do
            local old="${files[$idx]}"
            if [[ "$DRY_RUN_REMOTE" == "yes" ]]; then
                echo "[DRY RUN] Would remove ${dest}/${old}"
                debug_log "[DRY RUN] Would remove: ${dest}/${old}"
            else
                debug_log "Removing old remote backup: ${dest}/${old}"
                if ! rclone delete "${dest}/${old}"; then
                    echo "[WARNING] Failed to remove remote file $old on $remote"
                    debug_log "WARNING: Failed to remove remote file: ${dest}/${old}"
                    set_status "Retention warning for $remote"
                else
                    debug_log "Removed: ${dest}/${old}"
                fi
            fi
        done
    else
        debug_log "No old remote backups to remove ($num_files files, keeping $BACKUPS_TO_KEEP_REMOTE)"
    fi
}

# ------------------------------------------------------------------------------
# Process a single remote
# ------------------------------------------------------------------------------
process_remote() {
    local remote="$1"
    local backup_file="$2"

    remote="${remote#"${remote%%[![:space:]]*}"}"
    remote="${remote%"${remote##*[![:space:]]}"}"

    [[ -z "$remote" ]] && { debug_log "Skipping empty remote entry"; return 0; }

    debug_log "Processing remote: $remote"

    local type_info remote_type underlying_type
    read -r remote_type underlying_type <<< "$(resolve_remote_type "$remote")"

    local rclone_flags
    rclone_flags=$(get_rclone_flags "$remote_type" "$underlying_type")
    debug_log "rclone flags: $rclone_flags"

    local dest
    if [[ "$remote_type" == "b2" || "$remote_type" == "crypt" ]]; then
        dest="${remote}:${B2_BUCKET_NAME}${REMOTE_SUBPATH}"
    else
        dest="${remote}:${REMOTE_SUBPATH}"
    fi

    debug_log "Upload destination: $dest"

    set_status "Uploading flash backup to config $remote"

    # Ensure remote folder exists — skip for b2/crypt which don't support empty dirs
    if [[ "$remote_type" == "b2" || "$remote_type" == "crypt" ]]; then
        debug_log "Skipping mkdir for b2/crypt remote"
    elif [[ "$DRY_RUN_REMOTE" == "yes" ]]; then
        echo "[DRY RUN] Would ensure remote folder exists -> $dest"
        debug_log "[DRY RUN] Would mkdir: $dest"
    else
        debug_log "Creating remote folder: $dest"
        if ! rclone mkdir "$dest"; then
            debug_log "ERROR: Failed to create remote folder: $dest"
            echo "[ERROR] Failed to create folder $REMOTE_SUBPATH on config $remote"
            set_status "Failed to create folder on $remote"
            (( failure_count++ ))
            return 1
        fi
        debug_log "Remote folder ready: $dest"
    fi

    # Upload
    if [[ "$DRY_RUN_REMOTE" == "yes" ]]; then
        echo "[DRY RUN] Would upload $backup_file to $dest"
        debug_log "[DRY RUN] Would upload $backup_file to $dest"
        (( success_count++ ))
    else
        debug_log "Starting upload of $backup_file to $dest"
        if ! rclone copy "$backup_file" "$dest" $rclone_flags; then
            debug_log "ERROR: Upload failed for remote: $remote"
            echo "[ERROR] Failed to upload remote backup to $remote"
            set_status "Upload failed for $remote"
            (( failure_count++ ))
            return 1
        fi
        echo "Uploaded remote backup to -> $dest"
        debug_log "Upload successful to $dest"
        (( success_count++ ))
    fi

    prune_old_remote_backups "$dest" "$remote"

    debug_log "Finished processing remote: $remote"
}

# ------------------------------------------------------------------------------
# Main
# ------------------------------------------------------------------------------
main() {
    mkdir -p "$LOG_DIR" "$ROTATE_DIR"

    rotate_log "$LAST_RUN_FILE" "remote-backup"
    rotate_log "$DEBUG_LOG"     "flash-backup-remote-debug"

    exec > >(tee -a "$LAST_RUN_FILE") 2>&1

    # --- Read plugin version from the .plg installer file for logging ---
PLG_FILE="/boot/config/plugins/flash-backup.plg"
if [[ -f "$PLG_FILE" ]]; then
    version=$(grep -oP 'version="\K[^"]+' "$PLG_FILE" | head -n1)
else
    version="unknown"
fi

    echo "----------------------------------------------------------------------------------------"
    echo "Remote backup session started - $(date '+%Y-%m-%d %H:%M:%S')"
    echo "Plugin version: $version"
    set_status "Starting remote backup"

    debug_log "===== Session started ====="
    debug_log "RCLONE_CONFIG_REMOTE=$RCLONE_CONFIG_REMOTE"
    debug_log "BACKUPS_TO_KEEP_REMOTE=$BACKUPS_TO_KEEP_REMOTE"
    debug_log "DRY_RUN_REMOTE=$DRY_RUN_REMOTE"
    debug_log "MINIMAL_BACKUP_REMOTE=$MINIMAL_BACKUP_REMOTE"
    debug_log "NOTIFICATIONS_REMOTE=$NOTIFICATIONS_REMOTE"
    debug_log "NOTIFICATION_SERVICE_REMOTE=$NOTIFICATION_SERVICE_REMOTE"
    debug_log "REMOTE_PATH_IN_CONFIG=$REMOTE_PATH_IN_CONFIG"
    debug_log "B2_BUCKET_NAME=${B2_BUCKET_NAME:+(set)}"
    debug_log "WEBHOOK_DISCORD_REMOTE=${WEBHOOK_DISCORD_REMOTE:+(set)}"
    debug_log "WEBHOOK_GOTIFY_REMOTE=${WEBHOOK_GOTIFY_REMOTE:+(set)}"
    debug_log "WEBHOOK_NTFY_REMOTE=${WEBHOOK_NTFY_REMOTE:+(set)}"
    debug_log "WEBHOOK_PUSHOVER_REMOTE=${WEBHOOK_PUSHOVER_REMOTE:+(set)}"
    debug_log "WEBHOOK_SLACK_REMOTE=${WEBHOOK_SLACK_REMOTE:+(set)}"
    debug_log "PUSHOVER_USER_KEY_REMOTE=${PUSHOVER_USER_KEY_REMOTE:+(set)}"
    debug_log "SCRIPT_START_EPOCH=$SCRIPT_START_EPOCH"

    # --- Validation ---
    failure_count=0
    success_count=0

    if [[ -z "$RCLONE_CONFIG_REMOTE" ]]; then
        debug_log "ERROR: RCLONE_CONFIG_REMOTE is empty"
        echo "[ERROR] No rclone remotes selected"
        set_status "No rclone remotes selected"
        notify_remote "alert" "Flash Backup Error" "No rclone remotes selected"
        exit 1
    fi

    if ! [[ "$BACKUPS_TO_KEEP_REMOTE" =~ ^[0-9]+$ ]]; then
        debug_log "ERROR: BACKUPS_TO_KEEP_REMOTE is not numeric: $BACKUPS_TO_KEEP_REMOTE"
        echo "[ERROR] Backups to keep is not numeric: $BACKUPS_TO_KEEP_REMOTE"
        set_status "Backups to keep invalid"
        notify_remote "alert" "Flash Backup Error" "Backups to keep is not numeric: $BACKUPS_TO_KEEP_REMOTE"
        exit 1
    fi

    IFS=',' read -r -a REMOTE_ARRAY <<< "$RCLONE_CONFIG_REMOTE"
    local remote_count=${#REMOTE_ARRAY[@]}

    if (( remote_count == 0 )); then
        debug_log "ERROR: No valid rclone remotes after split"
        echo "[ERROR] No valid rclone remotes"
        notify_remote "alert" "Flash Backup Error" "No valid rclone remotes"
        exit 1
    fi

    debug_log "Remote count: $remote_count"

    # Validate each path segment in REMOTE_PATH_IN_CONFIG to reject unsafe characters
    if [[ -n "$REMOTE_PATH_IN_CONFIG" ]]; then
        local inner="${REMOTE_PATH_IN_CONFIG#/}"
        inner="${inner%/}"
        IFS='/' read -r -a parts <<< "$inner"
        local p
        for p in "${parts[@]}"; do
            if ! [[ "$p" =~ ^[A-Za-z0-9._+\-@[:space:]]+$ ]]; then
                debug_log "ERROR: Invalid folder name segment: $p"
                echo "[ERROR] Invalid folder name $p"
                set_status "Invalid folder name"
                notify_remote "alert" "Flash Backup Error" "Invalid folder name: $p"
                exit 1
            fi
        done
    fi

    # Strip leading and trailing slashes, fall back to default
    if [[ -z "$REMOTE_PATH_IN_CONFIG" ]]; then
        REMOTE_SUBPATH="Flash_Backups"
    else
        REMOTE_SUBPATH="${REMOTE_PATH_IN_CONFIG#/}"
        REMOTE_SUBPATH="${REMOTE_SUBPATH%/}"
        [[ -z "$REMOTE_SUBPATH" ]] && REMOTE_SUBPATH="Flash_Backups"
    fi

    debug_log "REMOTE_SUBPATH=$REMOTE_SUBPATH"

    notify_remote "normal" "Flash Backup" "Remote backup started"

    sleep 5

    build_tar_paths

    # Create the archive once then upload it to each configured remote
    local ts backup_file
    ts="$(date +"%Y-%m-%d_%H-%M-%S")"
    backup_file="/tmp/flash_${ts}.tar.gz"

    create_archive "$backup_file"

    if [[ "$DRY_RUN_REMOTE" != "yes" ]]; then
        local backup_size_bytes backup_size_human
        backup_size_bytes=$(stat -c%s "$backup_file" 2>/dev/null || echo 0)
        backup_size_human=$(format_bytes "$backup_size_bytes")
        echo "Backup size is $backup_size_human"
        debug_log "Backup size: $backup_size_human ($backup_size_bytes bytes)"
    fi

    for remote in "${REMOTE_ARRAY[@]}"; do
        process_remote "$remote" "$backup_file"
    done

    debug_log "All remotes processed - success_count=$success_count failure_count=$failure_count"
    exit 0
}

main "$@"
