<?php

declare(strict_types=1);

/*
 * Scripted HTTP/1.1 server of the transport contract tests (see
 * ScriptedServer). It works at the socket level, so that it can send what
 * PHP's built-in server cannot: interim responses, truncated bodies, stalls,
 * chunked or compressed bodies of any size, garbage, an empty reply. It keeps
 * connections alive when the client allows it, serves TLS when started with
 * a certificate, and handles one request at a time.
 *
 * Usage: php scripted-server.php <state directory> [<PEM with certificate and key>|-] [<bind address>]
 *
 * The listening port is written to <state directory>/port; every request is
 * appended to <state directory>/requests.jsonl as
 * {"connection": n, "index": n, "method", "target", "version", "headers"}.
 *
 * Endpoints (query parameters in brackets):
 *   /echo                      200, JSON of the request as logged
 *   /status/<code>             that status, Location from [location], an ETag on 304,
 *                              a text body or [size] bytes (none for 204 and 304)
 *   /bytes/<n>                 200 with n bytes of ScriptedServer::payload():
 *                              [mode] length (default), chunked or close (no length);
 *                              [gzip=1] gzip when the request accepts it;
 *                              [gzip_cut=k] with gzip=1 and mode=length, only the first
 *                              k bytes of the gzip stream, announced as the whole body;
 *                              [truncate=k] closes after k body bytes;
 *                              [header_delay], [body_delay], [stall]: milliseconds
 *                              before the headers, before the body, after its first block
 *   /zeros/<n>                 n zero bytes, chunked, gzip when the request accepts it
 *   /redirect/<n>              302 to /redirect/<n-1> until 0, which answers 200 "done";
 *                              [code] another redirect status
 *   /redirect-to               [code] (default 302) to [location]
 *   /conditional               ETag "v1", Last-Modified Tue, 01 Sep 2026 10:00:00 GMT;
 *                              304 when If-None-Match or If-Modified-Since match
 *   /early-hints               103 Early Hints, then 200 "final"
 *   /repeated-headers          two Vary fields and two X-Repeated fields
 *   /trickle/<part>            one byte every [every] ms (default 200) for [for] ms
 *                              (default 5000) inside a line, then the rest of a 200:
 *                              part header (a header field), chunk (a chunk size
 *                              line) or trailer (a trailer field)
 *   /close                     closes the connection without answering
 *   /garbage                   answers a line that is not HTTP, then closes
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

const BLOCK_SIZE = 65536;
const LAST_MODIFIED = 'Tue, 01 Sep 2026 10:00:00 GMT';

$state = $argv[1] ?? '';
$certificate = $argv[2] ?? '-';
$bind = $argv[3] ?? '127.0.0.1';
if ($state === '' || !is_dir($state)) {
    fwrite(STDERR, "Usage: php scripted-server.php <state directory> [<PEM>|-] [<bind address>]\n");
    exit(2);
}
$tls = $certificate !== '-';

$server = @stream_socket_server(
    'tcp://' . (strpos($bind, ':') !== false ? '[' . $bind . ']' : $bind) . ':0',
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
);
if ($server === false) {
    file_put_contents($state . '/error', $errstr);
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
file_put_contents($state . '/port.tmp', substr($name, (int) strrpos($name, ':') + 1));
rename($state . '/port.tmp', $state . '/port');

$block = '';
while (strlen($block) < BLOCK_SIZE) {
    $block .= hash('sha256', (string) strlen($block));
}
$block = substr($block, 0, BLOCK_SIZE);

/** @var array<int, array{stream: resource, id: int, index: int, buffer: string}> $connections */
$connections = [];
$nextId = 1;

for (;;) {
    $read = [$server];
    foreach ($connections as $connection) {
        $read[] = $connection['stream'];
    }
    $write = null;
    $except = null;
    if (@stream_select($read, $write, $except, 1) === false) {
        continue;
    }

    foreach ($read as $stream) {
        if ($stream === $server) {
            $client = @stream_socket_accept($server, 1);
            if ($client === false) {
                continue;
            }
            if ($tls) {
                stream_context_set_option($client, 'ssl', 'local_cert', $certificate);
                stream_set_timeout($client, 5);
                if (!@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                    @fclose($client);
                    continue;
                }
            }
            $connections[(int) $client] = ['stream' => $client, 'id' => $nextId++, 'index' => 0, 'buffer' => ''];
            continue;
        }

        $key = (int) $stream;
        if (!isset($connections[$key])) {
            continue;
        }
        $connection = $connections[$key];
        // A failed read (false) is handled like an empty one.
        $data = (string) @fread($stream, 65536);
        if ($data === '' && feof($stream)) {
            @fclose($stream);
            unset($connections[$key]);
            continue;
        }
        $connection['buffer'] .= $data;
        $keep = true;
        while ($keep && ($end = strpos($connection['buffer'], "\r\n\r\n")) !== false) {
            $head = substr($connection['buffer'], 0, $end);
            $connection['buffer'] = (string) substr($connection['buffer'], $end + 4);
            ++$connection['index'];
            $keep = handle($stream, $head, $connection['id'], $connection['index'], $state, $block);
        }
        if ($keep) {
            $connections[$key] = $connection;
        } else {
            @fclose($stream);
            unset($connections[$key]);
        }
    }
}

