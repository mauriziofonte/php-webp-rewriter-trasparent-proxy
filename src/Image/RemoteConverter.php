<?php

declare(strict_types=1);

namespace Mfonte\ProxyCdn\Image;

use Mfonte\ProxyCdn\Origin\CircuitBreaker;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Validation\FormatSniffer;
use Psr\Log\LoggerInterface;

/**
 * Converts through the conversion API v2 (POST /v2/convert), with what the
 * API's GET /healthz says it can do (findings P-6 and C-11).
 *
 * Capabilities: /healthz is asked with the key, which makes the API add
 * its Capabilities (the library's schema) to the answer; they are kept
 * for CapabilityCache::SUCCESS_TTL seconds. An answer that cannot be read,
 * or that Capabilities::fromArray() refuses, means that remote conversion
 * is unavailable (getCapabilities() returns null), for $unavailableTtl
 * seconds; it never escapes as an exception.
 *
 * A conversion is refused before anything is sent when the image is
 * larger than the max_bytes the API advertises, or of a format it does
 * not accept: the API would refuse it, after a transfer of up to
 * UPSTREAM_MAX_SIZE bytes. Otherwise the image is posted as the raw body,
 * with the parameters of ConversionRequest::toParameters() in the query
 * string, the key as "Authorization: Bearer", and the request ID of the
 * client, when there is a valid one, in X-Request-Id (risk L-20). The
 * converted image is written to the output file, bounded to
 * $maxOutputBytes; the ConversionResult is read from the X-Conversion-*
 * headers.
 *
 * Answers (ApiClient never follows a redirect):
 *
 * | Answer                                        | Failure     | Breaker  |
 * | --------------------------------------------- | ----------- | -------- |
 * | 200 with a consistent image                   | none        | closed   |
 * | 503 concurrency_unavailable,                  | unavailable | opened   |
 * | rate_limit_unavailable,                       |             | at once  |
 * | authentication_not_configured; 401, 403       |             |          |
 * | other 503, 429, 5xx but conversion_failed and | unavailable | counted  |
 * | output_verification_failed; no answer (DNS,   |             |          |
 * | connect, TLS, timeout); a redirect; an answer |             |          |
 * | that is not the API's                         |             |          |
 * | 400, 413, 415, 422, conversion_failed,        | rejected    | closed   |
 * | output_verification_failed; a body over its   |             |          |
 * | limit                                         |             |          |
 *
 * "Opened at once" uses a second breaker of threshold 1 on the same
 * state: the API itself says it cannot take conversions for a while.
 * While the breaker is open, conversions fail at once, as unavailable.
 * The error code of an answer is read from its JSON body
 * ({"error": {"code", "message"}}). The key never appears in a message or
 * a log entry.
 */
final class RemoteConverter implements ImageConverter
{
    public const NAME = 'remote';

    /**
     * The largest JSON body read: /healthz and the error answers.
     */
    public const MAX_JSON_BYTES = 65536;

    /**
     * Seconds the /healthz call may take at most: the first one after the
     * API starts probes its backends and its isolation, which takes
     * seconds (the API caches the result).
     */
    public const DISCOVERY_TIMEOUT = 30;

    /**
     * The request IDs sent in X-Request-Id: the ones the API adopts.
     */
    private const REQUEST_ID_PATTERN = '/\A[A-Za-z0-9._:\/+=@-]{1,128}\z/';

    /**
     * The API's error codes after which it takes no conversion for a
     * while: the breaker opens at once.
     */
    private const TRIPPING_CODES = ['concurrency_unavailable', 'rate_limit_unavailable', 'authentication_not_configured', 'missing_api_key', 'invalid_api_key', 'ip_not_allowed', 'https_required'];

    /**
     * The 5xx error codes that are about the image, not the API.
     */
    private const IMAGE_SERVER_CODES = [ErrorCode::CONVERSION_FAILED, ErrorCode::OUTPUT_VERIFICATION_FAILED];

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var ApiClient
     */
    private $client;

    /**
     * @var CircuitBreaker
     */
    private $breaker;

