<?php

// System passwd file
define('PASSWD_FILE',   '/etc/passwd');
// GID for the standard Unraid users group
define('USERS_GID',     100);
// Skip UIDs below this threshold to exclude system accounts
define('MIN_UID',       1000);
// Username always included regardless of UID or group membership
define('ALWAYS_INCLUDE', 'nobody');

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
// is_in_users_group()
// ------------------------------------------------------------------------------
function is_in_users_group(string $username): bool {
    $output = [];
    exec('id -G ' . escapeshellarg($username) . ' 2>/dev/null', $output);
    if (empty($output)) {
        return false;
    }
    // Parse the space-separated GID list and check for the target GID
    $gids = array_map('intval', explode(' ', $output[0]));
    return in_array(USERS_GID, $gids, true);
}

// ------------------------------------------------------------------------------
// main()
// ------------------------------------------------------------------------------
function main(): void {
    $lines = file(PASSWD_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        respond(500, ['error' => 'Failed to read passwd file']);
    }

    $users = [];

    foreach ($lines as $line) {
        $parts = explode(':', $line);
        if (count($parts) < 4) continue;

        $username = $parts[0];
        $uid      = (int)$parts[2];
        $gid      = (int)$parts[3];

        // Skip system accounts except nobody
        if ($uid < MIN_UID && $username !== ALWAYS_INCLUDE) continue;

        // Include if primary group matches or supplementary group matches
        if ($gid === USERS_GID || is_in_users_group($username)) {
            $users[] = $username;
        }
    }

    // Ensure nobody appears first in the list
    if (!in_array(ALWAYS_INCLUDE, $users, true)) {
        array_unshift($users, ALWAYS_INCLUDE);
    }

    respond(200, ['users' => array_values($users)]);
}

main();
