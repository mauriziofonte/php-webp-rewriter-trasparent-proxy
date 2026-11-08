<?php

declare(strict_types=1);

/*
 * Router script of the fake conversion API of the RemoteConverter tests,
 * run by PHP's built-in web server (see FakeApiServer). Every request is
 * appended to requests.jsonl in FAKE_API_STATE, with its method, target,
 * headers and the SHA-256 and length of its body; then the answer
 * answers.json holds for its path is sent: "status", "headers", and a body
 * that is "body" (a string), the contents of "file", or "bytes" zero bytes
 * flushed in blocks without Content-Length. A path without an answer gets
 * a 404 with the JSON error of the API.
 */

$state = (string) getenv('FAKE_API_STATE');
$server = $_SERVER;
$path = (string) parse_url(isset($server['REQUEST_URI']) && is_string($server['REQUEST_URI']) ? $server['REQUEST_URI'] : '/', PHP_URL_PATH);
$headers = [];
foreach ($server as $name => $value) {
    if (is_string($name) && is_string($value) && strncmp($name, 'HTTP_', 5) === 0) {
        $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
    } elseif (($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') && is_string($value)) {
        $headers[strtolower(str_replace('_', '-', $name))] = $value;
    }
}
$body = (string) file_get_contents('php://input');
file_put_contents($state . '/requests.jsonl', json_encode([
    'method' => isset($server['REQUEST_METHOD']) && is_string($server['REQUEST_METHOD']) ? $server['REQUEST_METHOD'] : 'GET',
    'target' => isset($server['REQUEST_URI']) && is_string($server['REQUEST_URI']) ? $server['REQUEST_URI'] : '/',
    'path' => $path,
    'headers' => $headers,
    'body_length' => strlen($body),
    'body_sha256' => hash('sha256', $body),
]) . "\n", FILE_APPEND | LOCK_EX);

$answers = json_decode((string) @file_get_contents($state . '/answers.json'), true);
$answer = is_array($answers) && isset($answers[$path]) && is_array($answers[$path]) ? $answers[$path] : null;
if ($answer === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":{"code":"not_found","message":"No such endpoint"}}';

    return true;
}

http_response_code(isset($answer['status']) && is_int($answer['status']) ? $answer['status'] : 200);
foreach (isset($answer['headers']) && is_array($answer['headers']) ? $answer['headers'] : [] as $name => $values) {
    foreach ((array) $values as $value) {
        if (is_string($name) && is_string($value)) {
            header($name . ': ' . $value, false);
        }
    }
}
if (isset($answer['file']) && is_string($answer['file'])) {
    readfile($answer['file']);
} elseif (isset($answer['bytes']) && is_int($answer['bytes'])) {
    for ($sent = 0; $sent < $answer['bytes']; $sent += 65536) {
        echo str_repeat("\0", min(65536, $answer['bytes'] - $sent));
        flush();
    }
} elseif (isset($answer['body']) && is_string($answer['body'])) {
    echo $answer['body'];
}

return true;
