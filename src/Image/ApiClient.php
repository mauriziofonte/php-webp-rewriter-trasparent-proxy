<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

/**
 * The HTTP client of RemoteConverter, on ext-curl, made for the conversion
 * API only (finding P-6: 2.x called it without timeout or TLS
 * verification, following redirects):
 *
 * - the certificate of the API is always verified, against the system
 *   bundle or IMAGE_API_CA_FILE; there is no opt-out;
 * - a connect timeout and a total timeout bound every call;
 * - redirects are never followed: a 3xx is a response like any other,
 *   which RemoteConverter refuses (the key must not be sent elsewhere);
 * - only http and https URLs are fetched;
 * - every body is bounded on the client side, also when the API sends no
 *   Content-Length (chunked, or Apache dropping it): the transfer stops
 *   one byte past the limit;
 * - the image is streamed from its file, and the converted image to its
 *   file: neither is held in memory.
 *
 * The header lines given must not hold line breaks; RemoteConverter builds
 * them from validated values only.
 */
final class ApiClient
{
    /**
     * @var float
     */
    private $connectTimeout;

    /**
     * @var float
     */
    private $timeout;

    /**
     * @var string|null
     */
    private $caFile;

    /**
     * @var string
     */
    private $userAgent;

    /**
     * @param float       $connectTimeout seconds to connect (UPSTREAM_CONNECT_TIMEOUT)
     * @param float       $timeout        seconds a whole call may take (IMAGE_CONVERSION_TIMEOUT)
     * @param string|null $caFile         PEM file of the trusted authorities; null for the system bundle
     */
    public function __construct(float $connectTimeout, float $timeout, ?string $caFile, string $userAgent)
    {
        $this->connectTimeout = $connectTimeout;
        $this->timeout = $timeout;
        $this->caFile = $caFile;
        $this->userAgent = $userAgent;
    }

    /**
     * Whether ext-curl is loaded with the functions this client calls.
     */
    public static function isAvailable(): bool
    {
        return function_exists('curl_init') && function_exists('curl_exec') && function_exists('curl_setopt_array');
    }

    /**
     * A GET whose body is kept in memory, up to $maxBytes.
     *
     * @param list<string> $headers header lines ("Name: value")
     * @param float|null   $timeout seconds the call may take; null for the timeout of the client
     */
    public function get(string $url, array $headers, int $maxBytes, ?float $timeout = null): ApiResponse
    {
        return $this->send($url, $headers, null, null, $maxBytes, $maxBytes, $timeout);
    }

    /**
     * A POST of the file $bodyFile as the raw body. The body of a 200 is
     * written to $outputFile, up to $maxOutputBytes, and any other body is
     * kept in memory, up to $maxErrorBytes. $outputFile must not exist.
     *
     * @param list<string> $headers header lines ("Name: value"); Content-Length is added
     */
    public function post(string $url, array $headers, string $bodyFile, string $outputFile, int $maxOutputBytes, int $maxErrorBytes): ApiResponse
    {
        return $this->send($url, $headers, $bodyFile, $outputFile, $maxOutputBytes, $maxErrorBytes, null);
    }

    /**
     * @param list<string> $headers
     */
    private function send(string $url, array $headers, ?string $bodyFile, ?string $outputFile, int $maxOutputBytes, int $maxErrorBytes, ?float $timeout): ApiResponse
    {
        if (!self::isAvailable() || preg_match('#\A(?i:https?)://#', $url) !== 1) {
            return new ApiResponse(0, [], '', -1, 'The curl extension is not available, or the URL is not http(s)', false);
        }
        $handle = curl_init();
        if ($handle === false) {
            return new ApiResponse(0, [], '', -1, 'curl_init() failed', false);
        }

        $input = null;
        if ($bodyFile !== null) {
            $size = @filesize($bodyFile);
            $input = $size === false ? false : @fopen($bodyFile, 'rb');
            if ($size === false || $input === false) {
                return new ApiResponse(0, [], '', -1, 'The image cannot be read', false);
            }
        }

        $status = 0;
        /** @var array<string, list<string>> $responseHeaders */
        $responseHeaders = [];
        $body = '';
        $written = 0;
        $tooLarge = false;
        $output = null;
        $outputFailed = false;

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ceil(($timeout ?? $this->timeout) * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER => false,
            CURLOPT_HTTP_CONTENT_DECODING => false,
            // No "Expect: 100-continue" round trip before the image.
            CURLOPT_HTTPHEADER => array_merge($headers, ['Expect:']),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$status, &$responseHeaders): int {
                $trimmed = rtrim($line, "\r\n");
                if (preg_match('#\AHTTP/[0-9.]+ ([0-9]{3})#', $trimmed, $match) === 1) {
                    // A new response (after a 1xx): forget the previous headers.
                    $status = (int) $match[1];
                    $responseHeaders = [];
                } elseif (strpos($trimmed, ':') !== false) {
                    list($name, $value) = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($name))][] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use (&$status, &$body, &$written, &$tooLarge, &$output, &$outputFailed, $outputFile, $maxOutputBytes, $maxErrorBytes): int {
                $length = strlen($data);
                $toFile = $outputFile !== null && $status === 200;
                if ($written + $length > ($toFile ? $maxOutputBytes : $maxErrorBytes)) {
                    $tooLarge = true;

                    return 0;
                }
                $written += $length;
                if (!$toFile) {
                    $body .= $data;

                    return $length;
                }
                if ($output === null) {
                    $opened = @fopen($outputFile, 'xb');
                    if ($opened === false) {
                        $outputFailed = true;

                        return 0;
                    }
                    $output = $opened;
                }

                return @fwrite($output, $data) === $length ? $length : 0;
            },
        ];
        if ($this->userAgent !== '') {
            $options[CURLOPT_USERAGENT] = $this->userAgent;
        }
        if ($this->caFile !== null && $this->caFile !== '') {
            $options[CURLOPT_CAINFO] = $this->caFile;
        }
        if ($input !== null) {
            // A streamed upload with the POST method: the body is read
            // from the file, with its Content-Length.
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_CUSTOMREQUEST] = 'POST';
            $options[CURLOPT_INFILE] = $input;
            $options[CURLOPT_INFILESIZE] = (int) @filesize((string) $bodyFile);
        }
        if (!curl_setopt_array($handle, $options)) {
            return new ApiResponse(0, [], '', -1, 'The curl options were refused', false);
        }

        curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
        if ($tooLarge) {
            $errno = $errno === 0 ? -1 : $errno;
            $error = 'The response body exceeds its size limit';
        } elseif ($outputFailed) {
            $errno = $errno === 0 ? -1 : $errno;
            $error = 'The output file cannot be created';
        }
        if ($errno !== 0 && $outputFile !== null && is_file($outputFile)) {
            @unlink($outputFile);
        }

        return new ApiResponse($status, $responseHeaders, $body, $errno, $error, $tooLarge);
    }
}
