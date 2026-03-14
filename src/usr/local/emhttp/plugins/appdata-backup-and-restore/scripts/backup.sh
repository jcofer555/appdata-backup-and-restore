#!/usr/bin/env bash
# ============================================================
# AppData Backup Plugin – Backup Script
# ============================================================
set -uo pipefail

PLUGIN="appdata-backup"
CONFIG_DIR="/boot/config/plugins/${PLUGIN}"
CONFIG_FILE="${CONFIG_DIR}/config.cfg"
LOG_DIR="/tmp/${PLUGIN}"
LOCK_FILE="${LOG_DIR}/backup.lock"
PID_FILE="${LOG_DIR}/backup.pid"
STATUS_FILE="${LOG_DIR}/backup.status"
LAST_FILE="${LOG_DIR}/last_backup.txt"
SIZE_FILE="${LOG_DIR}/backup_size.txt"

# ── Logging ─────────────────────────────────────────────────
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}
log_info()  { log "[INFO]    $*"; }
log_ok()    { log "[SUCCESS] $*"; }
log_warn()  { log "[WARNING] $*"; }
log_error() { log "[ERROR]   $*"; }

# ── Status Reporter ──────────────────────────────────────────
set_status() {
    local progress_int="$1"
    local message_str="$2"
    local success_bool="${3:-false}"
    cat > "${STATUS_FILE}" <<EOF
{"progress":${progress_int},"message":"${message_str}","success":${success_bool}}
EOF
}

