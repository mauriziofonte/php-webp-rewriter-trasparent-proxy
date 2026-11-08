<?php

declare(strict_types=1);

/*
 * Router script of the fake origin used by the HTTP-level tests, run by PHP's
 * built-in web server (see FakeOriginServer). Every request is appended to
 * requests.jsonl in FAKE_ORIGIN_STATE, then answered:
 *
 * - a path with a rule of status 200 and a body answers that body as it
 *   is, with the headers of the rule (an API answer, for example);
 * - any other path serves the file of the same (decoded) name below
 *   FAKE_ORIGIN_DOCROOT with Content-Type, Content-Length, ETag,
 *   Last-Modified and Vary, plus headers that a proxy must not forward
 *   (Set-Cookie, Cache-Control, X-Origin-Only); 404 when it does not exist.
 *
 * A rule stored in rules.json for the decoded path replaces the answer with
 * its "status" (and "location", "body", "headers"); the query parameters
 * origin_status and origin_location do the same for a single request. The
 * Cache-Control of the files is "private, max-age=60", or the value of
 * FAKE_ORIGIN_CACHE_CONTROL.
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

if ($status === 200 && isset($rule['body']) && is_string($rule['body'])) {
    echo $rule['body'];

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
// "private, max-age=60", which the proxy must neither store nor forward,
// unless the test that started the origin chose another one
// (FAKE_ORIGIN_CACHE_CONTROL), or a rule's headers say otherwise.
if (!isset($rule['headers']) || !is_array($rule['headers']) || !isset($rule['headers']['Cache-Control'])) {
    $cacheControl = getenv('FAKE_ORIGIN_CACHE_CONTROL');
    header('Cache-Control: ' . (is_string($cacheControl) && $cacheControl !== '' ? $cacheControl : 'private, max-age=60'));
}
header('X-Origin-Only: not-for-clients');
echo $contents;

return true;
