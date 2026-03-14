# AppData Backup Plugin for Unraid

A full-featured Docker AppData backup plugin for Unraid, inspired by the flash-backup plugin UI and Commifreak's appdata.backup plugin features.

## Features

- **Selective backup** – back up all containers or include/exclude specific ones
- **Container stop/start** – safely stop containers before backup, restart after
- **Compression** – gzip, bzip2, xz, or zstd compression (or none)
- **Archive verification** – test integrity of created archives
- **Retention policies** – by count and/or by age
- **Scheduled backups** – cron-based scheduling with presets
- **rclone cloud sync** – sync backups to any rclone-supported cloud provider
- **VM disk backup** – optionally include `/mnt/user/domains`
- **Extra folders** – include any additional paths
- **Pre/Post scripts** – custom scripts run before and after backup
- **Unraid notifications** – notify on success, failure, or both
- **Live log view** – stream backup progress in real time
- **Gold-themed UI** – clean dark interface with gold accent colors

## Directory Structure

```
src/
└── usr/local/emhttp/plugins/appdata-backup/
    ├── appdata-backup.page        # WebUI page
    ├── css/appdata-backup.css     # Stylesheet
    ├── js/appdata-backup.js       # Frontend JavaScript
    ├── helpers/ajax.php           # AJAX backend handler
    └── scripts/backup.sh          # Main backup script
plugin/
└── appdata-backup.plg             # Plugin installer
```

## Config Location

`/boot/config/plugins/appdata-backup/config.cfg`

## Log Location

`/tmp/appdata-backup/backup.log`

## Requirements

- Unraid 6.12+
- Docker (for container management)
- rclone (optional, for cloud sync)