    /**
     * @var CircuitBreaker
     */
    private $trip;

    /**
     * @var CapabilityCache
     */
    private $capabilityCache;

    /**
     * @var int
     */
    private $unavailableTtl;

    /**
     * @var int
     */
    private $maxOutputBytes;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * The capabilities read during this request: false until read.
     *
     * @var Capabilities|false|null
     */
    private $capabilities = false;

    /**
     * @param string         $baseUrl        IMAGE_API_URL, without trailing slash
     * @param string         $apiKey         IMAGE_API_KEY
     * @param CircuitBreaker $breaker        the breaker of the API, with CIRCUIT_BREAKER_THRESHOLD
     * @param CircuitBreaker $trip           the same breaker state with threshold 1, which opens at once
     * @param int            $unavailableTtl seconds a failed discovery is remembered (NETWORK_ERROR_TTL)
     * @param int            $maxOutputBytes the largest converted image accepted (UPSTREAM_MAX_SIZE)
     */
    public function __construct(
        string $baseUrl,
        string $apiKey,
        ApiClient $client,
        CircuitBreaker $breaker,
        CircuitBreaker $trip,
        CapabilityCache $capabilityCache,
        int $unavailableTtl,
        int $maxOutputBytes,
        LoggerInterface $logger
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->client = $client;
        $this->breaker = $breaker;
        $this->trip = $trip;
        $this->capabilityCache = $capabilityCache;
        $this->unavailableTtl = $unavailableTtl;
        $this->maxOutputBytes = $maxOutputBytes;
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getCapabilities(): ?Capabilities
    {
        if ($this->capabilities !== false) {
            return $this->capabilities;
        }
        $now = time();
        $cached = $this->capabilityCache->load($now);
        if ($cached !== null) {
            return $this->capabilities = $cached === false ? null : $cached;
        }

        $this->capabilities = $this->discover();
        if ($this->capabilities === null) {
            $this->capabilityCache->storeUnavailable($now, $this->unavailableTtl);
        } else {
            $this->capabilityCache->storeCapabilities($this->capabilities, $now);
        }

        return $this->capabilities;
    }

    public function convert(string $inputPath, string $outputPath, ConversionRequest $request, ?string $requestId = null): ConversionResult
    {
        if ($this->breaker->isOpen($this->breakerKey())) {
            throw ConversionFailure::unavailable(self::NAME, 'The circuit breaker of the conversion API is open', 'circuit_open');
        }
        $capabilities = $this->getCapabilities();
        if ($capabilities === null) {
            throw ConversionFailure::unavailable(self::NAME, 'The capabilities of the conversion API are unavailable', 'capabilities_unavailable');
        }

        $size = @filesize($inputPath);
        $handle = @fopen($inputPath, 'rb');
        $head = $handle === false ? false : fread($handle, FormatSniffer::HEAD_LENGTH);
        if ($handle !== false) {
            fclose($handle);
        }
        if ($size === false || $head === false) {
            throw ConversionFailure::rejected(self::NAME, 'The image cannot be read', ErrorCode::INTERNAL_ERROR);
        }
        $maxBytes = $capabilities->getLimit(Capabilities::LIMIT_MAX_BYTES);
        if ($size > $maxBytes) {
            throw ConversionFailure::rejected(self::NAME, sprintf('The image (%d bytes) is larger than the %d bytes the conversion API accepts', $size, $maxBytes), ErrorCode::FILE_TOO_LARGE);
        }
        $format = (new FormatSniffer())->detect($head);
        if ($format === null || !$capabilities->supportsInput($format)) {
            throw ConversionFailure::rejected(self::NAME, 'The conversion API does not accept the format of the image', ErrorCode::UNSUPPORTED_FORMAT);
        }
        $target = $request->resolveOutputFormat($format);
        if (!$capabilities->supportsOutput($target)) {
            throw ConversionFailure::rejected(self::NAME, 'The conversion API cannot produce ' . $target, 'output_format_unavailable');
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/octet-stream',
            'Accept: ' . ImageFormat::mimeType($target) . ', application/json',
        ];
        if ($requestId !== null && preg_match(self::REQUEST_ID_PATTERN, $requestId) === 1) {
            $headers[] = 'X-Request-Id: ' . $requestId;
        }
        $url = $this->baseUrl . '/v2/convert?' . http_build_query($request->toParameters(), '', '&', PHP_QUERY_RFC3986);
        $response = $this->client->post($url, $headers, $inputPath, $outputPath, $this->maxOutputBytes, self::MAX_JSON_BYTES);

        try {
            $result = $this->resultOf($response, $outputPath);
        } catch (ConversionFailure $failure) {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }

            throw $failure;
        }
        $this->breaker->reset($this->breakerKey());

        return $result;
    }