/**
 * Answers one request; returns whether the connection stays open.
 *
 * @param resource $stream
 */
function handle($stream, string $head, int $connection, int $index, string $state, string $block): bool
{
    $lines = explode("\r\n", $head);
    $parts = explode(' ', (string) array_shift($lines)) + ['', '', ''];
    list($method, $target, $version) = $parts;
    $headers = [];
    foreach ($lines as $line) {
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }
    }
    $request = ['connection' => $connection, 'index' => $index, 'method' => $method, 'target' => $target, 'version' => $version, 'headers' => $headers];
    file_put_contents($state . '/requests.jsonl', json_encode($request) . "\n", FILE_APPEND | LOCK_EX);

    $path = (string) parse_url($target, PHP_URL_PATH);
    $query = [];
    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
    $keepAlive = $version === 'HTTP/1.1' && strtolower($headers['connection'] ?? '') !== 'close';
    $isHead = $method === 'HEAD';
    $acceptsGzip = stripos($headers['accept-encoding'] ?? '', 'gzip') !== false;
    $segments = explode('/', trim($path, '/'));
    $number = isset($segments[1]) && ctype_digit($segments[1]) ? (int) $segments[1] : 0;

    switch ($segments[0]) {
        case 'echo':
            return respond($stream, 200, ['Content-Type' => 'application/json'], (string) json_encode($request), $keepAlive, $isHead);

        case 'status':
            $extra = ['Content-Type' => 'text/plain'];
            if (isset($query['location']) && is_string($query['location'])) {
                $extra['Location'] = $query['location'];
            }
            if ($number === 304) {
                $extra['ETag'] = '"v1"';
            }
            $body = isset($query['size']) && is_string($query['size']) ? str_repeat('e', (int) $query['size']) : 'status ' . $number;

            return respond($stream, $number, $extra, $number === 204 || $number === 304 ? '' : $body, $keepAlive, $isHead);

        case 'bytes':
            return bytes($stream, $number, $query, $acceptsGzip, $keepAlive, $isHead, $block);

        case 'zeros':
            $headers = ['Content-Type' => 'application/octet-stream', 'Transfer-Encoding' => 'chunked'];
            $deflate = $acceptsGzip ? deflate_init(ZLIB_ENCODING_GZIP) : false;
            if ($deflate !== false) {
                $headers['Content-Encoding'] = 'gzip';
            }
            writeHead($stream, 200, $headers, false);
            $zeros = str_repeat("\0", BLOCK_SIZE);
            for ($sent = 0; $sent < $number; $sent += BLOCK_SIZE) {
                $chunk = (string) substr($zeros, 0, min(BLOCK_SIZE, $number - $sent));
                $out = $deflate !== false ? (string) deflate_add($deflate, $chunk, ZLIB_NO_FLUSH) : $chunk;
                if ($out !== '' && !writeAll($stream, dechex(strlen($out)) . "\r\n" . $out . "\r\n")) {
                    return false;
                }
            }
            $out = $deflate !== false ? (string) deflate_add($deflate, '', ZLIB_FINISH) : '';
            writeAll($stream, ($out !== '' ? dechex(strlen($out)) . "\r\n" . $out . "\r\n" : '') . "0\r\n\r\n");

            return false;

        case 'redirect':
            if ($number === 0) {
                return respond($stream, 200, ['Content-Type' => 'text/plain', 'X-Hop' => 'final'], 'done', $keepAlive, $isHead);
            }
            $code = isset($query['code']) && is_string($query['code']) ? (int) $query['code'] : 302;
            $location = '/redirect/' . ($number - 1) . ($query !== [] ? '?' . http_build_query($query) : '');

            return respond($stream, $code, ['Location' => $location, 'X-Hop' => (string) $number, 'Content-Type' => 'text/plain'], 'redirecting', $keepAlive, $isHead);

        case 'redirect-to':
            $code = isset($query['code']) && is_string($query['code']) ? (int) $query['code'] : 302;
            $location = isset($query['location']) && is_string($query['location']) ? $query['location'] : '/echo';

            return respond($stream, $code, ['Location' => $location, 'Content-Type' => 'text/plain'], 'redirecting', $keepAlive, $isHead);

        case 'conditional':
            $validators = ['ETag' => '"v1"', 'Last-Modified' => LAST_MODIFIED];
            $ifNoneMatch = $headers['if-none-match'] ?? null;
            $ifModifiedSince = $headers['if-modified-since'] ?? null;
            $notModified = $ifNoneMatch !== null
                ? (strpos($ifNoneMatch, '"v1"') !== false || trim($ifNoneMatch) === '*')
                : ($ifModifiedSince !== null && strtotime($ifModifiedSince) >= strtotime(LAST_MODIFIED));
            if ($notModified) {
                return respond($stream, 304, $validators, '', $keepAlive, true);
            }

            return respond($stream, 200, $validators + ['Content-Type' => 'text/plain'], 'fresh content', $keepAlive, $isHead);

        case 'early-hints':
            writeAll($stream, "HTTP/1.1 103 Early Hints\r\nLink: </style.css>; rel=preload\r\n\r\n");

            return respond($stream, 200, ['Content-Type' => 'text/plain', 'X-Final' => 'yes'], 'final', $keepAlive, $isHead);

        case 'repeated-headers':
            $body = 'repeated';
            writeAll($stream, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nVary: Accept\r\nVary: Origin\r\nX-Repeated: one\r\nX-Repeated: two\r\n"
                . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . ($isHead ? '' : $body));

            return false;

        case 'trickle':
            return trickle($stream, $segments[1] ?? 'header', $query);

        case 'close':
            return false;

        case 'garbage':
            writeAll($stream, "THIS IS NOT HTTP AT ALL\r\n\r\nnothing\r\n");

            return false;

        default:
            return respond($stream, 404, ['Content-Type' => 'text/plain'], 'unknown endpoint', $keepAlive, $isHead);
    }
}