# ── Cleanup / Trap ───────────────────────────────────────────
STARTED_CONTAINERS=()
cleanup() {
    local exit_code_int=$?
    log_warn "Cleaning up…"
    set_status 100 "Cleaning up" false

    # Restart stopped containers
    if [[ ${#STARTED_CONTAINERS[@]} -gt 0 ]]; then
        log_info "Restarting ${#STARTED_CONTAINERS[@]} container(s)…"
        for c in "${STARTED_CONTAINERS[@]}"; do
            log_info "Starting: $c"
            docker start "$c" >> /dev/null 2>&1 || log_warn "Could not restart $c"
        done
    fi

    rm -f "${LOCK_FILE}" "${PID_FILE}"
    if [[ $exit_code_int -ne 0 ]]; then
        log_error "Backup ended with errors (exit $exit_code_int)"
        set_status 100 "Backup failed" false
    fi
}
trap cleanup EXIT INT TERM

# ── Load Config ──────────────────────────────────────────────
declare -A CFG
CFG[BACKUP_DEST]="/mnt/user/backups/appdata"
CFG[APPDATA_SRC]="/mnt/user/appdata"
CFG[STOP_CONTAINERS]="yes"
CFG[COMPRESS]="yes"
CFG[COMPRESSION_TYPE]="gz"
CFG[RETENTION_DAYS]="7"
CFG[RETENTION_COUNT]="5"
CFG[NOTIFY_ENABLE]="yes"
CFG[NOTIFY_LEVEL]="both"
CFG[EXCLUDE_CONTAINERS]=""
CFG[INCLUDE_CONTAINERS]=""
CFG[VERIFY_BACKUP]="yes"
CFG[BACKUP_VMDISKS]="no"
CFG[EXTRA_FOLDERS]=""
CFG[PRE_SCRIPT]=""
CFG[POST_SCRIPT]=""
CFG[RCLONE_ENABLE]="no"
CFG[RCLONE_REMOTE]=""
CFG[RCLONE_PATH]=""

if [[ -f "${CONFIG_FILE}" ]]; then
    while IFS='=' read -r key val; do
        [[ "$key" =~ ^#.*$ || -z "$key" ]] && continue
        key="${key// /}"
        val="${val//\"/}"
        val="${val//\'/}"
        CFG[$key]="$val"
    done < "${CONFIG_FILE}"
fi

# ── Derived vars ─────────────────────────────────────────────
TIMESTAMP_STR="$(date '+%Y-%m-%d_%H%M%S')"
BACKUP_NAME_STR="appdata_backup_${TIMESTAMP_STR}"
BACKUP_PATH_STR="${CFG[BACKUP_DEST]}/${BACKUP_NAME_STR}"
ERRORS_INT=0

# ── Pre-flight ───────────────────────────────────────────────
log_info "========================================"
log_info "AppData Backup – Starting"
log_info "Timestamp : ${TIMESTAMP_STR}"
log_info "Source    : ${CFG[APPDATA_SRC]}"
log_info "Dest      : ${CFG[BACKUP_DEST]}"
log_info "========================================"

set_status 2 "Pre-flight checks…"

if [[ ! -d "${CFG[APPDATA_SRC]}" ]]; then
    log_error "AppData source not found: ${CFG[APPDATA_SRC]}"
    exit 1
fi

mkdir -p "${CFG[BACKUP_DEST]}" || { log_error "Cannot create backup destination"; exit 1; }
mkdir -p "${BACKUP_PATH_STR}"  || { log_error "Cannot create backup directory";   exit 1; }

# Write lock
echo "$$" > "${LOCK_FILE}"
echo "$$" > "${PID_FILE}"

# ── Pre-Backup Script ────────────────────────────────────────
if [[ -n "${CFG[PRE_SCRIPT]}" && -x "${CFG[PRE_SCRIPT]}" ]]; then
    log_info "Running pre-backup script: ${CFG[PRE_SCRIPT]}"
    set_status 5 "Running pre-backup script…"
    if ! bash "${CFG[PRE_SCRIPT]}"; then
        log_warn "Pre-backup script returned non-zero"
    fi
fi

# ── Determine containers ─────────────────────────────────────
set_status 8 "Discovering containers…"
ALL_CONTAINERS=()
while IFS= read -r line; do
    [[ -n "$line" ]] && ALL_CONTAINERS+=("$line")
done < <(docker ps -a --format '{{.Names}}' 2>/dev/null | sort)

BACKUP_CONTAINERS=()
if [[ -n "${CFG[INCLUDE_CONTAINERS]}" ]]; then
    IFS=',' read -ra inc <<< "${CFG[INCLUDE_CONTAINERS]}"
    for c in "${inc[@]}"; do
        c="${c// /}"
        [[ -n "$c" ]] && BACKUP_CONTAINERS+=("$c")
    done
else
    IFS=',' read -ra exc <<< "${CFG[EXCLUDE_CONTAINERS]}"
    declare -A EXCLUDE_MAP
    for c in "${exc[@]}"; do
        c="${c// /}"
        [[ -n "$c" ]] && EXCLUDE_MAP[$c]=1
    done
    for c in "${ALL_CONTAINERS[@]}"; do
        [[ -z "${EXCLUDE_MAP[$c]+x}" ]] && BACKUP_CONTAINERS+=("$c")
    done
fi

log_info "Containers to back up: ${#BACKUP_CONTAINERS[@]}"

# Write manifest
printf '%s\n' "${BACKUP_CONTAINERS[@]}" > "${BACKUP_PATH_STR}/manifest.txt"

# ── Stop Containers ──────────────────────────────────────────
if [[ "${CFG[STOP_CONTAINERS]}" == "yes" && ${#BACKUP_CONTAINERS[@]} -gt 0 ]]; then
    set_status 12 "Stopping containers…"
    log_info "Stopping ${#BACKUP_CONTAINERS[@]} container(s)…"
    for c in "${BACKUP_CONTAINERS[@]}"; do
        STATUS=$(docker inspect --format '{{.State.Status}}' "$c" 2>/dev/null || echo "unknown")
        if [[ "$STATUS" == "running" ]]; then
            log_info "Stopping: $c"
            docker stop "$c" >> /dev/null 2>&1 || log_warn "Could not stop $c"
            STARTED_CONTAINERS+=("$c")
        fi
    done
fi

# ── Choose tar flags ─────────────────────────────────────────
EXT_STR="tar"
TAR_FLAGS_STR=""
if [[ "${CFG[COMPRESS]}" == "yes" ]]; then
    case "${CFG[COMPRESSION_TYPE]}" in
        gz)  EXT_STR="tar.gz";   TAR_FLAGS_STR="-z" ;;
        bz2) EXT_STR="tar.bz2";  TAR_FLAGS_STR="-j" ;;
        xz)  EXT_STR="tar.xz";   TAR_FLAGS_STR="-J" ;;
        zst) EXT_STR="tar.zst";  TAR_FLAGS_STR="--zstd" ;;
        *)   EXT_STR="tar.gz";   TAR_FLAGS_STR="-z" ;;
    esac
fi

# ── Backup Each Container ────────────────────────────────────
TOTAL_INT=${#BACKUP_CONTAINERS[@]}
DONE_INT=0

for c in "${BACKUP_CONTAINERS[@]}"; do
    PROGRESS_INT=$(( 15 + (DONE_INT * 65 / (TOTAL_INT > 0 ? TOTAL_INT : 1)) ))
    set_status "${PROGRESS_INT}" "Backing up: ${c}…"
    log_info "Backing up container: $c"

    SRC_DIR="${CFG[APPDATA_SRC]}/${c}"
    ARCHIVE_PATH_STR="${BACKUP_PATH_STR}/${c}.${EXT_STR}"

    if [[ ! -d "$SRC_DIR" ]]; then
        log_warn "AppData dir not found for $c: $SRC_DIR – skipping"
        (( DONE_INT++ )) || true
        continue
    fi

    if tar -c ${TAR_FLAGS_STR} -f "${ARCHIVE_PATH_STR}" -C "${CFG[APPDATA_SRC]}" "${c}" 2>/dev/null; then
        log_ok "Backed up: $c"
    else
        log_error "Failed to archive: $c"
        (( ERRORS_INT++ )) || true
    fi
    (( DONE_INT++ )) || true
done

# ── Extra Folders ────────────────────────────────────────────
if [[ -n "${CFG[EXTRA_FOLDERS]}" ]]; then
    set_status 82 "Backing up extra folders…"
    IFS=',' read -ra EXTRAS <<< "${CFG[EXTRA_FOLDERS]}"
    for folder in "${EXTRAS[@]}"; do
        folder="${folder// /}"
        [[ -z "$folder" || ! -d "$folder" ]] && continue
        fname=$(basename "$folder")
        log_info "Backing up extra folder: $folder"
        ARCHIVE_PATH_STR="${BACKUP_PATH_STR}/extra_${fname}.${EXT_STR}"
        if tar -c ${TAR_FLAGS_STR} -f "${ARCHIVE_PATH_STR}" -C "$(dirname "$folder")" "${fname}" 2>/dev/null; then
            log_ok "Backed up extra folder: $folder"
        else
            log_error "Failed to archive extra folder: $folder"
            (( ERRORS_INT++ )) || true
        fi
    done
fi

# ── VM Disks ─────────────────────────────────────────────────
if [[ "${CFG[BACKUP_VMDISKS]}" == "yes" && -d "/mnt/user/domains" ]]; then
    set_status 85 "Backing up VM disks…"
    log_info "Backing up VM disks…"
    ARCHIVE_PATH_STR="${BACKUP_PATH_STR}/vm_domains.${EXT_STR}"
    if tar -c ${TAR_FLAGS_STR} -f "${ARCHIVE_PATH_STR}" -C /mnt/user domains 2>/dev/null; then
        log_ok "VM disks backed up"
    else
        log_error "Failed to backup VM disks"
        (( ERRORS_INT++ )) || true
    fi
fi

# ── Restart Containers ───────────────────────────────────────
if [[ ${#STARTED_CONTAINERS[@]} -gt 0 ]]; then
    set_status 88 "Restarting containers…"
    log_info "Restarting ${#STARTED_CONTAINERS[@]} container(s)…"
    for c in "${STARTED_CONTAINERS[@]}"; do
        log_info "Starting: $c"
        docker start "$c" >> /dev/null 2>&1 || log_warn "Could not restart $c"
    done
    STARTED_CONTAINERS=()
fi

# ── Verify Backup ────────────────────────────────────────────
if [[ "${CFG[VERIFY_BACKUP]}" == "yes" && "${CFG[COMPRESS]}" == "yes" ]]; then
    set_status 90 "Verifying archives…"
    log_info "Verifying backup archives…"
    VERIFY_ERRORS_INT=0
    for archive in "${BACKUP_PATH_STR}"/*.${EXT_STR}; do
        [[ -f "$archive" ]] || continue
        if tar -t ${TAR_FLAGS_STR} -f "$archive" >> /dev/null 2>&1; then
            log_ok "OK: $(basename "$archive")"
        else
            log_error "CORRUPT: $(basename "$archive")"
            (( VERIFY_ERRORS_INT++ )) || true
            (( ERRORS_INT++ )) || true
        fi
    done
    [[ $VERIFY_ERRORS_INT -eq 0 ]] && log_ok "All archives verified" || log_error "${VERIFY_ERRORS_INT} corrupt archive(s)"
fi

# ── Backup Size ──────────────────────────────────────────────
BACKUP_SIZE_STR=$(du -sh "${BACKUP_PATH_STR}" 2>/dev/null | cut -f1)
echo "${BACKUP_SIZE_STR}" > "${SIZE_FILE}"
log_info "Backup size: ${BACKUP_SIZE_STR}"

# ── Retention ────────────────────────────────────────────────
set_status 93 "Applying retention policy…"

# Count-based retention
RETENTION_COUNT_INT="${CFG[RETENTION_COUNT]}"
if [[ "${RETENTION_COUNT_INT}" -gt 0 ]]; then
    EXISTING=$(find "${CFG[BACKUP_DEST]}" -maxdepth 1 -type d -name "appdata_backup_*" | sort -r)
    COUNT_INT=0
    while IFS= read -r dir; do
        (( COUNT_INT++ )) || true
        if [[ $COUNT_INT -gt $RETENTION_COUNT_INT ]]; then
            log_info "Retention: removing old backup $(basename "$dir")"
            rm -rf "$dir"
        fi
    done <<< "$EXISTING"
fi

# Age-based retention
RETENTION_DAYS_INT="${CFG[RETENTION_DAYS]}"
if [[ "${RETENTION_DAYS_INT}" -gt 0 ]]; then
    find "${CFG[BACKUP_DEST]}" -maxdepth 1 -type d -name "appdata_backup_*" -mtime "+${RETENTION_DAYS_INT}" | while read -r old_dir; do
        log_info "Retention: removing expired backup $(basename "$old_dir")"
        rm -rf "$old_dir"
    done
fi

# ── rclone Sync ──────────────────────────────────────────────
if [[ "${CFG[RCLONE_ENABLE]}" == "yes" && -n "${CFG[RCLONE_REMOTE]}" ]]; then
    set_status 95 "Syncing to cloud…"
    log_info "Syncing to rclone remote: ${CFG[RCLONE_REMOTE]}:${CFG[RCLONE_PATH]}"
    RCLONE_DEST="${CFG[RCLONE_REMOTE]}:${CFG[RCLONE_PATH]}"
    if command -v rclone &>/dev/null; then
        if rclone sync "${CFG[BACKUP_DEST]}" "${RCLONE_DEST}" --log-level INFO 2>&1; then
            log_ok "rclone sync complete"
        else
            log_error "rclone sync failed"
            (( ERRORS_INT++ )) || true
        fi
    else
        log_warn "rclone not found – skipping cloud sync"
    fi
fi

# ── Post-Backup Script ───────────────────────────────────────
if [[ -n "${CFG[POST_SCRIPT]}" && -x "${CFG[POST_SCRIPT]}" ]]; then
    set_status 97 "Running post-backup script…"
    log_info "Running post-backup script: ${CFG[POST_SCRIPT]}"
    bash "${CFG[POST_SCRIPT]}" || log_warn "Post-backup script returned non-zero"
fi

# ── Notifications ────────────────────────────────────────────
if [[ "${CFG[NOTIFY_ENABLE]}" == "yes" ]]; then
    if [[ $ERRORS_INT -eq 0 ]]; then
        NOTIFY_MSG="AppData backup completed successfully. Size: ${BACKUP_SIZE_STR}, Containers: ${#BACKUP_CONTAINERS[@]}"
        NOTIFY_TYPE="normal"
    else
        NOTIFY_MSG="AppData backup completed with ${ERRORS_INT} error(s). Check the log."
        NOTIFY_TYPE="alert"
    fi

    if [[ "${CFG[NOTIFY_LEVEL]}" == "both" ]] || \
       [[ "${CFG[NOTIFY_LEVEL]}" == "success" && $ERRORS_INT -eq 0 ]] || \
       [[ "${CFG[NOTIFY_LEVEL]}" == "failure" && $ERRORS_INT -gt 0 ]]; then
        /usr/local/emhttp/webGui/scripts/notify -e "AppData Backup" -s "$NOTIFY_MSG" -i "$NOTIFY_TYPE" 2>/dev/null || true
    fi
fi

# ── Finish ───────────────────────────────────────────────────
date '+%Y-%m-%d %H:%M:%S' > "${LAST_FILE}"
rm -f "${LOCK_FILE}" "${PID_FILE}"

if [[ $ERRORS_INT -eq 0 ]]; then
    log_ok "========================================"
    log_ok "Backup COMPLETE – ${BACKUP_NAME_STR}"
    log_ok "========================================"
    set_status 100 "Backup complete!" true
else
    log_error "========================================"
    log_error "Backup finished with ${ERRORS_INT} error(s)"
    log_error "========================================"
    set_status 100 "Backup finished with errors" false
    exit 1
fi