    /**
     * The result of a conversion answer, or its failure; the breaker is
     * updated for a failure.
     *
     * @throws ConversionFailure
     */
    private function resultOf(ApiResponse $response, string $outputPath): ConversionResult
    {
        if ($response->isTooLarge()) {
            throw ConversionFailure::rejected(self::NAME, 'The answer of the conversion API exceeds its size limit', 'response_too_large', $response->getStatus());
        }
        if ($response->getTransportErrorCode() !== 0 || $response->getStatus() === 0) {
            $this->breaker->recordFailure($this->breakerKey());

            throw ConversionFailure::unavailable(self::NAME, 'The conversion API cannot be reached: ' . self::printable($response->getTransportError()), 'unreachable');
        }
        $status = $response->getStatus();
        if ($status !== 200) {
            throw $this->failureOf($response);
        }

        $result = self::parseResult($response, $outputPath);
        if ($result === null) {
            $this->breaker->recordFailure($this->breakerKey());

            throw ConversionFailure::unavailable(self::NAME, 'The conversion API answered a 200 that is not a conversion result', 'invalid_response', $status);
        }

        return $result;
    }

    /**
     * The failure of an answer other than 200, with the breaker updated.
     */
    private function failureOf(ApiResponse $response): ConversionFailure
    {
        $status = $response->getStatus();
        list($code, $message) = self::errorOf($response->getBody());
        $reason = sprintf('The conversion API answered %d%s', $status, $message === '' ? '' : ': ' . $message);
        if ($status >= 300 && $status < 400) {
            $this->breaker->recordFailure($this->breakerKey());

            return ConversionFailure::unavailable(self::NAME, 'The conversion API answered a redirect, which is not followed (check IMAGE_API_URL)', 'redirected', $status);
        }
        if (in_array($code, self::TRIPPING_CODES, true) || $status === 401 || $status === 403) {
            $this->trip->recordFailure($this->breakerKey());

            return ConversionFailure::unavailable(self::NAME, $reason, $code ?? 'refused', $status);
        }
        if ($status === 429 || $status >= 500 && !in_array($code, self::IMAGE_SERVER_CODES, true)) {
            $this->breaker->recordFailure($this->breakerKey());

            return ConversionFailure::unavailable(self::NAME, $reason, $code ?? 'server_error', $status);
        }

        return ConversionFailure::rejected(self::NAME, $reason, $code ?? 'client_error', $status);
    }