/**
 * Streams /bytes/<n>.
 *
 * @param resource            $stream
 * @param array<mixed, mixed> $query
 */
function bytes($stream, int $size, array $query, bool $acceptsGzip, bool $keepAlive, bool $isHead, string $block): bool
{
    $mode = isset($query['mode']) && is_string($query['mode']) ? $query['mode'] : 'length';
    $gzip = ($query['gzip'] ?? '') === '1' && $acceptsGzip;
    $truncate = isset($query['truncate']) && is_string($query['truncate']) ? (int) $query['truncate'] : -1;
    pause($query, 'header_delay');

    $headers = ['Content-Type' => 'application/octet-stream'];
    $encoded = null;
    if ($gzip) {
        $headers['Content-Encoding'] = 'gzip';
        if ($mode === 'length') {
            $encoded = (string) gzencode(payload($size, $block));
            if (isset($query['gzip_cut']) && is_string($query['gzip_cut'])) {
                $encoded = (string) substr($encoded, 0, (int) $query['gzip_cut']);
            }
        }
    }
    if ($mode === 'length') {
        $headers['Content-Length'] = (string) ($encoded !== null ? strlen($encoded) : $size);
    } elseif ($mode === 'chunked') {
        $headers['Transfer-Encoding'] = 'chunked';
    } else {
        $keepAlive = false;
    }
    if ($truncate >= 0) {
        $keepAlive = false;
    }
    if (!writeHead($stream, 200, $headers, $keepAlive)) {
        return false;
    }
    if ($isHead) {
        return $keepAlive;
    }
    pause($query, 'body_delay');

    $sent = 0;
    $first = true;
    foreach (bodyChunks($size, $block, $encoded, $gzip && $encoded === null) as $out) {
        if ($out === '') {
            continue;
        }
        if ($truncate >= 0 && $sent + strlen($out) > $truncate) {
            $part = substr($out, 0, $truncate - $sent);
            writeAll($stream, $mode === 'chunked' ? dechex(strlen($out)) . "\r\n" . $part : $part);

            return false;
        }
        if (!writeAll($stream, $mode === 'chunked' ? dechex(strlen($out)) . "\r\n" . $out . "\r\n" : $out)) {
            return false;
        }
        $sent += strlen($out);
        if ($first) {
            $first = false;
            pause($query, 'stall');
        }
    }
    if ($mode === 'chunked' && !writeAll($stream, "0\r\n\r\n")) {
        return false;
    }

    return $keepAlive;
}

