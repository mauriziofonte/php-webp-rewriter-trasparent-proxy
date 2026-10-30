<?php

declare(strict_types=1);

/*
 * Router script of the fake origin used by the HTTP-level tests, run by PHP's
 * built-in web server (see FakeOriginServer). Every request is appended to
 * requests.jsonl in FAKE_ORIGIN_STATE, then answered:
 *
 * - POST /__api/convert imitates the 1.x conversion API: it answers with the
 *   uploaded image, prefixed with "API-WEBP:", as base64 in the 1.x JSON shape;
 *   with a rule, it answers the rule's status and {"message": <body>}, or an
 *   HTML page when the rule has no body; a rule with status 200 and a body
 *   answers that body as it is;
 * - any other path serves the file of the same (decoded) name below
 *   FAKE_ORIGIN_DOCROOT with Content-Type, Content-Length, ETag,
 *   Last-Modified and Vary, plus headers that a proxy must not forward
 *   (Set-Cookie, Cache-Control, X-Origin-Only); 404 when it does not exist.
 *
 * A rule stored in rules.json for the decoded path replaces the answer with
 * its "status" (and "location", "body", "headers"); the query parameters
 * origin_status and origin_location do the same for a single request.
 */

$state = (string) getenv('FAKE_ORIGIN_STATE');
$documentRoot = (string) getenv('FAKE_ORIGIN_DOCROOT');

$server = $_SERVER;
$method = isset($server['REQUEST_METHOD']) && is_string($server['REQUEST_METHOD']) ? $server['REQUEST_METHOD'] : 'GET';
$uri = isset($server['REQUEST_URI']) && is_string($server['REQUEST_URI']) ? $server['REQUEST_URI'] : '/';
$path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
$query = [];
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

$headers = [];
foreach ($server as $name => $value) {
    if (is_string($name) && strpos($name, 'HTTP_') === 0 && is_string($value)) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
    }
}

$record = ['method' => $method, 'uri' => $uri, 'path' => $path, 'headers' => $headers];
if ($path === '/__api/convert') {
    $record['post'] = $_POST;
    $record['files'] = array_keys($_FILES);
}
file_put_contents($state . '/requests.jsonl', json_encode($record) . "\n", FILE_APPEND | LOCK_EX);

$rules = json_decode((string) @file_get_contents($state . '/rules.json'), true);
$rule = is_array($rules) && isset($rules[$path]) && is_array($rules[$path]) ? $rules[$path] : [];
if (isset($query['origin_status']) && is_string($query['origin_status'])) {
    $rule['status'] = (int) $query['origin_status'];
}
if (isset($query['origin_location']) && is_string($query['origin_location'])) {
    $rule['location'] = $query['origin_location'];
}

$status = isset($rule['status']) && is_int($rule['status']) ? $rule['status'] : 200;
if (isset($rule['headers']) && is_array($rule['headers'])) {
    foreach ($rule['headers'] as $name => $value) {
        if (is_string($name) && is_string($value)) {
            header($name . ': ' . $value);
        }
    }
}

if ($path === '/__api/convert') {
    if ($status !== 200) {
        http_response_code($status);
        if (isset($rule['body']) && is_string($rule['body'])) {
            header('Content-Type: application/json');
            echo json_encode(['message' => $rule['body']]);
        } else {
            header('Content-Type: text/html');
            echo '<h1>Bad gateway</h1>';
        }

        return true;
    }
    header('Content-Type: application/json');
    if (isset($rule['body']) && is_string($rule['body'])) {
        echo $rule['body'];

        return true;
    }
    $images = $_FILES['images'] ?? null;
    $uploads = is_array($images) && isset($images['tmp_name']) && is_array($images['tmp_name']) ? $images['tmp_name'] : [];
    $upload = $uploads[0] ?? null;
    $contents = is_string($upload) ? (string) file_get_contents($upload) : '';
    echo json_encode(['response' => [['webp_image_base64' => base64_encode('API-WEBP:' . $contents)]]]);

    return true;
}

if ($status !== 200) {
    http_response_code($status);
    if (isset($rule['location']) && is_string($rule['location'])) {
        header('Location: ' . $rule['location']);
    }
    header('Content-Type: text/plain');
    echo isset($rule['body']) && is_string($rule['body']) ? $rule['body'] : 'Fake origin status ' . $status;

    return true;
}

$file = $documentRoot . $path;
if (strpos($path, '..') !== false || !is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Not found';

    return true;
}

$types = [
    'css' => 'text/css',
    'js' => 'application/javascript',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'svg' => 'image/svg+xml',
    'ttf' => 'font/ttf',
];
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$contents = (string) file_get_contents($file);

header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . strlen($contents));
header('ETag: "' . md5($contents) . '"');
header('Last-Modified: Tue, 01 Sep 2026 10:00:00 GMT');
header('Vary: Origin');
header('Accept-Ranges: bytes');
header('Set-Cookie: origin-session=secret');
header('Cache-Control: private, max-age=60');
header('X-Origin-Only: not-for-clients');
echo $contents;

return true;