    /**
     * The ConversionResult the X-Conversion-* headers of a 200 describe,
     * for the image written to $outputPath; null when the answer is not a
     * consistent conversion result.
     */
    private static function parseResult(ApiResponse $response, string $outputPath): ?ConversionResult
    {
        clearstatcache(true, $outputPath);
        $size = is_file($outputPath) ? @filesize($outputPath) : false;
        $format = (string) $response->getHeader('X-Conversion-Format');
        $contentType = strtolower(trim(explode(';', (string) $response->getHeader('Content-Type'))[0]));
        $length = $response->getHeader('Content-Length');
        if ($size === false || $size < 1 || !ImageFormat::isValid($format) || $contentType !== ImageFormat::mimeType($format)
            || ($length !== null && $length !== (string) $size)
        ) {
            return null;
        }
        $integers = [];
        foreach (['Width', 'Height', 'Frames', 'Duration-Ms'] as $name) {
            $value = (string) $response->getHeader('X-Conversion-' . $name);
            if (preg_match('/\A[0-9]{1,10}\z/', $value) !== 1) {
                return null;
            }
            $integers[$name] = (int) $value;
        }
        $backend = (string) $response->getHeader('X-Conversion-Backend');
        $isolation = (string) $response->getHeader('X-Conversion-Isolation');
        $kept = $response->getHeader('X-Conversion-Original-Kept');
        if (!IsolationLevel::isValid($isolation) || !in_array($kept, ['0', '1'], true)
            || ($backend !== ConversionResult::BACKEND_NONE && !Capabilities::isValidBackendName($backend))
        ) {
            return null;
        }
        $warnings = [];
        foreach ($response->getHeaderValues('X-Conversion-Warning') as $warning) {
            if (preg_match('/\A[\x20-\x7E]{1,' . ConversionResult::WARNING_MAX_LENGTH . '}\z/', $warning) === 1) {
                $warnings[] = $warning;
            }
        }

        try {
            return new ConversionResult(
                $outputPath,
                $format,
                $integers['Width'],
                $integers['Height'],
                $size,
                $integers['Frames'],
                $backend,
                $integers['Duration-Ms'],
                $isolation,
                $kept === '1',
                $warnings
            );
        } catch (LibraryException $exception) {
            return null;
        }
    }

    /**
     * Asks /healthz for the capabilities of the API; null when they cannot
     * be had, with the reason logged.
     */
    private function discover(): ?Capabilities
    {
        $response = $this->client->get(
            $this->baseUrl . '/healthz',
            ['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json'],
            self::MAX_JSON_BYTES,
            (float) self::DISCOVERY_TIMEOUT
        );
        $context = ['api' => $this->baseUrl, 'status' => $response->getStatus()];
        if ($response->getTransportErrorCode() !== 0 || $response->getStatus() === 0) {
            $this->breaker->recordFailure($this->breakerKey());
            $this->logger->warning('conversion API capabilities unavailable', $context + [
                ConversionFailure::LOG_ERROR_CODE => $response->isTooLarge() ? 'response_too_large' : 'unreachable',
                'reason' => self::printable($response->getTransportError()),
            ]);

            return null;
        }
        if ($response->getStatus() !== 200) {
            $failure = $this->failureOf($response);
            $this->logger->warning('conversion API capabilities unavailable', $context + $failure->logContext());

            return null;
        }

        $document = json_decode($response->getBody(), true);
        $data = is_array($document) && ($document['status'] ?? null) === 'ok' ? ($document['capabilities'] ?? null) : null;
        if (!is_array($data)) {
            $this->logger->error('conversion API capabilities unreadable', $context + [
                ConversionFailure::LOG_ERROR_CODE => 'invalid_capabilities',
                'reason' => 'The /healthz answer holds no capabilities: is IMAGE_API_KEY accepted, and is the API at version 2?',
            ]);

            return null;
        }
        try {
            return Capabilities::fromArray($data);
        } catch (LibraryException $exception) {
            $this->logger->error('conversion API capabilities unreadable', $context + [
                ConversionFailure::LOG_ERROR_CODE => 'invalid_capabilities',
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The error code and message of an error body of the API
     * ({"error": {"code", "message"}}): the code when it is a code (lower
     * case letters and "_"), the message cut to printable ASCII.
     *
     * @return array{string|null, string}
     */
    private static function errorOf(string $body): array
    {
        $document = json_decode($body, true);
        $error = is_array($document) && is_array($document['error'] ?? null) ? $document['error'] : [];
        $code = $error['code'] ?? null;
        $message = $error['message'] ?? null;

        return [
            is_string($code) && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $code) === 1 ? $code : null,
            is_string($message) ? self::printable($message) : '',
        ];
    }

    /**
     * $text with every character but printable ASCII removed, cut to 200
     * characters: safe in a log line.
     */
    private static function printable(string $text): string
    {
        return substr((string) preg_replace('/[^\x20-\x7E]/', '', $text), 0, 200);
    }

    private function breakerKey(): string
    {
        return $this->baseUrl;
    }
}