/**
 * Answers /trickle/<part>: a 200 that sends one byte at a time inside a
 * line of $part.
 *
 * @param resource            $stream
 * @param array<mixed, mixed> $query
 */
function trickle($stream, string $part, array $query): bool
{
    $chunked = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n5\r\nhello\r\n";
    $parts = [
        'header' => ["HTTP/1.1 200 OK\r\nX-Slow: ", "\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok"],
        'chunk' => [$chunked . '2;slow=', "\r\nok\r\n0\r\n\r\n"],
        'trailer' => [$chunked . "0\r\nX-Slow: ", "\r\n\r\n"],
    ];
    list($start, $end) = $parts[$part] ?? $parts['header'];
    $every = isset($query['every']) && is_string($query['every']) ? max(1, (int) $query['every']) : 200;
    $for = isset($query['for']) && is_string($query['for']) ? (int) $query['for'] : 5000;

    if (!writeAll($stream, $start)) {
        return false;
    }
    for ($until = microtime(true) + $for / 1000; microtime(true) < $until;) {
        usleep($every * 1000);
        if (!writeAll($stream, 'a')) {
            return false;
        }
    }
    writeAll($stream, $end);

    return false;
}

/**
 * The body of /bytes/<n> in blocks: $encoded when given, else the payload,
 * compressed on the fly when $deflate is true.
 *
 * @return Generator<int, string>
 */
function bodyChunks(int $size, string $block, ?string $encoded, bool $deflate): Generator
{
    if ($encoded !== null) {
        for ($offset = 0; $offset < strlen($encoded); $offset += BLOCK_SIZE) {
            yield substr($encoded, $offset, BLOCK_SIZE);
        }

        return;
    }
    $context = $deflate ? deflate_init(ZLIB_ENCODING_GZIP) : false;
    for ($offset = 0; $offset < $size; $offset += BLOCK_SIZE) {
        $raw = substr($block, 0, min(BLOCK_SIZE, $size - $offset));
        yield $context !== false ? (string) deflate_add($context, $raw, ZLIB_NO_FLUSH) : $raw;
    }
    if ($context !== false) {
        yield (string) deflate_add($context, '', ZLIB_FINISH);
    }
}

/**
 * The first $size bytes of the payload: $block repeated.
 */
function payload(int $size, string $block): string
{
    return str_repeat($block, intdiv($size, BLOCK_SIZE)) . substr($block, 0, $size % BLOCK_SIZE);
}

/**
 * @param array<mixed, mixed> $query
 */
function pause(array $query, string $name): void
{
    if (isset($query[$name]) && is_string($query[$name]) && (int) $query[$name] > 0) {
        usleep((int) $query[$name] * 1000);
    }
}

/**
 * Writes a whole response with a Content-Length body; returns whether the
 * connection stays open.
 *
 * @param resource              $stream
 * @param array<string, string> $headers
 */
function respond($stream, int $status, array $headers, string $body, bool $keepAlive, bool $isHead): bool
{
    if ($status !== 204 && $status !== 304 && $status >= 200) {
        $headers['Content-Length'] = (string) strlen($body);
    }

    return writeHead($stream, $status, $headers, $keepAlive) && ($isHead || writeAll($stream, $body)) && $keepAlive;
}

/**
 * @param resource              $stream
 * @param array<string, string> $headers
 */
function writeHead($stream, int $status, array $headers, bool $keepAlive): bool
{
    $reasons = [
        200 => 'OK', 204 => 'No Content', 301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other',
        304 => 'Not Modified', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect', 400 => 'Bad Request',
        401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 410 => 'Gone', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
    ];
    $head = 'HTTP/1.1 ' . $status . ' ' . ($reasons[$status] ?? 'Status') . "\r\n";
    $headers['Connection'] = $keepAlive ? 'keep-alive' : 'close';
    foreach ($headers as $name => $value) {
        $head .= $name . ': ' . $value . "\r\n";
    }

    return writeAll($stream, $head . "\r\n");
}

/**
 * @param resource $stream
 */
function writeAll($stream, string $data): bool
{
    $length = strlen($data);
    for ($written = 0; $written < $length; $written += $count) {
        $count = @fwrite($stream, substr($data, $written, 1048576));
        if (!is_int($count) || $count === 0) {
            return false;
        }
    }

    return true;
}
