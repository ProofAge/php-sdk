<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in server, used by the `network-local` test group.
 *
 *   /status/{code}  that status with a JSON error body (3xx also carries a Location header)
 *   /slow           sleeps 3 seconds, for timeout tests
 *   /bytes/{n}      n deterministic bytes as image/jpeg, for stream and sink tests
 *   anything else   JSON echo of the request: method, path, query, headers, raw body
 *                   (base64), its sha256 and length, decoded form fields, uploaded files
 *                   with their sha256
 */

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = (string) $_SERVER['REQUEST_METHOD'];

if (preg_match('#^/status/(\d{3})$#', $path, $m) === 1) {
    $status = (int) $m[1];
    http_response_code($status);
    header('Content-Type: application/json');

    if ($status >= 300 && $status < 400) {
        header('Location: /v1/workspace');
    }

    echo json_encode([
        'error' => ['code' => 'STATUS_'.$status, 'message' => 'Status '.$status],
        'errors' => ['field' => ['The field is required.']],
    ]);

    return;
}

if ($path === '/slow') {
    sleep(3);
    echo 'slow';

    return;
}

if (preg_match('#^/bytes/(\d+)$#', $path, $m) === 1) {
    $length = (int) $m[1];
    header('Content-Type: image/jpeg');
    header('Content-Length: '.$length);

    $pattern = '0123456789abcdef';
    $remaining = $length;

    while ($remaining > 0) {
        $chunk = substr(str_repeat($pattern, 4096), 0, min($remaining, 65536));
        echo $chunk;
        $remaining -= strlen($chunk);
    }

    return;
}

$headers = [];

foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
        $headers[strtolower(str_replace('_', '-', $key))] = $value;
    }
}

$raw = (string) file_get_contents('php://input');
$files = [];

foreach ($_FILES as $field => $file) {
    $files[] = [
        'field' => $field,
        'filename' => $file['name'],
        'size' => $file['size'],
        'type' => $file['type'],
        'sha256' => hash_file('sha256', $file['tmp_name']),
    ];
}

header('Content-Type: application/json');
header('X-Echo: 1');
header('Set-Cookie: a=1');
header('Set-Cookie: b=2', false);

echo json_encode([
    'method' => $method,
    'path' => $path,
    'query' => (string) ($_SERVER['QUERY_STRING'] ?? ''),
    'headers' => $headers,
    'body' => base64_encode($raw),
    'body_sha256' => hash('sha256', $raw),
    'body_length' => strlen($raw),
    'fields' => $_POST,
    'files' => $files,
]);
