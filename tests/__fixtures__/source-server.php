<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/announces-too-much') {
    header('Content-Length: 99999999');
    echo 'x';
    flush();
    sleep(2);

    exit;
}

if ($path === '/not-found-announcing-too-much') {
    http_response_code(404);
    header('Content-Length: 99999999');

    exit;
}

if ($path === '/grows-past-the-cap') {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    for ($chunk = 0; $chunk < 64; $chunk++) {
        echo str_repeat('x', 65536);
        flush();
    }

    exit;
}

echo 'PINNED';
