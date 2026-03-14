<?php

// ------------------------------------------------------------------------------
// respond_text() — plain-text response with explicit HTTP code
// ------------------------------------------------------------------------------
function respond_text(int $code, string $body): void {
    http_response_code($code);
    header('Content-Type: text/plain');
    echo $body;
    exit;
}

// ------------------------------------------------------------------------------
// main()
// ------------------------------------------------------------------------------
function main(): void {
    $path = $_POST['path'] ?? '';

    // Return empty string for an empty input
    if ($path === '') {
        respond_text(200, '');
    }

    $resolved = realpath($path);

    // Return the original path unchanged if realpath fails (e.g., path does not exist yet)
    respond_text(200, $resolved !== false ? $resolved : $path);
}

main();
